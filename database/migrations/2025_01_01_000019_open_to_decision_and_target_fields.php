<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Open to" becomes a decision the provider must make, and graduates can say what they want to study next.
 *
 *   opportunities.open_to_all_programmes   null  = not decided (a listing posted before this rule)
 *                                          true  = open to every programme at its level, on purpose
 *                                          false = open only to the programmes / fields chosen
 *   opportunities.any_institution          null  = not decided, or study outside Zimbabwe (not asked)
 *                                          true  = any institution, on purpose
 *                                          false = only the institutions chosen
 *   opportunities.accepts_o_level          an Undergraduate award that says it takes O-Level holders
 *   opportunities.host_institutions        for study abroad: where, as the provider wrote it (shown, never checked)
 *   opportunities.other_conditions         conditions ScholarFit cannot check (IELTS, work experience), one a line,
 *                                          shown to the student as "Also check yourself"
 *   applicant_target_fields                up to three fields an enrolled student or graduate wants to study next
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->boolean('open_to_all_programmes')->nullable();
            $table->boolean('any_institution')->nullable();
            $table->boolean('accepts_o_level')->default(false);
            $table->string('host_institutions', 500)->nullable();
            $table->text('other_conditions')->nullable();
        });

        Schema::create('applicant_target_fields', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('profile_id');
            $table->unsignedBigInteger('field_id');
            $table->timestamps();

            $table->unique(['profile_id', 'field_id'], 'uk_applicant_target_field');
            $table->foreign('profile_id')->references('profile_id')->on('applicant_profiles')->cascadeOnDelete();
            $table->foreign('field_id')->references('id')->on('fields')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_target_fields');

        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn(['open_to_all_programmes', 'any_institution', 'accepts_o_level', 'host_institutions', 'other_conditions']);
        });
    }
};
