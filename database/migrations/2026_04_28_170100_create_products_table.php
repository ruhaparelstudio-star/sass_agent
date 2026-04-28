<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('service_catalog_id')->constrained('service_catalogs')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('active_from')->nullable();
            $table->timestamp('active_until')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'service_catalog_id'], 'products_catalog_idx');
            $table->index(['tenant_id', 'is_active', 'active_from', 'active_until'], 'products_active_idx');
            $table->index(['tenant_id', 'sort_order', 'id'], 'products_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
