<?php

namespace App\Modules\WhatsApp\Services;

use App\Enums\MessageDirection;
use App\Models\Tenant;
use App\Models\WaInboundMessage;
use App\Models\WaOutboundMessage;
use App\Modules\Conversation\Services\ConversationService;

class WaInboundAutoReplyService
{
    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly WaOutboundService $waOutboundService,
    ) {}

    public function process(Tenant $tenant, WaInboundMessage $inboundMessage): void
    {
        $alreadyQueued = WaOutboundMessage::query()
            ->where('tenant_id', $tenant->id)
            ->whereJsonContains('meta->inbound_message_id', $inboundMessage->id)
            ->exists();

        if ($alreadyQueued) {
            return;
        }

        $text = $this->extractText($inboundMessage->payload ?? []);
        if ($text === null) {
            return;
        }

        $customerPhone = $this->normalizePhone($inboundMessage->from);
        if ($customerPhone === null) {
            return;
        }

        $conversation = $this->conversationService->findOrCreateActiveConversation($tenant, $customerPhone);
        $this->conversationService->storeMessage($conversation, $tenant, MessageDirection::Inbound, $text, [
            'source' => 'whatsapp',
            'wa_inbound_message_id' => $inboundMessage->id,
            'provider_message_id' => $inboundMessage->provider_message_id,
        ]);

        $replyText = 'Pesan Anda sudah kami terima. Tim kami akan segera membalas.';

        $this->conversationService->storeMessage($conversation, $tenant, MessageDirection::Outbound, $replyText, [
            'source' => 'whatsapp_auto_reply',
            'wa_inbound_message_id' => $inboundMessage->id,
        ]);

        $this->waOutboundService->queueOutboundMessage($tenant, [
            'provider' => $inboundMessage->provider,
            'wa_account_provider_ref' => (string) $inboundMessage->account?->provider_ref,
            'wa_session_provider_ref' => $inboundMessage->session?->provider_ref,
            'provider_message_id' => null,
            'to' => $inboundMessage->from,
            'message_type' => 'text',
            'payload' => ['text' => $replyText],
            'meta' => [
                'source' => 'inbound_auto_reply',
                'inbound_message_id' => $inboundMessage->id,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function extractText(array $payload): ?string
    {
        $message = $payload['message'] ?? null;
        if (! is_array($message)) {
            return null;
        }

        $conversation = $message['conversation'] ?? null;
        if (is_string($conversation) && trim($conversation) !== '') {
            return trim($conversation);
        }

        $extendedText = $message['extendedTextMessage']['text'] ?? null;
        if (is_string($extendedText) && trim($extendedText) !== '') {
            return trim($extendedText);
        }

        return null;
    }

    private function normalizePhone(string $raw): ?string
    {
        $normalized = preg_replace('/[^0-9]/', '', $raw);
        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        return $normalized;
    }
}
