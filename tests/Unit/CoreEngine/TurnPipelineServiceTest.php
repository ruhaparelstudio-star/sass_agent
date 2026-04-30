<?php

namespace Tests\Unit\CoreEngine;

use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\ConversationSummary;
use App\Models\LeadProfile;
use App\Models\Tenant;
use App\Models\TenantAsset;
use App\Models\WaAccount;
use App\Models\WaSession;
use App\Modules\AiLayer\Contracts\LlmClientContract;
use App\Modules\AiLayer\DTO\LlmResponse;
use App\Modules\CoreEngine\Services\TurnPipelineService;
use App\Modules\WhatsApp\Services\WaOutboundService;
use App\Modules\Validation\Contracts\ActionPermissionValidator;
use App\Modules\Validation\Contracts\GroundingValidator;
use App\Modules\Validation\Contracts\ModeValidator;
use App\Modules\Validation\Contracts\PolicyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnPipelineServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ask_pricelist_without_name_blocks_candidate_and_requests_missing_info(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlmJson('{"intent":"ask_pricelist","confidence":0.91,"entities":{"package_query":"gold"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'boleh kirim pricelist paket gold?',
            'extract intent'
        );

        $this->assertCount(0, $result['action_candidates']['allowed']);
        $this->assertSame('send_file', $result['action_candidates']['blocked'][0]['action']);
        $this->assertSame(['missing_name'], $result['action_candidates']['blocked'][0]['reasons']);
        $this->assertStringContainsString('nama', mb_strtolower($result['response_plan']['message']));
    }

    public function test_pipeline_does_not_auto_load_conversation_summary_into_default_response(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        ConversationSummary::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'message_count' => 24,
            'summary' => 'Dormant candidate summary should not be auto-loaded.',
            'retention_until' => now()->addDays(7),
            'summarized_at' => now(),
        ]);

        $this->bindLlmJson('{"intent":"ask_package","confidence":0.85,"entities":{}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'ada paket apa saja?',
            'extract intent'
        );

        $this->assertIsArray($result['state_snapshot']);
        $this->assertArrayNotHasKey('summary', $result['state_snapshot']);
        $this->assertArrayNotHasKey('memory', $result);
        $this->assertSame([
            'triggered' => false,
            'status' => 'not_requested',
            'reason' => 'flag_not_set',
        ], $result['trace']['dormant_retrieval']);
    }

    public function test_pipeline_loads_dormant_summary_only_on_explicit_trigger_with_valid_retention(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        ConversationSummary::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'message_count' => 24,
            'summary' => 'Dormant memory summary loaded by explicit trigger.',
            'retention_until' => now()->addDays(7),
            'summarized_at' => now(),
        ]);

        $this->bindLlmJson('{"intent":"ask_package","confidence":0.85,"entities":{}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'ada paket apa saja?',
            'extract intent',
            [
                'dormant_retrieval' => true,
            ]
        );

        $this->assertSame('Dormant memory summary loaded by explicit trigger.', $result['memory']['dormant']['summary']);
        $this->assertSame(24, $result['memory']['dormant']['message_count']);
        $this->assertNotNull($result['memory']['dormant']['summarized_at']);
        $this->assertSame([
            'triggered' => true,
            'status' => 'loaded',
            'reason' => null,
        ], $result['trace']['dormant_retrieval']);
    }

    public function test_pipeline_ignores_dormant_summary_when_retention_has_expired_even_if_triggered(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        ConversationSummary::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'message_count' => 24,
            'summary' => 'Expired dormant memory should not be loaded.',
            'retention_until' => now()->subMinute(),
            'summarized_at' => now(),
        ]);

        $this->bindLlmJson('{"intent":"ask_package","confidence":0.85,"entities":{}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'ada paket apa saja?',
            'extract intent',
            [
                'dormant_retrieval' => true,
            ]
        );

        $this->assertArrayNotHasKey('memory', $result);
        $this->assertSame([
            'triggered' => true,
            'status' => 'miss',
            'reason' => 'expired_retention',
        ], $result['trace']['dormant_retrieval']);
    }

    public function test_pipeline_enforces_tenant_scope_for_dormant_retrieval_even_when_triggered(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $otherTenant = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        $otherConversation = Conversation::query()->create([
            'tenant_id' => $otherTenant->id,
            'customer_phone' => '+628222222222',
            'status' => 'open',
        ]);

        ConversationSummary::query()->create([
            'tenant_id' => $otherTenant->id,
            'conversation_id' => $otherConversation->id,
            'message_count' => 30,
            'summary' => 'Cross-tenant dormant memory must never load.',
            'retention_until' => now()->addDays(5),
            'summarized_at' => now(),
        ]);

        $this->bindLlmJson('{"intent":"ask_package","confidence":0.85,"entities":{}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'ada paket apa saja?',
            'extract intent',
            [
                'dormant_retrieval' => true,
            ]
        );

        $this->assertArrayNotHasKey('memory', $result);
        $this->assertSame([
            'triggered' => true,
            'status' => 'miss',
            'reason' => 'not_found',
        ], $result['trace']['dormant_retrieval']);
    }

    public function test_invalid_json_for_short_greeting_is_classified_as_greeting_and_not_auto_handoff(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlmJson('{"intent":"ask_price"');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'Siang',
            'extract intent'
        );

        $this->assertSame('greeting', $result['intent']);
        $this->assertSame(0.6, $result['confidence']);
        $this->assertFalse($result['handoff_required']);
        $this->assertSame('allow_action', $result['decision']);
        $this->assertStringContainsString('asisten tenant one', mb_strtolower($result['response_plan']['message']));
        $this->assertSame('invalid_json', $result['trace']['fallback_reason']);
    }

    public function test_first_contact_uses_user_facing_reply_and_normalized_reply_text_action(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlmJson('{"intent":"first_contact","confidence":0.9,"entities":{"customer_name":"Aris"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'Saya Aris',
            'extract intent'
        );

        $this->assertSame('first_contact', $result['intent']);
        $this->assertSame(['reply_text'], $result['allowed_actions']);
        $this->assertNotSame('Respond safely based on allowed candidate only.', $result['response_plan']['message']);
        $this->assertStringNotContainsString('allowed candidate', mb_strtolower($result['response_plan']['message']));
    }

    public function test_booking_intent_with_missing_requirements_blocks_booking_candidate(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        LeadProfile::query()->where('tenant_id', $tenant->id)->where('customer_phone', $conversation->customer_phone)->update([
            'full_name' => 'Ayu',
        ]);

        $this->bindLlmJson('{"intent":"booking_intent","confidence":0.93,"entities":{"package_query":"gold"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'saya mau booking paket gold',
            'extract intent'
        );

        $this->assertCount(0, $result['action_candidates']['allowed']);
        $this->assertSame('send_booking_link', $result['action_candidates']['blocked'][0]['action']);
        $this->assertSame([
            'missing_event_date',
            'missing_availability_check',
        ], $result['action_candidates']['blocked'][0]['reasons']);
    }

    public function test_ask_package_with_previous_blocked_booking_missing_package_is_normalized_to_booking_intent(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        ConversationState::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->update([
                'active_goal' => 'booking',
            ]);

        LeadProfile::query()->where('tenant_id', $tenant->id)->where('customer_phone', $conversation->customer_phone)->update([
            'full_name' => 'Ayu',
        ]);

        $this->bindLlmJson('{"intent":"ask_package","confidence":0.88,"entities":{"package_query":"wedding photo only"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'paket wedding photo only',
            'extract intent',
            [
                'previous_blocked_action' => [
                    'action' => 'send_booking_link',
                    'reason' => 'missing_package',
                ],
            ]
        );

        $this->assertSame('booking_intent', $result['intent']);
        $this->assertSame('booking', $result['state_snapshot']['active_goal']);
        $this->assertSame('send_booking_link', $result['action_candidates']['blocked'][0]['action']);
        $this->assertSame([
            'missing_event_date',
            'missing_availability_check',
        ], $result['action_candidates']['blocked'][0]['reasons']);
    }

    public function test_ask_package_without_previous_blocked_booking_context_remains_ask_package(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlmJson('{"intent":"ask_package","confidence":0.88,"entities":{"package_query":"wedding photo only"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'paket wedding photo only',
            'extract intent'
        );

        $this->assertSame('ask_package', $result['intent']);
        $this->assertSame('reply_safe_text', $result['action_candidates']['allowed'][0]['action']);
    }

    public function test_ask_package_detail_uses_grounded_summary_when_available(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlmJson('{"intent":"ask_package_detail","confidence":0.9,"entities":{"package_query":"gold","resolved_package_name":"Gold Wedding"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'detail paket gold dong',
            'extract intent',
            [
                'package_detail_summary' => [
                    'package_name' => 'Gold Wedding',
                    'items' => ['Foto 8 jam', 'Video cinematic', 'Album 20 sheet'],
                ],
            ]
        );

        $this->assertSame('ask_package_detail', $result['intent']);
        $this->assertSame('allow_action', $result['decision']);
        $this->assertStringContainsString('gold wedding', mb_strtolower($result['response_plan']['message']));
        $this->assertStringContainsString('foto 8 jam', mb_strtolower($result['response_plan']['message']));
    }

    public function test_correction_updates_only_targeted_entity_without_reset(): void
    {
        [$tenant, $conversation] = $this->createConversation();
        ConversationState::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->update([
                'current_stage' => 'qualifying',
                'active_goal' => 'pricing',
            ]);

        $this->bindLlmJson('{"intent":"ask_price","confidence":0.82,"entities":{"package_query":"silver","event_date":"21/12/2026","correction":true,"corrected_fields":["package_query"]}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'koreksi paketnya silver, tanggal tetap',
            'extract intent',
            [
                'entities' => [
                    'package_query' => 'gold',
                    'event_date_iso' => '2026-12-21',
                    'budget_amount' => 15000000,
                ],
            ]
        );

        $this->assertSame('silver', $result['entities']['package_query']);
        $this->assertSame('2026-12-21', $result['entities']['event_date_iso']);
        $this->assertSame(15000000, $result['entities']['budget_amount']);

        $freshState = ConversationState::query()->where('conversation_id', $conversation->id)->firstOrFail();
        $this->assertSame('qualifying', $freshState->current_stage);
        $this->assertSame('pricing', $freshState->active_goal);
    }

    public function test_topic_switch_updates_active_goal_only_when_topic_changes(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        ConversationState::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->update([
                'active_goal' => 'pricing',
                'current_stage' => 'qualifying',
                'memory_mode' => 'short',
            ]);

        $this->bindLlmJson('{"intent":"booking_intent","confidence":0.9,"entities":{"package_query":"gold","event_date":"21/12/2026"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'saya jadi mau booking',
            'extract intent'
        );

        $this->assertSame('booking', $result['state_snapshot']['active_goal']);

        $freshState = ConversationState::query()->where('conversation_id', $conversation->id)->firstOrFail();
        $this->assertSame('qualifying', $freshState->current_stage);
        $this->assertSame('short', $freshState->memory_mode);
    }

    public function test_validator_order_stops_at_first_failure(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        LeadProfile::query()->where('tenant_id', $tenant->id)->where('customer_phone', $conversation->customer_phone)->update([
            'full_name' => 'Ayu',
        ]);

        $tracker = (object) ['calls' => []];

        $this->app->bind(PolicyValidator::class, fn () => new class($tracker) implements PolicyValidator {
            public function __construct(private object $tracker) {}
            public function validate(array $candidate, array $context): ?string
            {
                $this->tracker->calls[] = 'policy';

                return 'policy_blocked';
            }
        });

        $this->app->bind(GroundingValidator::class, fn () => new class($tracker) implements GroundingValidator {
            public function __construct(private object $tracker) {}
            public function validate(array $candidate, array $context): ?string
            {
                $this->tracker->calls[] = 'grounding';

                return null;
            }
        });

        $this->app->bind(ActionPermissionValidator::class, fn () => new class($tracker) implements ActionPermissionValidator {
            public function __construct(private object $tracker) {}
            public function validate(array $candidate, array $context): ?string
            {
                $this->tracker->calls[] = 'permission';

                return null;
            }
        });

        $this->app->bind(ModeValidator::class, fn () => new class($tracker) implements ModeValidator {
            public function __construct(private object $tracker) {}
            public function validate(array $candidate, array $context): ?string
            {
                $this->tracker->calls[] = 'mode';

                return null;
            }
        });

        $this->bindLlmJson('{"intent":"ask_pricelist","confidence":0.9,"entities":{"package_query":"gold","event_type":"wedding"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'kirim pricelist',
            'extract intent'
        );

        $this->assertSame(['policy'], $tracker->calls);
        $this->assertSame(['policy_blocked'], $result['action_candidates']['blocked'][0]['reasons']);
        $this->assertSame('policy_blocked', $result['trace']['validation_failure_reason']);
    }

    public function test_pipeline_dispatches_allowed_candidate_through_dispatcher_without_direct_outbound_send(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        LeadProfile::query()->where('tenant_id', $tenant->id)->where('customer_phone', $conversation->customer_phone)->update([
            'full_name' => 'Ayu',
        ]);

        $outboundService = $this->createMock(WaOutboundService::class);
        $outboundService->expects($this->never())
            ->method('queueOutboundMessage');
        $this->app->instance(WaOutboundService::class, $outboundService);

        $this->bindLlmJson('{"intent":"ask_package","confidence":0.8,"entities":{}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'paketnya apa saja?',
            'extract intent'
        );

        $this->assertTrue($result['trace']['executed']);
        $this->assertSame('reply_safe_text', $result['trace']['action']);
        $this->assertSame('executed', $result['trace']['status']);
        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'reply_safe_text',
            'status' => 'executed',
        ]);
    }

    public function test_blocked_candidate_remains_non_executed_and_still_logged(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlmJson('{"intent":"ask_pricelist","confidence":0.91,"entities":{"package_query":"gold"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'boleh kirim pricelist paket gold?',
            'extract intent'
        );

        $this->assertFalse($result['trace']['executed']);
        $this->assertSame('send_file', $result['trace']['action']);
        $this->assertSame('blocked', $result['trace']['status']);
        $this->assertSame('missing_name', $result['trace']['reason']);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_file',
            'status' => 'blocked',
            'reason' => 'missing_name',
        ]);
    }

    public function test_permission_validator_error_is_propagated_to_blocked_candidate_reason(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        LeadProfile::query()->where('tenant_id', $tenant->id)->where('customer_phone', $conversation->customer_phone)->update([
            'full_name' => 'Ayu',
        ]);

        $this->app->bind(PolicyValidator::class, fn () => new class implements PolicyValidator {
            public function validate(array $candidate, array $context): ?string
            {
                return null;
            }
        });

        $this->app->bind(GroundingValidator::class, fn () => new class implements GroundingValidator {
            public function validate(array $candidate, array $context): ?string
            {
                return null;
            }
        });

        $this->app->bind(ActionPermissionValidator::class, fn () => new class implements ActionPermissionValidator {
            public function validate(array $candidate, array $context): ?string
            {
                return 'permission_action_not_allowed';
            }
        });

        $this->app->bind(ModeValidator::class, fn () => new class implements ModeValidator {
            public function validate(array $candidate, array $context): ?string
            {
                return null;
            }
        });

        $this->bindLlmJson('{"intent":"ask_pricelist","confidence":0.9,"entities":{"package_query":"gold","event_type":"wedding"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'kirim pricelist',
            'extract intent'
        );

        $this->assertSame('send_file', $result['action_candidates']['blocked'][0]['action']);
        $this->assertSame(['permission_action_not_allowed'], $result['action_candidates']['blocked'][0]['reasons']);
    }

    public function test_mode_validator_error_is_propagated_to_blocked_candidate_reason(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        ConversationState::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->update([
                'agent_mode' => 'limited',
            ]);

        LeadProfile::query()->where('tenant_id', $tenant->id)->where('customer_phone', $conversation->customer_phone)->update([
            'full_name' => 'Ayu',
        ]);

        $this->app->bind(PolicyValidator::class, fn () => new class implements PolicyValidator {
            public function validate(array $candidate, array $context): ?string
            {
                return null;
            }
        });

        $this->app->bind(GroundingValidator::class, fn () => new class implements GroundingValidator {
            public function validate(array $candidate, array $context): ?string
            {
                return null;
            }
        });

        $this->app->bind(ActionPermissionValidator::class, fn () => new class implements ActionPermissionValidator {
            public function validate(array $candidate, array $context): ?string
            {
                return null;
            }
        });

        $this->app->bind(ModeValidator::class, \App\Modules\Validation\Services\ModeValidatorService::class);

        $this->bindLlmJson('{"intent":"ask_pricelist","confidence":0.9,"entities":{"package_query":"gold","event_type":"wedding"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'kirim pricelist',
            'extract intent'
        );

        $this->assertSame('send_file', $result['action_candidates']['blocked'][0]['action']);
        $this->assertSame(['mode_limited_blocked_action'], $result['action_candidates']['blocked'][0]['reasons']);
    }

    public function test_decision_contract_contains_required_prd_fields_and_entity_shape(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlmJson('{"intent":"ask_pricelist","confidence":0.9,"entities":{"package_query":"gold","event_date":"21/12/2026","event_type":"wedding","location":"bandung","budget":"15000000","customer_name":"Ayu"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'minta pricelist paket gold',
            'extract intent'
        );

        $this->assertArrayHasKey('intent', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('entities', $result);
        $this->assertArrayHasKey('current_stage', $result);
        $this->assertArrayHasKey('active_goal', $result);
        $this->assertArrayHasKey('decision', $result);
        $this->assertArrayHasKey('allowed_actions', $result);
        $this->assertArrayHasKey('blocked_actions', $result);
        $this->assertArrayHasKey('handoff_required', $result);
        $this->assertArrayHasKey('notification_required', $result);
        $this->assertArrayHasKey('grounding_refs', $result);
        $this->assertArrayHasKey('reply_strategy', $result);

        $this->assertArrayHasKey('name', $result['entities']);
        $this->assertArrayHasKey('event_date', $result['entities']);
        $this->assertArrayHasKey('event_type', $result['entities']);
        $this->assertArrayHasKey('location', $result['entities']);
        $this->assertArrayHasKey('package_interest', $result['entities']);
        $this->assertArrayHasKey('budget', $result['entities']);
    }

    public function test_invalid_classifier_json_fails_safe_and_does_not_enable_sensitive_action(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlmJson('{"intent":"ask_pricelist"');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'kirim pricelist ya',
            'extract intent'
        );

        $this->assertSame('ask_pricelist', $result['intent']);
        $this->assertSame('ask_missing_required_info', $result['decision']);
        $this->assertSame([], $result['allowed_actions']);
        $this->assertSame('send_file', $result['blocked_actions'][0]['action']);
        $this->assertSame('missing_name', $result['blocked_actions'][0]['reason']);
        $this->assertFalse($result['handoff_required']);
        $this->assertFalse($result['notification_required']);
        $this->assertFalse(in_array('send_file', $result['allowed_actions'], true));
        $this->assertFalse(in_array('send_booking_link', $result['allowed_actions'], true));
        $this->assertFalse(in_array('send_invoice', $result['allowed_actions'], true));
        $this->assertSame('invalid_json', $result['trace']['fallback_reason']);
        $this->assertSame('send_file', $result['trace']['action']);
    }

    public function test_complaint_intent_triggers_handoff_signal_with_high_priority(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlmJson('{"intent":"complaint","confidence":0.93,"entities":{}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'Saya komplain soal layanan tadi.',
            'extract intent'
        );

        $this->assertTrue($result['handoff_required']);
        $this->assertTrue($result['notification_required']);
        $this->assertSame('complaint_detected', $result['handoff_reason_code']);
        $this->assertSame('high', $result['handoff_priority']);
        $this->assertSame('handoff_required', $result['decision']);
    }

    public function test_calendar_unavailable_triggers_handoff_signal_with_high_priority(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlmJson('{"intent":"ask_availability","confidence":0.84,"entities":{"event_date":"21/12/2026"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'Ada slot tanggal 21 desember 2026?',
            'extract intent',
            [
                'calendar_check' => [
                    'status' => 'blocked',
                    'checked' => true,
                    'available' => false,
                    'reason' => 'calendar_unavailable',
                    'source' => 'calendar_api',
                ],
            ]
        );

        $this->assertTrue($result['handoff_required']);
        $this->assertSame('calendar_unavailable', $result['handoff_reason_code']);
        $this->assertSame('high', $result['handoff_priority']);
    }

    public function test_lead_limit_exhausted_triggers_handoff_signal_with_high_priority(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlmJson('{"intent":"ask_pricelist","confidence":0.86,"entities":{"package_query":"gold"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'minta pricelist',
            'extract intent',
            [
                'lead_limit' => [
                    'limit_exhausted_for_new_lead' => true,
                ],
            ]
        );

        $this->assertTrue($result['handoff_required']);
        $this->assertSame('lead_limit_exhausted', $result['handoff_reason_code']);
        $this->assertSame('high', $result['handoff_priority']);
    }

    public function test_paused_mode_triggers_critical_handoff_signal(): void
    {
        [$tenant, $conversation] = $this->createConversation();
        ConversationState::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->update(['agent_mode' => 'paused']);

        $this->bindLlmJson('{"intent":"ask_price","confidence":0.90,"entities":{"package_query":"gold"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'berapa harganya?',
            'extract intent'
        );

        $this->assertTrue($result['handoff_required']);
        $this->assertSame('mode_paused', $result['handoff_reason_code']);
        $this->assertSame('critical', $result['handoff_priority']);
    }

    public function test_low_confidence_unknown_message_uses_safe_recovery_without_auto_handoff(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $this->bindLlmJson('{"intent":"unknown","confidence":0.1,"entities":{"customer_name":null}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'Aris egi ka',
            'extract intent'
        );

        $this->assertSame('provide_name', $result['intent']);
        $this->assertFalse($result['handoff_required']);
        $this->assertFalse($result['notification_required']);
        $this->assertSame('allow_action', $result['decision']);
    }

    public function test_provide_name_resumes_blocked_pricelist_flow_and_requests_event_type_before_file_send(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $asset = TenantAsset::query()->create([
            'tenant_id' => $tenant->id,
            'asset_type' => 'pricelist',
            'display_name' => 'Pricelist April',
            'original_filename' => 'pricelist-april.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'tenant-assets/pricelist/'.$tenant->id.'/pricelist-april.pdf',
            'uploaded_by_user_id' => null,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'phone' => '+62818888',
            'status' => 'connected',
            'last_payload' => ['event' => 'connected'],
        ]);

        WaSession::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-001',
            'status' => 'active',
            'last_payload' => ['event' => 'active'],
        ]);

        $this->bindLlmJson('{"intent":"provide_name","confidence":0.95,"entities":{"customer_name":"Aris Egi Saputra"}}');

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'nama saya aris egi saputra',
            'extract intent',
            [
                'previous_blocked_action' => [
                    'action' => 'send_file',
                    'reason' => 'missing_name',
                ],
                'grounding' => [
                    'price' => ['is_grounded' => true],
                    'package' => ['is_grounded' => true],
                    'file' => ['is_grounded' => true],
                ],
                'pricelist_asset' => [
                    'id' => $asset->id,
                    'display_name' => $asset->display_name,
                    'original_filename' => $asset->original_filename,
                ],
                'delivery_channel' => [
                    'provider' => 'meta',
                    'wa_account_provider_ref' => 'acct-001',
                    'wa_session_provider_ref' => 'sess-001',
                    'to' => '+628111111111',
                ],
                'permissions' => [
                    'allowed_actions' => ['send_file', 'send_text', 'reply_safe_text'],
                    'blocked_actions' => [],
                ],
            ]
        );

        $this->assertSame([], $result['action_candidates']['allowed']);
        $this->assertSame('send_file', $result['action_candidates']['blocked'][0]['action']);
        $this->assertSame(['missing_event_type'], $result['action_candidates']['blocked'][0]['reasons']);
        $this->assertStringContainsString('layanan', mb_strtolower($result['response_plan']['message']));
        $this->assertSame('send_file', $result['trace']['action']);
        $this->assertSame('blocked', $result['trace']['status']);
        $this->assertSame('missing_event_type', $result['trace']['reason']);
    }

    public function test_provide_event_type_resumes_blocked_pricelist_flow_and_dispatches_file_when_context_is_ready(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $asset = TenantAsset::query()->create([
            'tenant_id' => $tenant->id,
            'asset_type' => 'pricelist',
            'display_name' => 'Pricelist April',
            'original_filename' => 'pricelist-april.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'tenant-assets/pricelist/'.$tenant->id.'/pricelist-april.pdf',
            'uploaded_by_user_id' => null,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        $account = WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => 'acct-001',
            'phone' => '+62818888',
            'status' => 'connected',
            'last_payload' => ['event' => 'connected'],
        ]);

        WaSession::query()->create([
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'provider_ref' => 'sess-001',
            'status' => 'active',
            'last_payload' => ['event' => 'active'],
        ]);

        $this->bindLlmJson('{"intent":"provide_event_type","confidence":0.95,"entities":{"event_type":"wedding"}}');
        LeadProfile::query()->where('tenant_id', $tenant->id)->where('customer_phone', $conversation->customer_phone)->update([
            'full_name' => 'Aris Egi Saputra',
        ]);

        $result = app(TurnPipelineService::class)->handle(
            $tenant,
            $conversation,
            'untuk wedding ka',
            'extract intent',
            [
                'previous_blocked_action' => [
                    'action' => 'send_file',
                    'reason' => 'missing_event_type',
                ],
                'entities' => [
                    'customer_name' => 'Aris Egi Saputra',
                ],
                'grounding' => [
                    'price' => ['is_grounded' => true],
                    'package' => ['is_grounded' => true],
                    'file' => ['is_grounded' => true],
                ],
                'pricelist_asset' => [
                    'id' => $asset->id,
                    'display_name' => $asset->display_name,
                    'original_filename' => $asset->original_filename,
                ],
                'delivery_channel' => [
                    'provider' => 'meta',
                    'wa_account_provider_ref' => 'acct-001',
                    'wa_session_provider_ref' => 'sess-001',
                    'to' => '+628111111111',
                ],
                'permissions' => [
                    'allowed_actions' => ['send_file', 'send_text', 'reply_safe_text'],
                    'blocked_actions' => [],
                ],
            ]
        );

        $this->assertSame('send_file', $result['action_candidates']['allowed'][0]['action']);
        $this->assertSame([], $result['action_candidates']['blocked']);
        $this->assertStringContainsString('pricelist terbaru', mb_strtolower($result['response_plan']['message']));
        $this->assertSame('send_file', $result['trace']['action']);
        $this->assertSame('executed', $result['trace']['status']);
    }

    private function createConversation(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => '+628111111111',
            'status' => 'open',
        ]);

        ConversationState::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'current_stage' => 'new',
            'active_goal' => 'pricing',
            'agent_mode' => 'assistant',
            'memory_mode' => 'short',
            'retention_policy' => 'standard',
        ]);

        LeadProfile::query()->create([
            'tenant_id' => $tenant->id,
            'customer_phone' => $conversation->customer_phone,
            'full_name' => null,
        ]);

        return [$tenant, $conversation];
    }

    private function bindLlmJson(string $json): void
    {
        $this->app->bind(LlmClientContract::class, fn () => new class($json) implements LlmClientContract {
            public function __construct(private readonly string $json) {}

            public function complete(int $tenantId, string $userMessage, string $instruction): LlmResponse
            {
                return new LlmResponse(
                    content: $this->json,
                    model: 'fake-model',
                    totalTokens: 10,
                    raw: ['ok' => true],
                );
            }
        });
    }
}
