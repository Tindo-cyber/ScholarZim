<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the applicant last said "yes, this is still my level" (ScholarFit 5.9).
 *
 * A level is a claim about a moment: a Form 4 pupil is a Form 5 pupil a year later and
 * nothing prompts them to say so, while ScholarFit keeps judging them on the old answer.
 * Null (every existing row) means "never asked", and the first prompt counts from when
 * the profile was created, so nobody is asked on the day this ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->dateTime('education_level_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->dropColumn('education_level_confirmed_at');
        });
    }
};
