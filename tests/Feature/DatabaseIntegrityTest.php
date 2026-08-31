<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AuditService;
use App\Support\AccountStatus;
use App\Support\AuditAction;
use App\Support\NotificationType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Invariants the database enforces itself, rather than trusting the application
 * to remember.
 *
 * Every rule asserted here is one where a bug in PHP would otherwise write a row
 * that no query can find and no screen can show - the sort of thing that surfaces
 * months later as "a student says they were never notified".
 */
class DatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------------------- audit integrity --

    /**
     * The audit trail must outlive the accounts it describes.
     *
     * audit_log deliberately carries actor_email as text with no foreign key to
     * users. A key would have to either block the deletion or cascade it, and
     * cascading would mean the record of what somebody did disappears the moment
     * they close their account - which is precisely when it matters most.
     */
    public function test_deleting_a_user_leaves_their_audit_trail_intact(): void
    {
        $audit = app(AuditService::class);
        $email = $this->student->email;

        $audit->log($email, AuditAction::LOGIN_SUCCESS, 'USER', $this->student->user_id, 'Signed in');
        $audit->log($email, AuditAction::APPLY, 'APPLICATION', 1, 'Applied to something');

        $before = AuditLog::where('actor_email', $email)->count();
        $this->assertSame(2, $before);

        // Everything the deletion path removes, removed - then the account.
        \App\Models\Application::where('user_id', $this->student->user_id)->delete();
        app(AccountDeletionService::class)->delete($this->student, $email, selfService: true);

        $this->assertDatabaseMissing('users', ['email' => $email]);

        // The two earlier entries survive, and the deletion adds its own - the
        // trail grows past the account rather than leaving with it.
        $this->assertGreaterThanOrEqual(
            $before,
            AuditLog::where('actor_email', $email)->count(),
            'the audit trail must survive the account it describes'
        );

        foreach ([AuditAction::LOGIN_SUCCESS, AuditAction::APPLY] as $action) {
            $this->assertDatabaseHas('audit_log', ['actor_email' => $email, 'action' => $action]);
        }

        $this->assertDatabaseHas('audit_log', [
            'actor_email' => $email,
            'action' => AuditAction::ACCOUNT_SELF_DELETED,
        ]);
    }

    public function test_the_audit_table_has_no_foreign_key_to_users(): void
    {
        $audit = app(AuditService::class);

        // Writable for an actor who does not exist as a user at all, which is
        // what keeps historical entries valid after a deletion.
        $audit->log('long-gone@example.test', AuditAction::LOGIN_FAILURE, 'USER', null, 'Unknown actor');

        $this->assertDatabaseHas('audit_log', ['actor_email' => 'long-gone@example.test']);
    }

    /** Suspension is a status change, so nothing of theirs is destroyed. */
    public function test_suspending_a_user_destroys_none_of_their_records(): void
    {
        app(AuditService::class)->log($this->student->email, AuditAction::LOGIN_SUCCESS, 'USER', $this->student->user_id);

        Notification::create([
            'user_id' => $this->student->user_id,
            'type' => NotificationType::APPLICATION_ACCEPTED,
            'message' => 'Kept through suspension.',
            'is_read' => false,
            'created_at' => Carbon::now(),
        ]);

        $this->student->update(['account_status' => AccountStatus::SUSPENDED]);

        $this->assertSame(1, AuditLog::where('actor_email', $this->student->email)->count());
        $this->assertSame(1, Notification::where('user_id', $this->student->user_id)->count());
    }

    // -------------------------------------------------- notification columns --

    /**
     * A notification with no owner is unreachable - every read filters by
     * user_id - so the column refuses to be null rather than accumulating rows
     * nobody can see.
     */
    public function test_a_notification_cannot_be_written_without_an_owner(): void
    {
        $this->expectException(QueryException::class);

        DB::table('notifications')->insert([
            'user_id' => null,
            'type' => NotificationType::APPLICATION_ACCEPTED,
            'message' => 'Owned by nobody.',
            'is_read' => false,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * And one with no type has no category, so the category filter would drop it
     * while the presentation layer still called it System - the two disagreeing
     * about the same row.
     */
    public function test_a_notification_cannot_be_written_without_a_type(): void
    {
        $this->expectException(QueryException::class);

        DB::table('notifications')->insert([
            'user_id' => $this->student->user_id,
            'type' => null,
            'message' => 'Uncategorisable.',
            'is_read' => false,
            'created_at' => Carbon::now(),
        ]);
    }

    public function test_a_notification_cannot_point_at_a_user_that_does_not_exist(): void
    {
        $this->expectException(QueryException::class);

        DB::table('notifications')->insert([
            'user_id' => 999999,
            'type' => NotificationType::APPLICATION_ACCEPTED,
            'message' => 'Orphan.',
            'is_read' => false,
            'created_at' => Carbon::now(),
        ]);
    }

    // ------------------------------------------------- duplicate prevention --

    public function test_a_scholarship_cannot_be_saved_twice_by_the_same_student(): void
    {
        $opportunityId = \App\Models\Opportunity::query()->value('opportunity_id');

        DB::table('saved_scholarships')->insert([
            'user_id' => $this->student->user_id,
            'opportunity_id' => $opportunityId,
            'saved_at' => Carbon::now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('saved_scholarships')->insert([
            'user_id' => $this->student->user_id,
            'opportunity_id' => $opportunityId,
            'saved_at' => Carbon::now(),
        ]);
    }

    public function test_an_opportunity_records_one_view_row_per_day(): void
    {
        $opportunityId = \App\Models\Opportunity::query()->value('opportunity_id');

        DB::table('opportunity_views')->insert([
            'opportunity_id' => $opportunityId,
            'viewed_on' => Carbon::today()->toDateString(),
            'views' => 1,
        ]);

        $this->expectException(QueryException::class);

        DB::table('opportunity_views')->insert([
            'opportunity_id' => $opportunityId,
            'viewed_on' => Carbon::today()->toDateString(),
            'views' => 1,
        ]);
    }

    // ------------------------------------------------------ indexes in place --

    /**
     * The indexes this stage added, asserted by name.
     *
     * A dropped index breaks nothing that a test would otherwise notice - the
     * queries keep returning the right rows, just slowly - so the only way it
     * fails loudly is if something checks.
     */
    #[DataProvider('expectedIndexes')]
    public function test_the_query_pattern_indexes_exist(string $table, string $index): void
    {
        $this->assertTrue(
            Schema::hasTable($table),
            $table . ' is missing entirely'
        );

        $indexes = collect(DB::select("PRAGMA index_list('" . $table . "')"))
            ->pluck('name')
            ->all();

        $this->assertContains(
            $index,
            $indexes,
            $index . ' is missing from ' . $table . ' - the queries still work, just slowly'
        );
    }

    public static function expectedIndexes(): array
    {
        return [
            'notifications by recency' => ['notifications', 'idx_notifications_user_created'],
            'notifications by category' => ['notifications', 'idx_notifications_user_type'],
            'applications by owner' => ['applications', 'idx_applications_user_submitted'],
            'applications by listing' => ['applications', 'idx_applications_opp_status'],
            'saved list by recency' => ['saved_scholarships', 'idx_saved_user_saved_at'],
            'audit by action' => ['audit_log', 'idx_audit_action_created'],
            'audit by entity' => ['audit_log', 'idx_audit_entity'],
            'duplicate title check' => ['opportunities', 'idx_opportunities_title'],
            'duplicate provider check' => ['opportunities', 'idx_opportunities_provider_deadline'],
        ];
    }

    /** The unique keys that make duplicates impossible rather than unlikely. */
    #[DataProvider('expectedUniqueKeys')]
    public function test_the_duplicate_preventing_unique_keys_exist(string $table, string $index): void
    {
        $unique = collect(DB::select("PRAGMA index_list('" . $table . "')"))
            ->where('unique', 1)
            ->pluck('name')
            ->all();

        $this->assertContains($index, $unique, $index . ' must stay unique');
    }

    public static function expectedUniqueKeys(): array
    {
        return [
            'one application per pair' => ['applications', 'uk_applications_user_opportunity'],
            'one save per pair' => ['saved_scholarships', 'uk_saved_user_opp'],
            'one view row per day' => ['opportunity_views', 'uk_opportunity_views_day'],
        ];
    }
}
