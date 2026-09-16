<?php

namespace App\Support\Academic;

use App\Support\EducationLevel;

/**
 * The reference catalogue: every qualification ScholarZim recognises, the
 * grading each one awards, and the subjects sat under it.
 *
 * This is the single definition. The data migration seeds a fresh database
 * from it, AcademicQualificationSeeder re-runs it locally, and validation
 * derives its allowed values from the rows it produced. Nothing else may
 * declare a qualification, a grade symbol or a point value - the audit that
 * preceded this file found ZIMSEC A-Level points defined in one place,
 * assumed in a second and tested in neither, which is how A-Level came to be
 * worth 12 points a grade.
 *
 * Eight families, deliberately kept apart:
 *
 *   zimbabwe-primary    Grade 7 learning areas, unit-marked, no points
 *   zimsec-o-level      symbol results, ranked, no points
 *   zimsec-a-level      symbol results, ranked, A=5 .. E=1  <- the only points
 *   cambridge-o-level   Cambridge's own A*-G scale, no points
 *   cambridge-igcse     Cambridge's own A*-G scale, no points
 *   cambridge-as-level  Cambridge's own lower-case a-e scale, no points
 *   cambridge-a-level   Cambridge's own A*-E scale, no points
 *   tertiary            degree classifications, recorded on the profile
 *
 * A Cambridge grade is never given a ZIMSEC point value. Comparing a Cambridge
 * result against a ZIMSEC requirement is not possible through this catalogue,
 * and that is the intended behaviour: an equivalence between the two boards is
 * a policy decision this product has not made, and inventing one by putting
 * both on the same numeric scale is exactly what the previous implementation
 * did by accident.
 *
 * Subject codes are official syllabus numbers for Cambridge, where they exist
 * and are verifiable. ZIMSEC and Primary rows carry no code rather than an
 * invented mnemonic that would read as an official one.
 */
final class AcademicCatalogue
{
    public const ZIMBABWE_PRIMARY = 'zimbabwe-primary';

    public const ZIMSEC_O_LEVEL = 'zimsec-o-level';

    public const ZIMSEC_A_LEVEL = 'zimsec-a-level';

    public const CAMBRIDGE_O_LEVEL = 'cambridge-o-level';

    public const CAMBRIDGE_IGCSE = 'cambridge-igcse';

    public const CAMBRIDGE_AS_LEVEL = 'cambridge-as-level';

    public const CAMBRIDGE_A_LEVEL = 'cambridge-a-level';

    public const TERTIARY = 'tertiary';

    /**
     * The most A-Level subjects a single applicant is recorded as sitting.
     *
     * This is what bounds a scholarship's minimum-points rule: the ceiling is
     * derived from this times the best grade's value, rather than the flat 60
     * the provider form used to accept - a number that belonged to no scale
     * anyone could name.
     */
    public const MAX_A_LEVEL_SUBJECTS = 5;

    private function __construct()
    {
    }

    /**
     * Every qualification, in display order.
     *
     * @return array<int, array{key: string, system: string, name: string, description: string, education_level: string, ordering: int, scheme: GradingScheme}>
     */
    public static function qualifications(): array
    {
        return [
            [
                'key' => self::ZIMBABWE_PRIMARY,
                'system' => 'ZIMSEC',
                'name' => 'Zimbabwe Primary (Grade 7)',
                'description' => 'Zimbabwe primary school leaving examination, marked in units per learning area.',
                'education_level' => EducationLevel::PRIMARY,
                'ordering' => 10,
                // Grade 7 learning areas are marked in units from 1 (best) to
                // 9. Units are not points and are never summed into any
                // secondary total - a Primary applicant has no A-Level points
                // and must not be given a number that looks like some.
                'scheme' => GradingScheme::make(
                    ['1', '2', '3', '4', '5', '6', '7', '8', '9'],
                    [],
                    GradingScheme::TYPE_ASSESSMENT,
                ),
            ],
            [
                'key' => self::ZIMSEC_O_LEVEL,
                'system' => 'ZIMSEC',
                'name' => 'ZIMSEC Ordinary Level',
                'description' => 'Zimbabwe School Examinations Council Ordinary Level. Results are symbols, not a point total.',
                'education_level' => EducationLevel::O_LEVEL,
                'ordering' => 20,
                // Ranked so "Mathematics at C or better" can be tested, with
                // no point values so nothing can be summed from it.
                'scheme' => GradingScheme::make(['A', 'B', 'C', 'D', 'E', 'F', 'U']),
            ],
            [
                'key' => self::ZIMSEC_A_LEVEL,
                'system' => 'ZIMSEC',
                'name' => 'ZIMSEC Advanced Level',
                'description' => 'Zimbabwe School Examinations Council Advanced Level. Points are derived from grades: A=5, B=4, C=3, D=2, E=1.',
                'education_level' => EducationLevel::A_LEVEL,
                'ordering' => 30,
                // The one point scale in the product. Applicants never type
                // these numbers; they are derived from the grade at save time.
                'scheme' => GradingScheme::make(
                    ['A', 'B', 'C', 'D', 'E', 'F', 'U'],
                    ['A' => 5, 'B' => 4, 'C' => 3, 'D' => 2, 'E' => 1, 'F' => 0, 'U' => 0],
                ),
            ],
            [
                'key' => self::CAMBRIDGE_O_LEVEL,
                'system' => 'Cambridge',
                'name' => 'Cambridge O Level',
                'description' => 'Cambridge Assessment International Education Ordinary Level, graded A*-G.',
                'education_level' => EducationLevel::O_LEVEL,
                'ordering' => 40,
                'scheme' => GradingScheme::make(['A*', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'U']),
            ],
            [
                'key' => self::CAMBRIDGE_IGCSE,
                'system' => 'Cambridge',
                'name' => 'Cambridge IGCSE',
                'description' => 'Cambridge International General Certificate of Secondary Education, graded A*-G.',
                'education_level' => EducationLevel::O_LEVEL,
                'ordering' => 50,
                // Cambridge also issues IGCSE on a 9-1 scale in some subjects
                // and regions. That is a separate scheme, not a mixture of
                // this one: it belongs in its own catalogue row so ranks stay
                // meaningful. See docs note in the final report.
                'scheme' => GradingScheme::make(['A*', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'U']),
            ],
            [
                'key' => self::CAMBRIDGE_AS_LEVEL,
                'system' => 'Cambridge',
                'name' => 'Cambridge AS Level',
                'description' => 'Cambridge International Advanced Subsidiary Level, graded a-e.',
                'education_level' => EducationLevel::A_LEVEL,
                'ordering' => 60,
                // Cambridge reports AS grades in lower case precisely so they
                // are not mistaken for A Level grades. Stored as issued.
                'scheme' => GradingScheme::make(['a', 'b', 'c', 'd', 'e', 'u']),
            ],
            [
                'key' => self::CAMBRIDGE_A_LEVEL,
                'system' => 'Cambridge',
                'name' => 'Cambridge International A Level',
                'description' => 'Cambridge Assessment International Education Advanced Level, graded A*-E.',
                'education_level' => EducationLevel::A_LEVEL,
                'ordering' => 70,
                'scheme' => GradingScheme::make(['A*', 'A', 'B', 'C', 'D', 'E', 'U']),
            ],
            [
                'key' => self::TERTIARY,
                'system' => 'Tertiary',
                'name' => 'University degree classification',
                'description' => 'Tertiary degree classification, recorded once on the profile rather than subject by subject.',
                'education_level' => EducationLevel::UNDERGRADUATE,
                'ordering' => 80,
                // Ranked so a classification can be compared, unpointed so it
                // can never be added to an A-Level total. Recorded on
                // applicant_profiles.degree_classification, not as subject
                // results - a degree class is one fact about a person, not a
                // score per module.
                'scheme' => GradingScheme::make([
                    'First Class',
                    'Upper Second (2:1)',
                    'Lower Second (2:2)',
                    'Third Class',
                    'Pass',
                    'Fail',
                ]),
            ],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_column(self::qualifications(), 'key');
    }

    /** @return array{key: string, system: string, name: string, description: string, education_level: string, ordering: int, scheme: GradingScheme}|null */
    public static function qualification(string $key): ?array
    {
        foreach (self::qualifications() as $qualification) {
            if ($qualification['key'] === $key) {
                return $qualification;
            }
        }

        return null;
    }

    public static function scheme(string $key): ?GradingScheme
    {
        return self::qualification($key)['scheme'] ?? null;
    }

    /**
     * The highest ZIMSEC A-Level total an applicant can hold, derived from the
     * grade scale rather than assumed. Used to bound the provider's minimum
     * points field.
     */
    public static function maxZimsecALevelPoints(): int
    {
        $scheme = self::scheme(self::ZIMSEC_A_LEVEL);

        return (int) round(($scheme?->maxSubjectPoints() ?? 0.0) * self::MAX_A_LEVEL_SUBJECTS);
    }

    /**
     * Cambridge's 9-1 scale, best first, as used on the IGCSE syllabuses that
     * report on it.
     *
     * Held apart from the A*-G scale rather than merged with it. There is no
     * position at which a 7 sits among A*..G, so one combined ordered list
     * would make every rank comparison meaningless, and asserting where the
     * two meet would be inventing an equivalence Cambridge does not publish.
     * A requirement stated in one scale is simply not testable against a
     * result in the other, and the evaluator says so rather than guessing.
     */
    public static function igcseNineToOneScheme(): GradingScheme
    {
        return GradingScheme::make(['9', '8', '7', '6', '5', '4', '3', '2', '1', 'U']);
    }

    /**
     * Subjects for one qualification, in display order.
     *
     * Each entry is [name, code] and may carry a `scheme` of its own, which
     * overrides the qualification's for that subject alone. Only the Cambridge
     * IGCSE 9-1 syllabuses use that today.
     *
     * The lists are starting points a provider or an administrator can extend -
     * the catalogue is database-backed precisely so it is not bounded by what
     * is written here.
     *
     * @return array<int, array{name: string, code: string|null, scheme?: GradingScheme}>
     */
    public static function subjects(string $key): array
    {
        return match ($key) {
            self::ZIMBABWE_PRIMARY => self::pairs([
                'Mathematics', 'English', 'Shona', 'Ndebele', 'General Paper',
                'Agriculture', 'Heritage-Social Studies', 'Physical Education, Arts and Sport',
                'Information and Communication Technology', 'Religious and Moral Education',
            ]),

            self::ZIMSEC_O_LEVEL => self::pairs([
                'English Language', 'English Literature', 'Mathematics', 'Additional Mathematics',
                'Combined Science', 'Physics', 'Chemistry', 'Biology', 'Agriculture',
                'Geography', 'History', 'Heritage Studies', 'Family and Religious Studies',
                'Commerce', 'Principles of Accounts', 'Economics', 'Business Enterprise Skills',
                'Computer Science', 'Information Technology', 'Food and Nutrition',
                'Fashion and Fabrics', 'Textile Technology and Design', 'Building Technology and Design',
                'Metal Technology and Design', 'Wood Technology and Design', 'Technical Graphics and Design',
                'Art', 'Music', 'Physical Education', 'Shona', 'Ndebele', 'French', 'Portuguese',
            ]),

            self::ZIMSEC_A_LEVEL => self::pairs([
                'Mathematics', 'Further Mathematics', 'Statistics', 'Physics', 'Chemistry',
                'Biology', 'Agriculture', 'Computer Science', 'Information Technology',
                'Economics', 'Accounting', 'Business Studies', 'Business Enterprise Skills',
                'Geography', 'History', 'Heritage Studies', 'Family and Religious Studies',
                'Divinity', 'Sociology', 'Literature in English', 'English Language',
                'Communication Skills', 'Art', 'Music', 'Physical Education',
                'Design and Technology', 'Food Science and Technology', 'Textile Technology and Design',
                'Shona', 'Ndebele', 'French',
            ]),

            // Cambridge O Level syllabus numbers.
            self::CAMBRIDGE_O_LEVEL => [
                ['name' => 'Mathematics (Syllabus D)', 'code' => '4024'],
                ['name' => 'Additional Mathematics', 'code' => '4037'],
                ['name' => 'Physics', 'code' => '5054'],
                ['name' => 'Chemistry', 'code' => '5070'],
                ['name' => 'Biology', 'code' => '5090'],
                ['name' => 'Combined Science', 'code' => '5129'],
                ['name' => 'English Language', 'code' => '1123'],
                ['name' => 'Literature in English', 'code' => '2010'],
                ['name' => 'History', 'code' => '2147'],
                ['name' => 'Geography', 'code' => '2217'],
                ['name' => 'Economics', 'code' => '2281'],
                ['name' => 'Sociology', 'code' => '2251'],
                ['name' => 'Commerce', 'code' => '7100'],
                ['name' => 'Principles of Accounts', 'code' => '7110'],
                ['name' => 'Business Studies', 'code' => '7115'],
                ['name' => 'Computer Science', 'code' => '2210'],
                ['name' => 'Art and Design', 'code' => '6090'],
                ['name' => 'Food and Nutrition', 'code' => '6065'],
                ['name' => 'Design and Technology', 'code' => '6043'],
                ['name' => 'French', 'code' => '3015'],
            ],

            // Cambridge IGCSE syllabus numbers (0xxx).
            self::CAMBRIDGE_IGCSE => [
                ['name' => 'Mathematics', 'code' => '0580'],
                ['name' => 'Additional Mathematics', 'code' => '0606'],
                ['name' => 'International Mathematics', 'code' => '0607'],
                ['name' => 'Physics', 'code' => '0625'],
                ['name' => 'Chemistry', 'code' => '0620'],
                ['name' => 'Biology', 'code' => '0610'],
                ['name' => 'Combined Science', 'code' => '0653'],
                ['name' => 'Co-ordinated Sciences', 'code' => '0654'],
                ['name' => 'English - First Language', 'code' => '0500'],
                ['name' => 'English as a Second Language', 'code' => '0510'],
                ['name' => 'Literature in English', 'code' => '0475'],
                ['name' => 'History', 'code' => '0470'],
                ['name' => 'Geography', 'code' => '0460'],
                ['name' => 'Economics', 'code' => '0455'],
                ['name' => 'Business Studies', 'code' => '0450'],
                ['name' => 'Accounting', 'code' => '0452'],
                ['name' => 'Computer Science', 'code' => '0478'],
                ['name' => 'Information and Communication Technology', 'code' => '0417'],
                ['name' => 'Global Perspectives', 'code' => '0457'],
                ['name' => 'Sociology', 'code' => '0495'],
                ['name' => 'Environmental Management', 'code' => '0680'],
                ['name' => 'Art and Design', 'code' => '0400'],
                ['name' => 'Design and Technology', 'code' => '0445'],
                ['name' => 'Music', 'code' => '0410'],
                ['name' => 'Drama', 'code' => '0411'],
                ['name' => 'Physical Education', 'code' => '0413'],
                ['name' => 'Food and Nutrition', 'code' => '0648'],
                ['name' => 'Travel and Tourism', 'code' => '0471'],
                ['name' => 'French', 'code' => '0520'],
                ['name' => 'Spanish', 'code' => '0530'],

                // The 9-1 syllabuses. These are separate Cambridge syllabus
                // numbers, not the same subject rendered on a second scale, so
                // they are separate catalogue rows carrying their own grading.
                // An applicant picks the one they actually sat, and the form
                // then offers only that syllabus's grades.
                ...self::igcseNineToOneSubjects(),
            ],

            // AS and A Level share Cambridge's 9xxx syllabus numbers; the two
            // qualifications differ by the award taken, not by the subject.
            self::CAMBRIDGE_AS_LEVEL, self::CAMBRIDGE_A_LEVEL => [
                ['name' => 'Mathematics', 'code' => '9709'],
                ['name' => 'Further Mathematics', 'code' => '9231'],
                ['name' => 'Physics', 'code' => '9702'],
                ['name' => 'Chemistry', 'code' => '9701'],
                ['name' => 'Biology', 'code' => '9700'],
                ['name' => 'Computer Science', 'code' => '9618'],
                ['name' => 'Information Technology', 'code' => '9626'],
                ['name' => 'Economics', 'code' => '9708'],
                ['name' => 'Business', 'code' => '9609'],
                ['name' => 'Accounting', 'code' => '9706'],
                ['name' => 'English Language', 'code' => '9093'],
                ['name' => 'Literature in English', 'code' => '9695'],
                ['name' => 'History', 'code' => '9489'],
                ['name' => 'Geography', 'code' => '9696'],
                ['name' => 'Psychology', 'code' => '9990'],
                ['name' => 'Sociology', 'code' => '9699'],
                ['name' => 'Law', 'code' => '9084'],
                ['name' => 'Global Perspectives and Research', 'code' => '9239'],
                ['name' => 'Art and Design', 'code' => '9479'],
                ['name' => 'Design and Technology', 'code' => '9705'],
                ['name' => 'Physical Education', 'code' => '9396'],
                ['name' => 'Media Studies', 'code' => '9607'],
                ['name' => 'Travel and Tourism', 'code' => '9395'],
                ['name' => 'French', 'code' => '9716'],
                ['name' => 'Spanish', 'code' => '9719'],
            ],

            // A degree classification is recorded on the profile, not as a
            // subject result, so this family deliberately has no subjects.
            default => [],
        };
    }

    /**
     * Cambridge IGCSE syllabuses reported on the 9-1 scale.
     *
     * Each is a distinct Cambridge syllabus number - 0580 Mathematics is
     * graded A*-G, 0980 Mathematics is graded 9-1 - so they sit alongside the
     * A*-G rows rather than replacing them, and each carries the scale it is
     * actually awarded on. The name carries the scale so an applicant can tell
     * the two apart in the picker, which is also what keeps the
     * (qualification, name) unique key satisfied.
     *
     * @return array<int, array{name: string, code: string, scheme: GradingScheme}>
     */
    private static function igcseNineToOneSubjects(): array
    {
        $scheme = self::igcseNineToOneScheme();

        $syllabuses = [
            'Mathematics' => '0980',
            'Biology' => '0970',
            'Chemistry' => '0971',
            'Physics' => '0972',
            'Combined Science' => '0973',
            'Co-ordinated Sciences' => '0974',
            'English - First Language' => '0990',
            'English as a Second Language' => '0991',
            'Literature in English' => '0992',
            'Business Studies' => '0986',
            'Economics' => '0987',
            'Geography' => '0976',
            'History' => '0977',
            'Computer Science' => '0984',
            'Information and Communication Technology' => '0983',
        ];

        $subjects = [];

        foreach ($syllabuses as $name => $code) {
            $subjects[] = [
                'name' => $name.' (9-1)',
                'code' => $code,
                'scheme' => $scheme,
            ];
        }

        return $subjects;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, array{name: string, code: string|null}>
     */
    private static function pairs(array $names): array
    {
        return array_map(static fn (string $name) => ['name' => $name, 'code' => null], $names);
    }
}
