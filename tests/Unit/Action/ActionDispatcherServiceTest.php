<?php

namespace Tests\Unit\Action;

use App\Jobs\DispatchWaOutboundMessageJob;
use App\Models\BookingSetting;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\Handoff;
use App\Models\Invoice;
use App\Models\LeadProfile;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\TenantAsset;
use App\Models\WaAccount;
use App\Jobs\DispatchNotificationJob;
use App\Modules\Action\Services\ActionDispatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ActionDispatcherServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowed_action_executes_and_logs_result(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'reply_safe_text',
                'reasons' => [],
            ]
        );

        $this->assertSame('executed', $result['status']);
        $this->assertSame('reply_safe_text', $result['action']);
        $this->assertNull($result['reason']);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'reply_safe_text',
            'status' => 'executed',
            'reason' => null,
        ]);
    }

    public function test_unsupported_action_is_blocked_and_logged(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'unknown_action',
                'reasons' => [],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('unknown_action', $result['action']);
        $this->assertSame('unsupported_action', $result['reason']);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'unknown_action',
            'status' => 'blocked',
            'reason' => 'unsupported_action',
        ]);
    }

    public function test_tenant_ownership_mismatch_is_blocked_and_logged(): void
    {
        [$tenantOne] = $this->createConversation();
        [$tenantTwo, $conversationTwo] = $this->createConversation('tenant-two');

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenantOne,
            $conversationTwo,
            [
                'action' => 'reply_safe_text',
                'reasons' => [],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('reply_safe_text', $result['action']);
        $this->assertSame('tenant_conversation_mismatch', $result['reason']);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenantOne->id,
            'conversation_id' => $conversationTwo->id,
            'action' => 'reply_safe_text',
            'status' => 'blocked',
            'reason' => 'tenant_conversation_mismatch',
        ]);

        $this->assertDatabaseMissing('action_logs', [
            'tenant_id' => $tenantTwo->id,
            'conversation_id' => $conversationTwo->id,
            'action' => 'reply_safe_text',
        ]);
    }

    public function test_candidate_with_reasons_is_blocked_and_logged(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'reply_safe_text',
                'reasons' => ['missing_name'],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('missing_name', $result['reason']);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'reply_safe_text',
            'status' => 'blocked',
            'reason' => 'missing_name',
        ]);
    }

    public function test_token_usage_total_in_candidate_meta_is_persisted_to_action_log_result(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'reply_safe_text',
                'reasons' => [],
                'meta' => [
                    'token_usage_total' => 77,
                ],
            ]
        );

        $log = \App\Models\ActionLog::query()->latest('id')->firstOrFail();
        $this->assertSame(77, $log->result['token_usage_total'] ?? null);
        $this->assertDatabaseHas('decision_traces', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action_log_id' => $log->id,
            'trace_key' => 'action_dispatch',
            'token_usage_total' => 77,
        ]);
    }

    public function test_missing_or_non_numeric_token_usage_total_is_stored_as_zero_in_decision_trace(): void
    {
        [$tenant, $conversation] = $this->createConversation();

        app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'reply_safe_text',
                'reasons' => [],
                'meta' => [
                    'token_usage_total' => 'invalid',
                ],
            ]
        );

        $this->assertDatabaseHas('decision_traces', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'trace_key' => 'action_dispatch',
            'token_usage_total' => 0,
        ]);
    }

    public function test_allowed_send_text_executes_queues_outbound_and_logs_result(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $account = $this->createWaAccount($tenant);

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_text',
                'reasons' => [],
                'meta' => [
                    'send_text' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => $account->provider_ref,
                        'to' => '+6281222333444',
                        'text' => 'Halo, ini balasan aman.',
                    ],
                ],
            ]
        );

        $this->assertSame('executed', $result['status']);
        $this->assertSame('send_text', $result['action']);
        $this->assertNull($result['reason']);
        $this->assertTrue($result['meta']['executed']);
        $this->assertArrayHasKey('wa_outbound_message_id', $result['meta']);

        $this->assertDatabaseHas('wa_outbound_messages', [
            'id' => $result['meta']['wa_outbound_message_id'],
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'to' => '+6281222333444',
            'message_type' => 'text',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_text',
            'status' => 'executed',
            'reason' => null,
        ]);

        Queue::assertPushed(DispatchWaOutboundMessageJob::class);
    }

    public function test_blocked_send_text_with_candidate_reasons_skips_outbound_and_logs_blocked(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $this->createWaAccount($tenant);

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_text',
                'reasons' => ['policy_blocked'],
                'meta' => [
                    'send_text' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => 'acct-001',
                        'to' => '+6281222333444',
                        'text' => 'Should not send',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('policy_blocked', $result['reason']);
        $this->assertDatabaseCount('wa_outbound_messages', 0);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_text',
            'status' => 'blocked',
            'reason' => 'policy_blocked',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_invalid_send_text_contract_is_blocked_and_logged_without_outbound_row(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $this->createWaAccount($tenant);

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_text',
                'reasons' => [],
                'meta' => [
                    'send_text' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => 'acct-001',
                        'to' => '+6281222333444',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_send_text_contract', $result['reason']);
        $this->assertDatabaseCount('wa_outbound_messages', 0);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_text',
            'status' => 'blocked',
            'reason' => 'invalid_send_text_contract',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_send_text_dispatch_failure_is_blocked_and_logged_without_unhandled_exception(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_text',
                'reasons' => [],
                'meta' => [
                    'send_text' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => 'missing-acct',
                        'to' => '+6281222333444',
                        'text' => 'send',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('send_text_dispatch_failed', $result['reason']);
        $this->assertDatabaseCount('wa_outbound_messages', 0);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_text',
            'status' => 'blocked',
            'reason' => 'send_text_dispatch_failed',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_allowed_send_file_executes_queues_outbound_and_logs_result(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $account = $this->createWaAccount($tenant);
        $asset = $this->createTenantAsset($tenant);

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_file',
                'reasons' => [],
                'meta' => [
                    'send_file' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => $account->provider_ref,
                        'to' => '+6281222333444',
                        'tenant_asset_id' => $asset->id,
                        'file_name' => 'pricelist-april.pdf',
                        'mime_type' => 'application/pdf',
                        'caption' => 'Berikut pricelist terbaru.',
                    ],
                ],
            ]
        );

        $this->assertSame('executed', $result['status']);
        $this->assertSame('send_file', $result['action']);
        $this->assertNull($result['reason']);
        $this->assertTrue($result['meta']['executed']);
        $this->assertArrayHasKey('wa_outbound_message_id', $result['meta']);

        $this->assertDatabaseHas('wa_outbound_messages', [
            'id' => $result['meta']['wa_outbound_message_id'],
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'to' => '+6281222333444',
            'message_type' => 'file',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_file',
            'status' => 'executed',
            'reason' => null,
        ]);

        Queue::assertPushed(DispatchWaOutboundMessageJob::class);
    }

    public function test_blocked_send_file_with_candidate_reasons_skips_outbound_and_logs_blocked(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $this->createWaAccount($tenant);
        $asset = $this->createTenantAsset($tenant);

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_file',
                'reasons' => ['policy_blocked'],
                'meta' => [
                    'send_file' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => 'acct-001',
                        'to' => '+6281222333444',
                        'tenant_asset_id' => $asset->id,
                        'file_name' => 'pricelist-april.pdf',
                        'mime_type' => 'application/pdf',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('policy_blocked', $result['reason']);
        $this->assertDatabaseCount('wa_outbound_messages', 0);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_file',
            'status' => 'blocked',
            'reason' => 'policy_blocked',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_invalid_send_file_contract_is_blocked_and_logged_without_outbound_row(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $this->createWaAccount($tenant);
        $asset = $this->createTenantAsset($tenant);

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_file',
                'reasons' => [],
                'meta' => [
                    'send_file' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => 'acct-001',
                        'to' => '+6281222333444',
                        'tenant_asset_id' => $asset->id,
                        'mime_type' => 'application/pdf',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_send_file_contract', $result['reason']);
        $this->assertDatabaseCount('wa_outbound_messages', 0);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_file',
            'status' => 'blocked',
            'reason' => 'invalid_send_file_contract',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_send_file_with_asset_not_owned_by_tenant_is_blocked_and_logged(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $this->createWaAccount($tenant);
        [$otherTenant] = $this->createConversation('other-tenant');
        $otherAsset = $this->createTenantAsset($otherTenant);

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_file',
                'reasons' => [],
                'meta' => [
                    'send_file' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => 'acct-001',
                        'to' => '+6281222333444',
                        'tenant_asset_id' => $otherAsset->id,
                        'file_name' => 'pricelist-april.pdf',
                        'mime_type' => 'application/pdf',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('send_file_asset_not_owned', $result['reason']);
        $this->assertDatabaseCount('wa_outbound_messages', 0);
        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_file',
            'status' => 'blocked',
            'reason' => 'send_file_asset_not_owned',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_send_file_dispatch_failure_is_blocked_and_logged_without_unhandled_exception(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $asset = $this->createTenantAsset($tenant);

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_file',
                'reasons' => [],
                'meta' => [
                    'send_file' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => 'missing-acct',
                        'to' => '+6281222333444',
                        'tenant_asset_id' => $asset->id,
                        'file_name' => 'pricelist-april.pdf',
                        'mime_type' => 'application/pdf',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('send_file_dispatch_failed', $result['reason']);
        $this->assertDatabaseCount('wa_outbound_messages', 0);
        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_file',
            'status' => 'blocked',
            'reason' => 'send_file_dispatch_failed',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_allowed_send_booking_link_executes_queues_outbound_and_logs_result(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $account = $this->createWaAccount($tenant);
        $booking = $this->createBookingSetting($tenant, 'https://book.example.com/tenant-one');

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_booking_link',
                'reasons' => [],
                'meta' => [
                    'send_booking_link' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => $account->provider_ref,
                        'to' => '+6281222333444',
                    ],
                ],
            ]
        );

        $this->assertSame('executed', $result['status']);
        $this->assertSame('send_booking_link', $result['action']);
        $this->assertNull($result['reason']);
        $this->assertTrue($result['meta']['executed']);
        $this->assertArrayHasKey('wa_outbound_message_id', $result['meta']);

        $this->assertDatabaseHas('wa_outbound_messages', [
            'id' => $result['meta']['wa_outbound_message_id'],
            'tenant_id' => $tenant->id,
            'wa_account_id' => $account->id,
            'provider' => 'meta',
            'to' => '+6281222333444',
            'message_type' => 'text',
            'status' => 'pending',
        ]);
        $this->assertSame($booking->booking_url, \App\Models\WaOutboundMessage::query()->findOrFail($result['meta']['wa_outbound_message_id'])->payload['text']);

        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_booking_link',
            'status' => 'executed',
            'reason' => null,
        ]);

        Queue::assertPushed(DispatchWaOutboundMessageJob::class);
    }

    public function test_send_booking_link_without_active_booking_setting_is_blocked_and_logged(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $account = $this->createWaAccount($tenant);
        $this->createBookingSetting($tenant, 'https://book.example.com/expired', now()->subDays(5), now()->subDay());

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_booking_link',
                'reasons' => [],
                'meta' => [
                    'send_booking_link' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => $account->provider_ref,
                        'to' => '+6281222333444',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('booking_link_not_available', $result['reason']);
        $this->assertDatabaseCount('wa_outbound_messages', 0);
        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_booking_link',
            'status' => 'blocked',
            'reason' => 'booking_link_not_available',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_send_booking_link_with_invalid_contract_is_blocked_and_logged(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $this->createWaAccount($tenant);
        $this->createBookingSetting($tenant, 'https://book.example.com/tenant-one');

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_booking_link',
                'reasons' => [],
                'meta' => [
                    'send_booking_link' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => 'acct-001',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_send_booking_link_contract', $result['reason']);
        $this->assertDatabaseCount('wa_outbound_messages', 0);
        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_booking_link',
            'status' => 'blocked',
            'reason' => 'invalid_send_booking_link_contract',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_send_booking_link_dispatch_failure_is_blocked_and_logged_without_unhandled_exception(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $this->createBookingSetting($tenant, 'https://book.example.com/tenant-one');

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_booking_link',
                'reasons' => [],
                'meta' => [
                    'send_booking_link' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => 'missing-acct',
                        'to' => '+6281222333444',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('send_booking_link_dispatch_failed', $result['reason']);
        $this->assertDatabaseCount('wa_outbound_messages', 0);
        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_booking_link',
            'status' => 'blocked',
            'reason' => 'send_booking_link_dispatch_failed',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_allowed_send_invoice_executes_logs_attempt_and_sets_limited_mode(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $account = $this->createWaAccount($tenant);
        $asset = $this->createInvoiceAsset($tenant);
        $invoice = $this->createInvoice($tenant, $conversation, $asset);

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_invoice',
                'reasons' => [],
                'meta' => [
                    'send_invoice' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => $account->provider_ref,
                        'to' => '+6281222333444',
                        'invoice_id' => $invoice->id,
                        'tenant_asset_id' => $asset->id,
                        'file_name' => 'invoice-april.pdf',
                        'mime_type' => 'application/pdf',
                    ],
                ],
            ]
        );

        $this->assertSame('executed', $result['status']);
        $this->assertSame('send_invoice', $result['action']);
        $this->assertNull($result['reason']);
        $this->assertTrue($result['meta']['executed']);
        $this->assertArrayHasKey('wa_outbound_message_id', $result['meta']);
        $this->assertArrayHasKey('invoice_send_log_id', $result['meta']);

        $this->assertDatabaseHas('invoice_send_logs', [
            'id' => $result['meta']['invoice_send_log_id'],
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'invoice_id' => $invoice->id,
            'status' => 'sent',
            'failure_reason' => null,
        ]);
        $this->assertDatabaseHas('conversation_states', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'agent_mode' => 'limited',
        ]);
        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_invoice',
            'status' => 'executed',
            'reason' => null,
        ]);

        Queue::assertPushed(DispatchWaOutboundMessageJob::class);
    }

    public function test_invalid_send_invoice_contract_is_blocked_and_logged(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $this->createWaAccount($tenant);

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_invoice',
                'reasons' => [],
                'meta' => [
                    'send_invoice' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => 'acct-001',
                        'to' => '+6281222333444',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_send_invoice_contract', $result['reason']);
        $this->assertDatabaseCount('invoice_send_logs', 0);
        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'send_invoice',
            'status' => 'blocked',
            'reason' => 'invalid_send_invoice_contract',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_send_invoice_resend_is_blocked_when_max_count_is_reached(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $account = $this->createWaAccount($tenant);
        $asset = $this->createInvoiceAsset($tenant);
        $invoice = $this->createInvoice($tenant, $conversation, $asset);

        $this->assertSame('executed', app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_invoice',
                'reasons' => [],
                'meta' => [
                    'send_invoice' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => $account->provider_ref,
                        'to' => '+6281222333444',
                        'invoice_id' => $invoice->id,
                        'tenant_asset_id' => $asset->id,
                        'file_name' => 'invoice-april.pdf',
                        'mime_type' => 'application/pdf',
                        'max_send_count' => 1,
                    ],
                ],
            ]
        )['status']);

        $second = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_invoice',
                'reasons' => [],
                'meta' => [
                    'send_invoice' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => $account->provider_ref,
                        'to' => '+6281222333444',
                        'invoice_id' => $invoice->id,
                        'tenant_asset_id' => $asset->id,
                        'file_name' => 'invoice-april.pdf',
                        'mime_type' => 'application/pdf',
                        'max_send_count' => 1,
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $second['status']);
        $this->assertSame('send_invoice_max_count_exceeded', $second['reason']);
        $this->assertDatabaseCount('invoice_send_logs', 1);
    }

    public function test_send_invoice_blocks_when_invoice_or_asset_not_owned_by_tenant(): void
    {
        Queue::fake();
        [$tenantA, $conversationA] = $this->createConversation('tenant-a');
        [$tenantB, $conversationB] = $this->createConversation('tenant-b');
        $accountA = $this->createWaAccount($tenantA, 'acct-tenant-a');
        $assetB = $this->createInvoiceAsset($tenantB);
        $invoiceB = $this->createInvoice($tenantB, $conversationB, $assetB);

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenantA,
            $conversationA,
            [
                'action' => 'send_invoice',
                'reasons' => [],
                'meta' => [
                    'send_invoice' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => $accountA->provider_ref,
                        'to' => '+6281222333444',
                        'invoice_id' => $invoiceB->id,
                        'tenant_asset_id' => $assetB->id,
                        'file_name' => 'invoice-foreign.pdf',
                        'mime_type' => 'application/pdf',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('send_invoice_invoice_not_owned', $result['reason']);
        $this->assertDatabaseCount('invoice_send_logs', 0);
        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenantA->id,
            'conversation_id' => $conversationA->id,
            'action' => 'send_invoice',
            'status' => 'blocked',
            'reason' => 'send_invoice_invoice_not_owned',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_send_invoice_dispatch_failure_is_blocked_and_persists_failed_send_log(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        $asset = $this->createInvoiceAsset($tenant);
        $invoice = $this->createInvoice($tenant, $conversation, $asset);

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'send_invoice',
                'reasons' => [],
                'meta' => [
                    'send_invoice' => [
                        'provider' => 'meta',
                        'wa_account_provider_ref' => 'missing-acct',
                        'to' => '+6281222333444',
                        'invoice_id' => $invoice->id,
                        'tenant_asset_id' => $asset->id,
                        'file_name' => 'invoice-april.pdf',
                        'mime_type' => 'application/pdf',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('send_invoice_dispatch_failed', $result['reason']);
        $this->assertDatabaseHas('invoice_send_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'invoice_id' => $invoice->id,
            'status' => 'failed',
            'failure_reason' => 'send_invoice_dispatch_failed',
        ]);
        $this->assertDatabaseHas('conversation_states', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'agent_mode' => 'assistant',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_allowed_handoff_to_human_executes_persists_and_queues_notification(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'handoff_to_human',
                'reasons' => [],
                'meta' => [
                    'handoff_to_human' => [
                        'reason_code' => 'low_confidence',
                        'note' => 'Need manual follow-up',
                        'context' => [
                            'intent' => 'ask_price',
                            'confidence' => 0.2,
                        ],
                    ],
                ],
            ]
        );

        $this->assertSame('executed', $result['status']);
        $this->assertSame('handoff_to_human', $result['action']);
        $this->assertNull($result['reason']);
        $this->assertTrue($result['meta']['executed']);
        $this->assertArrayHasKey('handoff_id', $result['meta']);
        $this->assertArrayHasKey('notification_id', $result['meta']);

        $this->assertDatabaseHas('handoffs', [
            'id' => $result['meta']['handoff_id'],
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'reason_code' => 'low_confidence',
            'note' => 'Need manual follow-up',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('notifications', [
            'id' => $result['meta']['notification_id'],
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'type' => 'handoff_created',
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('conversation_states', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'agent_mode' => 'handoff',
        ]);
        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'handoff_to_human',
            'status' => 'executed',
            'reason' => null,
        ]);

        Queue::assertPushed(DispatchNotificationJob::class, function (DispatchNotificationJob $job) use ($tenant, $result): bool {
            return $job->tenantId === $tenant->id
                && $job->notificationId === $result['meta']['notification_id'];
        });
    }

    public function test_blocked_handoff_to_human_with_candidate_reasons_skips_side_effects_and_logs(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'handoff_to_human',
                'reasons' => ['policy_blocked'],
                'meta' => [
                    'handoff_to_human' => [
                        'reason_code' => 'low_confidence',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('policy_blocked', $result['reason']);
        $this->assertDatabaseCount('handoffs', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseHas('conversation_states', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'agent_mode' => 'assistant',
        ]);
        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'handoff_to_human',
            'status' => 'blocked',
            'reason' => 'policy_blocked',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_invalid_handoff_to_human_contract_is_blocked_and_logged(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'handoff_to_human',
                'reasons' => [],
                'meta' => [
                    'handoff_to_human' => [
                        'note' => 'missing reason code',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('invalid_handoff_to_human_contract', $result['reason']);
        $this->assertDatabaseCount('handoffs', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseHas('conversation_states', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'agent_mode' => 'assistant',
        ]);
        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'handoff_to_human',
            'status' => 'blocked',
            'reason' => 'invalid_handoff_to_human_contract',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_handoff_to_human_without_conversation_state_is_blocked_and_has_no_partial_writes(): void
    {
        Queue::fake();
        [$tenant, $conversation] = $this->createConversation();
        ConversationState::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->delete();

        $result = app(ActionDispatcherService::class)->dispatch(
            $tenant,
            $conversation,
            [
                'action' => 'handoff_to_human',
                'reasons' => [],
                'meta' => [
                    'handoff_to_human' => [
                        'reason_code' => 'low_confidence',
                    ],
                ],
            ]
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('handoff_state_not_found', $result['reason']);
        $this->assertDatabaseCount('handoffs', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseHas('action_logs', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'action' => 'handoff_to_human',
            'status' => 'blocked',
            'reason' => 'handoff_state_not_found',
        ]);

        Queue::assertNothingPushed();
    }

    private function createConversation(string $slug = 'tenant-one'): array
    {
        $tenant = Tenant::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
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

    private function createWaAccount(Tenant $tenant, string $providerRef = 'acct-001'): WaAccount
    {
        return WaAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'provider_ref' => $providerRef,
            'status' => 'connected',
            'phone' => '+6281999999999',
            'last_payload' => ['event' => 'connected'],
        ]);
    }

    private function createTenantAsset(Tenant $tenant): TenantAsset
    {
        return TenantAsset::query()->create([
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
    }

    private function createBookingSetting(
        Tenant $tenant,
        string $url,
        ?\Carbon\CarbonInterface $activeFrom = null,
        ?\Carbon\CarbonInterface $activeUntil = null
    ): BookingSetting {
        return BookingSetting::query()->create([
            'tenant_id' => $tenant->id,
            'booking_url' => $url,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => $activeFrom ?? now()->subDay(),
            'active_until' => $activeUntil ?? now()->addDay(),
        ]);
    }

    private function createInvoiceAsset(Tenant $tenant): TenantAsset
    {
        return TenantAsset::query()->create([
            'tenant_id' => $tenant->id,
            'asset_type' => 'invoice',
            'display_name' => 'Invoice April',
            'original_filename' => 'invoice-april.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'tenant-assets/invoice/'.$tenant->id.'/invoice-april.pdf',
            'uploaded_by_user_id' => null,
            'sort_order' => 1,
            'is_active' => true,
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);
    }

    private function createInvoice(Tenant $tenant, Conversation $conversation, TenantAsset $asset): Invoice
    {
        return Invoice::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'tenant_asset_id' => $asset->id,
            'customer_phone' => $conversation->customer_phone,
            'status' => 'ready',
            'issued_at' => now(),
        ]);
    }
}
