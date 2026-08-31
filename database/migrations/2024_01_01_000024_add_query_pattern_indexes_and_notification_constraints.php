<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the queries this application actually runs, and two NOT NULLs.
 *
 * Every index below was chosen from a query that exists in the codebase and was
 * observed running, not from a column looking important. Each one is a composite
 * matching a real filter-plus-sort pair, because the sort is the half that keeps
 * getting missed: `where user_id = ? order by created_at desc limit 20` gains
 * nothing from an index on user_id alone once a user has a few hundred rows -
 * the database still reads them all to find the newest twenty.
 *
 * Deliberately NOT added: an index on audit_log.actor_email beyond the one that
 * exists (the filter is a leading-wildcard LIKE, which no B-tree can serve), and
 * anything on the low-cardinality boolean columns, where a scan is cheaper than
 * the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // The notification list and the bell dropdown both read
            // `user_id = ? order by created_at desc`. The existing
            // (user_id, is_read) index answers the unread count and nothing else.
            $table->index(['user_id', 'created_at'], 'idx_notifications_user_created');

            // Category filtering became a `type in (...)` in the query rather
            // than a PHP filter after pagination, so type is now a selective
            // column on the hot path for the first time.
            $table->index(['user_id', 'type'], 'idx_notifications_user_type');
        });

        Schema::table('applications', function (Blueprint $table) {
            // "My applications", the applicant dashboard, and the provider inbox
            // all sort by submitted_at within a single owner.
            $table->index(['user_id', 'submitted_at'], 'idx_applications_user_submitted');

            // The provider inbox filters by status within their own listings,
            // and the reminder sweeps scan `opportunity_id + status in (...)`.
            $table->index(['opportunity_id', 'application_status'], 'idx_applications_opp_status');
        });

        Schema::table('saved_scholarships', function (Blueprint $table) {
            // listSaved() is `user_id = ? order by saved_at desc`. The unique key
            // starts with user_id but cannot serve the sort.
            $table->index(['user_id', 'saved_at'], 'idx_saved_user_saved_at');
        });

        Schema::table('audit_log', function (Blueprint $table) {
            // The audit screen filters by action or entity type from a dropdown -
            // both exact matches - and always sorts newest first.
            $table->index(['action', 'created_at'], 'idx_audit_action_created');
            $table->index(['entity_type', 'entity_id'], 'idx_audit_entity');
        });

        Schema::table('opportunities', function (Blueprint $table) {
            // The moderation queue's duplicate check runs one
            // `title like 'prefix%'` per pending listing. A prefix LIKE can use a
            // B-tree; without this index each of those was a full table scan, so
            // the queue cost one scan per row awaiting review.
            $table->index('title', 'idx_opportunities_title');

            // The other arm of the same check: same provider, same closing date.
            $table->index(['provider_name', 'deadline'], 'idx_opportunities_provider_deadline');
        });

        // Two columns that are written on every insert and are meaningless empty.
        // A notification with no owner is unreachable - every read filters by
        // user_id - and one with no type has no category, so the category filter
        // added in the notification work would drop it silently while
        // NotificationPresentation still called it System. Making them NOT NULL
        // is what stops the query and the presentation layer disagreeing.
        //
        // Any row that somehow predates this is repaired rather than blocking the
        // migration: an ownerless notification cannot be shown to anybody, so
        // deleting it loses nothing, and an untyped one is genuinely System.
        DB::table('notifications')->whereNull('user_id')->delete();
        DB::table('notifications')->whereNull('type')->update(['type' => 'SYSTEM']);
        DB::table('notifications')->whereNull('created_at')->update(['created_at' => now()]);

        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->string('type', 50)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('idx_notifications_user_created');
            $table->dropIndex('idx_notifications_user_type');
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('type', 50)->nullable()->change();
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex('idx_applications_user_submitted');
        });

        // idx_applications_opp_status leads with opportunity_id, so when it was
        // created MySQL quietly dropped applications_opportunity_id_foreign as
        // redundant and started using this one to support the foreign key.
        // Dropping it now fails with "needed in a foreign key constraint" unless
        // the plain index is put back first. SQLite rebuilds the table on change
        // and never hits this, which is exactly why it went unnoticed until the
        // rollback was run against MySQL.
        if (DB::connection()->getDriverName() === 'mysql') {
            $exists = DB::selectOne(
                'SELECT 1 AS found FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                ['applications', 'applications_opportunity_id_foreign']
            );

            if ($exists === null) {
                DB::statement(
                    'ALTER TABLE `applications`
                     ADD INDEX `applications_opportunity_id_foreign` (`opportunity_id`)'
                );
            }
        }

        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex('idx_applications_opp_status');
        });

        Schema::table('saved_scholarships', function (Blueprint $table) {
            $table->dropIndex('idx_saved_user_saved_at');
        });

        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropIndex('idx_audit_action_created');
            $table->dropIndex('idx_audit_entity');
        });

        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropIndex('idx_opportunities_title');
            $table->dropIndex('idx_opportunities_provider_deadline');
        });
    }
};
