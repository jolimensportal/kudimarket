{{--
    Inline styles, not utility classes: this page must render correctly without a front-end build,
    because it is the page a regulator looks at. A build step is one more thing between us and the
    truth.
--}}
<x-filament-panels::page>
    @php
        $report = $this->getViewData()['report'];
        $accounts = $this->getViewData()['accounts'];
        // Postgres booleans arrive as true/false or 't' depending on the driver. Normalise, because
        // a page that calls a sound ledger BROKEN destroys the only thing it exists to give.
        $bool = static fn ($v) => $v === true || $v === 't' || $v === 1 || $v === '1';
        $unanchored = (int) $report->unanchored_trades;
        // rate_chain_ok arrived with the rate card. A report that silently stopped checking the one
        // thing that reprices the whole book would still look green, which is the failure this page
        // exists to prevent.
        $rateChain = property_exists($report, 'rate_chain_ok') ? $bool($report->rate_chain_ok) : false;
        $rateCount = property_exists($report, 'rates') ? (int) $report->rates : 0;
        $cards = [
            ['Ledger hash chain', $bool($report->ledger_chain_ok), $bool($report->ledger_chain_ok) ? 'verified' : 'BROKEN', 'every posting commits to the one before it', 'when-true'],
            ['Trade state chain', $bool($report->trade_chain_ok), $bool($report->trade_chain_ok) ? 'verified' : 'BROKEN', 'every state change commits to the one before it', 'when-true'],
            ['Rate chain', $bool($report->rate_chain_ok), $bool($report->rate_chain_ok) ? 'verified' : 'BROKEN', 'every rate we have quoted commits to the one before it', 'when-true'],
            ['Unanchored trades', $unanchored, (string) $unanchored, 'a trade with no state record was made outside the constructors', 'when-zero'],
            ['Balance mismatches', (int) $report->balance_mismatches, (string) $report->balance_mismatches, 'cached balances against the sum of postings', null],
            ['Negative balances', (int) $report->negative_accounts, (string) $report->negative_accounts, 'nobody can go below zero except the chain account', null],
            ['Trades', (int) $report->trades, (string) $report->trades, 'settled, waiting, disputed and open', null],
            ['Events recorded', (int) $report->events, (string) $report->events, 'write-once, hash-chained, one per state change', null],
            ['Rates published', $rateCount, (string) $rateCount, 'our own prices, versioned and never edited in place', null],
        ];
        // One verdict, used for the banner and nothing else, so the page cannot say "fine" in one
        // place and show a red number in another.
        $clean = $bool($report->ledger_chain_ok) && $bool($report->trade_chain_ok) && $rateChain && $unanchored === 0;

        // SEGREGATION. brand/product.html names the Bank of Ghana as the first regulator BECAUSE customer
        // funds have to be kept apart from our own, and "the arithmetic adds up" is not an answer to that.
        //
        // What this page can now show, and it is worth something: customer deposits sit in accounts named
        // for the trade they belong to, so they are ring-fenced by construction rather than by policy, and
        // commingled_transfers counts any movement between us and a customer that skipped that door. Zero
        // is the answer, and it is checkable rather than promised.
        //
        // What it cannot show, stated here rather than left for a reader to assume: fiat_legs_unposted is
        // every fiat trade in the product, because the naira and cedi that actually change hands arrive
        // off-system and operations attests them. There is no in-ledger record to point at for the two
        // currencies that are the business.
    @endphp

    <div style="display:flex;flex-wrap:wrap;gap:14px;">
        @foreach ($cards as [$label, $value, $display, $note, $severity])
            @php
                $tone = match ($severity) {
                    'when-true' => $value ? '#4ade80' : '#f87171',
                    'when-zero' => $value === 0 ? '#4ade80' : '#f87171',
                    default => null,
                };
            @endphp
            <div style="flex:1 1 260px;border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:16px 18px;background:rgba(255,255,255,.03)">
                <div style="font-size:13px;opacity:.65">{{ $label }}</div>
                <div style="font-size:26px;font-weight:600;margin-top:4px;font-variant-numeric:tabular-nums;@if ($tone) color: {{ $tone }}; @endif">
                    {{ $display }}
                </div>
                <div style="font-size:12px;opacity:.55;margin-top:2px">{{ $note }}</div>
            </div>
        @endforeach
    </div>

    @php $segregation = $this->getViewData()['segregation']; @endphp

    @if ($segregation)
        <h2 style="font-size:16px;font-weight:600;margin:28px 0 4px">Whose money</h2>
        <div style="font-size:13px;opacity:.7;margin-bottom:10px">
            The Bank of Ghana is our first regulator because customer funds must be kept apart from our own.
            That question is asked per currency, so it is answered per currency &mdash; a total across
            assets would be an amount in a currency nobody has.
        </div>

        <div style="border:1px solid rgba(255,255,255,.12);border-radius:10px;overflow:hidden">
            <table style="width:100%;border-collapse:collapse;font-size:14px">
                <thead>
                    <tr style="background:rgba(255,255,255,.05);text-align:left">
                        <th style="padding:10px 14px;font-weight:500;opacity:.65">Currency</th>
                        <th style="padding:10px 14px;font-weight:500;opacity:.65;text-align:right">Customer funds held</th>
                        <th style="padding:10px 14px;font-weight:500;opacity:.65;text-align:right">Of which, in trade escrow</th>
                        <th style="padding:10px 14px;font-weight:500;opacity:.65;text-align:right">Our own float</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($segregation as $row)
                        @php
                            // RateBoard::formatMoney, the one formatter the rest of the product uses. A
                            // number_format() here would divide by hand and drift from it, which is how the
                            // same money came to be rendered two different ways on one page.
                            $show = static fn ($minor): string => \App\Services\RateBoard::formatMoney($minor, $row->asset);
                        @endphp
                        <tr style="border-top:1px solid rgba(255,255,255,.08)">
                            <td style="padding:9px 14px;font-weight:500">{{ $row->asset }}</td>
                            <td style="padding:9px 14px;text-align:right;font-variant-numeric:tabular-nums">{{ $show($row->customer_available) }}</td>
                            <td style="padding:9px 14px;text-align:right;font-variant-numeric:tabular-nums;opacity:.75">{{ $show($row->customer_escrow) }}</td>
                            <td style="padding:9px 14px;text-align:right;font-variant-numeric:tabular-nums">{{ $show($row->our_float) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="font-size:12px;opacity:.6;margin-top:8px">
            Escrow balances sit in accounts named for the trade they belong to &mdash;
            <code>trade:7:seller_deposit</code> &mdash; so they are ring-fenced to one trade by construction,
            and only that trade can move them.
        </div>

        @php $counts = $this->getViewData()['segregation_counts']; @endphp

        <div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:14px">
            @php
                $commingled = (int) ($counts->commingled_transfers ?? 0);
                $unposted = (int) ($counts->fiat_legs_unposted ?? 0);
            @endphp
            <div style="flex:1 1 300px;border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:16px 18px;background:rgba(255,255,255,.03)">
                <div style="font-size:13px;opacity:.65">Commingled movements</div>
                <div style="font-size:26px;font-weight:600;margin-top:4px;font-variant-numeric:tabular-nums;color:{{ $commingled === 0 ? '#4ade80' : '#f87171' }}">{{ $commingled }}</div>
                <div style="font-size:12px;opacity:.55;margin-top:2px">value that moved between us and a customer without passing through a trade escrow</div>
            </div>
            <div style="flex:1 1 300px;border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:16px 18px;background:rgba(255,255,255,.03)">
                <div style="font-size:13px;opacity:.65">Fiat trades the ledger cannot evidence</div>
                <div style="font-size:26px;font-weight:600;margin-top:4px;font-variant-numeric:tabular-nums;color:{{ $unposted === 0 ? '#4ade80' : '#fbbf24' }}">{{ $unposted }}</div>
                <div style="font-size:12px;opacity:.55;margin-top:2px">the leg changes hands off-system and is attested by operations</div>
            </div>
        </div>

        @if ($commingled > 0)
            <div style="margin-top:14px;border:1px solid #f87171;border-radius:10px;padding:16px 18px;background:rgba(248,113,113,.08)">
                <div style="font-weight:600">{{ $commingled }} movement(s) of value between us and a customer skipped the trade escrow.</div>
                <div style="font-size:13px;opacity:.8;margin-top:4px">
                    Every other movement goes through an account named for its trade. These did not. This
                    counts transactions that have ever happened, so reversing one does not clear it: the
                    ledger cannot tell a correction from a fresh leak.
                </div>
            </div>
        @endif

        @if ($unposted > 0)
            <div style="margin-top:14px;border:1px solid #fbbf24;border-radius:10px;padding:16px 18px;background:rgba(251,191,36,.08)">
                <div style="font-weight:600">There is a gap here, and it is the whole product.</div>
                <div style="font-size:13px;opacity:.8;margin-top:4px">
                    {{ $unposted }} of our trades settle in cedi and naira, and this ledger records only their
                    deposits. The money that actually changes hands arrives off-system and is confirmed by
                    operations. To a regulator asking to see customer funds held apart, we can point at the
                    escrow above and not at the settlement. That has to be fixed before we are the kind of
                    business that says it is regulated.
                </div>
            </div>
        @endif
    @endif

    @unless ($clean)
        <div style="margin-top:18px;border:1px solid #f87171;border-radius:10px;padding:16px 18px;background:rgba(248,113,113,.08)">
            <div style="font-weight:600">Stop. Do not settle any trade until this is green again.</div>
            <div style="font-size:13px;opacity:.8;margin-top:4px">
                A chain did not verify, or a trade holds a state no record vouches for. Someone
                changed a row, or a restore was incomplete. The ledger is not trustworthy in this
                state, and if it is the rate chain that is red then nobody should be shown a price.
            </div>
        </div>
    @endunless

    <h2 style="font-size:16px;font-weight:600;margin:28px 0 10px">Balances by account</h2>
    <div style="border:1px solid rgba(255,255,255,.12);border-radius:10px;overflow:hidden">
        <table style="width:100%;border-collapse:collapse;font-size:14px">
            <thead>
                <tr style="background:rgba(255,255,255,.05);text-align:left">
                    <th style="padding:10px 14px;font-weight:500;opacity:.65">Owner</th>
                    <th style="padding:10px 14px;font-weight:500;opacity:.65">Kind</th>
                    <th style="padding:10px 14px;font-weight:500;opacity:.65">Asset</th>
                    <th style="padding:10px 14px;font-weight:500;opacity:.65;text-align:right">Balance</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($accounts as $row)
                    <tr style="border-top:1px solid rgba(255,255,255,.08)">
                        <td style="padding:9px 14px;font-weight:500">{{ $row->owner }}</td>
                        <td style="padding:9px 14px;opacity:.6">{{ $row->kind }}</td>
                        <td style="padding:9px 14px">{{ $row->asset }}</td>
                        <td style="padding:9px 14px;text-align:right;font-variant-numeric:tabular-nums">
                            {{ $row->balance_display }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
