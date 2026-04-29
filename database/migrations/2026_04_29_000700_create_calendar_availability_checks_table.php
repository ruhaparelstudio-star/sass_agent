<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_availability_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32);
            $table->boolean('checked')->default(false);
            $table->boolean('available')->default(false);
            $table->string('reason', 100)->nullable();
            $table->string('source', 50);
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_availability_checks');
    }
};
