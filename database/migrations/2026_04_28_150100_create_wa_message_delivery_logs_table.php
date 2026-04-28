<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_message_delivery_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('wa_outbound_message_id')->constrained('wa_outbound_messages')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status', 32);
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->index(['tenant_id', 'wa_outbound_message_id']);
            $table->index(['tenant_id', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_message_delivery_logs');
    }
};
