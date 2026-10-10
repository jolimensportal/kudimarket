<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class LedgerIntegrity extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected string $view = 'filament.pages.ledger-integrity';

    protected static ?string $navigationLabel = 'Ledger integrity';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return true;
    }

    public function getViewData(): array
    {
        return [
            'report' => DB::connection('pgsql_ops')->selectOne('select * from integrity_report'),
            // Balances are rendered from the asset table's own decimals, not from a list of
            // currencies named in PHP. A hardcoded list reads every currency added later as 0.00.
            // Segregation, PER CURRENCY and read from segregation_report() rather than from the report's
            // scalar columns -- because there are none, and there should not be. Summing minor units across
            // assets produced 228,838: 199,838 pesewas plus 29,000 USDT units, an amount in a currency that
            // does not exist. The question is asked per currency and answered per currency.
            'segregation' => DB::connection('pgsql_ops')->select(
                'select asset, customer_available, customer_escrow, our_float
                   from segregation_report() order by asset'
            ),
            'segregation_counts' => DB::connection('pgsql_ops')->selectOne(
                'select commingled_transfers, fiat_legs_unposted from segregation_report() limit 1'
            ),
            'accounts' => DB::connection('pgsql_ops')->select(
                "select a.owner, a.kind, a.asset, a.balance,
                        trim(trailing '.' from trim(trailing '0' from to_char(a.balance::numeric / power(10, d.decimals), 'FM9999999990.99999999'))) as balance_display
                   from account a
                   join asset d on d.code = a.asset
                  where a.owner <> 'chain'
                  order by a.asset, a.owner"
            ),
        ];
    }
}
