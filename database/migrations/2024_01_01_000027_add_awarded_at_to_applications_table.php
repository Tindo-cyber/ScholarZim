<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the scholarship was actually granted, as distinct from when the provider
 * decided to grant it.
 *
 * APPROVED already records the decision and its written reason; awarded_at
 * records the moment the award itself was made, which is the date a student is
 * asked for by a bank, a registrar, or the provider's own reporting. Nullable
 * because it is meaningless on every application that has not reached AWARDED,
 * and left alone once written - re-awarding is refused by the state machine, so
 * a second timestamp could only ever be wrong.
 *
 * No index: awarded_at is displayed, never filtered or sorted on. The provider's
 * awarded list is `opportunity_id in (...) and application_status = 'AWARDED'
 * order by submitted_at`, which idx_applications_opp_status already serves.
 * Indexing a column nothing queries costs writes and buys nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dateTime('awarded_at')->nullable()->after('interview_reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('awarded_at');
        });
    }
};
