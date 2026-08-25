<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Withdrawal by the applicant, and the provider's non-terminal
 * "tell me more" question with the applicant's reply to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dateTime('withdrawn_at')->nullable()->after('interview_at');
            $table->string('withdrawal_reason', 500)->nullable()->after('withdrawn_at');

            $table->text('info_request')->nullable()->after('withdrawal_reason');
            $table->dateTime('info_requested_at')->nullable()->after('info_request');
            $table->text('info_response')->nullable()->after('info_requested_at');
            $table->dateTime('info_responded_at')->nullable()->after('info_response');

            // Set by the interview reminder job so it never nags twice.
            $table->dateTime('interview_reminded_at')->nullable()->after('info_responded_at');

            $table->index('application_status', 'idx_applications_status');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex('idx_applications_status');
            $table->dropColumn([
                'withdrawn_at',
                'withdrawal_reason',
                'info_request',
                'info_requested_at',
                'info_response',
                'info_responded_at',
                'interview_reminded_at',
            ]);
        });
    }
};
