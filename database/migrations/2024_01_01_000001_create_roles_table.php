<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->bigIncrements('role_id');
            $table->string('role_name', 50)->unique();
            $table->string('description')->nullable();
        });

        DB::table('roles')->insertOrIgnore([
            ['role_id' => 1, 'role_name' => 'ROLE_APPLICANT', 'description' => 'Scholarship applicant'],
            ['role_id' => 2, 'role_name' => 'ROLE_PROVIDER', 'description' => 'Scholarship provider'],
            ['role_id' => 3, 'role_name' => 'ROLE_ADMIN', 'description' => 'Platform administrator'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
