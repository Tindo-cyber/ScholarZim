<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two facts the profile did not hold: the applicant's gender, and their degree
 * classification.
 *
 * Unlike the academic model tables, `applicant_profiles` is live in
 * production, so these arrive as their own migration rather than by editing
 * an existing one.
 *
 * gender
 *     Captured on the profile and nothing more. It is deliberately not read by
 *     ScholarFit, not part of any eligibility rule, and not part of profile
 *     completeness: no scholarship in this product states a gender rule, and
 *     inventing one here would be inventing policy. Nullable, because every
 *     existing profile has no answer and none of them is incomplete for it.
 *
 * degree_classification
 *     A degree class is one fact about a person, not a score per module, so it
 *     lives here rather than as rows in academic_results. That also keeps it
 *     structurally impossible for a First Class to be added into a ZIMSEC
 *     A-Level points total, which is what happened when tertiary shared the
 *     subject-and-points table with school qualifications. Validated against
 *     the `tertiary` qualification's own grading scheme in the catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->string('gender', 20)->nullable()->after('date_of_birth');
            $table->string('degree_classification', 50)->nullable()->after('year_of_study');
        });
    }

    public function down(): void
    {
        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->dropColumn(['gender', 'degree_classification']);
        });
    }
};
