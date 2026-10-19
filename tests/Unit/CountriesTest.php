<?php

namespace Tests\Unit;

use App\Support\Countries;
use App\Support\FormOptions;
use PHPUnit\Framework\TestCase;

class CountriesTest extends TestCase
{
    public function test_every_code_is_an_iso_alpha_2_code_and_unique(): void
    {
        $codes = Countries::codes();

        $this->assertCount(249, $codes, 'the ISO 3166-1 officially assigned codes');
        $this->assertSame($codes, array_values(array_unique($codes)));

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $code);
        }
    }

    public function test_names_are_unique_and_readable(): void
    {
        $names = array_values(Countries::options());

        $this->assertSame($names, array_values(array_unique($names)));
        $this->assertSame('Zimbabwe', Countries::name(Countries::ZIMBABWE));
        $this->assertSame('United Kingdom', Countries::name('GB'));
        $this->assertSame('South Africa', Countries::name('ZA'));
    }

    public function test_unknown_or_missing_codes_have_no_name_and_are_not_valid(): void
    {
        foreach ([null, '', 'XX', 'zw', 'GBR', 'Zimbabwe'] as $value) {
            $this->assertNull(Countries::name($value));
            $this->assertFalse(Countries::isValid($value));
        }

        $this->assertTrue(Countries::isValid('ZW'));
    }

    /** The listing form's destinations are spelt as they are here, so the two lists never disagree about a country's name. */
    public function test_every_real_study_destination_is_named_as_in_the_country_list(): void
    {
        $names = array_values(Countries::options());

        foreach (array_diff(FormOptions::countries(), FormOptions::FLEXIBLE_COUNTRIES) as $destination) {
            $this->assertContains($destination, $names, $destination . ' is spelt differently in Countries');
        }
    }
}
