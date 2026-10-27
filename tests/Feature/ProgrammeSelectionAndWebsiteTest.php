<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\ApplicantTargetField;
use App\Models\Field;
use App\Models\Opportunity;
use App\Models\Programme;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\Catalogue\ProgrammeCatalogue;
use App\Support\EducationLevel;
use App\Support\WebsiteAddress;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Three things the programme pickers and the public listing page must get right:
 *
 *   - nothing is listed twice (a field, a programme) in a provider's or a student's form;
 *   - "up to three" is chosen in one submission, and the server is the one that enforces it;
 *   - the organisation's website reaches the student-facing scholarship page, safely.
 */
class ProgrammeSelectionAndWebsiteTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------------------------- helpers --

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function level(string $level): void
    {
        $this->profile()->forceFill(['education_level' => $level])->save();
    }

    private function programme(string $name): int
    {
        return Programme::where('name', $name)->value('id');
    }

    private function save(array $data)
    {
        return $this->actingAs($this->student)->post('/applicant/profile/programmes', $data);
    }

    /** @return array<int, int> */
    private function saved(string $kind = 'intended'): array
    {
        return ApplicantProgramme::where('profile_id', $this->profile()->profile_id)->where('kind', $kind)->orderBy('programme_id')->pluck('programme_id')->all();
    }

    /** @return array<int, int> four different programmes a school-leaver may choose */
    private function four(): array
    {
        return array_map($this->programme(...), ['BSc Computer Science', 'Bachelor of Laws', 'BSc Physics', 'BSc Chemistry']);
    }

    private function publicListing(?User $provider = null, array $attributes = []): Opportunity
    {
        $provider ??= $this->provider;
        $base = Opportunity::where('provider_user_id', $this->provider->user_id)->publiclyVisible()->firstOrFail();

        if ($provider->user_id === $this->provider->user_id && $attributes === []) {
            return $base;
        }

        $copy = $base->replicate();
        $copy->forceFill(array_merge([
            'provider_user_id' => $provider->user_id,
            'provider_name' => $provider->full_name,
            'on_behalf_of' => null,
            'title' => 'Website probe ' . $provider->user_id,
            'deadline' => Carbon::today()->addMonth(),
        ], $attributes))->save();

        return $copy;
    }

    private function setWebsite(User $provider, ?string $website, bool $confirmed = false): void
    {
        ProviderProfile::where('user_id', $provider->user_id)->update([
            'website' => $website,
            'website_verified_at' => $confirmed ? Carbon::now() : null,
        ]);
    }

    private function otherProvider(): User
    {
        return User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------------ nothing is listed twice --

    public function test_the_catalogue_has_no_programme_twice(): void
    {
        $options = app(ProgrammeCatalogue::class)->formOptions($this->provider)['programmes'];

        $this->assertSame($options->pluck('id')->unique()->count(), $options->count(), 'the same record is listed once');

        $keys = $options->map(fn (Programme $p) => Programme::normalise($p->name) . '|' . $p->education_level);
        $this->assertSame($keys->unique()->count(), $keys->count(), 'no two entries share a name and level');
    }

    public function test_programmes_that_share_a_name_at_different_levels_stay_available(): void
    {
        $field = Field::whereNotNull('parent_id')->firstOrFail();
        Programme::create(['name' => 'Twin Studies', 'education_level' => EducationLevel::CERTIFICATE, 'field_id' => $field->id]);
        Programme::create(['name' => 'Twin Studies', 'education_level' => EducationLevel::DIPLOMA, 'field_id' => $field->id]);

        $options = app(ProgrammeCatalogue::class)->formOptions()['programmes']->where('name', 'Twin Studies');

        $this->assertCount(2, $options, 'a Certificate and a Diploma of the same name are different programmes');
    }

    public function test_the_same_name_at_the_same_level_cannot_be_stored_twice(): void
    {
        $existing = Programme::firstOrFail();

        $this->expectException(\Illuminate\Database\QueryException::class);

        Programme::create(['name' => $existing->name, 'education_level' => $existing->education_level, 'field_id' => $existing->field_id]);
    }

    public function test_a_students_programme_list_shows_each_programme_once(): void
    {
        $this->level(EducationLevel::A_LEVEL);

        $html = $this->actingAs($this->student)->get('/applicant/profile')->assertOk()->getContent();

        preg_match_all('/name="intended_programme_ids\[\]" value="(\d+)"/', $html, $found);

        $this->assertNotEmpty($found[1]);
        $this->assertSame(count($found[1]), count(array_unique($found[1])), 'a programme is not offered twice');
        $this->assertSame(Programme::inCatalogue()->whereIn('education_level', \App\Services\Catalogue\ApplicantProgrammes::LEAVER_LEVELS)->count(), count($found[1]));
    }

    public function test_a_providers_form_lists_each_field_and_programme_once(): void
    {
        // With a level chosen, the lists write that level's choices live. (With none, they all wait in an inert reserve.)
        $html = $this->actingAs($this->provider)
            ->withSession(['_old_input' => ['education_level' => 'UNDERGRADUATE']])
            ->get('/opportunities/create')->assertOk()->getContent();

        foreach (['scope_programmes', 'scope_fields', 'scope_institutions'] as $list) {
            $live = preg_replace('#<template.*?</template>#s', '', $html) ?? '';
            preg_match_all('/name="' . $list . '\[\]" value="(\d+)"/', $live, $values);

            $this->assertNotEmpty($values[1], $list);
            $this->assertSame(count($values[1]), count(array_unique($values[1])), $list . ' lists an id twice');
        }
    }

    public function test_a_broad_field_with_one_narrow_field_of_the_same_name_is_listed_once(): void
    {
        $html = $this->actingAs($this->provider)
            ->withSession(['_old_input' => ['education_level' => 'UNDERGRADUATE']])
            ->get('/opportunities/create')->assertOk()->getContent();

        // The fields picker only: the programme picker has groups with the same words in their headings.
        preg_match('#<fieldset[^>]*id="scope-fields".*?</fieldset>#s', $html, $picker);
        preg_match('/mt-1">Undergraduate · Education<\/div>(.*?)(?=<div data-picker-group|<div class="small text-secondary" data-picker-empty)/s', $picker[0], $group);

        $this->assertSame(1, substr_count($group[1], 'type="checkbox"'), 'Education is not offered as both "All of Education" and "Education"');
        $this->assertStringNotContainsString('All of Education', $picker[0]);
        // A broad field that mixes disciplines is a heading only: its fields are offered, not "All of" it.
        $this->assertStringNotContainsString('All of Engineering and construction', $picker[0]);
        $this->assertStringContainsString('Electrical, mechanical and other engineering', $picker[0]);
    }

    public function test_the_picker_keeps_whichever_of_the_two_fields_was_saved(): void
    {
        $broad = Field::where('code', '01')->firstOrFail();
        $narrow = Field::where('code', '011')->firstOrFail();
        $groups = fn (array $selected) => collect(Field::pickerGroups(Field::whereNull('parent_id')->with('children')->orderBy('code')->get(), $selected))
            ->firstWhere('label', 'Education')['options'];

        $none = $groups([]);
        $this->assertCount(1, $none);
        $this->assertSame($narrow->id, $none[0]['id'], 'a new choice is the narrow field, the stricter reading');
        $this->assertFalse($none[0]['selected']);

        $onlyBroad = $groups([$broad->id]);
        $this->assertSame($broad->id, $onlyBroad[0]['id'], 'a listing saved with the broad field keeps it');
        $this->assertTrue($onlyBroad[0]['selected']);

        $onlyNarrow = $groups([$narrow->id]);
        $this->assertSame($narrow->id, $onlyNarrow[0]['id']);
        $this->assertTrue($onlyNarrow[0]['selected']);
    }

    public function test_a_saved_scope_loads_ticked_on_the_edit_form_and_keeps_its_own_field_id(): void
    {
        $listing = Opportunity::where('provider_user_id', $this->provider->user_id)->firstOrFail();
        $listing->scopes()->delete();
        $broad = Field::where('code', '01')->firstOrFail();
        $engineering = Field::where('code', '07')->firstOrFail();
        $programme = Programme::inCatalogue()->firstOrFail();
        foreach ([['field_id' => $broad->id], ['field_id' => $engineering->id], ['programme_id' => $programme->id]] as $row) {
            \App\Models\OpportunityScope::create(['opportunity_id' => $listing->opportunity_id] + $row);
        }

        $html = $this->actingAs($this->provider)->get('/opportunities/' . $listing->opportunity_id . '/edit')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="scope_fields\[\]" value="' . $broad->id . '"[^>]*checked/s', $html, 'the saved broad Education id is the one offered, and ticked');
        $this->assertMatchesRegularExpression('/name="scope_fields\[\]" value="' . $engineering->id . '"[^>]*checked/s', $html);
        $this->assertMatchesRegularExpression('/name="scope_programmes\[\]" value="' . $programme->id . '"[^>]*checked/s', $html);
        $this->assertSame(3, $listing->scopes()->count(), 'viewing the form changes nothing');
    }

    public function test_field_of_study_is_not_asked_twice_in_the_same_words(): void
    {
        $provider = $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()->getContent();
        $this->assertSame(0, preg_match_all('/>\s*Field of study\s*</', $provider), 'the free-text box is not also called "Field of study"');
        $this->assertSame(1, substr_count($provider, '>2. Which programmes?</legend>'), 'one question, not a legend and a label saying the same');
    }

    public function test_a_students_field_of_study_is_the_programme_list_not_a_free_text_box(): void
    {
        $this->level(EducationLevel::UNDERGRADUATE);

        $html = $this->actingAs($this->student)->get('/applicant/profile')->assertOk()->getContent();

        $this->assertStringContainsString('Field of study (your programme)', $html);
        $this->assertStringNotContainsString('Your field, in your own words', $html);
        $this->assertStringNotContainsString('name="field_of_study"', $html);
        $this->assertStringContainsString('name="current_programme_id"', $html);
    }

    // ----------------------------------------------------- up to three, at once --

    public function test_one_two_or_three_programmes_can_be_saved_in_one_submission(): void
    {
        $this->level(EducationLevel::A_LEVEL);
        $ids = $this->four();

        foreach ([1, 2, 3] as $count) {
            $chosen = array_slice($ids, 0, $count);
            $this->save(['intended_programme_ids' => $chosen])->assertSessionHasNoErrors();

            $expected = $chosen;
            sort($expected);
            $this->assertSame($expected, $this->saved(), "$count saved together");
        }
    }

    public function test_four_are_refused_with_a_message_and_nothing_is_saved_or_trimmed(): void
    {
        $this->level(EducationLevel::A_LEVEL);

        $response = $this->save(['intended_programme_ids' => $this->four()]);

        $response->assertSessionHasErrors('intended_programme_ids');
        $this->assertStringContainsString('Choose up to 3', session('errors')->first('intended_programme_ids'));
        $this->assertSame([], $this->saved(), 'not even the first three were kept');
    }

    public function test_a_refused_fourth_leaves_what_was_already_saved_untouched(): void
    {
        $this->level(EducationLevel::A_LEVEL);
        $ids = $this->four();
        $this->save(['intended_programme_ids' => array_slice($ids, 0, 2)])->assertSessionHasNoErrors();
        $before = $this->saved();

        $this->save(['intended_programme_ids' => $ids])->assertSessionHasErrors('intended_programme_ids');

        $this->assertSame($before, $this->saved());
    }

    public function test_a_repeated_id_is_counted_once_and_four_different_ones_are_still_refused(): void
    {
        $this->level(EducationLevel::A_LEVEL);
        [$a, $b, $c, $d] = $this->four();

        $this->save(['intended_programme_ids' => [$a, $a, $a, $b, $c]])->assertSessionHasNoErrors();
        $this->assertCount(3, $this->saved(), 'repeats are normalised away, not stored twice');

        $this->save(['intended_programme_ids' => [$a, $a, $b, $c, $d]])->assertSessionHasErrors('intended_programme_ids');
    }

    public function test_submitting_again_replaces_the_selection_and_never_adds_to_it(): void
    {
        $this->level(EducationLevel::A_LEVEL);
        [$a, $b, $c, $d] = $this->four();

        $this->save(['intended_programme_ids' => [$a, $b, $c]])->assertSessionHasNoErrors();
        $this->save(['intended_programme_ids' => [$b, $c, $d]])->assertSessionHasNoErrors();
        $this->save(['intended_programme_ids' => [$b, $c, $d]])->assertSessionHasNoErrors();

        $expected = [$b, $c, $d];
        sort($expected);
        $this->assertSame($expected, $this->saved(), 'three, never six');
    }

    public function test_the_saved_choices_load_ticked_and_can_be_swapped_before_saving(): void
    {
        $this->level(EducationLevel::A_LEVEL);
        [$a, $b, $c, $d] = $this->four();
        $this->save(['intended_programme_ids' => [$a, $b, $c]])->assertSessionHasNoErrors();

        $html = $this->actingAs($this->student)->get('/applicant/profile')->assertOk()->getContent();

        preg_match_all('/<input[^>]*name="intended_programme_ids\[\]" value="(\d+)"[^>]*checked/', $html, $ticked);
        $this->assertEqualsCanonicalizing([$a, $b, $c], array_map('intval', $ticked[1]));
        $this->assertStringContainsString('data-max="3"', $html);
        $this->assertStringContainsString('Tick up to 3', $html);
        $this->assertStringNotContainsString('Hold Ctrl', $html);

        $this->save(['intended_programme_ids' => [$a, $b, $d]])->assertSessionHasNoErrors();
        $expected = [$a, $b, $d];
        sort($expected);
        $this->assertSame($expected, $this->saved(), 'one swapped for another');
    }

    public function test_nothing_ticked_clears_the_selection(): void
    {
        $this->level(EducationLevel::A_LEVEL);
        $this->save(['intended_programme_ids' => $this->four() ? array_slice($this->four(), 0, 2) : []]);

        $this->save([])->assertSessionHasNoErrors();

        $this->assertSame([], $this->saved());
    }

    public function test_a_direct_request_with_junk_in_the_list_is_refused_not_a_server_error(): void
    {
        $this->level(EducationLevel::A_LEVEL);

        $this->save(['intended_programme_ids' => ['abc', '', -5]])->assertSessionHasErrors();
        $this->save(['intended_programme_ids' => 'not-a-list'])->assertSessionHasErrors();

        $this->assertSame([], $this->saved());
    }

    public function test_three_fields_to_study_next_save_together_and_four_are_refused(): void
    {
        $this->level(EducationLevel::UNDERGRADUATE);
        $fields = Field::whereNotNull('parent_id')->orderBy('id')->limit(4)->pluck('id')->all();

        $this->save(['target_field_ids' => array_slice($fields, 0, 3)])->assertSessionHasNoErrors();
        $this->assertSame(3, ApplicantTargetField::where('profile_id', $this->profile()->profile_id)->count());

        $this->save(['target_field_ids' => $fields])->assertSessionHasErrors('target_field_ids');
        $this->assertSame(3, ApplicantTargetField::where('profile_id', $this->profile()->profile_id)->count(), 'the earlier three stand');
    }

    public function test_the_provider_form_is_not_capped_at_three_programmes(): void
    {
        // A scholarship can fund many programmes (ListingScopes::MAX_PROGRAMMES). The three-programme rule is the
        // student's, and was not copied onto the provider's separate "who is it open to" list.
        $this->assertGreaterThan(3, \App\Services\Catalogue\ListingScopes::MAX_PROGRAMMES);
    }

    public function test_a_student_cannot_save_another_students_selection_through_the_form(): void
    {
        $this->level(EducationLevel::A_LEVEL);
        $other = User::where('email', '!=', $this->student->email)
            ->whereHas('applicantProfile')->first();

        if ($other === null) {
            $this->markTestSkipped('no second student seeded');
        }

        $this->save(['intended_programme_ids' => [$this->four()[0]], 'profile_id' => $other->applicantProfile->profile_id])->assertSessionHasNoErrors();

        $this->assertSame(0, ApplicantProgramme::where('profile_id', $other->applicantProfile->profile_id)->count());
    }

    public function test_only_students_can_use_the_programme_form(): void
    {
        $this->actingAs($this->provider)->post('/applicant/profile/programmes', ['intended_programme_ids' => [$this->four()[0]]])->assertForbidden();
        auth()->logout();
        $this->post('/applicant/profile/programmes', [])->assertRedirect();
    }

    // ------------------------------------------------- organisation website --

    public function test_a_providers_website_is_shown_on_the_student_facing_scholarship_page(): void
    {
        $this->setWebsite($this->provider, 'https://www.zimedtrust.org/about');
        $listing = $this->publicListing();

        $html = $this->actingAs($this->student)->get(route('scholarships.show', $listing->opportunity_id))->assertOk()->getContent();

        $this->assertStringContainsString('Visit organisation website', $html);
        $this->assertMatchesRegularExpression('~<a href="https://www\.zimedtrust\.org/about"[^>]*target="_blank"[^>]*rel="noopener noreferrer nofollow"~s', $html);
    }

    public function test_a_visitor_who_is_not_signed_in_sees_it_too(): void
    {
        $this->setWebsite($this->provider, 'https://zimedtrust.org');

        $this->get(route('scholarships.show', $this->publicListing()->opportunity_id))
            ->assertOk()
            ->assertSee('Visit organisation website')
            ->assertSee('href="https://zimedtrust.org"', false);
    }

    public function test_each_listing_shows_its_own_organisations_website(): void
    {
        $other = $this->otherProvider();
        $this->setWebsite($this->provider, 'https://first-org.example');
        $this->setWebsite($other, 'https://second-org.example');
        $first = $this->publicListing();
        $second = $this->publicListing($other, ['status' => $first->status, 'moderation_status' => $first->moderation_status]);

        $a = $this->get(route('scholarships.show', $first->opportunity_id))->assertOk()->getContent();
        $b = $this->get(route('scholarships.show', $second->opportunity_id))->assertOk()->getContent();

        $this->assertStringContainsString('https://first-org.example', $a);
        $this->assertStringNotContainsString('second-org.example', $a);
        $this->assertStringContainsString('https://second-org.example', $b);
        $this->assertStringNotContainsString('first-org.example', $b);
    }

    public function test_a_listing_posted_on_behalf_of_another_shows_the_publishers_website_and_says_who_posted_it(): void
    {
        $this->setWebsite($this->provider, 'https://publisher.example');
        $listing = $this->publicListing($this->provider, ['on_behalf_of' => 'Some Other Trust']);

        $this->get(route('scholarships.show', $listing->opportunity_id))
            ->assertOk()
            ->assertSee('Posted by ' . $this->provider->full_name . ' on behalf of Some Other Trust')
            ->assertSee('https://publisher.example');
    }

    public function test_no_website_means_no_website_section_and_the_page_still_works(): void
    {
        $this->setWebsite($this->provider, null);

        $this->get(route('scholarships.show', $this->publicListing()->opportunity_id))
            ->assertOk()
            ->assertDontSee('Visit organisation website')
            ->assertDontSee('id="organisation-website"', false);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeWebsites')]
    public function test_a_stored_value_that_is_not_a_safe_web_address_is_never_made_a_link(string $stored): void
    {
        $this->setWebsite($this->provider, $stored);

        $this->get(route('scholarships.show', $this->publicListing()->opportunity_id))
            ->assertOk()
            ->assertDontSee('Visit organisation website')
            ->assertDontSee('javascript:', false);
    }

    public static function unsafeWebsites(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'ftp' => ['ftp://files.example.org'],
            'login in the address' => ['https://trusted.org@evil.example'],
            'no dot' => ['https://localhost'],
            'spaces' => ['https://exa mple.org'],
            'plain words' => ['not a website'],
            'markup' => ['https://example.org/"><script>'],
        ];
    }

    public function test_an_international_domain_is_accepted_and_linked(): void
    {
        $this->assertSame('https://bücher.example', WebsiteAddress::normalise('bücher.example'));
        $this->assertSame('https://bücher.example', WebsiteAddress::publicLink('bücher.example'));
        $this->assertSame('https://example.co.zw/path?x=1', WebsiteAddress::publicLink('https://example.co.zw/path?x=1'));
        $this->assertSame('https://sub.example.ac.uk', WebsiteAddress::publicLink('sub.example.ac.uk'));
        $this->assertNull(WebsiteAddress::publicLink(null));
        $this->assertNull(WebsiteAddress::publicLink('   '));

        $this->setWebsite($this->provider, 'https://bücher.example');
        $this->get(route('scholarships.show', $this->publicListing()->opportunity_id))->assertOk()->assertSee('Visit organisation website');
    }

    public function test_a_confirmed_website_says_so_and_an_unconfirmed_one_does_not(): void
    {
        $listing = $this->publicListing();

        $this->setWebsite($this->provider, 'https://zimedtrust.org', confirmed: false);
        $this->get(route('scholarships.show', $listing->opportunity_id))->assertOk()->assertDontSee('confirmed by ScholarZim');

        $this->setWebsite($this->provider, 'https://zimedtrust.org', confirmed: true);
        $this->get(route('scholarships.show', $listing->opportunity_id))->assertOk()->assertSee('confirmed by ScholarZim');
    }

    public function test_the_public_page_exposes_the_website_and_nothing_else_from_the_registration(): void
    {
        $this->setWebsite($this->provider, 'https://zimedtrust.org');
        $profile = ProviderProfile::where('user_id', $this->provider->user_id)->firstOrFail();

        $html = $this->get(route('scholarships.show', $this->publicListing()->opportunity_id))->assertOk()->getContent();

        foreach ([
            $this->provider->email,
            $this->provider->phone,
            $profile->registration_number,
            $profile->contact_name,
            $profile->certificate_path,
            $profile->certificate_filename,
        ] as $private) {
            $this->assertNotEmpty($private);
            $this->assertStringNotContainsString((string) $private, $html);
        }
    }

    public function test_provider_registration_and_review_pages_stay_closed_to_students_and_visitors(): void
    {
        $this->get('/admin/providers/' . $this->provider->user_id)->assertRedirect();
        $this->actingAs($this->student)->get('/admin/providers/' . $this->provider->user_id)->assertForbidden();
    }

    public function test_an_unpublished_listing_does_not_leak_its_providers_website(): void
    {
        $this->setWebsite($this->provider, 'https://hidden.example');
        $listing = $this->publicListing();
        $listing->forceFill(['moderation_status' => \App\Support\OpportunityModerationStatus::PENDING])->save();

        $this->get(route('scholarships.show', $listing->opportunity_id))->assertNotFound();
    }

    public function test_a_stored_provider_website_is_shown_on_the_listing(): void
    {
        // Providers can no longer add one themselves; one already on record is still shown.
        ProviderProfile::where('user_id', $this->provider->user_id)->update(['website' => 'https://www.zimedtrust.org']);

        auth()->logout();
        $this->get(route('scholarships.show', $this->publicListing()->opportunity_id))->assertOk()->assertSee('https://www.zimedtrust.org');
    }
}
