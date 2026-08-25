<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A named set of search filters an applicant asked to be alerted about.
 *
 * last_alerted_opportunity_id is the high-water mark the alert job reads: only
 * listings approved after it can produce an alert, which is what keeps the job
 * idempotent across daily runs without storing one row per (search, listing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->bigIncrements('saved_search_id');
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->json('filters');
            $table->boolean('alerts_enabled')->default(true);
            $table->unsignedBigInteger('last_alerted_opportunity_id')->default(0);
            $table->dateTime('last_alerted_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'alerts_enabled'], 'idx_saved_search_user_alerts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
    }
};
