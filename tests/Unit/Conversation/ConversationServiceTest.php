<?php

namespace Tests\Unit\Conversation;

use App\Enums\MessageDirection;
use App\Models\ConversationState;
use App\Models\Tenant;
use App\Jobs\GenerateConversationSummaryJob;
use App\Modules\Conversation\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ConversationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_phone_creates_open_conversation_and_initializes_state_defaults(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $service = app(ConversationService::class);

        $conversation = $service->findOrCreateActiveConversation($tenant, '+628111111111');

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'tenant_id' => $tenant->id,
            'customer_phone' => '+628111111111',
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('conversation_states', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'current_stage' => 'new',
            'active_goal' => null,
            'agent_mode' => 'assistant',
            'memory_mode' => 'short',
            'retention_policy' => 'standard',
            'retention_until' => null,
        ]);

        $this->assertDatabaseHas('lead_profiles', [
            'tenant_id' => $tenant->id,
            'customer_phone' => '+628111111111',
        ]);

        $leadProfileId = (int) \DB::table('lead_profiles')
            ->where('tenant_id', $tenant->id)
            ->where('customer_phone', '+628111111111')
            ->value('id');

        $this->assertDatabaseHas('lead_scores', [
            'tenant_id' => $tenant->id,
            'lead_profile_id' => $leadProfileId,
            'score_value' => 0,
            'score_label' => 'unscored',
        ]);

        $this->assertDatabaseHas('lead_sources', [
            'tenant_id' => $tenant->id,
            'lead_profile_id' => $leadProfileId,
            'source' => 'whatsapp',
        ]);
    }

    public function test_existing_phone_reuses_latest_open_conversation_and_state_row(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $service = app(ConversationService::class);

        $first = $service->findOrCreateActiveConversation($tenant, '+628122222222');
        $second = $service->findOrCreateActiveConversation($tenant, '+628122222222');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('conversation_states', 1);
        $this->assertDatabaseCount('lead_profiles', 1);
        $this->assertDatabaseCount('lead_scores', 1);
        $this->assertDatabaseCount('lead_sources', 1);
    }

    public function test_store_message_persists_direction_using_enum_value(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $service = app(ConversationService::class);
        $conversation = $service->findOrCreateActiveConversation($tenant, '+628133333333');

        $message = $service->storeMessage(
            $conversation,
            $tenant,
            MessageDirection::Inbound,
            'Halo, saya mau tanya paket.',
            ['source' => 'wa']
        );

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'Halo, saya mau tanya paket.',
        ]);

        $this->assertNotNull($conversation->fresh()->last_message_at);
    }

    public function test_tenant_isolation_prevents_cross_tenant_reuse_and_write(): void
    {
        $tenantOne = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $tenantTwo = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        $service = app(ConversationService::class);

        $tenantOneConversation = $service->findOrCreateActiveConversation($tenantOne, '+628144444444');
        $tenantTwoConversation = $service->findOrCreateActiveConversation($tenantTwo, '+628144444444');

        $this->assertNotSame($tenantOneConversation->id, $tenantTwoConversation->id);

        $this->assertDatabaseCount('lead_profiles', 2);
        $this->assertDatabaseCount('lead_scores', 2);
        $this->assertDatabaseCount('lead_sources', 2);

        $this->expectException(HttpException::class);
        $service->storeMessage(
            $tenantOneConversation,
            $tenantTwo,
            MessageDirection::Outbound,
            'Cross tenant write must fail.'
        );
    }

    public function test_tenant_isolation_prevents_cross_tenant_state_write(): void
    {
        $tenantOne = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $tenantTwo = Tenant::query()->create([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        $service = app(ConversationService::class);
        $tenantOneConversation = $service->findOrCreateActiveConversation($tenantOne, '+628199999999');

        $this->expectException(HttpException::class);
        $service->upsertState($tenantOneConversation, $tenantTwo, [
            'current_stage' => 'qualifying',
        ]);
    }

    public function test_upsert_state_updates_retention_policy_and_ttl(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $service = app(ConversationService::class);
        $conversation = $service->findOrCreateActiveConversation($tenant, '+628188888888');

        $state = $service->upsertState($conversation, $tenant, [
            'retention_policy' => 'strict_30d',
            'retention_until' => '2026-06-01 00:00:00',
        ]);

        $this->assertInstanceOf(ConversationState::class, $state);
        $this->assertDatabaseHas('conversation_states', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'retention_policy' => 'strict_30d',
        ]);
    }

    public function test_store_message_dispatches_summary_job_when_message_count_reaches_twenty(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $service = app(ConversationService::class);
        $conversation = $service->findOrCreateActiveConversation($tenant, '+628177777777');

        Queue::fake();

        for ($i = 1; $i <= 19; $i++) {
            $service->storeMessage(
                $conversation,
                $tenant,
                MessageDirection::Inbound,
                "Pesan ke-{$i}"
            );
        }

        Queue::assertNothingPushed();

        $service->storeMessage(
            $conversation,
            $tenant,
            MessageDirection::Inbound,
            'Pesan ke-20'
        );

        Queue::assertPushed(GenerateConversationSummaryJob::class, 1);
    }

    public function test_store_message_does_not_dispatch_summary_job_before_threshold(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $service = app(ConversationService::class);
        $conversation = $service->findOrCreateActiveConversation($tenant, '+628166666666');

        Queue::fake();

        for ($i = 1; $i <= 19; $i++) {
            $service->storeMessage(
                $conversation,
                $tenant,
                MessageDirection::Inbound,
                "Pesan ke-{$i}"
            );
        }

        Queue::assertNothingPushed();
    }
}
