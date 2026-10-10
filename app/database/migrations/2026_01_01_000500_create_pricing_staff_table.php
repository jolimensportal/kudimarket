<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Who may set our price.
 *
 * A SEPARATE TABLE, not a column on users. The same reasoning the operations console uses: an
 * operations account exists because somebody put a row in `users`, so the row IS the grant rather than a
 * flag somebody can flip. A `is_pricing` boolean would mean one table could express two desks, and the
 * row that means "this person may reprice the book" would sit in the same table as the row that means
 * "this person may judge a dispute".
 *
 * kudi-core/006 names the reason in full: "If one account could do both, a compromised desk account
 * could reprice the book and then judge its own trades. Segregation of duties, enforced in the database
 * rather than in a policy document." One row in each is two people, and the tool says so rather than
 * quietly preferring one desk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_staff', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('name');
            $table->timestamps();
        });

        // The table and its sequence, granted HERE rather than by 000400, because 000400 runs before this
        // table exists
        // and grants sequences from the tables it can see. A table grant without its sequence grants
        // produces "permission denied for sequence pricing_staff_id_seq" the first time anybody inserts a
        // row -- which is to say, the first time anybody is given the pricing desk. The dev database did
        // not catch it because the table was created there by hand before this migration existed.
        DB::unprepared('grant usage, select on sequence pricing_staff_id_seq to kudi_app');
        DB::unprepared('grant select, insert, update, delete on pricing_staff to kudi_app');
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_staff');
    }
};
