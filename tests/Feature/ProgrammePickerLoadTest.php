<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\User;
use App\Services\Catalogue\ListingScopes;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The programme picker no longer puts the whole catalogue into the page. Programmes at the level in use (and any a
 * listing already holds) are written as live checkboxes; every other level waits, inert, in a <template> that the
 * browser script opens when the level changes. This keeps the form light without changing what can be chosen, what is
 * saved, or the difference between a programme and its whole field.
 */
class ProgrammePickerLoadTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    /** @return array{live: array<int, int>, waiting: array<int, int>, html: string} programme ids written live / held in the template */
    private function pickerOf(string $html): array
    {
        $waitingHtml = preg_match('#<template data-picker-reserve>(.*?)</template>#s', $html, $m) ? $m[1] : '';
        $liveHtml = str_replace($waitingHtml, '', $html);

        $ids = fn (string $part) => array_map('intval', preg_match_all('#<input[^>]*name="scope_programmes\[\]"[^>]*value="(\d+)"#', $part, $x) ? $x[1] : []);

        return ['live' => $ids($liveHtml), 'waiting' => $ids($waitingHtml), 'html' => $html];
    }

    private function createPage(array $old = []): array
    {
        $response = $this->actingAs($this->provider)->withSession(['_old_input' => $old])->get('/opportunities/create')->assertOk();

        return $this->pickerOf($response->getContent());
    }

    private function atLevel(string $level): array
    {
        return Programme::inCatalogue()->where('education_level', $level)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_with_no_level_chosen_no_programme_is_a_live_checkbox_and_all_of_them_wait_in_the_template(): void
    {
        $page = $this->createPage();

        $this->assertSame([], $page['live'], 'nothing to tab through or post until a level is chosen');
        $this->assertEqualsCanonicalizing(Programme::inCatalogue()->pluck('id')->map(fn ($i) => (int) $i)->all(), $page['waiting']);
    }

    public function test_a_chosen_level_writes_only_its_programmes_live(): void
    {
        $page = $this->createPage(['education_level' => EducationLevel::UNDERGRADUATE]);

        $this->assertEqualsCanonicalizing($this->atLevel(EducationLevel::UNDERGRADUATE), $page['live']);
        $this->assertSame([], array_intersect($page['live'], $page['waiting']), 'a programme is either live or waiting, never both');
        $this->assertSame(Programme::inCatalogue()->count(), count($page['live']) + count($page['waiting']), 'every programme is still in the page');
    }

    public function test_the_page_is_much_lighter_once_a_level_is_chosen(): void
    {
        $full = strlen($this->createPage()['html']);
        $diploma = $this->createPage(['education_level' => EducationLevel::DIPLOMA]);

        $this->assertEqualsCanonicalizing($this->atLevel(EducationLevel::DIPLOMA), $diploma['live']);
        $this->assertLessThan(Programme::inCatalogue()->count() / 2, count($diploma['live']), 'the live list is a fraction of the catalogue');
        $this->assertLessThan(900_000, $full, 'the whole catalogue is written compactly');
    }

    public function test_a_listing_keeps_its_saved_programmes_live_and_ticked_when_edited(): void
    {
        $level = EducationLevel::UNDERGRADUATE;
        $programme = Programme::inCatalogue()->where('education_level', $level)->orderBy('id')->firstOrFail();
        $other = Programme::inCatalogue()->where('education_level', EducationLevel::DIPLOMA)->orderBy('id')->firstOrFail();

        $listing = Opportunity::create([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => $this->provider->full_name, 'title' => 'Load Award',
            'description' => 'A fixture.', 'education_level' => $level, 'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe', 'deadline' => '2030-12-31', 'open_to_all_programmes' => false, 'any_institution' => true,
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED, 'submitted_at' => now()->subDay(),
        ]);
        // One programme at the listing's level, and one left over from another level (a listing saved before the rule).
        OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'programme_id' => $programme->id]);
        OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'programme_id' => $other->id]);

        $html = $this->actingAs($this->provider)->get("/opportunities/{$listing->opportunity_id}/edit")->assertOk()->getContent();
        $page = $this->pickerOf($html);

        $this->assertContains($programme->id, $page['live']);
        $this->assertContains($other->id, $page['live'], 'a saved choice is never hidden away, whatever its level');
        $this->assertMatchesRegularExpression('#<input[^>]*name="scope_programmes\[\]"[^>]*value="' . $programme->id . '"[^>]*checked#', $html);
        $this->assertMatchesRegularExpression('#<input[^>]*name="scope_programmes\[\]"[^>]*value="' . $other->id . '"[^>]*checked#', $html);
    }

    public function test_the_distinction_between_programme_field_and_everything_is_unchanged(): void
    {
        $programme = Programme::inCatalogue()->where('education_level', EducationLevel::UNDERGRADUATE)->orderBy('id')->firstOrFail();

        $this->actingAs($this->provider)->post('/opportunities/create', [
            'title' => 'Load Specific Award', 'description' => 'A listing used to exercise the picker.',
            'education_level' => EducationLevel::UNDERGRADUATE, 'country' => 'Zimbabwe',
            'institutions_open_to' => ListingScopes::INSTITUTIONS_ANY, 'open_to' => ListingScopes::OPEN_PROGRAMMES,
            'scope_programmes' => [$programme->id],
        ]);

        $listing = Opportunity::where('title', 'Load Specific Award')->firstOrFail();

        $this->assertSame([$programme->id], $listing->scopes()->whereNotNull('programme_id')->pluck('programme_id')->all());
        $this->assertSame(0, $listing->scopes()->whereNotNull('field_id')->count(), 'a programme never becomes its whole field');
        $this->assertFalse((bool) $listing->open_to_all_programmes);
    }

    public function test_the_fields_and_institutions_lists_are_still_written_in_full(): void
    {
        $html = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();

        $this->assertGreaterThan(30, preg_match_all('#name="scope_fields\[\]"#', $html));
        $this->assertGreaterThan(30, preg_match_all('#name="scope_institutions\[\]"#', $html));
        $this->assertSame(1, substr_count($html, '<template data-picker-reserve>'), 'only the programme list has a reserve');
    }

    public function test_a_suggested_programme_waiting_for_approval_is_still_offered_to_its_suggester(): void
    {
        $field = \App\Models\Field::whereNotNull('parent_id')->orderBy('id')->firstOrFail();
        $suggested = app(\App\Services\Catalogue\ProgrammeCatalogue::class)->suggest($this->provider, 'Bachelor of Picker Testing', EducationLevel::UNDERGRADUATE, $field->id);

        $page = $this->createPage(['education_level' => EducationLevel::UNDERGRADUATE]);

        $this->assertContains($suggested->id, $page['live']);
        $this->assertStringContainsString('Bachelor of Picker Testing (waiting for approval)', $page['html']);
    }
}
