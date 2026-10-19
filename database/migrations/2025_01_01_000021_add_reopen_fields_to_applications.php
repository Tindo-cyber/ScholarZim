<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider can withdraw an accepted or rejected decision and reopen the application for a fresh one
 * (ApplicationService::reopen()). The application goes back to PENDING - not to WITHDRAWN, which is the applicant
 * stepping back and has its own re-apply rules - and its current decision (decision_reason, decided_at) is cleared.
 *
 * The decision that was withdrawn is not lost: the audit log keeps the original decision (who, when, the reason) and
 * the reopening (who, when, why) as entries of their own. These three columns are only what the screens need while
 * the application is waiting for its new decision - "your earlier acceptance was withdrawn on 12 Oct: <reason>" - and
 * are cleared again when the provider decides, or the applicant re-applies after withdrawing.
 *
 * All nullable and additive: every existing application simply has never been reopened. No index - they are read on
 * the single-application pages only, never filtered or sorted on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dateTime('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            // The decision that was withdrawn: ACCEPTED or REJECTED.
            $table->string('reopened_from', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['reopened_at', 'reopen_reason', 'reopened_from']);
        });
    }
};
