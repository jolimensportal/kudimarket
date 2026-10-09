<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Settling trades, once a minute.
 *
 * The ledger's paid_attested -> released step has actor 'system', and without this it never happened: every
 * attested trade sat at paid_attested for ever with money in escrow. The trade screen's copy was softened
 * once to stop promising automatic settlement, which was honest and also hid a missing mechanism instead
 * of adding one.
 *
 * Once a minute because that is how long the claim window is expressed in and the work is one indexed
 * lookup per due trade. It is safe to run more often than that -- the sweep is idempotent by construction,
 * because it only looks at trades still in paid_attested.
 *
 * withoutOverlapping: two sweeps at once would fight over the same trades, and the loser would log a
 * failure that means nothing.
 */
Schedule::command('trades:release-due')->everyMinute()->withoutOverlapping();