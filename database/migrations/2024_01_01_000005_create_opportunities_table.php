<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->bigIncrements('opportunity_id');
            $table->unsignedBigInteger('provider_user_id')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('education_level', 100)->nullable();
            $table->string('funding_type', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->date('deadline')->nullable();
            $table->string('status', 50)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->string('target_field')->nullable();
            $table->string('target_country')->nullable();

            // Admin review state, deliberately separate from the ACTIVE/closed
            // listing status: only the combination of both makes a post public.
            $table->string('moderation_status', 20)->default('PENDING');
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->string('rejection_reason', 500)->nullable();

            $table->foreign('provider_user_id')->references('user_id')->on('users');

            $table->index('moderation_status', 'idx_opportunity_moderation');
            $table->index(['moderation_status', 'status', 'deadline'], 'idx_opp_mod_status_deadline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
