<?php

namespace App\Modules\CoreEngine\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Composes the final outbound reply string for a turn.
 *
 * Goal-driven: each goal has a deterministic template family. Optional tenant
 * tone (from tenant_ai_settings.ai_tone) and per-goal custom overrides are
 * supported. Placeholders are filled from $entities + $grounding only — no
 * free-form LLM placeholder substitution to avoid hallucination.
 */
class ResponseComposerService
{
    /**
     * @param  array<string,mixed>  $decision  Decision payload (intent, allowed_actions, blocked_actions, active_goal, action_candidates, ...)
     * @param  array<string,mixed>  $grounding
     * @param  array<string,mixed>  $entities
     */
    public function compose(array $decision, array $grounding, array $entities, Tenant $tenant): string
    {
        $goal = (string) ($decision['active_goal'] ?? '');
        if ($goal === '') {
            $goal = 'clarification';
        }

        $tone = $this->resolveTone($tenant);
        $customTemplate = $this->resolveCustomTemplate($tenant, $goal);

        $message = $customTemplate !== null
            ? $this->fillPlaceholders($customTemplate, $tenant, $entities, $grounding, $decision)
            : $this->renderGoalTemplate($goal, $decision, $entities, $grounding, $tenant, $tone);

        $message = trim($message);
        if ($message === '') {
            $message = $this->fallbackForGoal($goal, $tenant);
        }

        return $message;
    }

    private function renderGoalTemplate(
        string $goal,
        array $decision,
        array $entities,
        array $grounding,
        Tenant $tenant,
        string $tone
    ): string {
        $candidates = is_array($decision['action_candidates'] ?? null) ? $decision['action_candidates'] : [];
        $allowed = is_array($candidates['allowed'] ?? null) ? $candidates['allowed'] : [];
        $blocked = is_array($candidates['blocked'] ?? null) ? $candidates['blocked'] : [];
        $reasons = is_array($blocked[0]['reasons'] ?? null) ? $blocked[0]['reasons'] : [];
        $allowedAction = (string) ($allowed[0]['action'] ?? '');
        $meta = is_array($blocked[0]['meta'] ?? null) ? $blocked[0]['meta'] : (is_array($allowed[0]['meta'] ?? null) ? $allowed[0]['meta'] : []);

        $tenantName = $this->tenantName($tenant);

        switch ($goal) {
            case 'opening':
                $phase = (string) ($meta['opening_phase'] ?? 'greeting');
                if ($phase === 'intro_interest') {
                    return sprintf('Halo kak, terima kasih sudah hubungi %s. Acara apa yang sedang kakak rencanakan?', $tenantName);
                }
                return sprintf('Halo kak, perkenalkan saya asisten %s. Ada yang bisa saya bantu untuk acaranya kak?', $tenantName);

            case 'qualification':
                $next = (string) ($meta['next_required'] ?? '');
                return $this->qualificationPrompt($next, $entities);

            case 'pricing':
                if ($allowedAction === 'send_pricelist_file' || $allowedAction === 'send_file') {
                    return 'Ini pricelist terbaru kami ya kak, kalau ada yang kurang jelas boleh langsung ditanyakan.';
                }
                return $this->blockerPrompt($reasons);

            case 'package_explanation':
                $summary = is_array($meta['package_summary'] ?? null)
                    ? $meta['package_summary']
                    : (is_array($grounding['package_summary'] ?? null) ? $grounding['package_summary'] : null);
                if ($summary !== null) {
                    $packageName = trim((string) ($summary['package_name'] ?? 'Paket pilihan'));
                    if ($packageName === '') {
                        $packageName = 'Paket pilihan';
                    }
                    $items = is_array($summary['items'] ?? null) ? $summary['items'] : [];
                    $itemList = array_values(array_filter(array_map(
                        static fn ($i): string => is_string($i) ? trim($i) : '',
                        $items
                    ), static fn (string $s): bool => $s !== ''));
                    $itemSentence = $itemList === []
                        ? 'bisa kami jelaskan lebih lanjut sesuai kebutuhan kakak'
                        : implode(', ', array_slice($itemList, 0, 3));
                    return sprintf('Untuk %s, detail photo+video-nya %s.', $packageName, $itemSentence);
                }
                $packagesList = is_array($meta['packages_list'] ?? null) ? $meta['packages_list'] : [];
                $packagesList = array_values(array_filter(array_map(
                    static fn ($p): string => is_string($p) ? trim($p) : '',
                    $packagesList
                ), static fn (string $s): bool => $s !== ''));
                if ($packagesList !== []) {
                    return sprintf(
                        'Saat ini kami punya beberapa paket aktif kak: %s. Mau saya jelaskan detail paket yang mana?',
                        implode(', ', array_slice($packagesList, 0, 5))
                    );
                }
                $namedPackage = $this->stringFrom($entities['resolved_package_name'] ?? ($entities['package_query'] ?? ($entities['package_interest'] ?? null)));
                if ($namedPackage !== '') {
                    return sprintf('Untuk paket %s, detail lengkapnya bisa langsung ditanyakan ke tim kami ya kak.', $namedPackage);
                }
                if (($meta['clarification_for'] ?? null) === 'package') {
                    return 'Boleh sebutkan paket atau layanan yang mau dijelaskan detailnya ya kak?';
                }
                return 'Boleh sebutkan paket atau layanan yang mau dijelaskan detailnya ya kak?';

            case 'availability':
                $availability = is_array($meta['availability'] ?? null) ? $meta['availability'] : [];
                $status = (string) ($availability['status'] ?? '');
                if ($status === 'available') {
                    $confirmed = $this->stringFrom($availability['confirmed_date'] ?? null);
                    if ($confirmed !== '') {
                        return sprintf('Sip kak, tanggal %s masih tersedia. Mau saya bantu lanjut booking?', $confirmed);
                    }
                    return 'Sip kak, tanggalnya masih tersedia. Mau saya bantu lanjut booking?';
                }
                if ($status === 'unavailable') {
                    $requested = $this->stringFrom($availability['requested_date'] ?? null);
                    if ($requested !== '') {
                        return sprintf('Mohon maaf kak, tanggal %s sudah terisi. Mau saya bantu cek tanggal alternatif?', $requested);
                    }
                    return 'Mohon maaf kak, tanggal yang diminta sudah terisi. Mau saya bantu cek tanggal alternatif?';
                }
                if (in_array('missing_event_date', $reasons, true)) {
                    return 'Boleh share tanggal acara yang ingin dicek ketersediaannya kak?';
                }
                if (in_array('calendar_provider_error', $reasons, true)) {
                    return 'Maaf kak, kalender kami sedang ada kendala. Saya hubungkan ke tim untuk bantu cek manual.';
                }
                $calendar = is_array($grounding['calendar'] ?? null) ? $grounding['calendar'] : [];
                if (($calendar['is_grounded'] ?? false) === true) {
                    return 'Sip kak, tanggalnya masih tersedia. Mau saya bantu lanjut booking?';
                }
                return 'Saya bantu cek dulu ketersediaan jadwalnya ya kak, sebentar.';

            case 'booking':
                if ($allowedAction === 'send_booking_link') {
                    $link = $this->stringFrom(($grounding['booking_link']['data']['booking_url'] ?? null) ?? ($grounding['booking_link']['booking_url'] ?? null));
                    if ($link !== '') {
                        return sprintf('Siap kak, ini link booking-nya ya: %s. Kalau sudah saya bantu konfirmasi.', $link);
                    }
                    return 'Siap kak, saya kirimkan link booking ya.';
                }
                return $this->blockerPrompt($reasons);

            case 'faq':
                $metaAnswer = $this->stringFrom($meta['faq_answer'] ?? null);
                if ($metaAnswer !== '') {
                    return $metaAnswer;
                }
                $faqAnswer = $this->stringFrom($grounding['faq_match']['answer'] ?? ($grounding['faq']['data']['answer'] ?? null));
                if ($faqAnswer !== '') {
                    return $faqAnswer;
                }
                return 'Boleh detailkan pertanyaannya kak, biar saya bantu jawab dengan tepat?';

            case 'objection_handling':
                $type = (string) ($meta['objection_type'] ?? '');
                if ($type === '') {
                    $type = match ((string) ($decision['intent'] ?? '')) {
                        'objection_price' => 'price',
                        'objection_time' => 'time',
                        default => 'misc',
                    };
                }
                return match ($type) {
                    'price' => 'Saya paham kak soal pertimbangan harga. Boleh saya jelaskan value paket yang paling sesuai dengan budget kakak?',
                    'time' => 'Saya mengerti kak soal kendala waktunya. Boleh saya bantu cek opsi tanggal lain yang lebih pas?',
                    default => 'Saya paham kak. Boleh ceritakan lebih detail apa yang jadi pertimbangan, supaya saya bisa bantu cari solusi yang pas?',
                };

            case 'handoff':
                return 'Tunggu sebentar ya kak, saya hubungkan ke tim kami untuk bantu lebih lanjut.';

            case 'invoice_phase':
                return 'Untuk urusan invoice/pembayaran, saya hubungkan ke tim kami ya kak.';

            case 'clarification':
            default:
                return 'Maaf kak, boleh dijelaskan ulang biar saya bisa bantu dengan tepat?';
        }
    }

    private function qualificationPrompt(string $nextRequired, array $entities): string
    {
        switch ($nextRequired) {
            case 'customer_name':
                return 'Sebelum kita lanjut, aku boleh tahu nama kakak?';
            case 'event_type':
                $name = $this->stringFrom($entities['customer_name'] ?? null);
                $prefix = $name !== '' ? sprintf('Siap kak %s, ', $name) : 'Siap kak, ';
                return $prefix.'kakak lagi cari layanan untuk acara apa ya?';
            case 'event_date':
                return 'Boleh share tanggal acaranya kak?';
            case 'package_interest':
                return 'Boleh info paket yang kakak minati ya kak?';
            default:
                return 'Terima kasih kak, informasinya sudah saya catat.';
        }
    }

    private function blockerPrompt(array $reasons): string
    {
        if (in_array('missing_name', $reasons, true)) {
            return 'Sebelum kita lanjut, aku boleh tahu nama kakak?';
        }
        if (in_array('missing_event_type', $reasons, true)) {
            return 'Siap kak, kakak lagi cari layanan untuk acara apa ya?';
        }
        if (in_array('missing_event_date', $reasons, true)) {
            return 'Boleh share tanggal acaranya ya kak?';
        }
        if (in_array('missing_package', $reasons, true)) {
            return 'Boleh info paket yang kakak minati dulu ya?';
        }
        if (in_array('missing_availability_check', $reasons, true)) {
            return 'Siap kak, kami bantu cek dulu ketersediaan jadwalnya ya.';
        }
        return 'Boleh dibantu lengkapi informasi yang masih kurang ya kak.';
    }

    private function fillPlaceholders(string $template, Tenant $tenant, array $entities, array $grounding, array $decision): string
    {
        $replacements = [
            '{tenant_name}' => $this->tenantName($tenant),
            '{customer_name}' => $this->stringFrom($entities['customer_name'] ?? null),
            '{event_type}' => $this->stringFrom($entities['event_type'] ?? null),
            '{event_date_iso}' => $this->stringFrom($entities['event_date_iso'] ?? null),
            '{package_name}' => $this->stringFrom($entities['selected_package'] ?? ($entities['package_interest'] ?? null)),
            '{location}' => $this->stringFrom($entities['location'] ?? null),
            '{booking_link}' => $this->stringFrom(($grounding['booking_link']['data']['booking_url'] ?? null) ?? ($grounding['booking_link']['booking_url'] ?? null)),
        ];
        return strtr($template, $replacements);
    }

    private function resolveTone(Tenant $tenant): string
    {
        try {
            $tone = (string) DB::table('tenant_ai_settings')
                ->where('tenant_id', $tenant->id)
                ->value('ai_tone');
            return $tone !== '' ? $tone : 'professional';
        } catch (\Throwable) {
            return 'professional';
        }
    }

    private function resolveCustomTemplate(Tenant $tenant, string $goal): ?string
    {
        // Optional override surface — read from tenant_ai_settings if a JSON column
        // is added in future. For now, returns null (no override) and is intentionally
        // graceful so future schema extension does not require composer changes.
        return null;
    }

    private function tenantName(Tenant $tenant): string
    {
        $name = trim((string) $tenant->name);
        return $name !== '' ? $name : 'kami';
    }

    private function stringFrom(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }

    private function fallbackForGoal(string $goal, Tenant $tenant): string
    {
        return match ($goal) {
            'opening' => sprintf('Halo kak, ada yang bisa kami bantu dari %s?', $this->tenantName($tenant)),
            'handoff', 'invoice_phase' => 'Tunggu sebentar ya kak, saya hubungkan ke tim kami.',
            default => 'Maaf kak, boleh dijelaskan ulang biar saya bisa bantu dengan tepat?',
        };
    }
}
