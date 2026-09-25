<?php

use App\Support\Academic\AcademicCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrects the Zimbabwe Primary subject catalogue to the five approved
 * learning-area categories - Mathematics, Physical Education, Science and
 * Technology, Social Science, and Languages. Languages is kept as the
 * separate subjects a Primary pupil actually sits (English, Shona, Ndebele)
 * rather than collapsed into one row - see AcademicCatalogue::subjects().
 *
 * The subjects Primary previously listed - General Paper, Agriculture,
 * Heritage-Social Studies, Physical Education, Arts and Sport, Information
 * and Communication Technology, Religious and Moral Education - are
 * deactivated here, not deleted: an applicant result or a scholarship's
 * subject requirement may already point at one of these rows, and both
 * tables restrict deleting a referenced subject. A retired subject stays
 * readable on any record that already holds it and simply stops being
 * offered to a new selection - see AcademicQualification::activeSubjects().
 *
 * Same pattern as 2025_01_01_000002_seed_academic_catalogue.php's own
 * down(): update the flag, never remove the row.
 */
return new class extends Migration
{
    private const RETIRED_PRIMARY_SUBJECTS = [
        'General Paper',
        'Agriculture',
        'Heritage-Social Studies',
        'Physical Education, Arts and Sport',
        'Information and Communication Technology',
        'Religious and Moral Education',
    ];

    public function up(): void
    {
        $qualificationId = $this->primaryQualificationId();

        if ($qualificationId === null) {
            return;
        }

        $now = now();

        DB::table('academic_subjects')
            ->where('qualification_id', $qualificationId)
            ->whereIn('name', self::RETIRED_PRIMARY_SUBJECTS)
            ->update(['is_active' => false, 'updated_at' => $now]);

        foreach (AcademicCatalogue::subjects(AcademicCatalogue::ZIMBABWE_PRIMARY) as $ordering => $subject) {
            DB::table('academic_subjects')->updateOrInsert(
                ['qualification_id' => $qualificationId, 'name' => $subject['name']],
                [
                    'code' => $subject['code'],
                    'grading_scheme' => isset($subject['scheme']) ? json_encode($subject['scheme']->toArray()) : null,
                    'is_active' => true,
                    'ordering' => $ordering + 1,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        $qualificationId = $this->primaryQualificationId();

        if ($qualificationId === null) {
            return;
        }

        // Reactivates what this migration retired. The subjects it added are
        // left active rather than removed, for the same reason up() does not
        // delete: a result may already have been recorded against one.
        DB::table('academic_subjects')
            ->where('qualification_id', $qualificationId)
            ->whereIn('name', self::RETIRED_PRIMARY_SUBJECTS)
            ->update(['is_active' => true, 'updated_at' => now()]);
    }

    private function primaryQualificationId(): ?int
    {
        return DB::table('academic_qualifications')
            ->where('qualification_key', AcademicCatalogue::ZIMBABWE_PRIMARY)
            ->value('id');
    }
};
