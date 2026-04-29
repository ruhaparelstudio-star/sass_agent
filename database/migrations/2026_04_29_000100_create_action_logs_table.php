<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('action', 64);
            $table->string('status', 32);
            $table->string('reason', 128)->nullable();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_logs');
    }
};
