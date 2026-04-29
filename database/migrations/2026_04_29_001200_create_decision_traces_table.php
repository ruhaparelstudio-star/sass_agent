<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_traces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('action_log_id')->nullable()->constrained('action_logs')->nullOnDelete();
            $table->string('trace_key', 64);
            $table->unsignedBigInteger('token_usage_total')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'conversation_id', 'created_at']);
            $table->index(['tenant_id', 'token_usage_total']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_traces');
    }
};
