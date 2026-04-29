<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('handoff_id')->nullable()->constrained('handoffs')->nullOnDelete();
            $table->string('type', 64);
            $table->string('channel', 64);
            $table->string('status', 32)->default('queued');
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'conversation_id', 'created_at']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
