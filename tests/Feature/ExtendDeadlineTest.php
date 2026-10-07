<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use App\Services\AuditService;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The provider dashboard's "Extend deadline" dialog.
 *
 * Every listing has its own dialog on one page, which is where most of what is
 * pinned here came from: errors and old input are keyed by field name, so a
 * refusal for one listing showed up in all of them. The rest are the rules an
 * extension has to obey - it needs a deadline to extend, cannot go backwards, and
 * is written with its audit entry or not at all.
 */
class ExtendDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        // Start from the provider's own listings only, so each dialog on the page is one of ours.
        \App\Models\Application::whereIn(
            'opportunity_id',
            Opportunity::where('provider_user_id', $this->provider->user_id)->pluck('opportunity_id')
        )->delete();
        Opportunity::where('provider_user_id', $this->provider->user_id)->each(function (Opportunity $o) {
            $o->subjectRequirements()->delete();
            $o->delete();
        });
    }

    // ------------------------------------------------------- rolling listings --

    public function test_a_rolling_listing_offers_no_extend_deadline(): void
    {
        $rolling = $this->listing(['title' => 'Rolling Award', 'deadline' => null]);
        $dated = $this->listing(['title' => 'Dated Award']);

        $html = $this->dashboard();

        $this->assertStringNotContainsString('id="extend-deadline-' . $rolling->opportunity_id . '"', $html);
        $this->assertStringNotContainsString('#extend-deadline-' . $rolling->opportunity_id . '"', $html);
        $this->assertStringContainsString('id="extend-deadline-' . $dated->opportunity_id . '"', $html);
        $this->assertStringContainsString('#extend-deadline-' . $dated->opportunity_id . '"', $html);
    }

    public function test_extending_a_rolling_listing_is_refused_by_the_server_too(): void
    {
        $rolling = $this->listing(['deadline' => null]);

        $this->as($this->provider)
            ->post('/opportunities/' . $rolling->opportunity_id . '/extend-deadline', [
                'deadline' => Carbon::today()->addDays(30)->toDateString(),
                'reason' => 'Trying to add one.',
            ])
            ->assertSessionHas('errorMessage');

        $this->assertNull($rolling->fresh()->deadline, 'extending must never add a deadline to a rolling listing');
    }

    // ----------------------------------------------------------- the date floor --

    public function test_the_date_picker_starts_at_the_current_deadline_when_it_is_ahead(): void
    {
        $deadline = Carbon::today()->addDays(20);
        $listing = $this->listing(['deadline' => $deadline]);

        $this->assertSame($deadline->toDateString(), $this->minOf($listing));
    }

    public function test_the_date_picker_starts_tomorrow_for_a_closed_listing(): void
    {
        $listing = $this->listing(['deadline' => Carbon::today()->subDays(10), 'status' => OpportunityStatus::CLOSED]);

        $this->assertSame(Carbon::tomorrow()->toDateString(), $this->minOf($listing));
    }

    public function test_the_date_picker_starts_tomorrow_when_the_deadline_is_today(): void
    {
        $listing = $this->listing(['deadline' => Carbon::today()]);

        $this->assertSame(Carbon::tomorrow()->toDateString(), $this->minOf($listing));
    }

    // -------------------------------------------------------------- the rules --

    public function test_extending_earlier_than_the_current_deadline_is_refused_with_a_message_on_the_field(): void
    {
        $listing = $this->listing(['deadline' => Carbon::today()->addDays(20)]);

        $this->as($this->provider)
            ->post('/opportunities/' . $listing->opportunity_id . '/extend-deadline', [
                'deadline' => Carbon::today()->addDays(10)->toDateString(),
                'reason' => 'Shortening by mistake.',
            ])
            ->assertSessionHasErrorsIn($this->extendBag($listing), 'deadline');

        $message = session('errors')->getBag($this->extendBag($listing))->first('deadline');
        $this->assertStringContainsString($listing->deadline->format('d M Y'), $message);
        $this->assertEquals(Carbon::today()->addDays(20)->toDateString(), $listing->fresh()->deadline->toDateString());
    }

    public function test_a_valid_extension_moves_the_date_and_keeps_it_live(): void
    {
        $listing = $this->listing(['deadline' => Carbon::today()->addDays(20)]);
        $newDate = Carbon::today()->addDays(45)->toDateString();

        $this->as($this->provider)
            ->post('/opportunities/' . $listing->opportunity_id . '/extend-deadline', [
                'deadline' => $newDate,
                'reason' => 'More time for applicants.',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('successMessage');

        $this->assertSame($newDate, $listing->fresh()->deadline->toDateString());
        $this->assertSame(OpportunityStatus::ACTIVE, $listing->fresh()->status);
    }

    public function test_extending_a_closed_listing_reopens_it(): void
    {
        $listing = $this->listing(['deadline' => Carbon::today()->subDays(5), 'status' => OpportunityStatus::CLOSED]);

        $this->as($this->provider)
            ->post('/opportunities/' . $listing->opportunity_id . '/extend-deadline', [
                'deadline' => Carbon::today()->addDays(14)->toDateString(),
                'reason' => 'Reopening.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(OpportunityStatus::ACTIVE, $listing->fresh()->status);
    }

    public function test_the_extension_and_its_audit_entry_are_written_together(): void
    {
        $listing = $this->listing(['deadline' => Carbon::today()->addDays(20)]);

        $this->partialMock(AuditService::class, function ($mock) {
            $mock->shouldReceive('logOrFail')->andThrow(new \RuntimeException('audit store is down'));
        });

        $this->as($this->provider)
            ->post('/opportunities/' . $listing->opportunity_id . '/extend-deadline', [
                'deadline' => Carbon::today()->addDays(45)->toDateString(),
                'reason' => 'More time.',
            ]);

        $this->assertSame(
            Carbon::today()->addDays(20)->toDateString(),
            $listing->fresh()->deadline->toDateString(),
            'a deadline moved with no record of it must be rolled back'
        );
    }

    public function test_the_audit_entry_records_the_old_and_new_deadline(): void
    {
        $listing = $this->listing(['deadline' => Carbon::today()->addDays(20)]);
        $new = Carbon::today()->addDays(45)->toDateString();

        $this->as($this->provider)
            ->post('/opportunities/' . $listing->opportunity_id . '/extend-deadline', [
                'deadline' => $new,
                'reason' => 'More time.',
            ])
            ->assertSessionHasNoErrors();

        $entry = \App\Models\AuditLog::where('entity_id', $listing->opportunity_id)
            ->where('action', \App\Support\AuditAction::EXTEND_OPPORTUNITY_DEADLINE)
            ->latest('audit_id')
            ->first();

        $this->assertNotNull($entry, 'the extension must be audited');
        $this->assertStringContainsString($new, json_encode($entry->new_values));
        $this->assertStringContainsString(Carbon::today()->addDays(20)->toDateString(), json_encode($entry->old_values));
    }

    // ------------------------------------------------ errors stay in their dialog --

    public function test_a_refusal_shows_in_the_dialog_that_failed_and_not_in_the_others(): void
    {
        $first = $this->listing(['title' => 'First Award']);
        $second = $this->listing(['title' => 'Second Award']);

        $this->as($this->provider)
            ->from('/provider/dashboard')
            ->post('/opportunities/' . $first->opportunity_id . '/extend-deadline', [
                'deadline' => Carbon::today()->addDays(45)->toDateString(),
                'reason' => '',
            ])
            ->assertRedirect('/provider/dashboard');

        $html = $this->get('/provider/dashboard')->assertOk()->getContent();

        $mine = $this->dialog($html, 'extend-deadline-' . $first->opportunity_id);
        $theirs = $this->dialog($html, 'extend-deadline-' . $second->opportunity_id);

        $this->assertStringContainsString('data-sz-open-on-load', $mine, 'the dialog that failed reopens');
        $this->assertStringContainsString('is-invalid', $mine);
        $this->assertStringContainsString('invalid-feedback', $mine);

        $this->assertStringNotContainsString('data-sz-open-on-load', $theirs);
        $this->assertStringNotContainsString('is-invalid', $theirs, 'another listing\'s dialog must not look refused');
        $this->assertStringNotContainsString('invalid-feedback', $theirs);
    }

    public function test_typed_input_comes_back_only_in_the_dialog_that_failed(): void
    {
        $first = $this->listing(['title' => 'First Award']);
        $second = $this->listing(['title' => 'Second Award']);
        $typedDate = Carbon::today()->addDays(5)->toDateString();

        $this->as($this->provider)
            ->from('/provider/dashboard')
            ->post('/opportunities/' . $first->opportunity_id . '/extend-deadline', [
                'deadline' => $typedDate,
                'reason' => 'My typed reason',
            ]);

        $html = $this->get('/provider/dashboard')->getContent();

        $this->assertStringContainsString('My typed reason', $this->dialog($html, 'extend-deadline-' . $first->opportunity_id));
        $this->assertStringNotContainsString('My typed reason', $this->dialog($html, 'extend-deadline-' . $second->opportunity_id));
        $this->assertStringNotContainsString('My typed reason', $this->dialog($html, 'withdraw-' . $first->opportunity_id));
        $this->assertStringNotContainsString('My typed reason', $this->dialog($html, 'withdraw-' . $second->opportunity_id));
    }

    public function test_a_refused_withdrawal_stays_in_its_own_dialog(): void
    {
        $first = $this->listing(['title' => 'First Award']);
        $second = $this->listing(['title' => 'Second Award']);

        $this->as($this->provider)
            ->from('/provider/dashboard')
            ->delete('/opportunities/' . $first->opportunity_id, ['reason' => ''])
            ->assertRedirect('/provider/dashboard');

        $html = $this->get('/provider/dashboard')->getContent();

        $this->assertStringContainsString('data-sz-open-on-load', $this->dialog($html, 'withdraw-' . $first->opportunity_id));
        $this->assertStringNotContainsString('is-invalid', $this->dialog($html, 'withdraw-' . $second->opportunity_id));
        $this->assertStringNotContainsString('is-invalid', $this->dialog($html, 'extend-deadline-' . $first->opportunity_id));
        $this->assertSame(OpportunityStatus::ACTIVE, $first->fresh()->status);
    }

    public function test_no_dialog_is_marked_to_reopen_when_nothing_failed(): void
    {
        $this->listing();

        $this->assertStringNotContainsString('data-sz-open-on-load', $this->dashboard());
    }

    public function test_other_forms_still_use_the_default_error_bag(): void
    {
        // The field components default to the default bag, so every existing form
        // keeps showing its errors exactly as before.
        $this->as($this->provider)
            ->from('/opportunities/create')
            ->post('/opportunities/create', ['title' => '', 'description' => 'Typed description survives'])
            ->assertSessionHasErrors(['title']);

        $page = $this->get('/opportunities/create')->assertOk()->getContent();

        $this->assertStringContainsString('is-invalid', $page, 'a failed create still marks its field');
        $this->assertStringContainsString('Typed description survives', $page, 'and still refills what was typed');
    }

    public function test_the_reopen_script_is_in_the_bundle_and_the_fallback(): void
    {
        $this->assertStringContainsString("import './reopen-modal';", file_get_contents(resource_path('js/app.js')));
        $this->assertContains('reopen-modal.js', \App\Http\Controllers\SourceAssetController::FALLBACK_SCRIPTS);
    }

    // ------------------------------------------------------------- helpers --

    private function as(User $user): self
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    private function dashboard(): string
    {
        return $this->as($this->provider)->get('/provider/dashboard')->assertOk()->getContent();
    }

    private function extendBag(Opportunity $listing): string
    {
        return 'extend-deadline-' . $listing->opportunity_id;
    }

    /** The `min` attribute of a listing's date picker, as a Y-m-d string. */
    private function minOf(Opportunity $listing): string
    {
        $html = $this->dashboard();

        $this->assertSame(
            1,
            preg_match('#<input[^>]*id="deadline-' . $listing->opportunity_id . '"[^>]*>#', $html, $input),
            'the date picker must render'
        );
        $this->assertSame(1, preg_match('#min="([^"]+)"#', $input[0], $min), 'the picker must carry a min');

        return $min[1];
    }

    /** One dialog's markup, so a test can say what is and is not inside it. */
    private function dialog(string $html, string $id): string
    {
        $this->assertSame(
            1,
            preg_match('#<div class="modal fade text-start" id="' . preg_quote($id, '#') . '".*?</form>#s', $html, $match),
            "dialog $id must render"
        );

        return $match[0];
    }

    private function listing(array $attributes = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => $this->provider->full_name,
            'title' => 'Extend Fixture ' . uniqid(),
            'description' => 'A fixture listing.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDays(5),
            'reviewed_at' => Carbon::now()->subDays(4),
            'reviewed_by' => 'admin@scholarzim.co.zw',
        ], $attributes));
    }
}
