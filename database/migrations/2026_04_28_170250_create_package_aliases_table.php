<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->string('alias', 128);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('active_from')->nullable();
            $table->timestamp('active_until')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'alias'], 'package_aliases_tenant_alias_unique');
            $table->index(['tenant_id', 'package_id'], 'package_aliases_package_idx');
            $table->index(['tenant_id', 'is_active', 'active_from', 'active_until'], 'package_aliases_active_idx');
            $table->index(['tenant_id', 'sort_order', 'id'], 'package_aliases_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_aliases');
    }
};
