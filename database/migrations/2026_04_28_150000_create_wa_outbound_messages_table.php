<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_outbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('wa_account_id')->constrained('wa_accounts')->cascadeOnDelete();
            $table->foreignId('wa_session_id')->nullable()->constrained('wa_sessions')->nullOnDelete();
            $table->string('provider', 100);
            $table->string('provider_message_id', 191)->nullable();
            $table->string('to', 64);
            $table->string('message_type', 64);
            $table->string('status', 32);
            $table->json('payload');
            $table->json('meta')->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'wa_outbound_tenant_status_idx');
            $table->index(['tenant_id', 'wa_account_id'], 'wa_outbound_tenant_account_idx');
            $table->index(['tenant_id', 'wa_session_id'], 'wa_outbound_tenant_session_idx');
            $table->index(['tenant_id', 'provider', 'provider_message_id'], 'wa_outbound_tenant_provider_msg_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_outbound_messages');
    }
};
