<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trust tiers and student reports (Phase 3). All additive, nothing rewritten.
 *
 * provider_profiles.trusted_override: an administrator's say-so about a provider,
 * overriding the computed trust level. true = always trusted, false = never
 * trusted, null (the default, and every existing row) = let ProviderTrust decide
 * from the provider's record.
 *
 * opportunities.auto_approved / post_reviewed_*: a listing from a trusted
 * provider goes live without waiting for a review, and is marked so it lands in
 * the "published without review" queue. post_reviewed_at records when an
 * administrator looked at it afterwards; until then it is in the queue. Existing
 * rows are all false/null: every listing so far was reviewed before it went live.
 *
 * opportunity_reports: one row per applicant per listing they have reported, with
 * the reason they gave and what an administrator decided. The unique key is what
 * makes "three distinct users" mean three people rather than one person
 * pressing the button three times. Rows are never deleted when a listing is
 * withdrawn: they are the evidence behind a provider losing trust.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->boolean('trusted_override')->nullable();
        });

        Schema::table('opportunities', function (Blueprint $table) {
            $table->boolean('auto_approved')->default(false);
            $table->dateTime('post_reviewed_at')->nullable();
            $table->string('post_reviewed_by')->nullable();
        });

        Schema::create('opportunity_reports', function (Blueprint $table) {
            $table->bigIncrements('report_id');
            $table->unsignedBigInteger('opportunity_id');
            $table->unsignedBigInteger('user_id');
            $table->string('reason', 40);
            $table->string('details', 1000)->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->string('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('created_at');

            $table->unique(['opportunity_id', 'user_id'], 'uk_report_opp_user');
            $table->index(['status', 'opportunity_id'], 'idx_report_status_opp');
            $table->foreign('opportunity_id')->references('opportunity_id')->on('opportunities');
            $table->foreign('user_id')->references('user_id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_reports');

        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn(['auto_approved', 'post_reviewed_at', 'post_reviewed_by']);
        });

        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->dropColumn('trusted_override');
        });
    }
};
