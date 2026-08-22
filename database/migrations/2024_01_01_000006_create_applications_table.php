<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->bigIncrements('application_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('opportunity_id')->nullable();
            $table->string('application_status', 50)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->text('personal_statement')->nullable();
            $table->string('document_filename')->nullable();
            $table->string('document_path')->nullable();
            $table->string('rejection_reason', 500)->nullable();

            $table->unique(['user_id', 'opportunity_id'], 'uk_applications_user_opportunity');
            $table->foreign('user_id')->references('user_id')->on('users');
            $table->foreign('opportunity_id')->references('opportunity_id')->on('opportunities');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
