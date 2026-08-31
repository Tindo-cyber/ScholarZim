<?php

namespace Tests\Feature;

use App\Mail\ScholarZimMail;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicationService;
use App\Services\AuditService;
use App\Services\OpportunityService;
use App\Support\ApplicationStatus;
use App\Support\AuditAction;
use App\Support\RequestContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Whether one action can be followed from the request that caused it through to
 * the record of what it changed.
 *
 * The audit trail was previously a sentence - who, what, which row - which reads
 * well and answers almost nothing. These tests are about the questions a person
 * actually arrives with: what did this change, who was connected from where, and
 * which log lines belong to it.
 */
class TraceabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::reset();
        $this->seed(DatabaseSeeder::class);
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------------------- correlation ids --

    public function test_every_response_carries_a_request_id(): void
    {
        $response = $this->get('/');

        $id = $response->headers->get(RequestContext::HEADER);

        $this->assertNotNull($id, 'the caller needs an id they can quote back');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{8,64}$/', $id);
    }

    /** A trace that started at a proxy continues rather than restarting here. */
    public function test_an_inbound_request_id_is_adopted(): void
    {
        $response = $this->withHeaders([RequestContext::HEADER => 'edge-abc-123'])->get('/');

        $this->assertSame('edge-abc-123', $response->headers->get(RequestContext::HEADER));
    }

    /**
     * But only as far as its shape. The id lands in log files and in the audit
     * table, so anything long or strange enough to be an injection attempt is
     * replaced rather than stored.
     */
    #[DataProvider('untrustworthyIds')]
    public function test_a_malformed_inbound_request_id_is_replaced(string $supplied): void
    {
        $response = $this->withHeaders([RequestContext::HEADER => $supplied])->get('/');

        $this->assertNotSame($supplied, $response->headers->get(RequestContext::HEADER));
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9._-]{8,64}$/',
            $response->headers->get(RequestContext::HEADER)
        );
    }

    public static function untrustworthyIds(): array
    {
        return [
            'newline injection' => ["abc\ndef ERROR forged line"],
            'too short' => ['x'],
            'too long' => [str_repeat('a', 200)],
            'html' => ['<script>alert(1)</script>'],
            'spaces' => ['not a valid id'],
            'empty' => [''],
        ];
    }

    /** The same id reaches the audit row written during that request. */
    public function test_an_action_and_its_audit_entry_share_one_request_id(): void
    {
        $response = $this->withHeaders([RequestContext::HEADER => 'trace-me-0001'])
            ->actingAs($this->provider)
            ->post('/opportunities/create', [
                'title' => 'Traceable Award',
                'description' => 'A description long enough to pass validation checks.',
                'education_level' => 'Undergraduate',
                'target_field' => 'Engineering',
                'funding_type' => 'Full Scholarship',
                'country' => 'Zimbabwe',
            ]);

        $response->assertRedirect();

        $entry = AuditLog::where('action', AuditAction::CREATE_OPPORTUNITY)->latest('audit_id')->first();

        $this->assertNotNull($entry);
        $this->assertSame('trace-me-0001', $entry->request_id);
    }

    // ----------------------------------------------------- structured records --

    public function test_an_audit_entry_records_the_actor_and_where_they_connected_from(): void
    {
        $this->withHeaders([RequestContext::HEADER => 'ctx-000001'])
            ->actingAs($this->provider)
            ->post('/opportunities/create', [
                'title' => 'Context Award',
                'description' => 'A description long enough to pass validation checks.',
                'education_level' => 'Undergraduate',
                'target_field' => 'Engineering',
                'funding_type' => 'Full Scholarship',
                'country' => 'Zimbabwe',
            ])->assertRedirect();

        $entry = AuditLog::where('action', AuditAction::CREATE_OPPORTUNITY)->latest('audit_id')->firstOrFail();

        $this->assertSame($this->provider->email, $entry->actor_email);
        $this->assertSame($this->provider->user_id, $entry->actor_user_id);
        $this->assertNotNull($entry->ip_address);
        $this->assertNotNull($entry->created_at);
        $this->assertSame('OPPORTUNITY', $entry->entity_type);
    }

    /** A status change records what it replaced, not only what it set. */
    public function test_a_status_change_records_the_old_and_new_values(): void
    {
        $application = $this->application(ApplicationStatus::PENDING);

        app(ApplicationService::class)->decide(
            $application->application_id,
            ApplicationStatus::ACCEPTED,
            'Outstanding academic record.',
            $this->provider
        );

        $entry = AuditLog::where('action', AuditAction::STATUS_UPDATE)->latest('audit_id')->firstOrFail();

        $this->assertSame(ApplicationStatus::PENDING, $entry->old_values['application_status']);
        $this->assertSame(ApplicationStatus::ACCEPTED, $entry->new_values['application_status']);
        $this->assertSame('Outstanding academic record.', $entry->reason);
        $this->assertTrue($entry->hasValueChanges());
    }

    /** An edit records only the fields that moved, not the whole row. */
    public function test_an_edit_records_only_what_changed(): void
    {
        $listing = Opportunity::where('provider_user_id', $this->provider->user_id)->firstOrFail();

        app(OpportunityService::class)->update(
            $listing->opportunity_id,
            [
                'title' => $listing->title,
                'description' => $listing->description,
                'education_level' => 'PhD',
                'target_field' => $listing->target_field,
                'funding_type' => $listing->funding_type,
                'country' => $listing->country,
                'deadline' => $listing->deadline?->toDateString(),
            ],
            $this->provider,
            'Retargeting at doctoral candidates.'
        );

        $entry = AuditLog::where('action', AuditAction::UPDATE_OPPORTUNITY)->latest('audit_id')->firstOrFail();

        $this->assertArrayHasKey('education_level', $entry->new_values);
        $this->assertSame('PhD', $entry->new_values['education_level']);
        $this->assertArrayNotHasKey('title', $entry->new_values, 'an unchanged field must not be recorded as a change');
        $this->assertSame('Retargeting at doctoral candidates.', $entry->reason);
    }

    public function test_the_changed_fields_helper_pairs_old_with_new(): void
    {
        app(AuditService::class)->log(
            $this->admin->email,
            AuditAction::UPDATE_USER,
            'USER',
            $this->student->user_id,
            'Changed status',
            ['old' => ['account_status' => 'ACTIVE'], 'new' => ['account_status' => 'SUSPENDED']]
        );

        $fields = AuditLog::latest('audit_id')->firstOrFail()->changedFields();

        $this->assertSame(
            [['field' => 'account_status', 'old' => 'ACTIVE', 'new' => 'SUSPENDED']],
            $fields
        );
    }

    // ------------------------------------------------------------- redaction --

    /**
     * The audit table is read by more people than the database is, kept longer,
     * and exported to explain decisions. A credential must never reach it.
     */
    #[DataProvider('sensitiveKeys')]
    public function test_a_sensitive_value_is_never_written_to_the_audit_trail(string $key): void
    {
        $secret = 'super-secret-value-' . $key;

        app(AuditService::class)->log(
            $this->admin->email,
            AuditAction::UPDATE_USER,
            'USER',
            $this->student->user_id,
            'Attempted to record a secret',
            ['new' => [$key => $secret, 'harmless' => 'kept']]
        );

        $entry = AuditLog::latest('audit_id')->firstOrFail();

        $this->assertSame('[redacted]', $entry->new_values[$key]);
        $this->assertSame('kept', $entry->new_values['harmless'], 'redaction must not swallow ordinary fields');
        $this->assertStringNotContainsString($secret, json_encode($entry->getAttributes()));
    }

    public static function sensitiveKeys(): array
    {
        return [
            'password' => ['password'],
            'password hash' => ['password_hash'],
            'current password' => ['current_password'],
            'api token' => ['api_token'],
            'two factor secret' => ['two_factor_secret'],
            'recovery codes' => ['recovery_codes'],
            'session id' => ['session_id'],
            'remember token' => ['remember_token'],
        ];
    }

    public function test_nested_values_are_scrubbed_too(): void
    {
        app(AuditService::class)->log(
            $this->admin->email,
            AuditAction::UPDATE_USER,
            'USER',
            null,
            'Nested',
            ['new' => ['profile' => ['name' => 'Tendai', 'password' => 'hunter2']]]
        );

        $entry = AuditLog::latest('audit_id')->firstOrFail();

        $this->assertSame('Tendai', $entry->new_values['profile']['name']);
        $this->assertSame('[redacted]', $entry->new_values['profile']['password']);
    }

    // ---------------------------------------------------------- queue tracing --

    /**
     * The id has to cross the queue boundary, because that is where the email is
     * actually sent and where the failure usually surfaces. Without it an
     * approval and the mail it produced are two unrelated log events.
     */
    public function test_the_request_id_is_carried_into_a_queued_job(): void
    {
        RequestContext::adopt('queued-trace-01');

        // Queued onto the database driver so the payload is actually written and
        // can be read back - the real path, rather than a fake that would prove
        // only that the fake records what it is told.
        config(['queue.default' => 'database']);
        DB::table('jobs')->delete();

        \Illuminate\Support\Facades\Mail::to('someone@example.test')
            ->queue(new ScholarZimMail('Subject', 'emails.notification', []));

        $row = DB::table('jobs')->first();

        $this->assertNotNull($row, 'the mailable should have been queued');

        $payload = json_decode($row->payload, true);

        $this->assertSame(
            'queued-trace-01',
            $payload['scholarzim_request_id'] ?? null,
            'a job must carry the id of the request that dispatched it'
        );
    }

    // ------------------------------------------------------------- health --

    public function test_liveness_reports_no_dependencies(): void
    {
        $response = $this->get('/health')->assertOk();

        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('checked', 'liveness');
        $response->assertJsonMissingPath('checks');
    }

    public function test_readiness_reports_the_dependencies_a_request_needs(): void
    {
        $response = $this->get('/health/ready')->assertOk();

        $response->assertJsonPath('status', 'ready');
        $response->assertJsonPath('checked', 'readiness');
        $response->assertJsonPath('checks.database', 'up');
        $this->assertContains('database', $response->json('required'));
    }

    /**
     * A database outage must fail readiness, not liveness. Failing liveness gets
     * a working container killed and restarted for a fault it cannot fix.
     */
    public function test_a_database_outage_fails_readiness_but_not_liveness(): void
    {
        DB::shouldReceive('connection')->andThrow(new \RuntimeException('connection refused'));

        $this->get('/health')->assertOk()->assertJsonPath('status', 'ok');
        $this->get('/health/ready')->assertStatus(503)->assertJsonPath('checks.database', 'down');
    }

    /** These probes are unauthenticated, so a failure must not describe itself. */
    public function test_a_failing_probe_does_not_leak_internals(): void
    {
        DB::shouldReceive('connection')
            ->andThrow(new \RuntimeException('SQLSTATE[HY000] [1045] Access denied for user root@10.0.0.5'));

        $body = $this->get('/health/ready')->assertStatus(503)->getContent();

        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('Access denied', $body);
        $this->assertStringNotContainsString('10.0.0.5', $body);
        $this->assertStringContainsString('down', $body);
    }

    // --------------------------------------------------------------- helpers --

    private function application(string $status): Application
    {
        $opportunity = Opportunity::where('provider_user_id', $this->provider->user_id)->firstOrFail();

        return Application::updateOrCreate(
            ['user_id' => $this->student->user_id, 'opportunity_id' => $opportunity->opportunity_id],
            ['application_status' => $status, 'submitted_at' => Carbon::now()->subDay()]
        );
    }
}
