<?php

declare(strict_types=1);

namespace App\Filament\Pricing\Resources\Commissions;

use App\Filament\Pricing\Resources\Commissions\Pages\ListCommissions;
use App\Models\TradePolicy;
use App\Services\CommissionDesk;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * What we charge, on the pricing desk.
 *
 * This is a PRICING screen and not an operations one for the reason 006 sets out and 017 repeats: the
 * commission is our price, so it sits beside the rate card. Operations resolve disputes, and a disputed
 * question is very often the fee -- putting the control there would let one account decide how much we
 * take and then judge the trades it took it on.
 *
 * Two things this screen is built not to offer, and both are load-bearing:
 *
 *   - NO EDIT BUTTON on a row. A setting that is changed by editing a table row has no record of WHO
 *     changed it or WHEN, and "who raised our commission" is a question a regulator asks. Writing goes
 *     through set_commission(), which appends to a hash-chained log.
 *   - NO IMMEDIATE EFFECT ON OPEN TRADES. The fee is frozen onto each trade when it is created, so
 *     changing this changes the next trade and nothing already agreed. The copy on the modal says so,
 *     because a pricing desk that believes it has just repriced the open book has been told otherwise by
 *     a system that does not agree.
 */
class CommissionResource extends Resource
{
    protected static ?string $model = TradePolicy::class;

    protected static ?string $navigationLabel = 'What we charge';

    protected static ?string $modelLabel = 'commission';

    protected static ?string $pluralModelLabel = 'commissions';

    public static function table(Table $table): Table
    {
        return $table
            ->heading('What we charge, per tier')
            ->description('A commission is in basis points per ten thousand, of what the buyer SENDS. It is frozen onto each trade when the trade is opened, so changing this changes the NEXT trade and nothing already agreed.')
            ->columns([
                TextColumn::make('trade_type')
                    ->label('Tier')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'platform_inventory' => 'KudiMarket handled — our own book',
                        'vendor_vendor' => 'Checked — trader to trader',
                        'p2p' => 'P2P',
                        default => $state,
                    }),
                TextColumn::make('fee_bps')
                    ->label('Commission')
                    ->formatStateUsing(fn (string $state): string => $state === '0'
                        ? 'none'
                        : rtrim(rtrim(number_format(((int) $state) / 100, 2, '.', ''), '0'), '.').'%')
                    ->badge()
                    ->color(fn (string $state): string => $state === '0' ? 'gray' : 'primary'),
                TextColumn::make('fee_bearer')
                    ->label('Who pays it')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'buyer' => 'the buyer — added to what they send',
                        'kudimarket' => 'KudiMarket — out of our margin',
                        default => $state,
                    }),
                TextColumn::make('claim_window')->label('Claim window')->toggleable(),
            ])
            ->defaultSort('trade_type')
            ->recordUrl(null)
            ->headerActions([
                Actions\Action::make('set')
                    ->label('Change what we charge')
                    ->modalHeading('Change the commission')
                    ->modalDescription('This takes effect on the next trade opened. Trades already open keep the commission they were quoted, because it was frozen onto them when they were created. The change is recorded against your name permanently.')
                    ->modalSubmitActionLabel('Publish it')
                    ->form([
                        Select::make('trade_type')
                            ->label('Tier')
                            ->options([
                                'platform_inventory' => 'KudiMarket handled — our own book',
                                'vendor_vendor' => 'Checked — trader to trader',
                                'p2p' => 'P2P',
                            ])
                            ->required(),
                        TextInput::make('fee_bps')
                            ->label('Commission (basis points)')
                            ->helperText('100 = 1% of what the buyer sends. 0 to 10000.')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(10000)
                            ->required(),
                        Select::make('fee_bearer')
                            ->label('Who pays it')
                            ->options([
                                'buyer' => 'The buyer — added on top of what they send',
                                'kudimarket' => 'KudiMarket — absorbed out of our margin',
                            ])
                            ->helperText('On our own book KudiMarket is the seller, so the question is never who receives the commission — it is who pays for it.')
                            ->required(),
                        Textarea::make('note')
                            ->label('Why (optional)')
                            ->helperText('What this change is for. It is kept with the record.'),
                    ])
                    ->action(function (array $data): void {
                        app(CommissionDesk::class)->set(
                            tradeType: (string) $data['trade_type'],
                            feeBps: (int) $data['fee_bps'],
                            feeBearer: (string) $data['fee_bearer'],
                            actor: auth()->user()?->email ?? 'unknown@kudimarket.com',
                            note: $data['note'] ?? null,
                        );
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommissions::route('/'),
        ];
    }

    /** No create, no edit, no delete. Writing is set_commission(), and only from the button above. */
    public static function canCreate(): bool
    {
        return false;
    }
}
