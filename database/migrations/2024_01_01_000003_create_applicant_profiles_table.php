<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicant_profiles', function (Blueprint $table) {
            $table->bigIncrements('profile_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('education_level', 100)->nullable();
            $table->string('institution_name')->nullable();
            $table->string('field_of_study')->nullable();
            $table->string('country', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('academic_results', 500)->nullable();
            $table->text('biography')->nullable();

            $table->string('results_certificate_path')->nullable();
            $table->string('results_certificate_filename')->nullable();
            $table->dateTime('results_uploaded_at')->nullable();

            $table->string('cv_path')->nullable();
            $table->string('cv_filename')->nullable();
            $table->dateTime('cv_uploaded_at')->nullable();

            $table->string('passport_path')->nullable();
            $table->string('passport_filename')->nullable();
            $table->dateTime('passport_uploaded_at')->nullable();

            $table->string('recommendation_letter_path')->nullable();
            $table->string('recommendation_letter_filename')->nullable();
            $table->dateTime('recommendation_letter_uploaded_at')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_profiles');
    }
};
