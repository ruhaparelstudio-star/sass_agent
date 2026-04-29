<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handoffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('reason_code', 64);
            $table->text('note')->nullable();
            $table->json('context')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamps();

            $table->index(['tenant_id', 'conversation_id', 'created_at']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handoffs');
    }
};
