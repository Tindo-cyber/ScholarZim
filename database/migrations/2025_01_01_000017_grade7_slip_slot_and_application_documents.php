<?php

use App\Support\EducationLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things that belong together: a Grade 7 slip of its own, and what an application was sent with.
 *
 * 1. applicant_profiles.grade7_slip_*  a slot of its own for a Primary pupil's results slip, beside (not inside)
 *    the O/A-Level results certificate. A slip can then never pass for a certificate, or the reverse, when
 *    someone's level changes, and nothing has to be deleted when it does. A Primary profile that already has a
 *    file in the certificate slot is holding a slip - nothing else could be uploaded for a pupil - so it is
 *    moved across.
 *
 * 2. application_documents  the documents an application went in with (results certificate, transcript, Grade 7
 *    slip - the three a provider can open). A provider used to be shown the applicant's CURRENT documents, so
 *    replacing one after applying changed what the provider saw and, the old file being deleted, destroyed what
 *    they had been sent. Each existing application is given a record of the documents its applicant has now,
 *    which is the best that can be known for applications made before this existed.
 *
 * down() puts a moved slip back where it was and drops both additions; no file is touched in either direction.
 */
return new class extends Migration
{
    private const SNAPSHOT_TYPES = [
        'results' => ['results_certificate_path', 'results_certificate_filename', 'results_uploaded_at'],
        'transcript' => ['transcript_path', 'transcript_filename', 'transcript_uploaded_at'],
        'grade7_slip' => ['grade7_slip_path', 'grade7_slip_filename', 'grade7_slip_uploaded_at'],
    ];

    public function up(): void
    {
        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->string('grade7_slip_path')->nullable();
            $table->string('grade7_slip_filename')->nullable();
            $table->dateTime('grade7_slip_uploaded_at')->nullable();
        });

        Schema::create('application_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('application_id');
            $table->string('type', 20);
            $table->string('path');
            $table->string('filename')->nullable();
            $table->dateTime('uploaded_at')->nullable();
            $table->dateTime('recorded_at');

            $table->unique(['application_id', 'type'], 'uk_application_document');
            $table->index('path', 'idx_application_document_path');
            $table->foreign('application_id')->references('application_id')->on('applications')->cascadeOnDelete();
        });

        // A pupil's file in the certificate slot is their slip: move it to its own.
        foreach (DB::table('applicant_profiles')->whereNotNull('results_certificate_path')->get() as $profile) {
            if (EducationLevel::isPrimary($profile->education_level)) {
                DB::table('applicant_profiles')->where('profile_id', $profile->profile_id)->update([
                    'grade7_slip_path' => $profile->results_certificate_path,
                    'grade7_slip_filename' => $profile->results_certificate_filename,
                    'grade7_slip_uploaded_at' => $profile->results_uploaded_at,
                    'results_certificate_path' => null,
                    'results_certificate_filename' => null,
                    'results_uploaded_at' => null,
                ]);
            }
        }

        // What each existing application can be said to have been sent with: its applicant's documents today.
        DB::table('applications')
            ->join('applicant_profiles', 'applicant_profiles.user_id', '=', 'applications.user_id')
            ->select('applications.application_id', 'applicant_profiles.*')
            ->orderBy('applications.application_id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    foreach (self::SNAPSHOT_TYPES as $type => [$path, $filename, $uploadedAt]) {
                        if (filled($row->{$path})) {
                            DB::table('application_documents')->insert([
                                'application_id' => $row->application_id, 'type' => $type, 'path' => $row->{$path},
                                'filename' => $row->{$filename}, 'uploaded_at' => $row->{$uploadedAt}, 'recorded_at' => now(),
                            ]);
                        }
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_documents');

        // Only a Primary profile has its slip put back, and only where the certificate slot is empty.
        foreach (DB::table('applicant_profiles')->whereNotNull('grade7_slip_path')->get() as $profile) {
            if (EducationLevel::isPrimary($profile->education_level) && blank($profile->results_certificate_path)) {
                DB::table('applicant_profiles')->where('profile_id', $profile->profile_id)->update([
                    'results_certificate_path' => $profile->grade7_slip_path,
                    'results_certificate_filename' => $profile->grade7_slip_filename,
                    'results_uploaded_at' => $profile->grade7_slip_uploaded_at,
                ]);
            }
        }

        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->dropColumn(['grade7_slip_path', 'grade7_slip_filename', 'grade7_slip_uploaded_at']);
        });
    }
};
