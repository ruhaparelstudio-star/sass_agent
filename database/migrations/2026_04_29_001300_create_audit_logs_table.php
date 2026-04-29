<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 120);
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('endpoint', 191);
            $table->unsignedSmallInteger('status_code');
            $table->string('reason', 120);
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['event_key', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['tenant_id', 'event_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
