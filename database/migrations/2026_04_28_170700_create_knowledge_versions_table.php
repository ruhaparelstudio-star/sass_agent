<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 128);
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'effective_from', 'effective_until'], 'knowledge_versions_active_idx');
            $table->index(['tenant_id', 'effective_from', 'id'], 'knowledge_versions_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_versions');
    }
};
