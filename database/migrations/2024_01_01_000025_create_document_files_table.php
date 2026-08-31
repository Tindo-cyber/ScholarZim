<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per stored file, recording what the path alone cannot say.
 *
 * Uploads are referenced from six different places - four document columns on
 * applicant_profiles, the certificate on provider_profiles, and the attachment
 * on applications - as a bare path string. That is enough to serve the file and
 * nothing else: there is no record of how big it was, what it really contained,
 * who uploaded it, or whether the bytes on disk are still the bytes that arrived.
 *
 * A central table rather than five more columns on each owner. Files are the
 * same kind of thing wherever they hang from, the metadata is identical in every
 * case, and account deletion needs to ask "what did this person upload?" without
 * knowing every table that might answer.
 *
 * `path` is unique because it is the identity: the storage path is generated
 * from a UUID, so two rows sharing one would mean two records believing they own
 * the same bytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_files', function (Blueprint $table) {
            $table->bigIncrements('document_file_id');

            $table->string('disk', 32)->default('local');
            $table->string('path')->unique('uk_document_files_path');

            // What the uploader called it, kept only for the download filename -
            // never used to build a path.
            $table->string('original_filename')->nullable();
            $table->string('stored_filename', 128);

            // Detected from the file's contents at upload, not read off the
            // request. The browser-supplied type is advisory and forgeable.
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');

            // sha256 of the stored bytes. Detects silent corruption, and makes an
            // identical re-upload recognisable without comparing whole files.
            $table->string('checksum', 64)->index('idx_document_files_checksum');

            // Nullable on purpose: a provider's registration certificate is
            // stored before their user row exists, during registration.
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();

            /*
             * Quarantine state. Scanning is optional infrastructure, so the
             * default is the honest one - PENDING until something says otherwise
             * - and the scanner marks SKIPPED rather than CLEAN when no engine is
             * configured. That distinction matters: "nothing looked at this" and
             * "something looked and found nothing" are different facts, and
             * collapsing them would make an unscanned platform look scanned.
             */
            $table->string('scan_status', 16)->default('PENDING');
            $table->dateTime('scanned_at')->nullable();
            $table->string('scan_detail', 255)->nullable();

            $table->dateTime('created_at')->nullable();

            $table->index(['uploaded_by_user_id', 'created_at'], 'idx_document_files_uploader');
            $table->index('scan_status', 'idx_document_files_scan_status');

            $table->foreign('uploaded_by_user_id')->references('user_id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_files');
    }
};
