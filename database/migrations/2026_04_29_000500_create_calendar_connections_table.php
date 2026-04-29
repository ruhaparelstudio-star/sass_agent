<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('status', 32)->default('disconnected');
            $table->boolean('is_enabled')->default(false);
            $table->json('config')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_connections');
    }
};
