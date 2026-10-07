<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Providers are no longer asked for a registration number.
 *
 * The column was NOT NULL with no default, so a registration that no longer
 * supplies it would be refused by MySQL. It is made nullable rather than
 * dropped on purpose: existing providers' numbers stay on record (an
 * administrator may have verified against them), nothing is deleted on deploy,
 * and the change reverses cleanly. Nothing reads or writes the column any more;
 * dropping it later is a separate, deliberate step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->string('registration_number', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows registered after the field was retired have no number; NOT NULL
        // needs a value, so an empty string stands in before it is restored.
        DB::table('provider_profiles')->whereNull('registration_number')->update(['registration_number' => '']);

        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->string('registration_number', 100)->nullable(false)->change();
        });
    }
};
