<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('user_id');
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('full_name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone', 50)->nullable();
            $table->string('password_hash')->nullable();
            $table->string('account_status', 50)->nullable();
            $table->boolean('email_verified')->default(true);
            $table->boolean('is_super_admin')->default(false);
            $table->boolean('email_notify_applications')->default(true);
            $table->boolean('email_notify_scholarships')->default(true);
            $table->boolean('email_notify_system')->default(true);
            $table->rememberToken();
            $table->timestamps();

            $table->foreign('role_id')->references('role_id')->on('roles');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
