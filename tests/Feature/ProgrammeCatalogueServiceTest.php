<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\Field;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\User;
use App\Services\Catalogue\ProgrammeCatalogue;
use App\Support\EducationLevel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Searching the catalogue, suggesting a programme that is not in it, and what an
 * administrator can then do with the suggestion.
 */
class ProgrammeCatalogueServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProgrammeCatalogue $catalogue;

    private User $student;

    private User $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->catalogue = app(ProgrammeCatalogue::class);
        $this->student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $this->other = User::where('email', 'farai.sibanda@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
    }

    private function field(string $code = '061'): Field
    {
        return Field::where('code', $code)->firstOrFail();
    }

    private function names($programmes): array
    {
        return collect($programmes)->pluck('name')->all();
    }

    // ----------------------------------------------------------------- search --

    public function test_search_finds_a_programme_by_its_name(): void
    {
        $this->assertContains('BSc Computer Science', $this->names($this->catalogue->searchProgrammes('computer sci')));
    }

    public function test_search_finds_a_programme_by_a_synonym(): void
    {
        $this->assertContains('BSc Information Systems', $this->names($this->catalogue->searchProgrammes('BIS')));
        $this->assertContains('BSc Computer Science', $this->names($this->catalogue->searchProgrammes('Comp Sci')));
    }

    public function test_search_can_be_limited_to_one_level(): void
    {
        $found = $this->catalogue->searchProgrammes('accounting', EducationLevel::DIPLOMA);

        $this->assertNotEmpty($found);
        $this->assertSame([EducationLevel::DIPLOMA], collect($found)->pluck('education_level')->unique()->values()->all());
    }

    public function test_a_blank_query_lists_the_level_rather_than_everything(): void
    {
        $found = $this->catalogue->searchProgrammes('', EducationLevel::PHD, null, 100);

        $this->assertNotEmpty($found);
        $this->assertSame([EducationLevel::PHD], collect($found)->pluck('education_level')->unique()->values()->all());
    }

    public function test_percent_and_underscore_in_a_search_are_not_wildcards(): void
    {
        $this->assertSame([], $this->names($this->catalogue->searchProgrammes('%')));
        $this->assertSame([], $this->names($this->catalogue->searchProgrammes('_')));
    }

    public function test_an_inactive_programme_is_not_offered(): void
    {
        Programme::where('name', 'BSc Physics')->update(['is_active' => false]);

        $this->assertNotContains('BSc Physics', $this->names($this->catalogue->searchProgrammes('physics')));
    }

    // ------------------------------------------------------------- suggestions --

    public function test_a_suggested_programme_is_pending_and_only_its_suggester_sees_it(): void
    {
        $programme = $this->catalogue->suggest($this->student, 'BSc Quantum Basketweaving', EducationLevel::UNDERGRADUATE, $this->field('053')->id);

        $this->assertTrue($programme->isPending());
        $this->assertSame($this->student->user_id, $programme->suggested_by);

        $this->assertContains('BSc Quantum Basketweaving', $this->names($this->catalogue->searchProgrammes('basketweaving', null, $this->student)));
        $this->assertNotContains('BSc Quantum Basketweaving', $this->names($this->catalogue->searchProgrammes('basketweaving', null, $this->other)));
        $this->assertNotContains('BSc Quantum Basketweaving', $this->names($this->catalogue->searchProgrammes('basketweaving')));
    }

    public function test_suggesting_something_already_listed_returns_the_listed_one(): void
    {
        $existing = Programme::where('name', 'BSc Information Systems')->firstOrFail();

        $byName = $this->catalogue->suggest($this->student, 'bsc information systems', EducationLevel::UNDERGRADUATE, $this->field()->id);
        $bySynonym = $this->catalogue->suggest($this->student, 'Info Systems', EducationLevel::UNDERGRADUATE, $this->field()->id);

        $this->assertSame($existing->id, $byName->id);
        $this->assertSame($existing->id, $bySynonym->id);
        $this->assertSame(0, Programme::pending()->count());
    }

    public function test_suggesting_the_same_thing_twice_makes_one_row(): void
    {
        $a = $this->catalogue->suggest($this->student, 'BSc Quantum Basketweaving', EducationLevel::UNDERGRADUATE, $this->field('053')->id);
        $b = $this->catalogue->suggest($this->other, 'BSc  Quantum Basketweaving', EducationLevel::UNDERGRADUATE, $this->field('053')->id);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Programme::pending()->count());
    }

    public function test_a_suggestion_is_checked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->catalogue->suggest($this->student, 'ab', EducationLevel::UNDERGRADUATE, $this->field()->id);
    }

    public function test_a_suggestion_needs_a_catalogue_level_and_a_real_field(): void
    {
        foreach ([[EducationLevel::O_LEVEL, 61], [EducationLevel::DIPLOMA, 999999]] as [$level, $fieldId]) {
            try {
                $this->catalogue->suggest($this->student, 'Something Valid', $level, $fieldId);
                $this->fail('expected a refusal');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_one_person_cannot_flood_the_queue(): void
    {
        config(['scholarzim.catalogue.max_pending_per_user' => 2]);
        $this->catalogue->suggest($this->student, 'Programme One', EducationLevel::DIPLOMA, $this->field()->id);
        $this->catalogue->suggest($this->student, 'Programme Two', EducationLevel::DIPLOMA, $this->field()->id);

        $this->expectException(\DomainException::class);
        $this->catalogue->suggest($this->student, 'Programme Three', EducationLevel::DIPLOMA, $this->field()->id);
    }

    public function test_markup_in_a_suggestion_is_stored_as_plain_text(): void
    {
        $programme = $this->catalogue->suggest($this->student, '<script>alert(1)</script> Studies', EducationLevel::DIPLOMA, $this->field()->id);

        $this->assertStringNotContainsString('<', $programme->name);
    }

    // ---------------------------------------------------------- administration --

    public function test_approving_puts_it_in_the_catalogue_for_everyone(): void
    {
        $programme = $this->catalogue->suggest($this->student, 'BSc Quantum Basketweaving', EducationLevel::UNDERGRADUATE, $this->field('053')->id);

        $this->catalogue->approve($programme, $this->admin);

        $this->assertSame(Programme::APPROVED, $programme->fresh()->status);
        $this->assertContains('BSc Quantum Basketweaving', $this->names($this->catalogue->searchProgrammes('basketweaving', null, $this->other)));
        $this->assertDatabaseHas('audit_log', ['actor_email' => $this->admin->email, 'action' => 'CATALOGUE_PROGRAMME_APPROVED']);
    }

    public function test_merging_moves_everything_to_the_existing_programme_and_keeps_the_name_as_a_synonym(): void
    {
        $pending = $this->catalogue->suggest($this->student, 'Computing Science', EducationLevel::UNDERGRADUATE, $this->field()->id);
        $target = Programme::where('name', 'BSc Computer Science')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
        $listing = Opportunity::firstOrFail();

        ApplicantProgramme::create(['profile_id' => $profile->profile_id, 'programme_id' => $pending->id, 'kind' => 'current']);
        OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'programme_id' => $pending->id]);

        $this->catalogue->merge($pending, $target, $this->admin);

        $this->assertSame($target->id, ApplicantProgramme::where('profile_id', $profile->profile_id)->value('programme_id'));
        $this->assertSame($target->id, OpportunityScope::where('opportunity_id', $listing->opportunity_id)->value('programme_id'));
        $this->assertContains('Computing Science', $target->fresh()->synonyms->pluck('synonym')->all());
        $this->assertSame($target->id, $pending->fresh()->merged_into_id);
        $this->assertNotContains('Computing Science', $this->names($this->catalogue->searchProgrammes('Computing Science', null, $this->student)), 'it is a synonym now, not a second row');
        $this->assertContains('BSc Computer Science', $this->names($this->catalogue->searchProgrammes('Computing Science')));
    }

    public function test_merging_when_the_student_already_chose_the_target_leaves_one_row(): void
    {
        $pending = $this->catalogue->suggest($this->student, 'Computing Science', EducationLevel::UNDERGRADUATE, $this->field()->id);
        $target = Programme::where('name', 'BSc Computer Science')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();

        ApplicantProgramme::create(['profile_id' => $profile->profile_id, 'programme_id' => $pending->id, 'kind' => 'intended']);
        ApplicantProgramme::create(['profile_id' => $profile->profile_id, 'programme_id' => $target->id, 'kind' => 'intended']);

        $this->catalogue->merge($pending, $target, $this->admin);

        $this->assertSame(1, ApplicantProgramme::where('profile_id', $profile->profile_id)->count());
    }

    public function test_a_programme_can_only_be_merged_into_one_at_the_same_level(): void
    {
        $pending = $this->catalogue->suggest($this->student, 'Computing Science', EducationLevel::UNDERGRADUATE, $this->field()->id);
        $wrongLevel = Programme::where('name', 'Diploma in Information Technology')->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        $this->catalogue->merge($pending, $wrongLevel, $this->admin);
    }

    public function test_only_a_pending_programme_can_be_approved_merged_or_rejected(): void
    {
        $listed = Programme::where('name', 'BSc Computer Science')->firstOrFail();

        foreach ([
            fn () => $this->catalogue->approve($listed, $this->admin),
            fn () => $this->catalogue->reject($listed, $this->admin),
        ] as $action) {
            try {
                $action();
                $this->fail('expected a refusal');
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rejecting_removes_it_from_everyones_choices(): void
    {
        $pending = $this->catalogue->suggest($this->student, 'Nonsense Studies', EducationLevel::DIPLOMA, $this->field()->id);
        $profile = ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
        ApplicantProgramme::create(['profile_id' => $profile->profile_id, 'programme_id' => $pending->id, 'kind' => 'intended']);

        $this->catalogue->reject($pending, $this->admin);

        $this->assertSame('rejected', $pending->fresh()->status);
        $this->assertSame(0, ApplicantProgramme::where('programme_id', $pending->id)->count());
        $this->assertNotContains('Nonsense Studies', $this->names($this->catalogue->searchProgrammes('Nonsense', null, $this->student)));
    }

    // ---------------------------------------------------------------- reading --

    public function test_a_name_or_synonym_resolves_to_its_programme(): void
    {
        $this->assertSame('BSc Information Systems', $this->catalogue->findProgramme('Info Systems')?->name);
        $this->assertNull($this->catalogue->findProgramme('Underwater Basketry'));
    }

    public function test_a_field_name_resolves_to_its_field(): void
    {
        $this->assertSame('07', $this->catalogue->findField('Engineering, manufacturing and construction')?->code);
        $this->assertSame('061', $this->catalogue->findField('information and communication technologies')?->code);
    }
}
