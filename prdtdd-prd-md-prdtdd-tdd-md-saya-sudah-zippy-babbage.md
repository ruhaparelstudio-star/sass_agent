# Perbaikan Menyeluruh Conversation Flow — SaaS WhatsApp AI Sales Agent

## Context

Setelah 23+ ronde fix tambal-sulam, percakapan masih sering "tidak nyambung":
bot lupa nama yang baru diberi, mengulang pertanyaan yang sudah dijawab,
mengirim balasan generic setelah user memberi preferensi, atau mereset
konteks setelah handoff. Investigasi (lihat ringkasan di bawah) menunjukkan
penyebabnya **arsitektural**, bukan satu bug.

Akar masalah:

1. **Intent → Goal → Candidate → Action tidak konsisten.**
   `TurnPipelineService::buildCandidate()` ([app/Modules/CoreEngine/Services/TurnPipelineService.php:629-644](app/Modules/CoreEngine/Services/TurnPipelineService.php#L629-L644))
   hanya membangun candidate terstruktur untuk `ask_pricelist`, `ask_price`,
   dan `booking_intent`. Semua intent qualification (`provide_name`,
   `provide_event_type`, `provide_date`, `provide_budget`, `provide_preference`)
   jatuh ke `default => reply_safe_text` — tidak pernah menghasilkan
   `blocked_actions` dengan reason terstruktur. PRD §7.3 dan §7.4 mewajibkan
   decision JSON yang lengkap untuk setiap intent.

2. **State tidak mengalir antar turn.** `event_date_iso`, `customer_name`,
   `event_type` tersimpan di `conversation_states`, tapi pipeline cuma
   menulisnya saat `active_goal` berubah ([TurnPipelineService.php:67-77](app/Modules/CoreEngine/Services/TurnPipelineService.php#L67-L77)).
   Entity hasil ekstraksi LLM bersifat ephemeral — kalau LLM tidak re-extract
   nama di turn berikutnya, state-nya bisa hilang. `pending_action` dibaca
   tapi tidak pernah ditulis oleh pipeline.

3. **`TenantHandoffResolutionService::resumeAi()` MENGHAPUS konteks**
   ([app/Modules/AdminUi/Services/TenantHandoffResolutionService.php:86-96](app/Modules/AdminUi/Services/TenantHandoffResolutionService.php#L86-L96)
   diff terbaru). Reset `active_goal=null` dan `event_date_iso=null` membuat
   bot melupakan semua kualifikasi setelah admin selesai handoff. Melanggar
   PRD §10 ("correction tidak reset stage").

4. **Hardcoded reply strings di pipeline.** [TurnPipelineService.php:712-805](app/Modules/CoreEngine/Services/TurnPipelineService.php#L712-L805)
   berisi puluhan string literal Indonesia. Tidak melalui composer yang
   menghormati tenant tone/preference. Melanggar PRD §19 step 4-5
   (Tenant Preference Formatting → Response Composer).

5. **Calendar provider error dianggap "grounded"** di diff terkini
   ([WaInboundTurnOrchestratorService.php:524-534](app/Modules/WhatsApp/Services/WaInboundTurnOrchestratorService.php#L524-L534)).
   Melanggar PRD §13 ("Agent tidak boleh mengklaim available" jika calendar
   error). Ini escape hatch berbahaya — booking link bisa dikirim tanpa
   availability terverifikasi.

6. **Grounding tidak auto-populated.** Validators (`GroundingValidatorService`)
   membaca `context['grounding']` tapi tidak memanggil resolver. Resolver
   (`PricelistAssetResolver`, `BookingLinkResolver`, dll) ada tapi orchestrator
   harus ingat memanggil & merge — kalau lupa, validator gagal silent.

User memilih: **refactor menyeluruh + preserve handoff context + handoff
saat calendar error + ResponseComposerService baru**.

Goal: percakapan yang konsisten end-to-end, dengan engine deterministik
sebagai final authority dan LLM hanya sebagai interpreter + composer.

---

## Pendekatan

Restructure pipeline jadi **goal-driven**, bukan intent-driven. Setiap
intent menghasilkan candidate terstruktur (action + reasons + required
entities). State sync eksplisit di akhir setiap turn. Reply komposisi
dipindah ke service terpisah yang menerima decision + grounding + tenant
tone.

```
Inbound → Orchestrator (build context + grounding)
       → TurnPipeline
            ├─ Interpret (LLM, JSON only)
            ├─ MergeEntities (state + LLM, deterministic precedence)
            ├─ ResolveGoal (intent + state + lead_profile)
            ├─ BuildCandidate (per goal, NOT per intent)
            ├─ Validate (Policy → Grounding → Permission → Mode)
            ├─ ResolveFinalAction (or fallback/handoff)
            ├─ ResponseComposerService (grounded + tone)
            ├─ PersistState (entities, goal, stage, pending_action)
            └─ Trace (decision_traces row)
       → Dispatch actions
       → Outbound
```

---

## File-by-file Changes

### A. Core engine — restructure pipeline

**[app/Modules/CoreEngine/Services/TurnPipelineService.php](app/Modules/CoreEngine/Services/TurnPipelineService.php)** (~1300 LOC saat ini)

- Pisah jadi orchestrator tipis + komponen kecil:
  - `IntentToGoalResolver` (private, atau kelas sendiri di `app/Modules/CoreEngine/Services/`): map setiap intent ke goal seperti tabel di bawah.
  - `CandidateBuilder` (private method per goal, bukan per intent): satu method per goal dengan input `(state, entities, knowledge, leadProfile)` → return `['action', 'meta', 'reasons', 'missing_required']`.
  - Pindahkan semua `buildResponsePlanMessage()` (L712-805) → `ResponseComposerService`.
- Goal map (lengkap, bukan parsial):

| Intent group | Goal |
|---|---|
| greeting / first_contact / intro_interest | `opening` |
| ask_pricelist / ask_price | `pricing` |
| ask_package / ask_package_detail | `package_explanation` |
| ask_faq | `faq` |
| ask_availability | `availability` |
| provide_name / provide_event_type / provide_date / provide_budget / provide_preference | `qualification` |
| booking_intent / confirm_booking / ask_booking_link / ask_lock_date | `booking` |
| objection_* | `objection_handling` |
| request_handoff / complaint | `handoff` |
| payment_related / ask_invoice | `invoice_phase` |
| topic_switch / correction | inherit previous goal |
| unclear_message / unknown | `clarification` |

- `buildQualificationCandidate()`: hitung **next required field** dari
  `(customer_name, event_type, event_date, package_interest)` dengan urutan
  bisnis (PRD §14: name → event_type → date → package).
  Return:
  - `action='reply_text'` + `reasons=['collected:<field>']` + `next_required=<field>`
  - Jika semua qualification field terisi & ada `previous_blocked_action`,
    resume action sebelumnya (mis. `send_pricelist_file`).
- `buildPricingCandidate()`: gunakan `PricelistAssetResolver` + `PackagePricingResolver`
  untuk grounding. Block `send_pricelist_file` jika minimum requirement
  tenant (`name_only` / `name_date`) belum terpenuhi (PRD §14).
- `buildBookingCandidate()`: wajib semua syarat PRD §12 (package, name,
  availability, booking_intent_signal, closing_readiness, link tersedia).
  Calendar mandatory call lewat `CalendarAvailabilityService`.
- `buildPackageExplanationCandidate()`: tarik `CatalogResolver` untuk
  paket spesifik / list paket aktif.
- `buildFaqCandidate()`: `FaqResolver` + match by topic.
- `buildHandoffCandidate()` / `buildClarificationCandidate()` / dll:
  candidate eksplisit, tidak fallback ke `reply_safe_text`.
- Hapus hardcoded strings (L712-805). `response_plan.message` diisi oleh
  `ResponseComposerService::compose($decision, $context)`.
- `mergeEntities()` (L536-610): tambah deterministic precedence — entitas
  lama dari state TIDAK boleh ditimpa null/empty dari LLM output. Hanya
  ditimpa jika `interpretation->correction === true` dan field ada di
  `corrected_fields`.
- Tambah `persistTurnState($state, $decision, $entities)` di akhir `handle()`:
  - simpan semua durable entities (name, event_type, event_date_iso, location, budget, package_interest)
  - simpan `current_stage`, `active_goal`, `pending_action`
  - **always**, bukan hanya saat goal berubah.
- `resolveHandoffSignal()` (L1205-1391): hapus escape hatch
  `calendar_provider_error` (yang ditambah di diff). Provider error =
  handoff (sesuai jawaban user untuk Q3).

### B. Reply composer baru

**Buat: [app/Modules/CoreEngine/Services/ResponseComposerService.php](app/Modules/CoreEngine/Services/ResponseComposerService.php)** (file baru)

- Input: `Decision`, `array $grounding`, `Tenant` (untuk `tenant_preferences.tone`,
  `business_hours`, dll), `array $entities`.
- Output: `string` final reply.
- Pakai template per goal (deterministic), bukan LLM, untuk MVP. Setiap
  template menerima placeholder yang diisi dari grounding (mis. nama
  paket, harga formatted, link booking) — TIDAK ada placeholder yang
  diisi LLM bebas.
- Kalau tenant punya `custom_template` di `tenant_preferences`, override.
- Fallback aman per goal (mis. clarification → "Maaf kak, boleh dijelaskan
  ulang?").
- Singleton via service binding di `app/Providers/AppServiceProvider.php`.

### C. Entity & state model

**[app/Modules/Conversation/Models/ConversationState.php](app/Modules/Conversation/Models/ConversationState.php)** (read-only check, fillable harus mencakup semua durable entities — kalau belum, tambah migration di langkah F).

**[app/Modules/Conversation/Services/ConversationService.php](app/Modules/Conversation/Services/ConversationService.php)**
- Tambah method `persistDurable(int $conversationId, array $entities, array $stateUpdate): void`
  yang merge entitas non-null ke kolom durable + update `current_stage`,
  `active_goal`, `pending_action` dalam satu DB transaction.
- Replace pemanggilan `upsertState()` ad-hoc dari TurnPipelineService.

### D. Handoff resolution — JANGAN reset

**[app/Modules/AdminUi/Services/TenantHandoffResolutionService.php](app/Modules/AdminUi/Services/TenantHandoffResolutionService.php)**

Diff terkini reset `active_goal` dan `event_date_iso`. Buang reset itu.
Hanya:
```php
$state->agent_mode = 'assistant';
$state->save();
$conversation->forceFill(['agent_mode' => 'assistant'])->save();
```
Optional: log AuditLogger entry "ai_resumed_with_state_preserved".

### E. Calendar provider error → handoff (bukan escape)

**[app/Modules/WhatsApp/Services/WaInboundTurnOrchestratorService.php](app/Modules/WhatsApp/Services/WaInboundTurnOrchestratorService.php)**
- Revert escape hatch L524-534: `is_grounded` HANYA `true` jika
  `checked === true && available === true`. Provider error = `is_grounded:false`.
- `resolveHandoffSignal()` di TurnPipeline (D di atas) sudah menerjemahkan
  ini jadi handoff signal `calendar_provider_error`.

**[app/Modules/Calendar/Services/CalendarAvailabilityService.php](app/Modules/Calendar/Services/CalendarAvailabilityService.php)**
- Pastikan `reason` value `calendar_provider_error` konsisten saat exception.
- Tambah short-cache (optional, 60s) hasil sukses agar tidak hammer Google saat berentet.

### F. Migration — pastikan kolom durable lengkap

Cek [database/migrations/2026_04_30_210000_add_durable_entities_to_conversation_states_table.php](database/migrations/2026_04_30_210000_add_durable_entities_to_conversation_states_table.php).
Jika belum ada, tambah migration baru `2026_05_05_*_add_pending_action_and_location_to_conversation_states_table.php`:
- `pending_action` (json, nullable) — struktur `{action, reason, captured_at}`
- `location` (string nullable)
- `budget_min` / `budget_max` (decimal nullable)
- `event_date_raw` (string nullable) — preserve format user sebelum normalisasi (PRD §9)

### G. Intent prompt → tetap rapi

**[app/Modules/WhatsApp/Services/WaInboundTurnOrchestratorService.php](app/Modules/WhatsApp/Services/WaInboundTurnOrchestratorService.php)**
- Sudah diperluas di diff (rules per intent + entity rules). Pertahankan.
- Tambah satu instruksi: "Re-extract semua entitas yang sudah diketahui
  dari `compact_context.previous_entities` jika user tidak mengulang —
  jangan kembalikan null untuk field yang sudah ada." (Backstop kalau
  classifier amnesia.)

### H. Validator wiring sesuai PRD §19

**[app/Modules/CoreEngine/Services/TurnPipelineService.php](app/Modules/CoreEngine/Services/TurnPipelineService.php)** `runValidators()` (L1120-1143)
- Urutan: Policy → Grounding → Permission → Mode (sudah benar).
- Pastikan **Response Composer dipanggil SETELAH** validator final, bukan
  sebelumnya seperti sekarang (L194 dipanggil sebelum validation finish).
  Restructure `handle()`: validate → resolveFinalAction → compose → trace.

### I. Regression test baru

**Buat: [tests/Feature/WhatsApp/ConversationGoalDrivenPipelineTest.php](tests/Feature/WhatsApp/ConversationGoalDrivenPipelineTest.php)**

Skenario end-to-end:
1. User: "Halo" → bot greeting + tanya kebutuhan.
2. User: "Mau pricelist dong" → bot blok send_file, tanya nama.
3. User: "Saya Aris" → state `customer_name=Aris`. Bot tanya event type.
4. User: "Wedding" → state `event_type=wedding`. Bot tanya tanggal.
5. User: "5 Juni 2026" → state `event_date_iso=2026-06-05`. Bot kirim file (resume `send_pricelist_file`).
6. User: "Mau booking" → bot cek calendar, kirim booking link.
7. Admin handoff → resumeAi → state `customer_name`, `event_date_iso` masih ada. Bot tidak ulang qualification.

Plus skenario calendar error → handoff (bukan booking link terkirim).

Update [tests/Feature/WhatsApp/ConversationLatestLogRegressionTest.php](tests/Feature/WhatsApp/ConversationLatestLogRegressionTest.php)
agar assertion baru lulus tanpa skip.

---

## File yang Akan Diubah (ringkas)

Modify:
- [app/Modules/CoreEngine/Services/TurnPipelineService.php](app/Modules/CoreEngine/Services/TurnPipelineService.php) (besar — refactor)
- [app/Modules/WhatsApp/Services/WaInboundTurnOrchestratorService.php](app/Modules/WhatsApp/Services/WaInboundTurnOrchestratorService.php) (revert calendar escape, panggil composer, simplifikasi context build)
- [app/Modules/AdminUi/Services/TenantHandoffResolutionService.php](app/Modules/AdminUi/Services/TenantHandoffResolutionService.php) (hapus reset)
- [app/Modules/Conversation/Services/ConversationService.php](app/Modules/Conversation/Services/ConversationService.php) (tambah `persistDurable()`)
- [app/Modules/Calendar/Services/CalendarAvailabilityService.php](app/Modules/Calendar/Services/CalendarAvailabilityService.php) (kanonkan reason value, optional cache)
- [app/Modules/AiLayer/Enums/Intent.php](app/Modules/AiLayer/Enums/Intent.php) (audit kelengkapan vs PRD §8 — tambah enum yang hilang seperti `AskFaq`, `Objection*`, `ConfirmBooking` jika belum)
- [app/Providers/AppServiceProvider.php](app/Providers/AppServiceProvider.php) (binding ResponseComposerService)

Create:
- [app/Modules/CoreEngine/Services/ResponseComposerService.php](app/Modules/CoreEngine/Services/ResponseComposerService.php)
- [app/Modules/CoreEngine/Services/Goals/IntentToGoalResolver.php](app/Modules/CoreEngine/Services/Goals/IntentToGoalResolver.php) (atau private inline)
- `database/migrations/2026_05_05_*_extend_conversation_states_durable.php` (jika kolom belum ada)
- [tests/Feature/WhatsApp/ConversationGoalDrivenPipelineTest.php](tests/Feature/WhatsApp/ConversationGoalDrivenPipelineTest.php)

Reuse (jangan duplikasi):
- `PricelistAssetResolver`, `PackagePricingResolver`, `CatalogResolver`, `FaqResolver`, `BookingLinkResolver` — sudah ada, baru dipanggil dari pipeline yang baru lewat orchestrator buildContext.
- `PolicyValidatorService`, `GroundingValidatorService`, `ActionPermissionValidatorService`, `ModeValidatorService` — sudah benar, hanya pastikan urutan & input.
- `LeadProfileService::syncFromEntities()` — extend ringan untuk sync field tambahan.
- `AuditLogger` — log handoff resolution & state transitions besar.

---

## Verifikasi

Jalankan secara berurutan di Docker:

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan test --filter=ConversationGoalDrivenPipelineTest
docker compose exec app php artisan test --filter=Conversation
docker compose exec app php artisan test --filter=Calendar
docker compose exec app php artisan test
```

Manual smoke test (tenant lokal, simulator inbound):
1. Kirim "halo" → expect: greeting reply.
2. "minta pricelist" → expect: tanya nama (bukan kirim file).
3. "saya budi" → expect: tanya event type (bukan ulang tanya nama).
4. "wedding" → expect: tanya tanggal.
5. "5 juni 2026" → expect: file pricelist terkirim (resume).
6. Cek `decision_traces` row terakhir: `decision_json` harus punya
   `current_stage`, `active_goal`, `decision`, `allowed_actions`,
   `blocked_actions` lengkap (PRD §7.3 contract).
7. Buat handoff manual via dashboard → resume → kirim "halo" lagi →
   expect: bot tahu nama+event+date masih sama, lanjut dari konteks.
8. Disable Google Calendar di tenant settings → user tanya availability
   → expect: handoff terbuat, bot TIDAK kirim "available".

SQL spot-check:
```sql
SELECT current_stage, active_goal, customer_name, event_type, event_date_iso, pending_action
FROM conversation_states WHERE conversation_id = <id>;

SELECT id, intent, decision, blocked_actions_json
FROM decision_traces WHERE conversation_id = <id> ORDER BY id DESC LIMIT 10;
```

---

## Out of Scope (untuk iterasi berikutnya)

- Memory dormant retrieval (PRD §16) — current implementation sudah ada, tidak diubah.
- Analytics dashboard widget baru (PRD §20).
- Multi-tenant LLM token usage tracking detail.
- Tone fine-tuning per tenant lewat LLM (composer MVP pakai template).
- Versioning audit untuk knowledge changes.

## Risiko & Mitigasi

- **Refactor besar di TurnPipelineService** — banyak test existing bisa
  patah. Mitigasi: jalankan full test suite per langkah, commit per fase
  (intent map → candidate builder → composer → state sync → handoff fix).
- **Migration baru** — pastikan idempotent & nullable agar data lama
  selamat. Mitigasi: test seed data + `php artisan migrate:fresh --seed` di lokal.
- **Behavior calendar error berubah jadi handoff** — kalau Google sering
  error, admin akan kebanjiran. Mitigasi: cache 60s di `CalendarAvailabilityService`,
  monitoring alert.
