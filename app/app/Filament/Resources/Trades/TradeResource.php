<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trades;

use App\Filament\Resources\Trades\Pages\ListTrades;
use App\Filament\Resources\Trades\Pages\ViewTrade;
use App\Models\Trade;
use App\Services\TradeLedger;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use RuntimeException;

class TradeResource extends Resource
{
    protected static ?string $model = Trade::class;   // read-only model over trade_view

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Trades';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'id';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('trade_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'platform_inventory' => 'warning',
                        'vendor_vendor' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('seller')->label('Seller'),
                Tables\Columns\TextColumn::make('buyer')->label('Buyer'),
                Tables\Columns\TextColumn::make('fiat_amount')
                    ->label('Fiat leg')
                    ->formatStateUsing(fn ($state, $record) => $record->fiat_display)
                    ->sortable(),
                Tables\Columns\TextColumn::make('crypto_amount')
                    ->label('Crypto leg')
                    ->formatStateUsing(fn ($state, $record) => $record->crypto_display)
                    ->placeholder('none'),
                Tables\Columns\TextColumn::make('colour')
                    ->label('Colour')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'green' => 'success',
                        'red' => 'danger',
                        default => 'primary',
                    }),
                Tables\Columns\TextColumn::make('state')
                    ->label('State')
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', $state)),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('release')
                    ->label('Release')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Release this trade?')
                    ->modalDescription('The claim window must have elapsed. The release is recorded against your staff identity and cannot be undone.')
                    ->visible(fn ($record) => $record->state === 'paid_attested')
                    ->action(function ($record, TradeLedger $ledger) {
                        $ledger->release((int) $record->id, static::operatorIdentity());
                    }),
                Action::make('refund')
                    ->label('Refund the seller')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Refund this disputed trade?')
                    ->modalDescription('The seller is made whole. This closes the dispute and cannot be undone.')
                    ->form([
                        Select::make('fault')
                            ->label('Who is at fault?')
                            ->options([
                                'none' => 'Nobody — both deposits are returned',
                                'buyer' => 'The buyer — their deposit is forfeited to the seller',
                            ])
                            ->default('none')
                            ->required()
                            ->disabled(fn ($record) => $record->trade_type !== 'vendor_vendor')
                            ->helperText('Only a vendor-to-vendor trade has a counterparty to blame. On our own book there is nobody at fault, because we took the other side.'),
                    ])
                    ->visible(fn ($record) => $record->state === 'disputed')
                    ->action(function ($record, array $data, TradeLedger $ledger) {
                        $ledger->resolve(
                            (int) $record->id, 'disputed', 'resolved_refund',
                            static::operatorIdentity(),
                            static::faultFor($record, $data['fault'] ?? 'none')
                        );
                    }),
                Action::make('release_to_buyer')
                    ->label('Release to the buyer')
                    ->icon(Heroicon::OutlinedHandRaised)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Release to the buyer?')
                    ->modalDescription('The buyer is made whole. Name the party at fault so the other deposit is forfeited.')
                    ->form([
                        Select::make('fault')
                            ->label('Who is at fault?')
                            ->options([
                                'none' => 'Nobody — both deposits are returned',
                                'seller' => 'The seller — their deposit is forfeited to the buyer',
                            ])
                            ->default('none')
                            ->required()
                            ->disabled(fn ($record) => $record->trade_type !== 'vendor_vendor')
                            ->helperText('The seller is the party that took the money and did not deliver, so the seller is the one who can be at fault here.'),
                    ])
                    ->visible(fn ($record) => $record->state === 'disputed')
                    ->action(function ($record, array $data, TradeLedger $ledger) {
                        $ledger->resolve(
                            (int) $record->id, 'disputed', 'resolved_release',
                            static::operatorIdentity(),
                            static::faultFor($record, $data['fault'] ?? 'none')
                        );
                    }),
            ]);
    }

    /**
     * The staff member who pressed the button, taken from the session and never from the form.
     *
     * A dispute record is read months later by someone who was not in the room. If the actor is a
     * hardcoded placeholder, every decision on the platform has the same author, and the record is
     * decoration.
     */
    private static function operatorIdentity(): string
    {
        $email = auth()->user()?->email;

        if (! is_string($email) || trim($email) === '') {
            throw new RuntimeException('No operator is signed in, so this decision cannot be recorded.');
        }

        return $email;
    }

    /**
     * A fault only exists between two vendors, and only the party that can lose money from it.
     *
     * The database refuses a fault that contradicts the outcome (refunding with the seller at fault,
     * releasing with the buyer at fault) and refuses one on our own book, so the form offers exactly
     * the combinations that mean something rather than letting the ledger reject them later.
     *
     * @return 'seller'|'buyer'|null
     */
    private static function faultFor(mixed $record, mixed $fault): ?string
    {
        if ($record->trade_type !== 'vendor_vendor' || ! in_array($fault, ['seller', 'buyer'], true)) {
            return null;
        }

        return $fault;
    }

    /**
     * The record itself, assembled from the chain rather than from the application's memory of it.
     */
    public static function infolist(Schema $schema): Schema
    {
        $tradeId = (int) $schema->getRecord()->id;
        $ledger = app(TradeLedger::class);
        $quote = $ledger->quote($tradeId);

        // A rate is fiat units per one unit of the crypto leg. On a trade with no crypto leg it is a
        // stored number that means nothing, so it is not offered as if it did.
        $rate = static fn ($state) => rtrim(rtrim(number_format((float) $state, 8, '.', ''), '0'), '.');
        $hasCryptoLeg = $quote !== null && $quote->crypto_amount !== null;

        $terms = [
            TextEntry::make('fiat_display')->label('Fiat leg'),
            TextEntry::make('crypto_display')->label('Crypto leg'),
            TextEntry::make('trade_type')->label('Type'),
            TextEntry::make('seller')->label('Seller'),
            TextEntry::make('buyer')->label('Buyer'),
            TextEntry::make('state')->label('State')
                ->formatStateUsing(fn ($state) => str_replace('_', ' ', (string) $state))
                ->badge(),
            TextEntry::make('deposit_asset')->label('Deposits held in')->placeholder('none taken'),
        ];

        if ($hasCryptoLeg) {
            $terms[] = TextEntry::make('rate')->label('Agreed rate')->formatStateUsing($rate);
            $terms[] = TextEntry::make('actual_rate')->label('Rate actually paid')->formatStateUsing($rate);
        }

        return $schema->schema([
            Section::make('Terms')
                ->columns(3)
                ->schema($terms),
            Section::make('State record')
                ->description('Write-once and hash-chained. Each row commits to the one before it, so an edited or deleted row breaks the chain on the integrity page.')
                ->schema([
                    RepeatableEntry::make('events')
                        ->state($ledger->events($tradeId))
                        ->columns(5)
                        ->schema([
                            TextEntry::make('seq')->label('#'),
                            TextEntry::make('from_state')
                                ->label('From')
                                ->formatStateUsing(fn ($state) => $state === 'none' ? 'no state yet' : str_replace('_', ' ', (string) $state)),
                            TextEntry::make('to_state')->label('To')->formatStateUsing(fn ($state) => str_replace('_', ' ', (string) $state)),
                            TextEntry::make('actor')->label('Actor')->placeholder('—'),
                            TextEntry::make('fault')->label('Fault')->placeholder('none'),
                        ]),
                ]),
            Section::make('Where the money went')
                ->description('Every posting this trade made, netted by account.')
                ->schema([
                    RepeatableEntry::make('postings')
                        ->state($ledger->postings($tradeId))
                        ->columns(4)
                        ->schema([
                            TextEntry::make('owner')->label('Account'),
                            TextEntry::make('kind')->label('Kind'),
                            TextEntry::make('asset')->label('Asset'),
                            TextEntry::make('amount_display')->label('Amount'),
                        ]),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrades::route('/'),
            'view' => ViewTrade::route('/{record}'),
        ];
    }
}
