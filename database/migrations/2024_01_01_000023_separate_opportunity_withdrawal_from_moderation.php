<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moves withdrawal from the moderation column to the publication column.
 *
 * Withdrawing is a provider taking their own listing down; approving and
 * declining are an administrator's verdicts. Keeping both in moderation_status
 * meant withdrawing an approved listing overwrote the approval, so afterwards
 * the platform could not say whether that listing had ever passed review.
 *
 * The verdict is recovered from the columns that recorded it at the time rather
 * than guessed: reviewed_at is only ever set by approve() or reject(), and
 * rejection_reason only by reject(). So a withdrawn row that was reviewed and
 * carries no reason was approved, one with a reason was declined, and one never
 * reviewed was still pending. down() re-flattens it, losing the same
 * information again, which is the price of going back.
 *
 * Status values are written as literals rather than through the Support classes
 * on purpose. A migration describes the data as it stood when it ran, and
 * 'WITHDRAWN' has since been removed from OpportunityModerationStatus - pointing
 * at the constant would make this file stop running the moment the vocabulary it
 * migrates away from is cleaned up.
 */
return new class extends Migration
{
    public function up(): void
    {
        $withdrawn = DB::table('opportunities')
            ->where('moderation_status', 'WITHDRAWN')
            ->get(['opportunity_id', 'reviewed_at', 'rejection_reason']);

        foreach ($withdrawn as $row) {
            $verdict = match (true) {
                $row->reviewed_at === null => 'PENDING',
                filled($row->rejection_reason) => 'REJECTED',
                default => 'APPROVED',
            };

            DB::table('opportunities')
                ->where('opportunity_id', $row->opportunity_id)
                ->update([
                    'status' => 'WITHDRAWN',
                    'moderation_status' => $verdict,
                ]);
        }
    }

    public function down(): void
    {
        DB::table('opportunities')
            ->where('status', 'WITHDRAWN')
            ->update([
                'status' => 'ACTIVE',
                'moderation_status' => 'WITHDRAWN',
            ]);
    }
};
