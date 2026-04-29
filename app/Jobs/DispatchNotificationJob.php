<?php

namespace App\Jobs;

use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $notificationId
    ) {
    }

    public function handle(): void
    {
        $notification = Notification::query()
            ->where('id', $this->notificationId)
            ->where('tenant_id', $this->tenantId)
            ->first();

        if (! $notification || $notification->status !== 'queued') {
            return;
        }

        $notification->status = 'sent';
        $notification->sent_at = now();
        $notification->failed_at = null;
        $notification->failure_reason = null;
        $notification->save();
    }
}
