<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Currencies, trade-type policy, and the first trades so the console has something real to show. */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([['GHS', 2], ['NGN', 2], ['USDT', 6]] as [$code, $decimals]) {
            DB::table('asset')->insertOrIgnore(['code' => $code, 'decimals' => $decimals]);
        }

        // Our own book settles behind a claim window, because it receives fiat by mobile money
        // and is exposed to reversals. The window is opened to zero only while seeding, so that the
        // one historical trade below can be shown already settled.
        DB::table('trade_policy')->where('trade_type', 'platform_inventory')->update([
            'claim_window' => '0 seconds',
        ]);

        // Deposits are held in cedi, per the decision that they are a cedi instrument.
        DB::select("select ledger_deposit('ops-1','USDT',25000,'seed-ops-usdt')");
        DB::select("select ledger_deposit('platform','GHS',120000,'seed-platform-ghs')");
        DB::select("select ledger_deposit('ama','USDT',4000,'seed-ama-usdt')");
        // Vendor deposits are taken in cedi, so every party needs a cedi balance to post one.
        DB::select("select ledger_deposit('ama','GHS',50000,'seed-ama-ghs')");
        DB::select("select ledger_deposit('kwesi','GHS',90000,'seed-kwesi-ghs')");
        DB::select("select ledger_deposit('adwoa','GHS',60000,'seed-adwoa-ghs')");

        // One trade settled, one waiting on its window, one disputed, one open.
        //
        // Each names the rate card pair it was priced from, because a rate without one is refused now and
        // should have been refused then: 0.10 was a plausible-looking number that meant nothing, and the
        // console derives every cedi figure on it. The pair is (what the buyer pays, what the buyer
        // receives) -- kudi-core/012 records both ends for this reason.
        //
        //   platform: the trader pays cedi and receives naira, so the pair is GHS/NGN.
        //   vendor:   the buyer pays naira and receives cedi, so the pair is NGN/GHS.
        DB::select("select set_rate('GHS','NGN',148.2,'pricing@kudimarket.com')");
        DB::select("select set_rate('NGN','GHS',0.006756756756756756,'pricing@kudimarket.com')");

        DB::select("select create_fiat_trade('platform','ama',null,null,'NGN',200000,148.2,'platform_inventory','GHS','NGN','GHS')");
        DB::select("select create_fiat_trade('platform','kwesi',null,null,'NGN',85000,148.2,'platform_inventory','GHS','NGN','GHS')");
        DB::select("select create_fiat_trade('adwoa','ama',null,null,'NGN',120000,0.006756756756756756,'vendor_vendor','GHS','GHS','NGN')");
        // A same-currency vendor pair, at rate 1. It is the one exemption: 1 is the placeholder for "no
        // conversion here" rather than a conversion between a currency and itself.
        DB::select("select create_fiat_trade('kwesi','adwoa',null,null,'GHS',150000,1,'vendor_vendor','GHS','GHS','GHS')");

        // Settle the first one so the console shows a completed trade as well as open ones.
        $t = DB::select("select id from trade where buyer='ama' and state='open'")[0]->id;
        DB::select("select transition_trade({$t},'open','paid_claimed','s1','ama')");
        DB::select("select ops_transition({$t},'paid_claimed','paid_attested','s2','ops@kudimarket.com')");
        DB::select("select transition_trade({$t},'paid_attested','released','s3','system')");

        // Second one stops at attested, so the release guard is visibly pending.
        $t2 = DB::select("select id from trade where buyer='kwesi' and state='open'")[0]->id;
        DB::select("select transition_trade({$t2},'open','paid_claimed','v1','kwesi')");
        DB::select("select ops_transition({$t2},'paid_claimed','paid_attested','v2','ops@kudimarket.com')");

        // Dispute the vendor-to-vendor trade so the console shows a red state and a case to read.
        $t3 = DB::select("select id from trade where trade_type='vendor_vendor' and state='open' order by id limit 1")[0]->id;
        DB::select("select transition_trade({$t3},'open','paid_claimed','d1','ama')");
        DB::select("select transition_trade({$t3},'paid_claimed','disputed','d2','adwoa')");

        // Close the window again: from now on our own book settles only after the window.
        DB::table('trade_policy')->where('trade_type', 'platform_inventory')->update([
            'claim_window' => '15 minutes',
        ]);
    }

    public function down(): void
    {
        DB::table('trade')->delete();
    }
};
