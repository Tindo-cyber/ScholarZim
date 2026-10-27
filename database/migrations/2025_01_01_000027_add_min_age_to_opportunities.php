<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The youngest an applicant can be, beside the oldest (max_age).
 *
 * Nullable, like max_age, and not back-filled: every existing listing means "no floor" today and keeps meaning that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_age')->nullable()->after('max_age');
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn('min_age');
        });
    }
};
