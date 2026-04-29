<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Modules\AdminUi\Services\SuperadminConversationMonitoringQueryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SuperadminConversationMonitoringController extends Controller
{
    public function __construct(
        private readonly SuperadminConversationMonitoringQueryService $queryService,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->assertSuperadmin($request);
        $data = $this->queryService->getMonitoringData();

        return Inertia::render('Superadmin/ConversationsIndex', [
            'summary' => $data['summary'],
            'rows' => $data['rows'],
        ]);
    }

    private function assertSuperadmin(Request $request): void
    {
        if ($request->user()?->role !== UserRole::Superadmin) {
            throw new HttpException(403, 'Forbidden role.');
        }
    }
}
