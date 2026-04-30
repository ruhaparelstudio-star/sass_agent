<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('wa_account_id')->nullable()->after('tenant_id')->constrained('wa_accounts')->nullOnDelete();
            $table->string('current_stage', 64)->default('new')->after('status');
            $table->string('active_goal', 64)->nullable()->after('current_stage');
            $table->string('agent_mode', 32)->default('assistant')->after('active_goal');
            $table->string('memory_mode', 32)->default('short')->after('agent_mode');
            $table->timestamp('last_message_at')->nullable()->after('memory_mode');

            $table->index(['tenant_id', 'last_message_at']);
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->string('message_type', 32)->default('text')->after('direction');
            $table->json('raw_payload')->nullable()->after('content');
            $table->json('grounding_refs')->nullable()->after('raw_payload');
            $table->foreignId('decision_trace_id')->nullable()->after('grounding_refs')->constrained('decision_traces')->nullOnDelete();

            $table->index(['tenant_id', 'conversation_id', 'decision_trace_id'], 'messages_tenant_conversation_trace_idx');
        });

        Schema::table('decision_traces', function (Blueprint $table): void {
            $table->foreignId('message_id')->nullable()->after('conversation_id')->constrained('messages')->nullOnDelete();
            $table->json('input_snapshot_json')->nullable()->after('token_usage_total');
            $table->json('interpretation_json')->nullable()->after('input_snapshot_json');
            $table->json('decision_json')->nullable()->after('interpretation_json');
            $table->json('validators_json')->nullable()->after('decision_json');
            $table->json('blocked_actions_json')->nullable()->after('validators_json');
            $table->json('grounding_refs_json')->nullable()->after('blocked_actions_json');
            $table->text('final_reply')->nullable()->after('grounding_refs_json');
            $table->string('model_name', 128)->nullable()->after('final_reply');
            $table->json('token_usage_json')->nullable()->after('model_name');

            $table->index(['tenant_id', 'conversation_id', 'message_id', 'created_at'], 'decision_traces_tenant_conv_msg_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('decision_traces', function (Blueprint $table): void {
            $table->dropIndex('decision_traces_tenant_conv_msg_created_idx');
            $table->dropConstrainedForeignId('message_id');
            $table->dropColumn([
                'input_snapshot_json',
                'interpretation_json',
                'decision_json',
                'validators_json',
                'blocked_actions_json',
                'grounding_refs_json',
                'final_reply',
                'model_name',
                'token_usage_json',
            ]);
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_tenant_conversation_trace_idx');
            $table->dropConstrainedForeignId('decision_trace_id');
            $table->dropColumn([
                'message_type',
                'raw_payload',
                'grounding_refs',
            ]);
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'last_message_at']);
            $table->dropConstrainedForeignId('wa_account_id');
            $table->dropColumn([
                'current_stage',
                'active_goal',
                'agent_mode',
                'memory_mode',
                'last_message_at',
            ]);
        });
    }
};

