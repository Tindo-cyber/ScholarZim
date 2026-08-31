<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splits location into the four things it actually is: country, province,
 * district, and whether the applicant is rural or urban.
 *
 * ScholarFit v1 had no column for the last two and worked around it by testing
 * `province === 'Rural'`. "Rural" is not one of Zimbabwe's ten provinces and is
 * not offered by the province dropdown, so that branch could never fire for a
 * profile filled in through the site - a rural-weighting rule that looked
 * present in the code and did nothing in practice.
 *
 * Rural/urban matters here beyond tidiness: a large share of Zimbabwean
 * scholarship funding is explicitly aimed at rural students, and a listing that
 * targets them had no way to say so. Both columns are nullable, so a profile
 * that has not stated a locality is treated as unknown rather than as urban.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->string('district', 100)->nullable()->after('province');
            $table->string('locality', 10)->nullable()->after('district');
        });

        Schema::table('opportunities', function (Blueprint $table) {
            $table->string('target_district', 100)->nullable()->after('required_province');
            $table->string('target_locality', 10)->nullable()->after('target_district');
        });
    }

    public function down(): void
    {
        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->dropColumn(['district', 'locality']);
        });

        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn(['target_district', 'target_locality']);
        });
    }
};
