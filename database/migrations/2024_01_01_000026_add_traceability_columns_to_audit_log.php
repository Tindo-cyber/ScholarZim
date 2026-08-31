<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the audit trail from a sentence into a record.
 *
 * Every entry currently says who (by email), what, and to which row, with the
 * rest of the story compressed into a free-text `details` string. That is enough
 * to read and almost useless to answer a question with: "who changed this
 * applicant's status, from what, and where were they connecting from?" needs the
 * before and after values, and the request they arrived on.
 *
 * `actor_user_id` sits alongside `actor_email` rather than replacing it, and is
 * deliberately nullable with no foreign key. The email is the durable identity -
 * it survives the account being deleted, which is the whole reason the trail has
 * no FK to users (see the account-deletion behaviour). The id is the convenient
 * one for joining while the account still exists.
 *
 * old_values / new_values are JSON so a change of any shape fits, and are
 * scrubbed of secrets before they are written - AuditService owns that, because
 * a redaction rule that lives at the call site is a redaction rule somebody
 * forgets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_user_id')->nullable()->after('actor_email');

            // What changed, as structured data rather than prose.
            $table->json('old_values')->nullable()->after('details');
            $table->json('new_values')->nullable()->after('old_values');

            // The stated justification, where the action demands one - a
            // moderation refusal, a provider's withdrawal reason.
            $table->string('reason', 500)->nullable()->after('new_values');

            // Where the request came from. IPv6 needs 45 characters.
            $table->string('ip_address', 45)->nullable()->after('reason');
            $table->string('user_agent', 255)->nullable()->after('ip_address');

            // Ties this entry to the log lines and queued jobs from the same
            // request, which is what makes a single action traceable end to end.
            $table->string('request_id', 64)->nullable()->after('user_agent');

            $table->index('actor_user_id', 'idx_audit_actor_user');
            $table->index('request_id', 'idx_audit_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropIndex('idx_audit_actor_user');
            $table->dropIndex('idx_audit_request_id');
            $table->dropColumn([
                'actor_user_id',
                'old_values',
                'new_values',
                'reason',
                'ip_address',
                'user_agent',
                'request_id',
            ]);
        });
    }
};
