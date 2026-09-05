<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three changes, none of them destructive.
 *
 * 1. LOCALITY IS BEING RENAMED, NOT REMOVED - AND SO IS ITS OLD NAME.
 *
 *    Two columns collided under one concept. `applicant_profiles.district`
 *    held a specific place name ("Gweru", "Chitungwiza") - exactly what the
 *    platform's education-domain redesign calls "Locality". But the column
 *    already named `locality` held something else entirely: Rural or Urban,
 *    a settlement *type*, not a place. Renaming `district` straight to
 *    `locality` would collide with that existing column, and simply dropping
 *    the rural/urban column to make room would destroy a working, tested
 *    targeting axis (`Locality` taxonomy, `LocationMatcher::localityTier()`)
 *    that a number of Zimbabwean rural-targeted scholarships genuinely rely
 *    on and that nothing in this change was asked to remove.
 *
 *    So: the rural/urban column moves to `settlement_type` first (same data,
 *    new name, still RURAL/URBAN), which frees `locality` for its new,
 *    correct meaning - a rename of `district`'s data and role, not a new
 *    column. The mirrored `target_locality`/`target_district` pair on
 *    `opportunities` gets the same treatment. No data is lost in either
 *    direction; every row keeps exactly the value it had, under the name that
 *    now actually describes it.
 *
 * 2. `country` AND `target_country` ARE KEPT, NOT DROPPED.
 *
 *    ScholarZim is Zimbabwe-only, so a per-profile country selector asks
 *    every applicant to answer a question with one possible answer. Removed
 *    from the profile form and no longer read by LocationMatcher (Zimbabwe is
 *    now assumed rather than compared) - but the columns themselves are left
 *    in place. They cost nothing sitting unused, and dropping them would be a
 *    destructive change with no behavioural upside; if this system is ever
 *    reused outside Zimbabwe, the column is still there to resume meaning
 *    something.
 *
 * 3. NEW COLUMNS FOR THE PARTS OF THE DOMAIN THAT GENUINELY DID NOT EXIST YET.
 *
 *    - `applicant_profiles`: guardian details (Primary applicants apply
 *      through a guardian-assisted pathway, not alone), `year_of_study`
 *      (relevant only to tertiary/postgraduate applicants), and a `transcript`
 *      document slot alongside the existing four - a Masters applicant does
 *      not have a school "results certificate", they have a transcript, and
 *      asking for the wrong one is worse than asking for neither.
 *    - `opportunities.minimum_education_level`: the level a specific
 *      scholarship actually requires, separate from `education_level` (the
 *      level it *targets*). An O-Level applicant reaching an
 *      Undergraduate-targeted scholarship is a *possible* pathway in general;
 *      whether this particular scholarship accepts O-Level applicants or
 *      requires A-Level first is this column, checked per listing rather than
 *      assumed from the target level alone.
 *
 * Nothing here rewrites an existing `education_level` value. Historical rows
 * keep whatever string they have; `App\Support\EducationLevel::canonical()`
 * is where every current and future consumer maps them, at read time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->renameColumn('locality', 'settlement_type');
        });

        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->renameColumn('district', 'locality');
        });

        Schema::table('opportunities', function (Blueprint $table) {
            $table->renameColumn('target_locality', 'target_settlement_type');
        });

        Schema::table('opportunities', function (Blueprint $table) {
            $table->renameColumn('target_district', 'target_locality');
        });

        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->string('guardian_name')->nullable()->after('citizenship');
            $table->string('guardian_phone', 50)->nullable()->after('guardian_name');
            $table->string('guardian_relationship', 100)->nullable()->after('guardian_phone');
            $table->dateTime('guardian_confirmed_at')->nullable()->after('guardian_relationship');

            $table->unsignedTinyInteger('year_of_study')->nullable()->after('field_of_study');

            $table->string('transcript_path')->nullable()->after('recommendation_letter_uploaded_at');
            $table->string('transcript_filename')->nullable()->after('transcript_path');
            $table->dateTime('transcript_uploaded_at')->nullable()->after('transcript_filename');
        });

        Schema::table('opportunities', function (Blueprint $table) {
            // Nullable, and read defensively (see EligibilityEvaluator): a
            // listing that has never set this is not newly broken by its
            // absence, it simply carries no extra requirement beyond the
            // general pathway check.
            $table->string('minimum_education_level', 50)->nullable()->after('education_level');
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn('minimum_education_level');
        });

        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'guardian_name',
                'guardian_phone',
                'guardian_relationship',
                'guardian_confirmed_at',
                'year_of_study',
                'transcript_path',
                'transcript_filename',
                'transcript_uploaded_at',
            ]);
        });

        Schema::table('opportunities', function (Blueprint $table) {
            $table->renameColumn('target_locality', 'target_district');
        });

        Schema::table('opportunities', function (Blueprint $table) {
            $table->renameColumn('target_settlement_type', 'target_locality');
        });

        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->renameColumn('locality', 'district');
        });

        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->renameColumn('settlement_type', 'locality');
        });
    }
};
