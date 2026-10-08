<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What matching against the programme catalogue needs on top of the catalogue itself. All additive.
 *
 *   fields.display_name           the short name people see ("Engineering"); the official ISCED-F name
 *                                 and code stay as they are.
 *   opportunity_scopes.source     who made the row. Null = the provider; 'legacy_field' = made by
 *                                 `catalogue:migrate-fields` from the old free-text field, so it can be
 *                                 undone without touching anything a provider chose.
 *   applicant_institutions        the institutions a school-leaver has applied to or has an offer from
 *                                 (up to three), so a listing limited to institutions can be checked.
 *   legacy_field_aliases          "an old free-text field of study means this catalogue field", made by an
 *                                 administrator for a value the built-in map did not know. One alias
 *                                 settles every profile and listing that wrote it that way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fields', function (Blueprint $table) {
            $table->string('display_name', 100)->nullable();
        });

        Schema::table('opportunity_scopes', function (Blueprint $table) {
            $table->string('source', 30)->nullable();
        });

        Schema::create('applicant_institutions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('profile_id');
            $table->unsignedBigInteger('institution_id');
            $table->timestamps();

            $table->unique(['profile_id', 'institution_id'], 'uk_applicant_institution');
            $table->foreign('profile_id')->references('profile_id')->on('applicant_profiles')->cascadeOnDelete();
            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
        });

        Schema::create('legacy_field_aliases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('value_key', 200)->unique();
            $table->string('original_value', 255);
            $table->unsignedBigInteger('field_id');
            $table->timestamps();

            $table->foreign('field_id')->references('id')->on('fields')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_field_aliases');
        Schema::dropIfExists('applicant_institutions');

        Schema::table('opportunity_scopes', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('fields', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });
    }
};
