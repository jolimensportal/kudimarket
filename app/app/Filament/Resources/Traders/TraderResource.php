<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders;

use App\Filament\Resources\Traders\Pages\ListTraders;
use App\Filament\Resources\Traders\Pages\ViewTrader;
use App\Models\Trader;
use App\Services\VerificationDesk;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

class TraderResource extends Resource
{
    protected static ?string $model = Trader::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Trader checks';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'email';

    /**
     * Read the verification chain per trader rather than the verified_at column.
     *
     * The column is a cache written in the same statement as the decision, and a desk looking at "who is
     * checked" must be reading the record. Four queries for the list, not one per row: this is the screen
     * that unblocks people, and it cannot be the slow one.
     */
    public static function table(Table $table): Table
    {
        $desk = app(VerificationDesk::class);

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('email')->label('Trader')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Name')->toggleable(),
                Tables\Columns\TextColumn::make('phone')->label('Phone')->toggleable(),
                // Derived from the chain. 'Never applied' is a real state and says so, rather than showing
                // a blank cell that could be read as "pending" or "refused".
                Tables\Columns\TextColumn::make('verification')
                    ->label('Checking')
                    ->state(fn (Trader $record) => $desk->applicationFor($record->email)?->event ?? 'never applied')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'verified' => 'Checked',
                        'applied' => 'Waiting',
                        'refused' => 'Not checked',
                        default => 'Never applied',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'verified' => 'success',
                        'applied' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->recordActions([
                // CHECK. No reason field: an approval is a statement that the criteria were met, and a
                // box inviting a comment here would fill with "ok".
                Action::make('verify')
                    ->label('Check this trader')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Confirm this trader has been checked?')
                    ->modalDescription('You are confirming that identity, address, the own-name account, '
                        .'the source of funds and the Ghana Card all match. Recorded against your staff '
                        .'identity, on the permanent record, and cannot be edited afterwards.')
                    ->visible(fn (Trader $record) => $desk->applicationFor($record->email)?->is_pending === true)
                    ->action(function (Trader $record, VerificationDesk $desk): void {
                        $desk->decide($record->email, true, null);
                    }),

                // REFUSE. A reason is REQUIRED, and the modal says so before it is typed rather than after
                // it is refused -- a refusal with no reason is a dead end the trader cannot argue with.
                Action::make('refuse')
                    ->label('Not checked — say why')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Turn this application down?')
                    ->modalDescription('What you write is what the trader is shown, in full. Say what is '
                        .'missing so it can be fixed — a bare "no" gives them nothing to act on.')
                    ->form([
                        Textarea::make('reason')
                            ->label('What is missing?')
                            ->required()
                            ->minLength(10)
                            ->maxLength(2000)
                            ->placeholder('Ghana Card number does not match the name on your account.'),
                    ])
                    ->visible(fn (Trader $record) => $desk->applicationFor($record->email)?->is_pending === true)
                    ->action(function (Trader $record, VerificationDesk $desk, array $data): void {
                        $desk->decide($record->email, false, $data['reason']);
                    }),
            ])
            // No toolbar buttons. A "Waiting first" button that does nothing was in the first version,
            // and a control that looks like it sorts but does not is worse than no control at all. The
            // queue is one screen long; ordering it is not a problem yet.
            ->defaultSort('created_at', 'desc');
    }

    public static function schema(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Trader')->schema([
                TextEntry::make('name')->label('Name'),
                TextEntry::make('email')->label('Email'),
                TextEntry::make('phone')->label('Phone')->placeholder('none given'),
                TextEntry::make('kind')->label('Kind'),
                TextEntry::make('verified_at')->label('Checked on')->dateTime()->placeholder('never checked'),
            ])->columns(2),

            Section::make('What they wrote')->description(
                'Word for word, as they wrote it. This is the thing being decided, so it is not summarised.'
            )->schema([
                TextEntry::make('application')
                    ->label('Application')
                    ->state(fn (Trader $record) => app(VerificationDesk::class)
                        ->applicationFor($record->email)?->narrative ?? 'Nothing on file.')
                    ->columnSpanFull(),
            ]),

            Section::make('Decisions')->description('Hash-chained. Each line commits to the one above it.')->schema([
                RepeatableEntry::make('history')
                    ->label('History')
                    ->state(fn (Trader $record) => app(VerificationDesk::class)->history($record->email))
                    ->schema([
                        TextEntry::make('event')->label('Event'),
                        TextEntry::make('actor')->label('By'),
                        TextEntry::make('created_at')->label('When')->dateTime(),
                        TextEntry::make('narrative')->label('Said')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTraders::route('/'),
            'view' => ViewTrader::route('/{record}'),
        ];
    }
}
