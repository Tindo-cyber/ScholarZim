<?php

namespace Tests\Browser;

use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\Field;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\User;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The two advisory notes together, as a school-leaver reads them.
 *
 * Tanaka is an A-Level leaver who has chosen to study Computer Science and is not at any university yet. An Engineering
 * degree award to be studied at MSU is outside what he chose and names a place he is not at: both are said to him, in
 * the listing's own page, and neither makes him ineligible. The rules that really are rules - age, the qualification
 * asked for, province, and (for a student already enrolled) the programme they are on - still say NOT ELIGIBLE.
 * The rules themselves are ScholarFitOpenToTest's and StudyDestinationEligibilityTest's; this is the page.
 */
class LeaverPreferenceAndDestinationBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    /** @var array<int, array<string, mixed>> */
    private array $savedProgrammes = [];

    private ?string $savedLevel = null;

    private function tanaka(): User
    {
        return User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->tanaka()->user_id)->firstOrFail();
    }

    /** Tanaka as a leaver who chose Computer Science (or, as an undergraduate, studies it); what he had is put back afterwards. */
    private function chooseComputerScience(string $kind, string $level): void
    {
        $profile = $this->profile();
        $this->savedLevel = $profile->education_level;
        $this->savedProgrammes = ApplicantProgramme::where('profile_id', $profile->profile_id)->get()->map->only(['profile_id', 'programme_id', 'kind'])->all();

        ApplicantProgramme::where('profile_id', $profile->profile_id)->delete();
        $profile->forceFill(['education_level' => $level])->save();

        ApplicantProgramme::create([
            'profile_id' => $profile->profile_id,
            'programme_id' => Programme::where('name', 'BSc Computer Science')->firstOrFail()->id,
            'kind' => $kind,
        ]);
    }

    private function restoreTanaka(): void
    {
        $profile = $this->profile();
        ApplicantProgramme::where('profile_id', $profile->profile_id)->delete();

        foreach ($this->savedProgrammes as $row) {
            ApplicantProgramme::create($row);
        }

        if ($this->savedLevel !== null) {
            $profile->forceFill(['education_level' => $this->savedLevel])->save();
        }
    }

    /** An undergraduate Engineering award to be studied at MSU, plus whatever else is asked of the attributes. */
    private function listing(string $title, array $attributes = []): Opportunity
    {
        $listing = Opportunity::create($attributes + [
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->value('user_id'),
            'provider_name' => 'Browser Provider', 'title' => $title . ' ' . uniqid(),
            'description' => 'An Engineering degree award at Midlands State University.',
            'education_level' => EducationLevel::UNDERGRADUATE, 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'open_to_all_programmes' => false, 'any_institution' => false,
            'deadline' => Carbon::today()->addDays(30), 'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now(),
        ]);

        OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'field_id' => Field::where('code', '071')->firstOrFail()->id]);
        OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'institution_id' => Institution::where('code', 'MSU')->firstOrFail()->id]);

        return $listing;
    }

    public function test_a_leaver_sees_both_notes_on_the_listing_and_is_not_called_ineligible(): void
    {
        $this->chooseComputerScience(ApplicantProgramme::INTENDED, EducationLevel::A_LEVEL);
        $listing = $this->listing('Engineering At MSU Award');

        try {
            $this->browse(function (Browser $browser) use ($listing) {
                $browser->loginAs($this->tanaka())
                    ->visit('/scholarships/' . $listing->opportunity_id)
                    ->waitForText('Outside your chosen programmes', self::WAIT)
                    // The programme preference, in his own words: what he chose and that it does not rule him out.
                    ->assertSee('BSc Computer Science')
                    ->assertSee('This does not rule you out')
                    // The destination, and that he does not have to be there already.
                    ->assertSee('Where you would study')
                    ->assertSee('study at')
                    ->assertSee('Midlands State University')
                    ->assertSee('You do not have to be there already')
                    // Neither is a refusal, and neither asks for anything on his profile.
                    ->assertDontSee('NOT ELIGIBLE')
                    ->assertDontSee('NEEDS INFORMATION')
                    ->assertSee('Apply now');
            });

            $html = $this->profile()->institution_name;
            $this->assertNotSame('Midlands State University', $html, 'he is not at MSU, and does not need to be');
        } finally {
            $this->restoreTanaka();
        }
    }

    public function test_the_rules_that_really_are_rules_still_say_not_eligible(): void
    {
        $this->chooseComputerScience(ApplicantProgramme::INTENDED, EducationLevel::A_LEVEL);

        // Tanaka is 19, from Harare, with an A-Level: each of these asks for something he is not.
        $tooYoung = $this->listing('Under Eighteens Award', ['max_age' => 17]);
        $needsDegree = $this->listing('Needs A Degree Award', ['minimum_education_level' => EducationLevel::UNDERGRADUATE]);
        $otherProvince = $this->listing('Bulawayo Only Award', ['required_province' => 'Bulawayo']);

        try {
            $this->browse(function (Browser $browser) use ($tooYoung, $needsDegree, $otherProvince) {
                $browser->loginAs($this->tanaka());

                foreach ([$tooYoung, $needsDegree, $otherProvince] as $listing) {
                    $browser->visit('/scholarships/' . $listing->opportunity_id)
                        ->waitForText('NOT ELIGIBLE', self::WAIT)
                        ->assertSee('Why you do not qualify');
                }
            });
        } finally {
            $this->restoreTanaka();
        }
    }

    public function test_an_enrolled_student_is_still_refused_a_programme_they_are_not_on(): void
    {
        $this->chooseComputerScience(ApplicantProgramme::CURRENT, EducationLevel::UNDERGRADUATE);
        $listing = $this->listing('Engineering Students Award');

        try {
            $this->browse(function (Browser $browser) use ($listing) {
                $browser->loginAs($this->tanaka())
                    ->visit('/scholarships/' . $listing->opportunity_id)
                    ->waitForText('NOT ELIGIBLE', self::WAIT)
                    // What he is enrolled on is a fact, so it is a refusal and not a preference note; the MSU note is still only a note.
                    ->assertDontSee('Outside your chosen programmes')
                    ->assertSee('Where you would study');
            });
        } finally {
            $this->restoreTanaka();
        }
    }
}
