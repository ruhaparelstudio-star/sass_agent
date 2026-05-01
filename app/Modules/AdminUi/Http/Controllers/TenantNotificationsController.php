<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Modules\Tenancy\Services\TenantContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantNotificationsController extends Controller
{
    public function __construct(
        private readonly TenantContextResolver $tenantContextResolver,
    ) {}

    public function show(Request $request): Response
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $filter = $request->query('filter', 'all');

        $query = Notification::query()
            ->where('tenant_id', $tenantId)
            ->latest('id');

        if ($filter === 'unread') {
            $query->where('status', '!=', 'read');
        } elseif ($filter === 'failed') {
            $query->where('status', 'failed');
        }

        $notifications = $query->limit(50)->get([
            'id',
            'tenant_id',
            'conversation_id',
            'handoff_id',
            'type',
            'channel',
            'status',
            'payload',
            'sent_at',
            'failed_at',
            'failure_reason',
            'created_at',
        ])->map(fn (Notification $n): array => [
            'id' => $n->id,
            'conversation_id' => $n->conversation_id,
            'handoff_id' => $n->handoff_id,
            'type' => $n->type,
            'channel' => $n->channel,
            'status' => $n->status,
            'is_read' => $n->status === 'read' || $n->status === 'sent',
            'payload' => $n->payload,
            'sent_at' => $n->sent_at,
            'failed_at' => $n->failed_at,
            'failure_reason' => $n->failure_reason,
            'created_at' => $n->created_at,
            'type_label' => $this->typeLabel((string) $n->type),
            'status_label' => $this->statusLabel((string) $n->status),
        ])->toArray();

        $summary = [
            'total' => Notification::query()->where('tenant_id', $tenantId)->count(),
            'unread' => Notification::query()->where('tenant_id', $tenantId)->whereNotIn('status', ['read', 'sent'])->count(),
            'failed' => Notification::query()->where('tenant_id', $tenantId)->where('status', 'failed')->count(),
        ];

        return Inertia::render('Tenant/Notifications', [
            'notifications' => $notifications,
            'summary' => $summary,
            'filter' => $filter,
        ]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);

        Notification::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['read', 'sent', 'failed'])
            ->update(['status' => 'read']);

        return redirect('/tenant/notifications')->with('success', 'Semua notifikasi sudah ditandai dibaca.');
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'handoff_required' => 'Handoff Diperlukan',
            'booking_action' => 'Aksi Booking',
            'complaint' => 'Keluhan',
            'invoice_phase_reply' => 'Balasan Fase Invoice',
            'resend_invoice_request' => 'Permintaan Kirim Ulang Invoice',
            'message_while_paused' => 'Pesan Saat Jeda',
            'wa_session_disconnected' => 'Sesi WA Terputus',
            'calendar_integration_error' => 'Error Kalender',
            'lead_limit_exhausted' => 'Batas Lead Habis',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'queued' => 'Antrean',
            'sent' => 'Terkirim',
            'failed' => 'Gagal',
            'read' => 'Dibaca',
            default => ucfirst($status),
        };
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
