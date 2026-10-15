<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Referral codes and who sent whom.
 *
 * Every teardown of every pre-launch page that actually converted names the same lever: a share link
 * that moves the sharer up a queue. Dropbox went 100k to 4m users on a 60% referral rate; Robinhood
 * is widely reported to have passed a million signups on refer-to-skip-the-line alone. It is the
 * difference between a list and a launch, and it was the largest single omission on the first version
 * of this page.
 *
 * referred_by holds the INVITER'S CODE, not the row id and not the address. The row id leaks how many
 * people are on the list and breaks the moment anything is ever deleted; an address would work but says
 * more than a referral needs to say.
 *
 * Not simply "who sent them" for its own sake: because referred_by can only ever be a code that already
 * exists in this same table, attribution is unspoofable. There is no input a visitor can supply that
 * inflates another person's position, because a fake code matches no row.
 *
 * The column is varchar(12), the same width as referral_code, because it holds a CODE. An earlier
 * version of this migration made it hold the inviter's email, which does not fit in twelve characters
 * and fails at insert with "value too long for type character varying(12)".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('launch_signups', function (Blueprint $table): void {
            $table->string('referral_code', 12)->nullable()->unique()->after('email');
            $table->string('referred_by', 12)->nullable()->after('referral_code');
            $table->index('referred_by');
        });

        DB::unprepared('grant select, insert, update, delete on table launch_signups to kudi_app');
    }

    public function down(): void
    {
        Schema::table('launch_signups', function (Blueprint $table): void {
            $table->dropIndex(['referred_by']);
            $table->dropColumn(['referral_code', 'referred_by']);
        });
    }
};
