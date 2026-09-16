<?php

namespace Database\Seeders;

use App\Models\AcademicQualification;
use App\Support\Academic\AcademicCatalogue;
use Illuminate\Database\Seeder;

/**
 * Re-applies the qualification and subject catalogue locally.
 *
 * This is a convenience, not the source of truth for a deployment. The
 * catalogue is reference data and is installed by the
 * 2025_01_01_000002_seed_academic_catalogue migration, so a fresh production
 * database is complete after `migrate` alone - which matters because
 * DatabaseSeeder refuses to run under APP_ENV=production, and this seeder used
 * to be reachable only through it. A production instance would have come up
 * with an empty catalogue: no qualifications to pick, no subjects to record a
 * result against.
 *
 * Both paths read App\Support\Academic\AcademicCatalogue, so a local reset and
 * a production migration cannot disagree about what a qualification is or what
 * grades it awards.
 *
 * Idempotent, and it never deletes: applicant results and scholarship rules
 * point at these rows, and both tables restrict deletion of a referenced
 * qualification or subject. A retired subject is deactivated instead.
 */
class AcademicQualificationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AcademicCatalogue::qualifications() as $definition) {
            $qualification = AcademicQualification::updateOrCreate(
                ['qualification_key' => $definition['key']],
                [
                    'system' => $definition['system'],
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'grading_scheme' => $definition['scheme']->toArray(),
                    'education_level' => $definition['education_level'],
                    'is_active' => true,
                    'ordering' => $definition['ordering'],
                ]
            );

            foreach (AcademicCatalogue::subjects($definition['key']) as $ordering => $subject) {
                $qualification->subjects()->updateOrCreate(
                    ['name' => $subject['name']],
                    [
                        'code' => $subject['code'],
                        'grading_scheme' => isset($subject['scheme']) ? $subject['scheme']->toArray() : null,
                        'is_active' => true,
                        'ordering' => $ordering + 1,
                    ]
                );
            }
        }
    }
}
