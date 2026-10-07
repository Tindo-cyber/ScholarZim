<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ListingRiskChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Each red-flag check, with the honest wording that must NOT trip it as much
 * attention as the scam wording that must.
 *
 * A checker that flags "tuition fees covered" flags every genuine scholarship,
 * and moderators learn to ignore it. So the negative cases here are as much the
 * point as the positive ones.
 */
class ListingRiskCheckerTest extends TestCase
{
    // ------------------------------------------------------------ payment --

    #[DataProvider('askingForMoney')]
    public function test_wording_that_asks_applicants_for_money_is_flagged(string $description): void
    {
        $this->assertContains(
            ListingRiskChecker::ASKS_FOR_PAYMENT,
            $this->codes(['description' => $description]),
            $description
        );
    }

    /** @return array<string, array{0: string}> */
    public static function askingForMoney(): array
    {
        return [
            'processing fee' => ['Pay a $20 processing fee to secure your place.'],
            'registration fee' => ['Applicants must pay a registration fee of USD 15 before their form is read.'],
            'application fee noun' => ['An application fee of US$25 applies to every applicant.'],
            'ecocash' => ['Send the fee via EcoCash to 0771234567 to confirm your slot.'],
            'mukuru' => ['Please transfer the amount through Mukuru.'],
            'refundable deposit' => ['A refundable deposit is required to hold your place.'],
            'you will pay' => ['You will pay a small handling fee when you are selected.'],
            'in one breath with coverage' => ['Tuition is fully covered, but applicants must pay a registration fee of USD 20.'],
            'paid by the applicant' => ['The verification fee is paid by the applicant on submission.'],
            'western union' => ['Send money by Western Union to the coordinator.'],
            'clearance fee' => ['A clearance fee must be sent before the award letter is released.'],
        ];
    }

    #[DataProvider('honestWording')]
    public function test_honest_wording_about_fees_is_not_flagged(string $description): void
    {
        $this->assertNotContains(
            ListingRiskChecker::ASKS_FOR_PAYMENT,
            $this->codes(['description' => $description]),
            $description
        );
    }

    /** @return array<string, array{0: string}> */
    public static function honestWording(): array
    {
        return [
            'tuition fees covered' => ['Tuition fees covered.'],
            'fees paid directly to the institution' => ['All fees are paid directly to the institution by the foundation.'],
            'covers tuition fees' => ['This scholarship covers tuition fees, accommodation and books.'],
            'covers application fees' => ['The scholarship also covers application fees and exam fees.'],
            'no application fee' => ['There is no application fee.'],
            'no fees charged' => ['No fees are charged to applicants at any stage.'],
            'free of charge' => ['Applying is free of charge.'],
            'pay nothing' => ['Applicants pay nothing at any stage.'],
            'sponsor pays' => ['The sponsor pays all tuition fees directly.'],
            'sponsor will pay' => ['The sponsor will pay all tuition fees directly to the university.'],
            'fees waived' => ['Registration fees are waived for successful candidates.'],
            'stipend by ecocash' => ['A monthly stipend is paid to the student by EcoCash.'],
            'funded' => ['Fully funded, including all university fees.'],
            'plain description' => ['A bursary for first-year engineering students at a Zimbabwean university.'],
            'tuition and fees mention' => ['Applicants must provide proof of enrolment. Tuition and fees are met in full by the trust.'],
            'bank deposit of award' => ['The award is deposited into the university account each term.'],
        ];
    }

    public function test_the_title_is_read_too(): void
    {
        $this->assertContains(
            ListingRiskChecker::ASKS_FOR_PAYMENT,
            $this->codes(['title' => 'Registration fee required', 'description' => 'A bursary.'])
        );
    }

    public function test_the_message_quotes_the_offending_sentence(): void
    {
        $flags = (new ListingRiskChecker())->check(['description' => 'A bursary. Pay a $20 processing fee to secure your place.']);

        $this->assertStringContainsString('processing fee', $flags[0]['message']);
    }

    // ------------------------------------------------------------ contact --

    public function test_whatsapp_only_contact_is_flagged(): void
    {
        $this->assertContains(
            ListingRiskChecker::PHONE_ONLY_CONTACT,
            $this->codes(['description' => 'Message us on WhatsApp to apply.'])
        );
    }

    #[DataProvider('phoneNumbers')]
    public function test_a_phone_number_alone_is_flagged(string $number): void
    {
        $this->assertContains(
            ListingRiskChecker::PHONE_ONLY_CONTACT,
            $this->codes(['description' => 'To apply, call ' . $number . ' and ask for the coordinator.']),
            $number
        );
    }

    /** @return array<string, array{0: string}> */
    public static function phoneNumbers(): array
    {
        return [
            'local' => ['0771234567'],
            'spaced local' => ['077 123 4567'],
            'international' => ['+263 77 123 4567'],
            'international tight' => ['+263771234567'],
        ];
    }

    public function test_a_phone_number_alongside_an_email_is_fine(): void
    {
        $this->assertNotContains(
            ListingRiskChecker::PHONE_ONLY_CONTACT,
            $this->codes(['description' => 'Email bursaries@trust.org or call 0771234567.'])
        );
    }

    public function test_a_phone_number_alongside_a_link_is_fine(): void
    {
        $this->assertNotContains(
            ListingRiskChecker::PHONE_ONLY_CONTACT,
            $this->codes(['description' => 'Call 0771234567.', 'external_url' => 'https://trust.org/apply'])
        );

        $this->assertNotContains(
            ListingRiskChecker::PHONE_ONLY_CONTACT,
            $this->codes(['description' => 'Apply at https://trust.org/apply or call 0771234567.'])
        );
    }

    public function test_no_contact_details_at_all_is_not_flagged(): void
    {
        $this->assertNotContains(
            ListingRiskChecker::PHONE_ONLY_CONTACT,
            $this->codes(['description' => 'A bursary for engineering students.'])
        );
    }

    public function test_money_amounts_are_not_mistaken_for_phone_numbers(): void
    {
        $this->assertNotContains(
            ListingRiskChecker::PHONE_ONLY_CONTACT,
            $this->codes(['description' => 'An award of 5 000 000 ZWG or USD 10 000 per year, ref 2026-0042.'])
        );
    }

    // ---------------------------------------------------------------- url --

    public function test_a_link_on_the_providers_own_domain_is_fine(): void
    {
        foreach (['https://trust.org/apply', 'https://www.trust.org/apply', 'https://apply.trust.org/form'] as $url) {
            $this->assertNotContains(
                ListingRiskChecker::URL_DOMAIN_MISMATCH,
                $this->codes(['external_url' => $url], $this->provider('admin@trust.org')),
                $url
            );
        }
    }

    public function test_a_link_on_someone_elses_domain_is_flagged(): void
    {
        $flags = (new ListingRiskChecker())->check(['external_url' => 'https://free-bursaries.example.net/apply'], $this->provider('admin@trust.org'));

        $this->assertSame([ListingRiskChecker::URL_DOMAIN_MISMATCH], array_column($flags, 'code'));
        $this->assertStringContainsString('trust.org', $flags[0]['message']);
    }

    public function test_a_lookalike_domain_is_not_a_subdomain(): void
    {
        $this->assertContains(
            ListingRiskChecker::URL_DOMAIN_MISMATCH,
            $this->codes(['external_url' => 'https://nottrust.org/apply'], $this->provider('admin@trust.org'))
        );

        $this->assertContains(
            ListingRiskChecker::URL_DOMAIN_MISMATCH,
            $this->codes(['external_url' => 'https://trust.org.evil.com/apply'], $this->provider('admin@trust.org'))
        );
    }

    public function test_a_free_email_provider_cannot_vouch_for_a_link(): void
    {
        $flags = (new ListingRiskChecker())->check(['external_url' => 'https://gmail.com/x'], $this->provider('someone@gmail.com'));

        $this->assertSame([ListingRiskChecker::URL_DOMAIN_MISMATCH], array_column($flags, 'code'));
        $this->assertStringContainsString('does not identify an organisation', $flags[0]['message']);
    }

    public function test_no_link_means_nothing_to_check(): void
    {
        $this->assertNotContains(ListingRiskChecker::URL_DOMAIN_MISMATCH, $this->codes([], $this->provider('admin@trust.org')));
        $this->assertNotContains(ListingRiskChecker::URL_DOMAIN_MISMATCH, $this->codes(['external_url' => ''], $this->provider('admin@trust.org')));
    }

    public function test_the_link_is_not_checked_without_knowing_who_posted(): void
    {
        $this->assertNotContains(ListingRiskChecker::URL_DOMAIN_MISMATCH, $this->codes(['external_url' => 'https://anywhere.example/apply']));
    }

    // ------------------------------------------------- the two existing ones --

    public function test_an_award_above_the_ceiling_is_flagged(): void
    {
        $this->assertContains(ListingRiskChecker::AWARD_ABOVE_CEILING, $this->codes(['award_amount' => 5000000, 'award_currency' => 'USD']));
        $this->assertNotContains(ListingRiskChecker::AWARD_ABOVE_CEILING, $this->codes(['award_amount' => 5000, 'award_currency' => 'USD']));
    }

    public function test_an_on_behalf_of_name_is_flagged(): void
    {
        $this->assertContains(ListingRiskChecker::ON_BEHALF_OF, $this->codes(['on_behalf_of' => 'Another Trust']));
        $this->assertNotContains(ListingRiskChecker::ON_BEHALF_OF, $this->codes(['on_behalf_of' => null]));
    }

    // ------------------------------------------------------------ overall --

    public function test_an_ordinary_listing_raises_nothing(): void
    {
        $this->assertSame([], (new ListingRiskChecker())->check([
            'title' => 'Engineering Undergraduate Bursary 2026',
            'description' => 'Covers tuition fees and books for a four-year engineering degree. Apply through the link below.',
            'external_url' => 'https://trust.org/apply',
            'award_amount' => 5000,
            'award_currency' => 'USD',
        ], $this->provider('admin@trust.org')));
    }

    public function test_several_reasons_are_all_reported(): void
    {
        $codes = $this->codes([
            'description' => 'Pay a $20 processing fee. WhatsApp 0771234567.',
            'award_amount' => 9999999,
            'award_currency' => 'USD',
            'on_behalf_of' => 'Another Trust',
        ]);

        $this->assertEqualsCanonicalizing(
            [
                ListingRiskChecker::ASKS_FOR_PAYMENT,
                ListingRiskChecker::PHONE_ONLY_CONTACT,
                ListingRiskChecker::AWARD_ABOVE_CEILING,
                ListingRiskChecker::ON_BEHALF_OF,
            ],
            $codes
        );
    }

    public function test_flags_set_by_reports_survive_a_recompute(): void
    {
        $checker = new ListingRiskChecker();
        $existing = [
            ['code' => ListingRiskChecker::REPORTED, 'message' => 'Reported by 3 students.'],
            ['code' => ListingRiskChecker::ON_BEHALF_OF, 'message' => 'old'],
        ];

        $merged = $checker->withStickyFlags($existing, []);

        $this->assertSame([ListingRiskChecker::REPORTED], array_column($merged, 'code'), 'a recompute must not drop the report flag, nor keep a stale text flag');
    }

    // ------------------------------------------------------------- helpers --

    /** @return array<int, string> */
    private function codes(array $listing, ?User $provider = null): array
    {
        return array_column((new ListingRiskChecker())->check($listing, $provider), 'code');
    }

    private function provider(string $email): User
    {
        return new User(['email' => $email, 'full_name' => 'Fixture']);
    }
}
