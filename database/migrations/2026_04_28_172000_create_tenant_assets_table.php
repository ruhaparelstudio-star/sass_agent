<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('asset_type', 50);
            $table->string('display_name', 255)->nullable();
            $table->string('original_filename', 255);
            $table->string('storage_disk', 100);
            $table->string('storage_path', 500);
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('active_from')->nullable();
            $table->timestamp('active_until')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'asset_type', 'is_active', 'active_from', 'active_until'], 'tenant_assets_active_idx');
            $table->index(['tenant_id', 'asset_type', 'sort_order', 'id'], 'tenant_assets_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_assets');
    }
};
