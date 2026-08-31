<?php

use App\Support\ApplicationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collapses the multi-stage application lifecycle onto PENDING / ACCEPTED /
 * REJECTED, and renames the reason column to match what it now holds.
 *
 * Nothing is dropped. The columns the old lifecycle used - interview_at, the
 * info-request pair, interview_reminded_at, awarded_at - stay exactly where they
 * are as nullable legacy columns, so historical rows keep every fact they
 * recorded and no data is destroyed. They are simply no longer written or read
 * by the application.
 *
 * Three changes:
 *
 *   1. rejection_reason -> decision_reason. The column always held the provider's
 *      written reason; now that both decisions require one, "rejection" was the
 *      wrong half of the story. A rename keeps the data.
 *
 *   2. decided_at, so the decision has its own timestamp. Backfilled from
 *      awarded_at where a legacy award recorded one.
 *
 *   3. The status values themselves are rewritten onto the three live states.
 *      Everything that meant "still being looked at" becomes PENDING; APPROVED
 *      and AWARDED - the two words the old system had for one fact - both become
 *      ACCEPTED.
 *
 * down() restores the schema but cannot restore the old status vocabulary: seven
 * distinct values map onto PENDING, so the collapse is one-way. It puts the two
 * intake-ish states back to SUBMITTED and ACCEPTED back to APPROVED, which is
 * the closest honest reversal, rather than pretending to recover a distinction
 * the data no longer carries.
 */
return new class extends Migration
{
    /** Legacy status => the live status it becomes. */
    private const FORWARD = [
        'SUBMITTED' => ApplicationStatus::PENDING,
        'UNDER_REVIEW' => ApplicationStatus::PENDING,
        'DOCUMENTS_REQUESTED' => ApplicationStatus::PENDING,
        'INFO_REQUESTED' => ApplicationStatus::PENDING,
        'SHORTLISTED' => ApplicationStatus::PENDING,
        'INTERVIEW' => ApplicationStatus::PENDING,
        'WAITLISTED' => ApplicationStatus::PENDING,
        'APPROVED' => ApplicationStatus::ACCEPTED,
        'AWARDED' => ApplicationStatus::ACCEPTED,
    ];

    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->renameColumn('rejection_reason', 'decision_reason');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dateTime('decided_at')->nullable()->after('decision_reason');
        });

        foreach (self::FORWARD as $legacy => $live) {
            DB::table('applications')
                ->where('application_status', $legacy)
                ->update(['application_status' => $live]);
        }

        // A row with no status at all is pending by definition; saying so in the
        // column keeps the status filters and the index useful for it.
        DB::table('applications')
            ->whereNull('application_status')
            ->update(['application_status' => ApplicationStatus::PENDING]);

        // The moment a legacy award was granted is the moment that application
        // was decided, so the new column inherits it rather than starting blank
        // on every historical acceptance.
        if (Schema::hasColumn('applications', 'awarded_at')) {
            DB::table('applications')
                ->whereNotNull('awarded_at')
                ->update(['decided_at' => DB::raw('awarded_at')]);
        }
    }

    public function down(): void
    {
        DB::table('applications')
            ->where('application_status', ApplicationStatus::ACCEPTED)
            ->update(['application_status' => 'APPROVED']);

        DB::table('applications')
            ->where('application_status', ApplicationStatus::PENDING)
            ->update(['application_status' => 'SUBMITTED']);

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('decided_at');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->renameColumn('decision_reason', 'rejection_reason');
        });
    }
};
