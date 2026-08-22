<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->bigIncrements('notification_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 50)->nullable();
            $table->string('message', 500)->nullable();
            $table->string('link')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->nullable();

            $table->foreign('user_id')->references('user_id')->on('users');
            $table->index(['user_id', 'is_read'], 'idx_notifications_user_read');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
