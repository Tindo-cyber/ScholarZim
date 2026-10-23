<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Institutions a provider names that are not in the institution catalogue - most often universities abroad.
 *
 * The catalogue holds Zimbabwean institutions only and is curated by administrators, so a provider's typed name must not
 * become a catalogue row (unverified input would become a global record) and cannot be an `opportunity_scopes` row (those
 * point at catalogue ids). It is kept on the listing instead, as a list of {name, country}: one nullable column, so every
 * existing listing and relationship is untouched and a listing without it reads exactly as before.
 *
 * The older free-text `host_institutions` column stays where it is; it is read for listings that have it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->json('custom_institutions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn('custom_institutions');
        });
    }
};
