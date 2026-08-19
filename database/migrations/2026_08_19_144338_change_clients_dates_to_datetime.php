<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * clients.start_date/end_date were plain DATE columns (no time component).
 * The agent now wants a subscription to expire at the exact time it was
 * created/renewed (e.g. created 14:30 -> expires 14:30 thirty days later),
 * not just "sometime on that day" — so both columns need to store time too.
 *
 * Uses raw SQL instead of Schema::table(...)->change() because that method
 * requires doctrine/dbal, which this project doesn't otherwise need.
 *
 * NOTE: `clients` is a table isp-panel (the legacy app sharing this same
 * database) originally created and still reads/writes — this migration
 * only widens the column type (DATE -> DATETIME), which does not break
 * isp-panel's existing date-only reads/writes (MySQL still returns/accepts
 * plain dates fine against a DATETIME column, just at midnight).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE clients MODIFY start_date DATETIME NOT NULL, MODIFY end_date DATETIME NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE clients MODIFY start_date DATE NOT NULL, MODIFY end_date DATE NOT NULL');
    }
};
