<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->string('module_name', 100)->index();
            $table->string('record_id', 100)->nullable()->index();
            $table->string('action_type', 30)->index();
            $table->string('field_name')->nullable();
            $table->longText('old_value')->nullable();
            $table->longText('new_value')->nullable();
            $table->json('changes')->nullable();
            $table->unsignedBigInteger('changed_by_user_id')->nullable()->index();
            $table->string('changed_by_name')->nullable();
            $table->string('user_role')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'module_name', 'action_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
