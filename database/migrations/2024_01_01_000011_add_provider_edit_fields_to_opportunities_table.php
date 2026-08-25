<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dateTime('updated_at')->nullable()->after('created_at');
            $table->string('last_change_reason', 500)->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn(['updated_at', 'last_change_reason']);
        });
    }
};
