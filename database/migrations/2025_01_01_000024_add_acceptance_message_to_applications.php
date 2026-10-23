<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The optional message a provider leaves with an acceptance: next steps, registration, deadlines, contact details.
 *
 * One nullable column on the application, written once with the decision (ApplicationService::decide) and never changed
 * afterwards, because a decision is final. It is not a conversation: there is no reply, no thread and no second message.
 * Every existing application simply has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('acceptance_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('acceptance_message');
        });
    }
};
