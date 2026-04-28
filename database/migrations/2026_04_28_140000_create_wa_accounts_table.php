<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider', 100);
            $table->string('provider_ref', 191);
            $table->string('phone', 64)->nullable();
            $table->string('status', 32);
            $table->json('meta')->nullable();
            $table->json('last_payload')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'provider_ref']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_accounts');
    }
};
