<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activation_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('issued_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['email', 'tenant_id']);
            $table->index(['expires_at', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_tokens');
    }
};

