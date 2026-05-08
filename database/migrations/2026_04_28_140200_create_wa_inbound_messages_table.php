<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_inbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('wa_account_id')->constrained('wa_accounts')->cascadeOnDelete();
            $table->foreignId('wa_session_id')->nullable()->constrained('wa_sessions')->nullOnDelete();
            $table->string('provider', 100);
            $table->string('provider_message_id', 191);
            $table->string('from', 64);
            $table->string('to', 64);
            $table->string('message_type', 64);
            $table->timestamp('message_timestamp');
            $table->json('payload');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'provider_message_id'], 'wa_inbound_msgs_tenant_provider_msg_id_unique');
            $table->index(['tenant_id', 'wa_account_id']);
            $table->index(['tenant_id', 'wa_session_id']);
            $table->index(['tenant_id', 'message_timestamp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_inbound_messages');
    }
};
