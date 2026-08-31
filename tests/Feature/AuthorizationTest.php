<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\SavedScholarship;
use App\Models\User;
use App\Policies\ApplicationPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\NotificationPolicy;
use App\Policies\OpportunityPolicy;
use App\Policies\ReportPolicy;
use App\Policies\SavedScholarshipPolicy;
use App\Services\OpportunityModerationService;
use App\Support\AccountStatus;
use App\Support\ApplicationStatus;
use App\Support\NotificationType;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who can reach what, tested from the outside as an attacker would.
 *
 * Every "cannot" here is a request that is genuinely made rather than a policy
 * method called in isolation: the question is whether the endpoint refuses, not
 * whether a rule exists somewhere that says it should. The legitimate-access
 * cases sit beside them on purpose - an authorization test suite that only
 * proves things are blocked will pass just as happily on an application that
 * blocks everybody.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $applicantA;

    private User $applicantB;

    private User $providerA;

    private User $providerB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->applicantA = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->providerA = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $this->applicantB = $this->makeUser('applicant-b@example.test', $this->applicantA->role_id);
        $this->providerB = $this->makeUser('provider-b@example.test', $this->providerA->role_id);
    }

    // ------------------------------------------- applicant vs other applicant --

    public function test_an_applicant_cannot_open_another_applicants_application(): void
    {
        $application = $this->applicationFor($this->applicantA);

        $this->as($this->applicantB)
            ->get('/applications/' . $application->application_id . '/confirmation')
            ->assertForbidden();
    }

    public function test_an_applicant_cannot_withdraw_another_applicants_application(): void
    {
        $application = $this->applicationFor($this->applicantA);

        $this->as($this->applicantB)
            ->post('/applications/' . $application->application_id . '/withdraw')
            ->assertRedirect();

        $this->assertSame(
            ApplicationStatus::PENDING,
            $application->fresh()->application_status,
            'the application must be untouched'
        );
    }

    public function test_an_applicant_cannot_download_another_applicants_document(): void
    {
        $application = $this->applicationFor($this->applicantA);
        $application->update(['document_path' => 'applications/secret.pdf']);

        $this->as($this->applicantB)
            ->get('/applications/' . $application->application_id . '/document')
            ->assertForbidden();
    }

    public function test_an_applicant_cannot_open_another_applicants_notification(): void
    {
        $notification = Notification::create([
            'user_id' => $this->applicantA->user_id,
            'type' => NotificationType::APPLICATION_SUBMITTED,
            'message' => 'Private to applicant A.',
            'is_read' => false,
            'created_at' => Carbon::now(),
        ]);

        // 404 rather than 403: whether a notification id exists is itself a
        // disclosure, so the answer is the same as for an id that does not.
        $this->as($this->applicantB)
            ->get('/notifications/' . $notification->notification_id . '/open')
            ->assertNotFound();

        $this->assertFalse($notification->fresh()->is_read);
    }

    /** A saved list is per-user, so B removing "A's" save must not touch A's row. */
    public function test_an_applicant_cannot_remove_another_applicants_saved_scholarship(): void
    {
        $opportunity = $this->approvedListing($this->providerA);

        SavedScholarship::create([
            'user_id' => $this->applicantA->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
            'saved_at' => Carbon::now(),
        ]);

        $this->as($this->applicantB)
            ->post('/applicant/saved/' . $opportunity->opportunity_id . '/remove')
            ->assertRedirect();

        $this->assertDatabaseHas('saved_scholarships', [
            'user_id' => $this->applicantA->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    public function test_profile_documents_are_served_only_to_their_owner(): void
    {
        $this->applicantA->applicantProfile->update(['cv_path' => 'profiles/a/cv.pdf']);

        // B asking for "my document" can only ever get B's own - there is no id
        // in the route to point at somebody else's.
        $this->as($this->applicantB)
            ->get('/my-documents/cv')
            ->assertNotFound();
    }

    // --------------------------------------------- provider vs other provider --

    public function test_a_provider_cannot_open_another_providers_application(): void
    {
        $application = $this->applicationFor($this->applicantA, $this->approvedListing($this->providerA));

        $this->as($this->providerB)
            ->get('/provider/applications/' . $application->application_id)
            ->assertForbidden();
    }

    public function test_a_provider_cannot_review_another_providers_application(): void
    {
        $application = $this->applicationFor($this->applicantA, $this->approvedListing($this->providerA));

        $this->as($this->providerB)
            ->post('/provider/applications/' . $application->application_id . '/review', [
                'status' => ApplicationStatus::REJECTED,
                'reason' => 'Not my applicant to reject.',
            ])
            ->assertForbidden();

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
    }

    public function test_a_provider_cannot_download_another_providers_applicant_documents(): void
    {
        $application = $this->applicationFor($this->applicantA, $this->approvedListing($this->providerA));
        $application->update(['document_path' => 'applications/secret.pdf']);

        $this->as($this->providerB)
            ->get('/applications/' . $application->application_id . '/document')
            ->assertForbidden();

        $this->as($this->providerB)
            ->get('/provider/applications/' . $application->application_id . '/results-certificate')
            ->assertForbidden();
    }

    public function test_a_provider_cannot_edit_or_withdraw_another_providers_listing(): void
    {
        $listing = $this->approvedListing($this->providerA);

        $this->as($this->providerB)
            ->put('/opportunities/' . $listing->opportunity_id, [
                'title' => 'Hijacked Award',
                'description' => 'A description long enough to pass validation checks.',
                'education_level' => 'Undergraduate',
                'funding_type' => 'Full Scholarship',
                'country' => 'Zimbabwe',
                'change_reason' => 'Taking this over.',
            ])
            ->assertRedirect();

        $this->assertSame('Provider A Award', $listing->fresh()->title);

        $this->as($this->providerB)
            ->delete('/opportunities/' . $listing->opportunity_id, ['reason' => 'Not mine.'])
            ->assertRedirect();

        $this->assertSame(OpportunityStatus::ACTIVE, $listing->fresh()->status);
    }

    // ------------------------------------------------ crossing the role border --

    #[DataProvider('adminOnlyRoutes')]
    public function test_non_admins_cannot_reach_administrative_routes(string $method, string $uri): void
    {
        foreach ([$this->applicantRole(), $this->providerRole()] as $actor) {
            $response = $this->as($actor)->call($method, $uri);

            $this->assertContains(
                $response->getStatusCode(),
                [403, 404],
                $actor->email . ' reached ' . $method . ' ' . $uri
            );
        }
    }

    public static function adminOnlyRoutes(): array
    {
        return [
            'moderation queue' => ['GET', '/admin/dashboard'],
            'user administration' => ['GET', '/admin/users'],
            'reports hub' => ['GET', '/admin/reports'],
            'user export' => ['GET', '/admin/reports/users.xlsx'],
            'applications export' => ['GET', '/admin/reports/applications.pdf'],
            'audit log' => ['GET', '/admin/audit-log'],
            'scholarfit weights' => ['GET', '/admin/scholarfit'],
            'platform search' => ['GET', '/admin/search'],
        ];
    }

    /** A provider cannot publish their own listing by reaching the queue. */
    public function test_a_provider_cannot_approve_their_own_scholarship(): void
    {
        $listing = $this->pendingListing($this->providerA);

        $this->as($this->providerA)
            ->post('/admin/opportunities/' . $listing->opportunity_id . '/approve')
            ->assertForbidden();

        $this->assertSame(
            OpportunityModerationStatus::PENDING,
            $listing->fresh()->moderation_status,
            'moderation must remain an administrator decision'
        );
    }

    /** Nor by any other route: posting leaves it pending, full stop. */
    public function test_a_newly_posted_listing_cannot_publish_itself(): void
    {
        $this->as($this->providerA)->post('/opportunities/create', [
            'title' => 'Self Published Award',
            'description' => 'A description long enough to pass validation checks.',
            'education_level' => 'Undergraduate',
            'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
        ])->assertRedirect();

        $created = Opportunity::where('title', 'Self Published Award')->firstOrFail();

        $this->assertSame(OpportunityModerationStatus::PENDING, $created->moderation_status);
        $this->assertFalse($created->isPubliclyVisible());
    }

    public function test_an_applicant_cannot_modify_an_opportunity(): void
    {
        $listing = $this->approvedListing($this->providerA);

        $this->as($this->applicantA)
            ->put('/opportunities/' . $listing->opportunity_id, ['title' => 'Student Edit'])
            ->assertForbidden();

        $this->as($this->applicantA)
            ->delete('/opportunities/' . $listing->opportunity_id, ['reason' => 'x'])
            ->assertForbidden();

        $this->assertSame('Provider A Award', $listing->fresh()->title);
    }

    public function test_an_applicant_cannot_decide_their_own_application(): void
    {
        $application = $this->applicationFor($this->applicantA, $this->approvedListing($this->providerA));

        $this->as($this->applicantA)
            ->post('/provider/applications/' . $application->application_id . '/review', [
                'status' => ApplicationStatus::ACCEPTED,
                'reason' => 'Approving myself.',
            ])
            ->assertForbidden();

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
    }

    // -------------------------------------------------- legitimate access --

    public function test_each_party_can_reach_their_own_records(): void
    {
        $listing = $this->approvedListing($this->providerA);
        $application = $this->applicationFor($this->applicantA, $listing);

        $this->as($this->applicantA)
            ->get('/applications/' . $application->application_id . '/confirmation')
            ->assertOk();

        $this->as($this->providerA)
            ->get('/provider/applications/' . $application->application_id)
            ->assertOk();

        $this->as($this->providerA)
            ->get('/opportunities/' . $listing->opportunity_id . '/edit')
            ->assertOk();
    }

    #[DataProvider('adminOnlyRoutes')]
    public function test_administrators_retain_administrative_access(string $method, string $uri): void
    {
        $response = $this->as($this->admin)->call($method, $uri);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'an administrator must still reach ' . $uri
        );
    }

    public function test_an_administrator_can_moderate_a_listing_they_do_not_own(): void
    {
        $listing = $this->pendingListing($this->providerA);

        $this->as($this->admin)
            ->post('/admin/opportunities/' . $listing->opportunity_id . '/approve')
            ->assertRedirect();

        $this->assertSame(OpportunityModerationStatus::APPROVED, $listing->fresh()->moderation_status);
    }

    /** Separation of duties, asserted at the service where the rule lives. */
    public function test_nobody_moderates_a_listing_they_posted_themselves(): void
    {
        $listing = $this->pendingListing($this->admin);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('You cannot moderate a scholarship you posted yourself.');

        app(OpportunityModerationService::class)->approve($listing->opportunity_id, $this->admin);
    }

    // ------------------------------------------------------------ suspension --

    /**
     * The gap this stage closed. Suspension was checked at login only, so
     * anybody already holding a session kept it - which is exactly the person a
     * suspension is aimed at.
     */
    public function test_suspension_ends_an_active_session_on_the_next_request(): void
    {
        $this->as($this->applicantA)->get('/my-applications')->assertOk();

        $this->applicantA->update(['account_status' => AccountStatus::SUSPENDED]);

        $this->get('/my-applications')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[DataProvider('suspendableRoles')]
    public function test_a_suspended_account_of_any_role_loses_access(string $property, string $uri): void
    {
        $user = $this->{$property};

        $this->as($user)->get($uri)->assertOk();

        $user->update(['account_status' => AccountStatus::SUSPENDED]);

        $this->get($uri)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public static function suspendableRoles(): array
    {
        return [
            'applicant' => ['applicantA', '/my-applications'],
            'provider' => ['providerA', '/provider/dashboard'],
            'admin' => ['admin', '/admin/dashboard'],
        ];
    }

    /** Suspension takes effect on the live session, not only at the next sign-in. */
    public function test_a_suspended_account_loses_its_live_session(): void
    {
        $this->as($this->applicantA)
            ->get('/my-applications')
            ->assertOk();

        $this->applicantA->update(['account_status' => AccountStatus::SUSPENDED]);

        $this->get('/my-applications')->assertRedirect();
    }

    public function test_an_administrator_cannot_suspend_themselves(): void
    {
        $this->as($this->admin)
            ->post('/admin/users/' . $this->admin->user_id . '/suspend')
            ->assertRedirect();

        $this->assertNotSame(
            AccountStatus::SUSPENDED,
            $this->admin->fresh()->account_status,
            'suspending yourself would log you straight out'
        );
    }

    // ----------------------------------------------------- the policies alone --

    public function test_the_policies_answer_the_matrix_directly(): void
    {
        $listing = $this->approvedListing($this->providerA);
        $application = $this->applicationFor($this->applicantA, $listing);
        $application->setRelation('opportunity', $listing);

        $applications = new ApplicationPolicy();
        $this->assertTrue($applications->view($this->applicantA, $application));
        $this->assertTrue($applications->view($this->providerA, $application));
        $this->assertFalse($applications->view($this->applicantB, $application));
        $this->assertFalse($applications->view($this->providerB, $application));
        $this->assertFalse($applications->review($this->applicantA, $application));
        $this->assertTrue($applications->review($this->providerA, $application));
        $this->assertFalse($applications->withdraw($this->providerA, $application));

        $opportunities = new OpportunityPolicy();
        $this->assertTrue($opportunities->update($this->providerA, $listing));
        $this->assertFalse($opportunities->update($this->providerB, $listing));
        $this->assertFalse($opportunities->moderate($this->providerA, $listing));
        $this->assertTrue($opportunities->moderate($this->admin, $listing));
        $this->assertFalse($opportunities->create($this->applicantA));

        $reports = new ReportPolicy();
        $this->assertTrue($reports->viewAny($this->admin));
        $this->assertFalse($reports->viewAny($this->providerA));
        $this->assertFalse($reports->viewAny($this->applicantA));

        $documents = new DocumentPolicy();
        $this->assertTrue($documents->viewOwnProfileDocument($this->applicantA, $this->applicantA->applicantProfile));
        $this->assertFalse($documents->viewOwnProfileDocument($this->applicantB, $this->applicantA->applicantProfile));
        $this->assertTrue($documents->viewApplicantDocumentViaApplication($this->providerA, $this->providerA->user_id));
        $this->assertFalse($documents->viewApplicantDocumentViaApplication($this->providerB, $this->providerA->user_id));
    }

    public function test_saved_data_and_notification_policies_are_owner_only(): void
    {
        $saved = new SavedScholarship(['user_id' => $this->applicantA->user_id]);
        $notification = new Notification(['user_id' => $this->applicantA->user_id]);

        $this->assertTrue((new SavedScholarshipPolicy())->delete($this->applicantA, $saved));
        $this->assertFalse((new SavedScholarshipPolicy())->delete($this->applicantB, $saved));

        $this->assertTrue((new NotificationPolicy())->view($this->applicantA, $notification));
        $this->assertFalse((new NotificationPolicy())->view($this->applicantB, $notification));
    }

    // --------------------------------------------------------------- helpers --

    private function applicantRole(): User
    {
        return $this->applicantA;
    }

    private function providerRole(): User
    {
        return $this->providerA;
    }

    /**
     * AuthenticateSession binds a session to the account that opened it, so a
     * second actingAs() in one test ends the session rather than switching
     * users. Flushing first gives each actor a clean one.
     */
    private function as(User $user): self
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    private function makeUser(string $email, int $roleId): User
    {
        return User::create([
            'role_id' => $roleId,
            'full_name' => 'Test ' . $email,
            'email' => $email,
            'password_hash' => bcrypt('ChangeMe123'),
            'account_status' => AccountStatus::ACTIVE,
            'email_verified' => true,
        ]);
    }

    private function approvedListing(User $provider): Opportunity
    {
        return $this->listing($provider, OpportunityModerationStatus::APPROVED);
    }

    private function pendingListing(User $provider): Opportunity
    {
        return $this->listing($provider, OpportunityModerationStatus::PENDING);
    }

    private function listing(User $provider, string $moderation): Opportunity
    {
        return Opportunity::create([
            'provider_user_id' => $provider->user_id,
            'provider_name' => $provider->full_name,
            'title' => $provider->user_id === $this->providerA->user_id
                ? 'Provider A Award'
                : 'Listing ' . uniqid(),
            'description' => 'An award used by the authorization tests.',
            'education_level' => 'Undergraduate',
            'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => $moderation,
            'submitted_at' => Carbon::now()->subDay(),
        ]);
    }

    private function applicationFor(User $applicant, ?Opportunity $opportunity = null): Application
    {
        $opportunity ??= $this->approvedListing($this->providerA);

        return Application::create([
            'user_id' => $applicant->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
            'application_status' => ApplicationStatus::PENDING,
            'submitted_at' => Carbon::now()->subDay(),
        ]);
    }
}
