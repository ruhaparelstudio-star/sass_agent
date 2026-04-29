<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->unsignedInteger('message_count')->default(0);
            $table->text('summary');
            $table->timestamp('retention_until')->nullable();
            $table->timestamp('summarized_at')->nullable();
            $table->timestamps();

            $table->unique('conversation_id');
            $table->index(['tenant_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_summaries');
    }
};
