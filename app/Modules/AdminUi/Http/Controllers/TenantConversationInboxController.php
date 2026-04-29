<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
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
        ]);
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
