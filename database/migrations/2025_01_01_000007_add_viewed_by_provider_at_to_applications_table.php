<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The moment a provider first opens a pending application, as distinct from
 * the moment they decide it (decided_at). Nothing about application_status
 * changes because of this - PENDING stays PENDING, see
 * App\Support\ApplicationStateMachine and App\Support\ApplicationStatus's
 * LEGACY_UNDER_REVIEW note. This is purely "has a human looked at this yet",
 * surfaced as the existing "Under review" timeline step actually lighting
 * up instead of sitting permanently undone, and as a one-time notification
 * to the applicant - see App\Services\ApplicationService::markViewedByProvider().
 *
 * Nullable and written once: left alone on an already-viewed application,
 * the same reasoning awarded_at (the migration just before this one) already
 * uses for its own one-way timestamp.
 *
 * No index: read on the single-application detail pages only, never filtered
 * or sorted on in a list query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dateTime('viewed_by_provider_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('viewed_by_provider_at');
        });
    }
};
