<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Invoice;
use App\Models\TenantAsset;
use App\Modules\AdminUi\Services\TenantConversationInboxQueryService;
use App\Modules\AdminUi\Services\TenantHandoffResolutionService;
use App\Modules\Tenancy\Services\TenantContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantConversationInboxController extends Controller
{
    public function __construct(
        private readonly TenantContextResolver $tenantContextResolver,
        private readonly TenantConversationInboxQueryService $inboxQueryService,
        private readonly TenantHandoffResolutionService $handoffResolutionService,
    ) {
    }

    public function show(Request $request): Response
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $query = trim((string) $request->query('q', ''));

        $conversationId = $request->query('conversation_id');
        if ($conversationId !== null) {
            $conversation = Conversation::query()->findOrFail((int) $conversationId);
            if ((int) $conversation->tenant_id !== $tenantId) {
                throw new HttpException(403, 'Forbidden tenant scope.');
            }
        }

        $inboxData = $this->inboxQueryService->getInboxData(
            $tenantId,
            $conversationId !== null ? (int) $conversationId : null,
            $query,
        );

        return Inertia::render('Tenant/Inbox', [
            'tenantId' => $tenantId,
            'query' => $query,
            'conversationList' => $inboxData['conversationList'],
            'selectedConversation' => $inboxData['selectedConversation'],
            'messages' => $inboxData['messages'],
            'handoffs' => $inboxData['handoffs'],
            'contextPanel' => $inboxData['contextPanel'],
            'invoiceAssets' => $inboxData['invoiceAssets'],
            'invoices' => $inboxData['invoices'],
        ]);
    }

    public function storeInvoice(Request $request, int $conversation): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $conversationRow = Conversation::query()->findOrFail($conversation);
        if ((int) $conversationRow->tenant_id !== $tenantId) {
            throw new HttpException(403, 'Forbidden tenant scope.');
        }

        $payload = $request->validate([
            'tenant_asset_id' => ['required', 'integer', 'exists:tenant_assets,id'],
        ]);

        $asset = TenantAsset::query()->findOrFail((int) $payload['tenant_asset_id']);
        if ((int) $asset->tenant_id !== $tenantId || $asset->asset_type !== 'invoice') {
            throw new HttpException(403, 'Forbidden tenant scope.');
        }

        Invoice::query()->create([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationRow->id,
            'tenant_asset_id' => $asset->id,
            'customer_phone' => (string) $conversationRow->customer_phone,
            'status' => 'ready',
            'issued_at' => now(),
            'last_sent_at' => null,
        ]);

        return redirect('/tenant/inbox?conversation_id='.$conversationRow->id)
            ->with('success', 'Invoice untuk lead berhasil dibuat.');
    }

    public function resolveHandoff(Request $request, int $conversation, int $handoff): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);

        $this->handoffResolutionService->resolve($tenantId, $conversation, $handoff);

        return redirect('/tenant/inbox?conversation_id='.$conversation);
    }

    public function resumeAi(Request $request, int $conversation, int $handoff): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);

        $this->handoffResolutionService->resumeAi($tenantId, $conversation, $handoff);

        return redirect('/tenant/inbox?conversation_id='.$conversation);
    }

    private function resolveAuthorizedTenantId(Request $request): int
    {
        $user = $request->user();
        if ($user->role !== UserRole::TenantAdmin) {
            throw new HttpException(403, 'Forbidden role.');
        }

        $context = $this->tenantContextResolver->resolve($user);
        if (! is_int($context->tenantId)) {
            throw new HttpException(403, 'Tenant context unavailable.');
        }

        return $context->tenantId;
    }
}
