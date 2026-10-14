<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a provider organisation is registered, and the details an administrator
 * verifies it against - so an organisation outside Zimbabwe can register through
 * the same form and the same review.
 *
 * registration_country: ISO 3166-1 alpha-2 code (App\Support\Countries). Its own
 * column, not the listing's `country`: where an organisation is registered and
 * where the study it funds takes place are different facts. NULL means "not
 * recorded", which is every provider registered before this migration. Nothing is
 * backfilled - assuming Zimbabwe would put an unverified statement on record.
 * An administrator can record it from the provider's review page.
 *
 * registration_number already exists (made nullable and retired in
 * 2025_01_01_000008); it is read and written again, not duplicated. Organisations
 * with no such number explain why in registration_number_note instead.
 *
 * contact_name / contact_role: the person registering and their position, so an
 * administrator can judge whether they may act for the organisation. users.full_name
 * stays the organisation's name, as it is shown on listings.
 *
 * info_requested_*: an administrator asking for more evidence before deciding,
 * and info_provided_at: when the organisation last updated its details in answer.
 * The account stays PENDING throughout; nothing here approves anyone.
 *
 * All nullable and additive, so existing rows are untouched and rollback only
 * drops what this adds.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'registration_country',
        'registration_number_note',
        'contact_name',
        'contact_role',
        'info_requested_at',
        'info_requested_by',
        'info_request_note',
        'info_provided_at',
    ];

    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->char('registration_country', 2)->nullable();
            $table->string('registration_number_note', 500)->nullable();
            $table->string('contact_name', 255)->nullable();
            $table->string('contact_role', 150)->nullable();
            $table->dateTime('info_requested_at')->nullable();
            $table->string('info_requested_by')->nullable();
            $table->string('info_request_note', 1000)->nullable();
            $table->dateTime('info_provided_at')->nullable();

            $table->index('registration_country', 'idx_provider_registration_country');
        });
    }

    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->dropIndex('idx_provider_registration_country');
            $table->dropColumn(self::COLUMNS);
        });
    }
};
