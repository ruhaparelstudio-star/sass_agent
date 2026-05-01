<?php

namespace App\Modules\AdminUi\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\TenantAiSetting;
use App\Modules\Tenancy\Services\TenantContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantAiConfigController extends Controller
{
    public function __construct(
        private readonly TenantContextResolver $tenantContextResolver,
    ) {}

    public function show(Request $request): Response
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);
        $settings = TenantAiSetting::query()->where('tenant_id', $tenantId)->first();

        $defaults = [
            'ai_tone' => 'professional',
            'reply_delay_seconds' => 2,
            'followup_enabled' => true,
            'followup_delay_hours' => 24,
            'pricelist_mode' => 'text_first',
            'pricelist_min_requirement' => 'name_only',
            'pricelist_file_enabled' => true,
            'out_of_hours_auto_reply' => true,
            'out_of_hours_message' => 'Terima kasih sudah menghubungi kami! Kami sedang di luar jam operasional. Tim kami akan membalas pesan Anda segera.',
        ];

        return Inertia::render('Tenant/AiConfig', [
            'settings' => $settings ? [
                'ai_tone' => $settings->ai_tone,
                'reply_delay_seconds' => $settings->reply_delay_seconds,
                'followup_enabled' => $settings->followup_enabled,
                'followup_delay_hours' => $settings->followup_delay_hours,
                'pricelist_mode' => $settings->pricelist_mode,
                'pricelist_min_requirement' => $settings->pricelist_min_requirement,
                'pricelist_file_enabled' => $settings->pricelist_file_enabled,
                'out_of_hours_auto_reply' => $settings->out_of_hours_auto_reply,
                'out_of_hours_message' => $settings->out_of_hours_message,
            ] : $defaults,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveAuthorizedTenantId($request);

        $payload = $request->validate([
            'ai_tone' => ['required', 'string', 'in:professional,casual,friendly,formal'],
            'reply_delay_seconds' => ['required', 'integer', 'min:0', 'max:60'],
            'followup_enabled' => ['required', 'boolean'],
            'followup_delay_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'pricelist_mode' => ['required', 'string', 'in:text_first,file_first'],
            'pricelist_min_requirement' => ['required', 'string', 'in:name_only,name_date'],
            'pricelist_file_enabled' => ['required', 'boolean'],
            'out_of_hours_auto_reply' => ['required', 'boolean'],
            'out_of_hours_message' => ['nullable', 'string', 'max:500'],
        ]);

        TenantAiSetting::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            $payload,
        );

        return redirect('/tenant/ai-config')->with('success', 'Konfigurasi AI berhasil disimpan.');
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
