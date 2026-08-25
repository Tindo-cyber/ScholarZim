<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per listing per day, so the provider funnel can show a trend rather
 * than a single lifetime counter. opportunities.view_count keeps the running
 * total for the cheap case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_views', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('opportunity_id');
            $table->date('viewed_on');
            $table->unsignedInteger('views')->default(0);

            $table->unique(['opportunity_id', 'viewed_on'], 'uk_opportunity_views_day');
            $table->foreign('opportunity_id')->references('opportunity_id')->on('opportunities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_views');
    }
};
