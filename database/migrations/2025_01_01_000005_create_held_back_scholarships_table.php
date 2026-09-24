<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scholarship an applicant has deliberately set aside - distinct from
 * saved_scholarships (a watchlist a listing stays on regardless) and from
 * ScholarFit's own "things holding this score back" explanation, which is
 * never persisted at all. Same shape as saved_scholarships on purpose: one
 * row per (applicant, listing), nothing else needed to answer "is this
 * held back" or "what has this applicant held back".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_back_scholarships', function (Blueprint $table) {
            $table->bigIncrements('held_back_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('opportunity_id');
            $table->dateTime('held_back_at')->nullable();

            $table->unique(['user_id', 'opportunity_id'], 'uk_held_back_user_opp');
            $table->foreign('user_id')->references('user_id')->on('users');
            $table->foreign('opportunity_id')->references('opportunity_id')->on('opportunities');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_back_scholarships');
    }
};
