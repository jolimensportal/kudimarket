<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trades;

use App\Filament\Resources\Trades\Pages\ListTrades;
use App\Filament\Resources\Trades\Pages\ViewTrade;
use App\Models\Trade;
use App\Services\RateBoard;
use App\Services\TradeFigures;
use App\Services\TradeLedger;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
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
                // Searchable because a sender reads their send id off their own screen and quotes it
                // on the phone. Without this the console can only be paged through, and the handover
                // conversation has no way to get from the number a customer reads to the row it names.
                Tables\Columns\TextColumn::make('id')->label('#')->sortable()->searchable(isIndividual: true),
                Tables\Columns\TextColumn::make('trade_type')
                    ->label('Type')
                    ->badge()
                    // Searchable, and sortable: sends are the rows an operator is here to work, and
                    // they were mixed into every other trade with no way to separate them -- the
                    // handover queue could only be reached by paging.
                    ->searchable()
                    ->color(fn (string $state) => match ($state) {
                        'remittance' => 'primary',
                        'platform_inventory' => 'warning',
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
            // A dropdown rather than per-column filtering: the type names are a closed set the ledger
            // owns, and a free-text box here would let an operator type "send" and get nothing.
            ->filters([
                Tables\Filters\SelectFilter::make('trade_type')
                    ->label('Type')
                    ->options(Trade::query()->distinct()->orderBy('trade_type')->pluck('trade_type', 'trade_type')->all()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                // Attestation. This action was missing entirely, and its absence stopped every trade
                // dead: the Release button below is only visible at paid_attested, and before this there
                // was nothing in the console that could reach paid_attested. A trade could be opened,
                // paid and claimed, and then nothing could ever happen to it -- the buyer's money held
                // with no way out. It is deliberately not on the trader side: confirming that somebody
                // else's money arrived is the one thing a payer may never do.
                Action::make('attest')
                    // The icon name was read off Heroicon's actual constant list rather than guessed.
                    // OutlinedClipboardCheck does not exist in this Filament version, and a wrong constant
                    // is a fatal at table-build time: the whole ops trades page 500s before it renders.
                    ->label('Confirm the payment arrived')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Confirm this payment arrived?')
                    ->modalDescription(fn ($record) => $record->trade_type === 'remittance'
                        // No claim window on a send, and no buyer. A send has no window to wait out --
                        // the next step is the handover -- so telling an operator to wait for one
                        // describes a mechanism this type does not have, at the moment they are deciding
                        // what to do next.
                        ? 'You are confirming that money you can see has reached KudiMarket. '
                            .'You are not the payer, and this is recorded against your staff identity. '
                            .'Next, record the handover against a till reference.'
                        : 'You are confirming that money you can see has reached KudiMarket. '
                            .'You are not the payer, and this is recorded against your staff identity. '
                            .'Once confirmed, the buyer still waits out the claim window before it is released.')
                    ->visible(fn ($record) => $record->state === 'paid_claimed')
                    ->action(function ($record, TradeLedger $ledger) {
                        $ledger->attest((int) $record->id, static::operatorIdentity());
                    }),
                // The handover. Only on a remittance send, only once the money is confirmed, and only
                // reachable with a form rather than a confirmation dialog -- because the two things an
                // operator has to supply ARE the record: what was handed over, and against which till
                // reference. record_payout() refuses both if they are missing, so a form with two
                // required fields is the honest shape rather than a dialog that could be confirmed
                // without either.
                Action::make('record_payout')
                    ->label('Record the payout')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('primary')
                    // $record is NOT always a record. Filament calls visible() once with no argument while
                    // building the table, and reading a property off null there is a fatal that kills the
                    // console page before any HTML is produced -- which is precisely how the two 500s this
                    // test file was written for shipped.
                    ->visible(fn (?object $record) => $record !== null
                        && $record->trade_type === 'remittance'
                        && $record->state === 'paid_attested')
                    ->form([
                        TextInput::make('amount')
                            ->label('Amount handed over')
                            ->required()
                            // In naira, not in minor units. The form used to default to 370500000, and
                            // the person standing at the till cannot check a bundle of notes against
                            // that -- the number on the record they are meant to match is in naira, so
                            // the box they are asked to confirm against was in a unit the counter does
                            // not use. Converted to minor units on the way in.
                            ->default(fn ($record) => number_format(((int) $record->fiat_amount) / 100, 2, '.', ''))
                            ->helperText('Naira, as it is written on the send. '
                                .'A figure that does not match what this send owes is refused.'),
                        TextInput::make('reference')
                            ->label('Till reference')
                            ->required()
                            ->minLength(3)
                            ->maxLength(80)
                            ->helperText('The drawer or till this came out of. '
                                .'Without it a handover cannot be recorded at all.'),
                    ])
                    ->action(function ($record, TradeLedger $ledger, array $data) {
                        // Decimal string to minor units, never a float: the form is in naira because that
                        // is what the counter counts in, and the ledger is in minor units because that is
                        // what it holds. A float in between would lose pesewas on the way past.
                        $ledger->recordPayout(
                            (int) $record->id,
                            RateBoard::toMinor($data['amount'], 'NGN'),
                            (string) $data['reference'],
                            static::operatorIdentity(),
                        );
                    }),
                Action::make('release')
                    ->label('Release')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Release this trade?')
                    ->modalDescription('The release is recorded against your staff identity and cannot be undone.')
                    // Shown but disabled, not hidden. The locked mockup asks for a Release button that
                    // carries its unlock condition, and the brand constitution requires a locked control to
                    // say why it is locked. Hidden is worse than disabled: an operator looking at a
                    // paid_claimed trade could not tell whether the button was missing, broken, or
                    // deliberately withheld.
                    // A remittance send settles from paid_out, not paid_attested -- the cash has to have
                    // been handed over first. Locking Release to paid_attested alone meant a send that
                    // had been paid out and was ready to complete could never be completed from here.
                    ->disabled(fn (?object $record) => $record === null
                        || $record->state !== static::releasableState($record))
                    // There is no disabledReason() on this Filament version; the reason travels as a
                    // tooltip. Written onto a disabled control rather than hidden, because an operator
                    // looking at a paid_claimed trade otherwise cannot tell whether Release is missing,
                    // broken, or deliberately withheld.
                    ->tooltip(function (?object $record) {
                        if ($record === null) {
                            return null;
                        }
                        $state = $record->state;

                        if ($state === static::releasableState($record)) {
                            return $record->trade_type === 'remittance'
                                ? 'Complete this send. The naira has been handed over and the beneficiary has it.'
                                : 'Release the naira to the buyer. The claim window has elapsed.';
                        }

                        // On a send, the thing standing between here and release is the handover, and
                        // saying "the claim window has not elapsed" would describe a control this
                        // product does not have for this type.
                        if ($record->trade_type === 'remittance' && $state === 'paid_attested') {
                            return 'Locked: the naira has not been handed over yet. Use "Record the payout" first.';
                        }

                        return match ($state) {
                            'paid_claimed' => 'Locked: the payment has not been confirmed yet. Use "Confirm the payment arrived" first.',
                            default => 'Locked: release only applies to a confirmed payment.',
                        };
                    })
                    ->visible(fn (?object $record) => $record !== null && ($record->trade_type === 'remittance'
                        ? in_array($record->state, ['open', 'paid_claimed', 'paid_attested', 'paid_out'], true)
                        : in_array($record->state, ['open', 'escrowed', 'paid_claimed', 'paid_attested'], true)))
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
    /**
     * The state this trade settles FROM, asked of the trade's own rules.
     *
     * 'paid_attested' for every type that has been true since before remittance existed, and
     * 'paid_out' for a send. Reading it here keeps the button's lock, its tooltip and its action from
     * each holding their own copy of the answer -- which is how a send ended up with a Release button
     * that was locked in the one state where it was the only thing left to do.
     */
    private static function releasableState(object $record): string
    {
        if ($record->trade_type !== 'remittance') {
            return 'paid_attested';
        }

        return DB::connection('pgsql_ops')->selectOne(
            "select r.from_state
               from trade_rule r join trade t on t.trade_type = r.trade_type
              where t.id = ? and r.to_state = 'released'
              order by case r.from_state when 'paid_attested' then 0 else 1 end
              limit 1",
            [(int) $record->id]
        )->from_state ?? 'paid_out';
    }

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
        $postings = $ledger->postings($tradeId);

        // A rate is fiat units per one unit of the crypto leg. On a trade with no crypto leg it is a
        // stored number that means nothing, so it is not offered as if it did.
        $rate = static fn ($state) => rtrim(rtrim(number_format((float) $state, 8, '.', ''), '0'), '.');
        $hasCryptoLeg = $quote !== null && $quote->crypto_amount !== null;

        // Fetched ONCE. The events are also rendered by the State record section below, and a trade record
        // page that reads them twice for two sections is a page that can show one and not the other.
        $events = $ledger->events($tradeId);
        $narratives = array_values(array_filter($events, fn ($e) => filled($e->narrative ?? null)));

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

        // A vendor trade has no crypto leg, and the rule below used to hide the rate entirely for that
        // reason -- on the comment that a rate on a fiat-only trade "means nothing". It does mean
        // something: it is the agreed cedi per naira, it is what both deposits were priced against, and
        // it is the first thing anybody resolving a dispute asks for. Operations could not see the rate,
        // or what the trade was worth, on exactly the trades that hold money and get disputed.
        if (! $hasCryptoLeg && $quote !== null && $quote->fiat_amount !== null) {
            $terms[] = TextEntry::make('rate')->label('Agreed rate')
                ->helperText('quoted in '.($quote->rate_quote_asset ?? $quote->fiat_asset))
                ->formatStateUsing($rate);

            // cediMinor is the one place that knows which way this rate points, and there is no buyer or
            // seller to ask on this screen, so it is deliberately the neutral question.
            //
            // The refusal is shown rather than thrown. cediMinor throws when a rate counts in neither
            // cedi nor the fiat leg, and that is the right behaviour for a screen that is about to tell
            // somebody an amount to pay. It is the wrong behaviour for an operations console: a page that
            // 500s on one malformed row means nobody can resolve the dispute at all, which is worse than
            // the malformed row. So the reason is put on the page instead of thrown from it.
            $terms[] = TextEntry::make('cedi_value')->label('Agreed value in cedi')
                ->state(static function () use ($quote): string {
                    try {
                        return RateBoard::formatMoney(TradeFigures::cediMinor($quote), 'GHS');
                    } catch (RuntimeException $e) {
                        return 'cannot be derived — '.$e->getMessage();
                    }
                });
        }

        // Who the money is going to, and whether it has been. On every other trade type the counterparty
        // is an email and nothing else, but a send is made out to a named person at a phone number --
        // and an operator standing at a till with a handover form has to be able to check they are paying
        // the right person before they write a reference against it. The record page showed neither.
        $remittance = DB::connection('pgsql_ops')->selectOne(
            'select beneficiary_name, beneficiary_phone, corridor,
                    delivered_amount, delivered_reference, delivered_by, delivered_at
               from remittance_send where trade_id = ?',
            [$tradeId]
        );

        // Each entry's state is bound explicitly. A TextEntry named 'beneficiary_name' would look for
        // that property on the Trade record, which is a read-only model over trade_view and has no such
        // column -- so the section rendered with every field blank, which reads as "no beneficiary" and
        // is the worst possible way for that section to fail.
        $field = fn (string $column, ?string $placeholder) => TextEntry::make($column)
            ->label(trim(str_replace('_', ' ', ucwords(str_replace('_', ' ', $column)))))
            ->state($remittance->{$column} ?? null)
            ->placeholder($placeholder ?? '—');

        $sendSection = $remittance === null ? [] : [
            Section::make('Who is receiving it')
                ->description('A send is made out to a person, not to an account. Check the name and the '
                    .'number against what they gave you before you hand anything over.')
                ->schema([
                    $field('beneficiary_name', null),
                    $field('beneficiary_phone', null),
                    $field('corridor', null),
                    $field('delivered_amount', 'not yet handed over'),
                    $field('delivered_reference', 'not yet handed over'),
                    $field('delivered_by', 'not yet handed over'),
                    $field('delivered_at', 'not yet handed over'),
                ]),
        ];

        return $schema->schema([
            ...$sendSection,
            Section::make('Terms')
                ->columns(3)
                ->schema($terms),
            Section::make('What was said')
                ->description('The trader\'s own words. A dispute nobody can read is a dispute nobody can answer, so this is what an operator reads before deciding where the money goes.')
                ->schema(array_map(
                    fn (object $event): TextEntry => TextEntry::make('narrative-'.$event->seq)
                        ->label(sprintf(
                            '#%s %s, by %s',
                            $event->seq,
                            str_replace('_', ' ', (string) $event->to_state),
                            $event->actor
                        ))
                        ->state((string) $event->narrative)
                        ->prose(),
                    $narratives
                ))
                // Hidden entirely when nobody said anything, rather than rendered as an empty section with
                // a heading that promises words and shows none.
                ->visible($narratives !== []),

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
                    // Stated rather than left blank. At the start of a send there are no postings at
                    // all -- the cedi has been declared but nothing has been confirmed and no naira has
                    // moved -- so the section rendered as an empty box under a heading about money, on
                    // the one page whose job is to account for money. An empty box there reads as
                    // "the money went somewhere and it is not written down", which is the exact fear
                    // this screen exists to remove.
                    TextEntry::make('no_postings')
                        ->label('No money has moved on this send yet.')
                        ->hidden(fn () => $postings !== [])
                        ->hiddenLabel(),
                    RepeatableEntry::make('postings')
                        ->state($postings)
                        ->hidden(fn () => $postings === [])
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
