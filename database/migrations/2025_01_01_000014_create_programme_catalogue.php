<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The programme catalogue (ScholarFit 5.6, replacing the idea of a short list of field groups).
 *
 * Field of study used to be free text, so "Computer Science" and "Computer Science & IT" were
 * different fields and a provider had no way to say "any Engineering programme". Now everyone
 * picks from one list:
 *
 *   fields           ISCED-F 2013 broad fields (parent_id null) and their narrow fields.
 *                    Exists so a scholarship can say "any Engineering programme" without
 *                    ticking forty rows - it is one column on a programme, not something
 *                    anyone has to understand.
 *   programmes       what a person studies: a name, a level, a field. A programme someone typed
 *                    because theirs was not listed is `pending` until an administrator approves
 *                    it or merges it into an existing one.
 *   programme_synonyms  other names for a programme ("BIS", "Info Systems", "Comp Sci").
 *   institutions     universities, polytechnics, colleges.
 *   institution_programme  which institution offers which programme.
 *   opportunity_scopes     what a scholarship is open to. No rows = any. See OpportunityScope.
 *   applicant_programmes   a student's current programme, or up to three they hope to study.
 *
 * Everything is new; the old free-text `field_of_study` and `target_field` columns are kept
 * exactly as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fields', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 10)->unique();
            $table->string('name', 150);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('fields');
        });

        Schema::create('institutions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->string('type', 30);
            $table->string('province', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('programmes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 200);
            $table->string('education_level', 30);
            $table->unsignedBigInteger('field_id');
            $table->boolean('is_active')->default(true);
            $table->string('status', 20)->default('approved');
            $table->unsignedBigInteger('suggested_by')->nullable();
            $table->unsignedBigInteger('merged_into_id')->nullable();
            $table->timestamps();

            $table->unique(['name', 'education_level'], 'uk_programme_name_level');
            $table->index(['status', 'is_active', 'education_level'], 'idx_programme_status_level');
            $table->foreign('field_id')->references('id')->on('fields');
            $table->foreign('suggested_by')->references('user_id')->on('users')->nullOnDelete();
        });

        Schema::create('programme_synonyms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('programme_id');
            $table->string('synonym', 200);

            $table->unique(['programme_id', 'synonym'], 'uk_programme_synonym');
            $table->index('synonym', 'idx_programme_synonym_text');
            $table->foreign('programme_id')->references('id')->on('programmes')->cascadeOnDelete();
        });

        Schema::create('institution_programme', function (Blueprint $table) {
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('programme_id');

            $table->primary(['institution_id', 'programme_id']);
            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign('programme_id')->references('id')->on('programmes')->cascadeOnDelete();
        });

        Schema::create('opportunity_scopes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('opportunity_id');
            // Exactly one of these three is set on a row.
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->unsignedBigInteger('field_id')->nullable();
            $table->unsignedBigInteger('institution_id')->nullable();

            $table->index('opportunity_id', 'idx_scope_opportunity');
            $table->foreign('opportunity_id')->references('opportunity_id')->on('opportunities')->cascadeOnDelete();
            $table->foreign('programme_id')->references('id')->on('programmes')->cascadeOnDelete();
            $table->foreign('field_id')->references('id')->on('fields')->cascadeOnDelete();
            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
        });

        Schema::create('applicant_programmes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('profile_id');
            $table->unsignedBigInteger('programme_id');
            $table->string('kind', 10);
            $table->unsignedBigInteger('institution_id')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'programme_id'], 'uk_applicant_programme');
            $table->foreign('profile_id')->references('profile_id')->on('applicant_profiles')->cascadeOnDelete();
            $table->foreign('programme_id')->references('id')->on('programmes')->cascadeOnDelete();
            $table->foreign('institution_id')->references('id')->on('institutions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_programmes');
        Schema::dropIfExists('opportunity_scopes');
        Schema::dropIfExists('institution_programme');
        Schema::dropIfExists('programme_synonyms');
        Schema::dropIfExists('programmes');
        Schema::dropIfExists('institutions');
        Schema::dropIfExists('fields');
    }
};
