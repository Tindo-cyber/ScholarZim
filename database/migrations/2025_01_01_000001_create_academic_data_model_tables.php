<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The structured academic data model.
 *
 * Four tables let an applicant record their results subject by subject, let a
 * scholarship require particular subjects at particular grades, and let
 * ScholarFit read facts instead of parsing prose. The free-text
 * `applicant_profiles.academic_results` column this replaces stored
 * "14 points at A-Level" in whatever words a student chose, and the engine
 * read a number back out of it with a regular expression.
 *
 * Two things are deliberately absent, having been present in the first draft
 * of this migration and found to be wrong before it ever shipped:
 *
 *   is_manual_override / a writable points column
 *       An applicant states facts - qualification, subject, grade, year. The
 *       platform derives the points. A column an applicant can write a point
 *       total into is the same problem as the free text it replaced.
 *
 *   is_primary
 *       It meant "a headline row", never Primary education, and was read by
 *       nothing. With Zimbabwe Primary now a qualification in its own right,
 *       a boolean of that name on these tables could only mislead. Primary is
 *       education_level = PRIMARY, and nothing else.
 *
 * Referenced catalogue rows cannot be hard-deleted: academic_results and
 * opportunity_subject_requirements restrict deletion of the qualification and
 * subject they point at, so a subject that a student's record or a
 * scholarship's rule depends on is retired with is_active = false rather than
 * removed underneath them.
 *
 * The catalogue itself is seeded by the data migration that follows this one,
 * so a fresh production database is complete after `migrate` alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Qualification frameworks: Zimbabwe Primary, ZIMSEC O/A-Level, the
        // four Cambridge awards, and tertiary degree classification. Each row
        // owns its grading_scheme, which carries the ordered grade symbols
        // (for comparison) and, only where the qualification genuinely has
        // one, the points per grade. See App\Support\Academic\GradingScheme.
        Schema::create('academic_qualifications', function (Blueprint $table) {
            $table->id();
            $table->string('system', 50);
            $table->string('qualification_key', 100)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('grading_scheme')->nullable();
            $table->string('education_level', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('ordering')->default(0);
            $table->timestamps();

            $table->index(['system', 'education_level']);
            $table->index('is_active');
        });

        // Subjects belong to one qualification. "Mathematics" under ZIMSEC
        // A-Level and "Mathematics" under Cambridge IGCSE are different rows
        // sat under different boards, so the unique key is scoped to the
        // qualification rather than global.
        Schema::create('academic_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qualification_id')->constrained('academic_qualifications')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            // A subject may grade differently from the rest of its
            // qualification. Cambridge IGCSE is the case that requires this:
            // it awards A*-G on most syllabuses and 9-1 on others, and those
            // are genuinely different syllabus numbers rather than one scale
            // written two ways. Null means "grade as the qualification does",
            // which is every subject except the 9-1 IGCSE ones.
            //
            // The two scales are never merged into one ordered list. Doing so
            // would make rank meaningless - there is no position at which a 7
            // sits among A*..G - and would amount to inventing an equivalence
            // between them that Cambridge does not publish.
            $table->json('grading_scheme')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('ordering')->default(0);
            $table->timestamps();

            $table->unique(['qualification_id', 'name']);
            $table->index(['qualification_id', 'is_active']);
        });

        // One row per subject the applicant sat. `result` is the grade symbol
        // exactly as the board awards it (A*, a, 4, First Class);
        // `derived_points` is computed by the platform from the
        // qualification's own scheme and is null for every qualification that
        // does not award points - which is all of them except ZIMSEC A-Level.
        Schema::create('academic_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('applicant_profiles', 'profile_id')->cascadeOnDelete();
            $table->foreignId('qualification_id')->constrained('academic_qualifications')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('academic_subjects')->restrictOnDelete();
            $table->string('result', 20);
            // Deliberately not the YEAR column type: MySQL's YEAR cannot
            // represent anything before 1901, which is a trap rather than a
            // constraint anyone wanted.
            $table->unsignedSmallInteger('year')->nullable();
            $table->decimal('derived_points', 5, 2)->nullable();
            $table->timestamps();

            // One result per subject per qualification per applicant. A re-sit
            // replaces the grade rather than adding a second row: the profile
            // states what the applicant holds now, and two rows for the same
            // subject would both be counted by any points total. Recording a
            // full sitting history would mean adding `year` to this key, and
            // teaching every consumer which row to read - a deliberate change,
            // not something to fall into by leaving the key off.
            $table->unique(['profile_id', 'qualification_id', 'subject_id'], 'academic_results_unique');
            $table->index('qualification_id');
        });

        // A scholarship's subject rules. `minimum_grade` is compared under the
        // requirement's own qualification scheme. There is no per-subject
        // points column: total points are a scholarship-level rule
        // (opportunities.min_academic_points) and grades are the subject-level
        // one, and the product has no third thing for a per-subject points
        // floor to mean.
        Schema::create('opportunity_subject_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities', 'opportunity_id')->cascadeOnDelete();
            $table->foreignId('qualification_id')->constrained('academic_qualifications')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('academic_subjects')->restrictOnDelete();
            $table->string('minimum_grade', 20)->nullable();
            $table->timestamps();

            $table->unique(['opportunity_id', 'subject_id'], 'opp_req_unique');
            $table->index('qualification_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_subject_requirements');
        Schema::dropIfExists('academic_results');
        Schema::dropIfExists('academic_subjects');
        Schema::dropIfExists('academic_qualifications');
    }
};
