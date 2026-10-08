<?php

namespace Tests\Unit;

use App\Models\ApplicantProfile;
use App\Support\EducationLevel;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who counts as a minor, and what a minor must give before applying.
 *
 * Decided from date of birth, not from education level: a 16-year-old undergraduate is a minor and a
 * 25-year-old at O-Level is not. Only when there is no date of birth is the level used as the best guess -
 * a pupil at school (Primary, O-Level, A-Level) is treated as a minor until they say otherwise.
 */
class MinorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-09 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function profile(?string $dob, ?string $level, array $more = []): ApplicantProfile
    {
        return new ApplicantProfile(['date_of_birth' => $dob, 'education_level' => $level] + $more);
    }

    /** @return array<string, array{0: ?string, 1: ?string, 2: bool}> */
    public static function cases(): array
    {
        return [
            'one day short of 18' => ['2008-10-10', EducationLevel::A_LEVEL, true],
            '18 today' => ['2008-10-09', EducationLevel::A_LEVEL, false],
            'long past 18' => ['2000-01-01', EducationLevel::UNDERGRADUATE, false],
            'a 16-year-old undergraduate' => ['2010-03-01', EducationLevel::UNDERGRADUATE, true],
            'a 17-year-old with no level' => ['2009-06-01', null, true],
            'an adult at O-Level' => ['2000-05-05', EducationLevel::O_LEVEL, false],
            'an adult at Primary' => ['1990-05-05', EducationLevel::PRIMARY, false],
            'no birth date, Primary' => [null, EducationLevel::PRIMARY, true],
            'no birth date, O-Level' => [null, EducationLevel::O_LEVEL, true],
            'no birth date, A-Level' => [null, EducationLevel::A_LEVEL, true],
            'no birth date, Diploma' => [null, EducationLevel::DIPLOMA, false],
            'no birth date, Undergraduate' => [null, EducationLevel::UNDERGRADUATE, false],
            'no birth date, Masters' => [null, EducationLevel::MASTERS, false],
            'no birth date, no level' => [null, null, false],
        ];
    }

    #[DataProvider('cases')]
    public function test_who_is_a_minor(?string $dob, ?string $level, bool $minor): void
    {
        $this->assertSame($minor, $this->profile($dob, $level)->isMinor());
    }

    public function test_the_age_of_majority_is_18(): void
    {
        $this->assertSame(18, ApplicantProfile::AGE_OF_MAJORITY);
    }

    public function test_a_legacy_spelling_of_a_school_level_still_counts(): void
    {
        $this->assertTrue($this->profile(null, 'High School (A-Level)')->isMinor());
    }

    // ------------------------------------------------------------ the guardian --

    public function test_every_missing_guardian_detail_is_listed(): void
    {
        $this->assertSame([
            'your guardian\'s full name',
            'your guardian\'s phone number',
            'your relationship to your guardian',
            'the tick confirming a parent or guardian is aware of and involved in your applications',
        ], $this->profile('2010-01-01', EducationLevel::O_LEVEL)->missingGuardianDetails());
    }

    public function test_only_what_is_missing_is_listed(): void
    {
        $profile = $this->profile('2010-01-01', EducationLevel::O_LEVEL, ['guardian_name' => 'Grace Moyo', 'guardian_relationship' => 'Mother']);

        $this->assertSame([
            'your guardian\'s phone number',
            'the tick confirming a parent or guardian is aware of and involved in your applications',
        ], $profile->missingGuardianDetails());
    }

    public function test_nothing_is_missing_when_all_four_are_given(): void
    {
        $profile = $this->profile('2010-01-01', EducationLevel::O_LEVEL, [
            'guardian_name' => 'Grace Moyo', 'guardian_phone' => '0771234567', 'guardian_relationship' => 'Mother',
            'guardian_confirmed_at' => now(),
        ]);

        $this->assertSame([], $profile->missingGuardianDetails());
    }

    public function test_blank_or_spaces_do_not_count_as_given(): void
    {
        $profile = $this->profile('2010-01-01', EducationLevel::O_LEVEL, [
            'guardian_name' => '   ', 'guardian_phone' => '', 'guardian_relationship' => ' ', 'guardian_confirmed_at' => now(),
        ]);

        $this->assertCount(3, $profile->missingGuardianDetails());
    }
}
