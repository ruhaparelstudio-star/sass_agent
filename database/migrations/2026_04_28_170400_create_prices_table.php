<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->string('label', 128);
            $table->string('currency', 8);
            $table->unsignedBigInteger('amount');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('active_from')->nullable();
            $table->timestamp('active_until')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'package_id'], 'prices_package_idx');
            $table->index(['tenant_id', 'is_active', 'active_from', 'active_until'], 'prices_active_idx');
            $table->index(['tenant_id', 'sort_order', 'id'], 'prices_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
