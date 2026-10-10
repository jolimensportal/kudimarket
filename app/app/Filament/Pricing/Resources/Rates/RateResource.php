<?php

declare(strict_types=1);

namespace App\Filament\Pricing\Resources\Rates;

use App\Models\RateCard;
use App\Services\RateDesk;
use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Our own price, and the whole history of it.
 *
 * "KudiMarket quotes its own rates" is the central claim in brand/product.html. Until this screen the
 * price of every trade was 148.2 hardcoded in scripts/reset-dev-database.sh: two screens read the card
 * faithfully and nothing in the product could write to it.
 *
 * FOUR PROPERTIES, all of them kudi-core/006's rather than this file's, and every one of them is
 * enforced in the database so that a screen cannot weaken them:
 *
 *   1. Append-only. There is no edit and no delete. Publishing inserts a row that supersedes the last by
 *      effective_from, so "what was the rate at 14:03" survives.
 *   2. Hash-chained per pair, like the ledger. A price quietly rewritten afterwards is the cleanest scam
 *      in an FX book: move the rate to fill your own trade badly, then hide the move.
 *   3. kudi_pricing, not kudi_ops. This panel is the application half of that; the database refuses
 *      set_rate to the operations role regardless of what any screen offers.
 *   4. Backdating refused, so nobody can insert a rate that was "live" when they filled a trade badly.
 */
class RateResource extends Resource
{
    protected static ?string $model = RateCard::class;

    protected static ?string $navigationLabel = 'Our rate';

    protected static ?string $modelLabel = 'rate';

    protected static ?string $pluralModelLabel = 'rates';

    /**
     * Listed through RateCard, which is read-only by construction -- save() and delete() throw. Writing is
     * RateDesk::publish(), which calls set_rate().
     *
     * No EditAction and no DeleteAction appear here, and that is the whole of property 1: a rate is never
     * updated, because inserting one that supersedes the last by effective_from is what makes "the rate
     * was 148.20 at 14:03" survive. A screen offering an edit button would be offering the one thing this
     * table exists to refuse.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->heading('Every price we have published')
            ->description('Append-only and hash-chained. A row is never edited, so what a trade was filled at is still provable afterwards.')
            ->columns([
                TextColumn::make('base_asset')->label('Pay in'),
                TextColumn::make('quote_asset')->label('Receive'),
                TextColumn::make('rate')->label('Rate')
                    ->formatStateUsing(fn ($state): string => rtrim(rtrim(number_format((float) $state, 8, '.', ''), '0'), '.'))
                    ->sortable(),
                TextColumn::make('effective_from')->label('Live from')->date('j M Y, H:i')->sortable(),
                TextColumn::make('set_by')->label('Set by'),
                TextColumn::make('note')->label('Note')->placeholder('—')->limit(40),
            ])
            ->defaultSort('effective_from', 'desc')
            ->recordUrl(null)
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Publish a rate')
                    // Filament's default is "Create". A pricing desk publishing a price is not creating
                    // an object, and the button is the last thing said before the price changes, so it is
                    // named for what it does.
                    ->submitButtonLabel('Publish it')
                    ->modalHeading('Publish a rate')
                    ->modalDescription('This does not change any trade already made. It changes what the next one is filled at, and it is recorded against your name permanently.')
                    ->mutateFormDataUsing(function (array $data): array {
                        // effective_from is NOT taken from the request beyond what the ledger allows, and
                        // the note is optional. The rate itself is the only thing that matters and it is
                        // passed through as a DECIMAL STRING: a float would round-trip the price through
                        // binary and quietly change what we publish.
                        $data['rate'] = (string) $data['rate'];

                        return $data;
                    })
                    ->using(function (array $data): Model {
                        return app(RateDesk::class)->publish(
                            base: (string) $data['base_asset'],
                            quote: (string) $data['quote_asset'],
                            rate: (string) $data['rate'],
                            actor: auth()->user()?->email ?? 'unknown@kudimarket.com',
                            effectiveFrom: $data['effective_from'] ?? null,
                            note: $data['note'] ?? null,
                        );
                    })
                    ->form([
                        Select::make('base_asset')
                            ->label('Pay in')
                            ->options(['GHS' => 'Cedi (GHS)', 'NGN' => 'Naira (NGN)'])
                            ->required(),
                        Select::make('quote_asset')
                            ->label('Receive')
                            ->options(['GHS' => 'Cedi (GHS)', 'NGN' => 'Naira (NGN)'])
                            ->required(),
                        TextInput::make('rate')
                            ->label('Rate')
                            ->helperText('Whole units of "receive" per one unit of "pay". 0.0067567 means 1 naira buys 0.0067567 cedi.')
                            ->required()
                            ->numeric(),
                        DateTimePicker::make('effective_from')
                            ->label('Live from')
                            ->helperText('Leave empty for now. The ledger refuses a time in the past, so a rate cannot be backdated over a trade.')
                            ->seconds(false),
                        Textarea::make('note')->label('Why the price moved')->rows(2),
                    ]),
            ])
            ->paginated([25, 50]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRates::route('/'),
        ];
    }
}
