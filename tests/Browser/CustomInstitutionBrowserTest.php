<?php

namespace Tests\Browser;

use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\EducationLevel;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Naming an institution that is not in the catalogue, from the provider's own form: the option is folded away until
 * wanted, a typed name with its country is saved without touching the catalogue, and it comes back when the listing
 * is edited.
 */
class CustomInstitutionBrowserTest extends DuskTestCase
{
    private const WAIT = 20;

    public function test_a_provider_enters_an_institution_that_is_not_listed_and_finds_it_again_on_edit(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $title = 'Browser Abroad Award ' . uniqid();
        $institutions = Institution::count();

        $this->browse(function (Browser $browser) use ($provider, $title) {
            $browser->loginAs($provider)
                ->visit('/opportunities/create')
                ->waitFor('#field-title', self::WAIT)
                ->type('#field-title', $title)
                ->type('#field-description', 'Covers tuition at a university abroad.')
                ->select('#field-education_level', EducationLevel::UNDERGRADUATE)
                ->select('#field-country', 'South Africa')
                ->script("document.getElementById('open-to-all').click(); document.getElementById('institutions-chosen').click();");

            // Folded away until it is wanted, and the explanation is on offer.
            $browser->assertSeeIn('[data-custom-institutions] summary', 'Institution not listed? Enter institution name')
                ->click('[data-custom-institutions] summary')
                ->waitFor('#listing-custom-name-0', self::WAIT)
                ->type('#listing-custom-name-0', 'University of Cape Town')
                // The country is asked once, above, as the country of study - not again for each institution.
                ->assertMissing('#listing-custom-country-0')
                ->click('#submit-listing') // "Publish" for a trusted provider, "Submit for review" otherwise
                ->waitForLocation('/provider/dashboard', self::WAIT);
        });

        $listing = Opportunity::where('title', $title)->firstOrFail();

        $this->assertSame([['name' => 'University of Cape Town', 'country' => 'South Africa']], $listing->custom_institutions);
        $this->assertSame($institutions, Institution::count(), 'a typed name must not create a catalogue institution');

        $this->browse(function (Browser $browser) use ($provider, $listing) {
            $browser->loginAs($provider)
                ->visit('/opportunities/' . $listing->opportunity_id . '/edit')
                ->waitFor('#field-title', self::WAIT)
                ->assertAttribute('[data-custom-institutions]', 'open', 'true')
                ->assertValue('#listing-custom-name-0', 'University of Cape Town')
                ->assertMissing('#listing-custom-country-0')
                ->assertSelected('#field-country', 'South Africa');
        });
    }

    public function test_a_refused_name_is_explained_beside_the_field_and_kept_on_screen(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->browse(function (Browser $browser) use ($provider) {
            $browser->loginAs($provider)
                ->visit('/opportunities/create')
                ->waitFor('#field-title', self::WAIT)
                ->type('#field-title', 'Browser Refused Award ' . uniqid())
                ->type('#field-description', 'A listing with a meaningless institution name.')
                ->select('#field-education_level', EducationLevel::UNDERGRADUATE)
                ->script("document.getElementById('open-to-all').click(); document.getElementById('institutions-chosen').click();");

            $browser->click('[data-custom-institutions] summary')
                ->waitFor('#listing-custom-name-0', self::WAIT)
                ->type('#listing-custom-name-0', 'n/a')
                ->click('#submit-listing')
                ->waitFor('.invalid-feedback', self::WAIT)
                ->assertSee('does not look like an institution')
                ->assertValue('#listing-custom-name-0', 'n/a');
        });
    }
}
