<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('current_stage', 64)->default('new');
            $table->string('active_goal', 64)->nullable();
            $table->string('agent_mode', 32)->default('assistant');
            $table->string('memory_mode', 32)->default('short');
            $table->string('retention_policy', 64)->default('standard');
            $table->timestamp('retention_until')->nullable();
            $table->timestamps();

            $table->unique('conversation_id');
            $table->index(['tenant_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_states');
    }
};
