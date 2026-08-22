<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('audit_id');
            $table->string('actor_email');
            $table->string('action', 50);
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('details')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index('created_at', 'idx_audit_created_at');
            $table->index('actor_email', 'idx_audit_actor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
