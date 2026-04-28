<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('question', 255);
            $table->text('answer');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('active_from')->nullable();
            $table->timestamp('active_until')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'active_from', 'active_until'], 'faqs_active_idx');
            $table->index(['tenant_id', 'sort_order', 'id'], 'faqs_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
