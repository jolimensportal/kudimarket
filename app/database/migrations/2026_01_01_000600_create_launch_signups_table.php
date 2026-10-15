<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses from the landing page, for the day we open.
 *
 * A single table with one column, on purpose. A waitlist is not a product, and the moment it grows
 * tables it has become something that needs a privacy policy, a retention policy and an unsubscribe.
 * Until then: the address, when it was given, and where it came from.
 *
 * email is unique because the form must be safe to submit twice. Somebody on a bad connection will
 * press the button again, and the second press must be a no-op rather than a second row and a second
 * "you are on the list" email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launch_signups', function ($table): void {
            $table->id();
            $table->string('email')->unique();
            $table->timestamp('created_at')->nullable();
        });

        // The grant, here, rather than added to 000400_grant_application_tables -- because that
        // migration ran and finished before this table existed, and it deliberately grants only what it
        // can see. This is the same arrangement 000500 uses for pricing_staff.
        //
        // Without it the landing page returns "permission denied for table launch_signups" on every
        // submission, which is the one screen whose entire job is to accept an address from a stranger.
        DB::unprepared('grant select, insert, update, delete on table launch_signups to kudi_app');
        DB::unprepared('grant usage, select on sequence launch_signups_id_seq to kudi_app');
    }

    public function down(): void
    {
        DB::unprepared('revoke all on table launch_signups from kudi_app');

        Schema::dropIfExists('launch_signups');
    }
};
