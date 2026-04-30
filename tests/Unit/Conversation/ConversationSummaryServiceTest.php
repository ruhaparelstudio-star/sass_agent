<?php

namespace Tests\Unit\Conversation;

use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\Tenant;
use App\Modules\Conversation\Services\ConversationService;
use App\Modules\Conversation\Services\ConversationSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_if_eligible_dispatches_only_when_message_count_reaches_threshold(): void
    {
        $tenant = $this->createTenant('tenant-one');
        $conversation = $this->createConversation($tenant, '+628111111111');

        $summaryService = app(ConversationSummaryService::class);

        for ($i = 1; $i <= 19; $i++) {
            $this->storeMessage($conversation, $tenant, "msg {$i}");
        }

        \Queue::fake();
        $summaryService->queueIfEligible($tenant, $conversation);
        \Queue::assertNothingPushed();

        $this->storeMessage($conversation, $tenant, 'msg 20');

        $summaryService->queueIfEligible($tenant, $conversation);

        \Queue::assertPushed(\App\Jobs\GenerateConversationSummaryJob::class, 1);
    }

    public function test_generate_for_conversation_is_tenant_scoped_and_ignores_cross_tenant_request(): void
    {
        $tenantOne = $this->createTenant('tenant-one');
        $tenantTwo = $this->createTenant('tenant-two');

        $conversation = $this->createConversation($tenantOne, '+628122222222');

        for ($i = 1; $i <= 20; $i++) {
            $this->storeMessage($conversation, $tenantOne, "msg {$i}");
        }

        app(ConversationSummaryService::class)->generateForConversation($tenantTwo->id, $conversation->id);

        $this->assertDatabaseCount('conversation_summaries', 0);
    }

    public function test_generate_for_conversation_skips_when_retention_is_expired(): void
    {
        $tenant = $this->createTenant('tenant-one');
        $conversation = $this->createConversation($tenant, '+628133333333');

        app(ConversationService::class)->upsertState($conversation, $tenant, [
            'retention_until' => now()->subMinute()->toDateTimeString(),
        ]);

        for ($i = 1; $i <= 22; $i++) {
            $this->storeMessage($conversation, $tenant, "msg {$i}");
        }

        app(ConversationSummaryService::class)->generateForConversation($tenant->id, $conversation->id);

        $this->assertDatabaseCount('conversation_summaries', 0);
    }

    public function test_generate_for_conversation_builds_deterministic_non_empty_summary(): void
    {
        $tenant = $this->createTenant('tenant-one');
        $conversation = $this->createConversation($tenant, '+628144444444');

        for ($i = 1; $i <= 20; $i++) {
            $direction = $i % 2 === 0 ? MessageDirection::Outbound : MessageDirection::Inbound;
            $this->storeMessage($conversation, $tenant, "Pesan {$i}", $direction);
        }

        $service = app(ConversationSummaryService::class);
        $service->generateForConversation($tenant->id, $conversation->id);

        $first = ConversationSummary::query()->where('tenant_id', $tenant->id)->where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertNotSame('', trim($first->summary));
        $this->assertSame(20, $first->message_count);
        $this->assertIsArray($first->summary_json);
        $this->assertArrayHasKey('lead_profile', $first->summary_json ?? []);
        $this->assertArrayHasKey('need', $first->summary_json ?? []);
        $this->assertArrayHasKey('entities', $first->summary_json ?? []);
        $this->assertArrayHasKey('objection', $first->summary_json ?? []);
        $this->assertArrayHasKey('last_stage', $first->summary_json ?? []);
        $this->assertArrayHasKey('last_active_goal', $first->summary_json ?? []);
        $this->assertArrayHasKey('unresolved_action', $first->summary_json ?? []);

        $service->generateForConversation($tenant->id, $conversation->id);

        $second = ConversationSummary::query()->where('tenant_id', $tenant->id)->where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertSame($first->summary, $second->summary);
        $this->assertSame($first->message_count, $second->message_count);
    }

    public function test_generate_for_conversation_refreshes_summary_after_threshold_when_message_count_increases(): void
    {
        $tenant = $this->createTenant('tenant-one');
        $conversation = $this->createConversation($tenant, '+628155555555');

        for ($i = 1; $i <= 20; $i++) {
            $this->storeMessage($conversation, $tenant, "Pesan {$i}");
        }

        $service = app(ConversationSummaryService::class);
        $service->generateForConversation($tenant->id, $conversation->id);

        $first = ConversationSummary::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();
        $this->assertSame(20, $first->message_count);

        $this->storeMessage($conversation, $tenant, 'Pesan 21');
        $service->generateForConversation($tenant->id, $conversation->id);

        $second = ConversationSummary::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();

        $this->assertSame(21, $second->message_count);
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'is_active' => true,
        ]);
    }

    private function createConversation(Tenant $tenant, string $phone): Conversation
    {
        return app(ConversationService::class)->findOrCreateActiveConversation($tenant, $phone);
    }

    private function storeMessage(
        Conversation $conversation,
        Tenant $tenant,
        string $content,
        MessageDirection $direction = MessageDirection::Inbound
    ): void
    {
        \App\Models\Message::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => $direction,
            'content' => $content,
            'meta' => null,
        ]);
    }
}
