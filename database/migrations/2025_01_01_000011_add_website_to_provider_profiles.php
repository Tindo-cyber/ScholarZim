<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's website, and an administrator's confirmation of it.
 *
 * The risk checker judges a listing's application link against the provider's
 * identity. It only had their email domain to go on, which says nothing for an
 * organisation registered with a free-mail address. A website the administrator
 * has confirmed as the organisation's own is better evidence; one that has only
 * been typed in is not, so confirmation is recorded separately and the checker
 * reads it only when present.
 *
 * All nullable and additive; every existing provider simply has no website.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->string('website', 255)->nullable();
            $table->dateTime('website_verified_at')->nullable();
            $table->string('website_verified_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->dropColumn(['website', 'website_verified_at', 'website_verified_by']);
        });
    }
};
