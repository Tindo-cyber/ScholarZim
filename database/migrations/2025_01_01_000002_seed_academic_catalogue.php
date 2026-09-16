<?php

use App\Support\Academic\AcademicCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The qualification and subject catalogue, as reference data.
 *
 * This is a migration rather than a seeder because the catalogue is not demo
 * data. DatabaseSeeder refuses to run under APP_ENV=production, and rightly
 * so - it creates accounts whose password is published in the README. But the
 * only route to the qualification catalogue used to be through that same
 * refusal, so a production database would have come up with no qualifications
 * at all: an empty subject picker on the provider form, and no way for an
 * applicant to record a result.
 *
 * Seeding reference data from a migration is the pattern this repository
 * already uses for the three role rows in
 * 2024_01_01_000001_create_roles_table.php, and it is what lets
 * DatabaseSeeder's docblock claim a fresh production database is complete
 * after `migrate` alone.
 *
 * Written through updateOrInsert keyed on the stable qualification_key and on
 * (qualification_id, name), so this is idempotent and safe to re-run.
 * Nothing is deleted: a qualification or subject that leaves the catalogue is
 * deactivated by a later migration rather than removed, because applicant
 * results and scholarship rules point at these rows.
 *
 * AcademicQualificationSeeder calls the same App\Support\Academic\
 * AcademicCatalogue definition, so a local reset and a production migration
 * cannot disagree about what a qualification is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (AcademicCatalogue::qualifications() as $qualification) {
            DB::table('academic_qualifications')->updateOrInsert(
                ['qualification_key' => $qualification['key']],
                [
                    'system' => $qualification['system'],
                    'name' => $qualification['name'],
                    'description' => $qualification['description'],
                    'grading_scheme' => json_encode($qualification['scheme']->toArray()),
                    'education_level' => $qualification['education_level'],
                    'is_active' => true,
                    'ordering' => $qualification['ordering'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $qualificationId = DB::table('academic_qualifications')
                ->where('qualification_key', $qualification['key'])
                ->value('id');

            if ($qualificationId === null) {
                continue;
            }

            foreach (AcademicCatalogue::subjects($qualification['key']) as $ordering => $subject) {
                DB::table('academic_subjects')->updateOrInsert(
                    ['qualification_id' => $qualificationId, 'name' => $subject['name']],
                    [
                        'code' => $subject['code'],
                        // Null for all but the Cambridge IGCSE 9-1 syllabuses,
                        // which are awarded on a different scale from the rest
                        // of their qualification.
                        'grading_scheme' => isset($subject['scheme'])
                            ? json_encode($subject['scheme']->toArray())
                            : null,
                        'is_active' => true,
                        'ordering' => $ordering + 1,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        // Subjects cascade from their qualification, and both tables are
        // dropped wholesale by the schema migration this one follows. Removing
        // the rows here would orphan any applicant result or scholarship rule
        // that referenced them, which the restrictOnDelete foreign keys would
        // refuse anyway.
        DB::table('academic_qualifications')
            ->whereIn('qualification_key', AcademicCatalogue::keys())
            ->update(['is_active' => false]);
    }
};
