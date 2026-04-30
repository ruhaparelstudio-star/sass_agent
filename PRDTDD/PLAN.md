# PLAN — PRD/TDD Alignment Execution (P0-P2)

## 1. Goal
Menyelaraskan implementasi runtime dan test suite agar **100% mengikuti PRD sebagai source of truth** dan **TDD sebagai desain teknis** untuk SaaS WhatsApp AI Sales Agent.

Prinsip eksekusi:
- Controlled workflow engine, bukan chatbot bebas.
- LLM tidak pernah jadi final authority.
- Semua aksi wajib lewat validator berurutan.
- Default fail-closed: data/grounding tidak cukup => block action + safe fallback/handoff.

---

## 2. Scope Alignment Matrix (PRD -> TDD -> Implementasi)

### A. Inbound Turn Pipeline (P0)
PRD ref: 7.2 (20-step non-negotiable flow), Final Principle  
TDD ref: 5.1 `TurnPipelineService`, 6.1 inbound API, 6.3 decision trace

Target:
- Jalur inbound wajib mengikuti urutan:
  Receive -> Deduplicate -> Load tenant/WA -> Check tenant status/plan -> Load conversation/state/lead -> Interpret -> Extract entities -> Candidate memory update -> Knowledge retrieval structured-first -> Decision build -> Validator chain -> Compose -> Send -> Persist trace/log/metrics.
- Hilangkan shortcut/bypass yang langsung reply tanpa pipeline penuh.
- Idempotency dedupe tetap deterministic (`provider + provider_message_id + tenant_id`).

Deliverable:
- Satu orchestrator pipeline terpusat untuk inbound turn.
- Trace persistence lengkap (`messages`, `decision_traces`, `action_logs`) dengan boundary transaksi yang aman.

### B. Decision Contract & Determinism (P0)
PRD ref: 7.3 Decision JSON Contract, 7.4 Decision Rule  
TDD ref: 5.6 DecisionEngineService, 6.3 Decision Trace Contract

Target:
- Contract decision internal minimal memuat:
  `intent`, `confidence`, `entities`, `current_stage`, `active_goal`, `decision`, `allowed_actions`, `blocked_actions`, `handoff_required`, `notification_required`, `grounding_refs`, `reply_strategy`.
- Tegakkan rule: classifier output bukan keputusan final.
- Final action ditentukan oleh intent + entity + state + policy + knowledge + permission + mode.

Deliverable:
- DTO/schema decision seragam lintas service.
- Reason code blocked action konsisten untuk audit dan analytics.

### C. Validator Chain & Anti-Hallucination (P0)
PRD ref: 19 Safety/Policy Requirement, Global forbidden actions, 12/13/14/15 rules  
TDD ref: 5.7 PolicyValidator, 5.8 GroundingValidator, 5.9 ActionPermissionValidator, 5.10 ModeValidator

Target:
- Urutan validasi runtime wajib:
  Policy -> Grounding -> Action Permission -> Mode.
- Wajib block:
  - harga tanpa structured price valid,
  - availability tanpa hasil calendar check valid,
  - booking link tanpa booking setting aktif + precondition booking,
  - file/pricelist tanpa asset tenant valid,
  - invoice resend melebihi `max_send_count`,
  - reply/action saat mode `paused`.

Deliverable:
- Enforcement validator konsisten di semua action sensitif.
- Taxonomy reason code validator dibakukan.

### D. Tenant Isolation, Plan Gating, Feature Gate (P0-P1)
PRD ref: 3 subscription/lead limit, 13 calendar gating, 14 pricelist rules, 17 handoff trigger  
TDD ref: 4.x tenancy/knowledge/lead/booking schema, 5.1 validate tenant status and plan

Target:
- No cross-tenant access untuk data knowledge, assets, conversation, invoice.
- Runtime gating aktif:
  - limit WA active accounts,
  - monthly unique lead limit,
  - calendar enablement,
  - automation fallback saat limit habis/suspended.
- Violation wajib block action + fallback/handoff sesuai policy.

Deliverable:
- Integrasi `FeatureGateService`/plan check ke jalur keputusan.
- Guard tenant scope di query/action yang berisiko kebocoran data.

### E. Stage/Mode/Memory Consistency (P1)
PRD ref: 10 stage rules, 16 memory modes, 15 invoice mode transition  
TDD ref: 4.3 conversation fields, 5.10 ModeValidator

Target:
- Stage transitions sesuai PRD, termasuk koreksi entity tidak reset stage.
- `topic_switch` hanya ubah active goal.
- Invoice sent => `agent_mode=limited`, `memory_mode=dormant`.
- Dormant memory tidak dipakai default, hanya via trigger eksplisit PRD.

Deliverable:
- State transition guard + test coverage untuk mode/stage/memory.

### F. Intent/Entity Coverage Expansion (P1-P2)
PRD ref: 8 intent groups, 9 entity requirement  
TDD ref: 5.2 IntentClassifier, 5.3 EntityExtraction, 5.4 EntityMatcher

Target:
- Tambah coverage intent prioritas konversi + safety:
  `ask_pricelist`, `ask_price`, `ask_package_detail`, `ask_availability`, `booking_intent`, `request_handoff`, `complaint`, `payment_related`, `topic_switch`, `correction`, `unclear_message`.
- Entity normalization minimal:
  `customer_name`, `event_type`, `event_date`, `location`, `package_interest`, `budget_min/max`, `invoice_reference`.
- Tenant package matching dinamis (tanpa hardcode).

Deliverable:
- Update classifier/extractor/matcher contract + regression tests.

---

## 3. Test Strategy (Mandatory, PRD-Driven)

### Phase T0 — Stabilization Baseline (P0)
- Semua test existing kembali hijau.
- Perbaiki flaky/fail terkait environment, termasuk audit/health path.

### Phase T1 — Pipeline & Validator E2E (P0)
Test minimal wajib:
- Inbound pipeline urut + trace tersimpan.
- Dedupe inbound idempotent.
- Invalid JSON dari classifier => safe fallback.
- Missing grounding => action blocked.
- Mode paused/handoff => AI action blocked sesuai rule.

### Phase T2 — Anti-Hallucination Matrix (P0)
Test wajib eksplisit untuk larangan global:
- no price hallucination,
- no availability hallucination,
- no booking link hallucination,
- no file hallucination,
- no invoice status hallucination.

### Phase T3 — Plan/Lead/Feature Gating (P1)
- Monthly unique lead counting per tenant per billing period.
- Lead limit exhausted => automation blocked/fallback/handoff.
- Calendar disabled/disconnected => no availability claim.

### Phase T4 — Stage/Memory/Invoice Lifecycle (P1)
- Correction tidak reset stage.
- Topic switch ubah active goal saja.
- Invoice sent => limited+dormant.
- Invoice resend > max ditolak dan logged.

### Phase T5 — Tenant Isolation & Security (P0-P1)
- Semua query/action sensitif tenant-scoped.
- Asset/file/link tenant lain wajib tertolak.
- Internal endpoint secret enforcement.

---

## 4. Delivery Priority & Exit Criteria

### P0 (Release Blocker)
Harus selesai dulu:
- Full inbound pipeline aktif.
- Validator chain strict order aktif.
- Anti-hallucination enforcement aktif.
- Tenant isolation basic + dedupe + trace logging aman.
- Test E2E pipeline + validator matrix inti lulus.

Exit criteria P0:
- Tidak ada action sensitif yang bisa bypass validator.
- Tidak ada reply yang klaim data tanpa grounding valid.
- Semua test P0 pass di CI.

### P1 (Operational Safety & Monetization)
- Plan/feature gating runtime lengkap.
- Stage/mode/memory/invoice lifecycle konsisten.
- Test billing/lead limit/calendar gating lulus.

Exit criteria P1:
- Enforcement limit plan berjalan di runtime, bukan hanya UI.
- Semua transisi mode kritikal tervalidasi lewat test.

### P2 (Coverage & Scale Readiness)
- Perluasan intent/entity + analytics completeness + robustness improvement.
- Penyempurnaan observability dan replay/audit granularity.

Exit criteria P2:
- Coverage intent/entity prioritas PRD terpenuhi.
- Tidak ada gap kritikal antara PRD, TDD, dan perilaku runtime.

---

## 5. Implementation Guardrails
- Dilarang menambah abstraction yang tidak dibutuhkan scope fase.
- Setiap perubahan harus menyebut mapping PRD/TDD yang disentuh.
- Tests ditulis/diupdate sebelum atau bersamaan dengan logic perubahan.
- Jika requirement PRD konflik dengan implementasi lama, PRD menang.
- Jika data tidak cukup: block action, fallback aman, dan/atau handoff.

---

## 6. Out of Scope Saat Ini
- Role tambahan non-MVP (staff/finance/manager granular ACL penuh).
- Optimasi UX dashboard non-kritis terhadap alur kontrol.
- Integrasi channel selain WhatsApp.

---

## 7. Modul-by-Modul Execution Checklist (Ready for PR Breakdown)

### A. `AgentCore` (Owner: Pipeline, Decision, Validator)
P0 checklist:
- [x] Implement/rapikan orchestrator inbound turn tunggal (entrypoint decision-driven).
- [x] Pastikan urutan langkah turn mengikuti PRD 7.2 tanpa skip.
- [x] Standarkan decision contract (field wajib PRD 7.3).
- [x] Tegakkan validator chain strict order: policy -> grounding -> permission -> mode.
- [x] Implement fail-safe: invalid JSON, missing data/grounding, low confidence, system error.
- [x] Persist `decision_traces` + `action_logs` konsisten setiap turn.

P1 checklist:
- [x] Sinkronkan rule handoff/notification dari decision output.
- [x] Bakukan reason code taxonomy untuk blocked action lintas validator.

PR candidates:
1. `agentcore/pipeline-orchestrator-p0`
2. `agentcore/decision-contract-and-failsafe`
3. `agentcore/validator-chain-enforcement`

### B. `Conversation` (Owner: State, Stage, Message, Memory)
P0 checklist:
- [x] Pastikan load/create conversation + state + lead profile konsisten di awal turn.
- [x] Simpan inbound/outbound message terhubung ke decision trace.

P1 checklist:
- [x] Terapkan transition guard stage/goal sesuai PRD (correction tidak reset stage).
- [x] Terapkan mode behavior: `paused`, `handoff`, `limited`, `active`.
- [x] Terapkan memory mode `active/dormant` + dormant trigger retrieval.

PR candidates:
1. `conversation/state-load-and-trace-linking`
2. `conversation/stage-goal-transition-guard`
3. `conversation/mode-memory-enforcement`

### C. `WhatsApp` (Owner: Inbound, Dedupe, Outbound, Status)
P0 checklist:
- [x] Pastikan endpoint inbound memanggil pipeline baru (bukan static auto-reply).
- [x] Dedupe idempotent: `provider + provider_message_id + tenant_id`.
- [x] Sinkronkan status lifecycle WA account dengan PRD (connect/reconnect/failed).
- [x] Queue outbound + delivery log aman untuk retry tanpa duplikasi action.

P1 checklist:
- [x] Perkuat reconnect/session-expired behavior + observability.

PR candidates:
1. `whatsapp/inbound-to-pipeline`
2. `whatsapp/dedupe-and-delivery-safety`
3. `whatsapp/status-transition-alignment`

### D. `Knowledge` (Owner: Grounded Data & Asset Validation)
P0 checklist:
- [x] Structured knowledge sebagai source utama (price/package/booking config).
- [x] Grounding validator memastikan price/package/file/link berasal dari tenant data valid.
- [x] Blok send file jika asset tidak valid/tidak tenant-owned.

P1 checklist:
- [x] Version/effective-date filtering konsisten (`effective_from/until`, `is_active`).
- [x] Unstructured retrieval hanya saat diperlukan dan tetap tenant-scoped.

PR candidates:
1. `knowledge/structured-grounding-hardening`
2. `knowledge/asset-ownership-validation`
3. `knowledge/versioned-retrieval-rules`

### E. `Billing` / `Plans` (Owner: Feature Gate & Lead Limit)
P0 checklist:
- [x] Validasi tenant status (`trial/active/suspended/expired`) sebelum automation.

P1 checklist:
- [x] Integrasikan plan feature gate ke runtime decision path.
- [x] Enforce limit WA agent aktif per plan.
- [x] Enforce monthly unique lead counting per billing period.
- [x] Saat limit habis: block automation + fallback/handoff sesuai policy.

PR candidates:
1. `billing/tenant-status-runtime-gate`
2. `plans/feature-gate-runtime-integration`
3. `billing/monthly-unique-lead-limit-enforcement`

### F. `Booking` / `Calendar` / `Invoice` (Owner: Sensitive Action Preconditions)
P0 checklist:
- [x] Action permission rule untuk `send_booking_link` sesuai precondition PRD.
- [x] Blok claim availability tanpa calendar check valid.
- [x] Blok invoice resend di atas `max_send_count`.

P1 checklist:
- [x] Pastikan invoice sent memicu state `limited + dormant`.
- [x] Jalur fallback ke handoff saat calendar unavailable/user minta availability.

PR candidates:
1. `booking/precondition-enforcement`
2. `calendar/availability-grounding-block`
3. `invoice/resend-limit-and-mode-transition`

### G. `Handoff` / `Notification` (Owner: Escalation Safety)
P0 checklist:
- [x] Trigger handoff untuk reason PRD high-priority (complaint, low confidence, paused, dsb).
- [x] Trigger notification saat handoff/action penting/error integration.

P1 checklist:
- [x] Lengkapi payload admin context panel sesuai field PRD.
- [x] Priority mapping konsisten untuk admin triage.

PR candidates:
1. `handoff/trigger-policy-matrix`
2. `notification/critical-event-payloads`
3. `handoff/admin-context-completeness`

### H. `Audit` / `Analytics` / `Security` (Owner: Traceability & Isolation)
P0 checklist:
- [x] Pastikan audit/action logs tidak gagal di health/runtime path.
- [x] Internal endpoint secret enforcement (WA internal API).
- [x] Verifikasi tenant isolation di query/action sensitif.

P1 checklist:
- [x] Tambah metrics yang dibutuhkan PRD dashboard tenant/superadmin.
- [x] Pastikan replay/audit dapat merekonstruksi turn secara deterministik.

PR candidates:
1. `security/internal-endpoint-hardening`
2. `audit/logging-stability-fix`
3. `analytics/prd-metrics-coverage`

---

## 8. Suggested PR Sequence (Low-Risk Order)
1. `agentcore/pipeline-orchestrator-p0`
2. `whatsapp/inbound-to-pipeline`
3. `agentcore/validator-chain-enforcement`
4. `knowledge/structured-grounding-hardening`
5. `booking/precondition-enforcement`
6. `conversation/stage-goal-transition-guard`
7. `plans/feature-gate-runtime-integration`
8. `billing/monthly-unique-lead-limit-enforcement`
9. `handoff/trigger-policy-matrix`
10. `audit/logging-stability-fix`

Catatan:
- Setiap PR wajib menyertakan mapping requirement PRD/TDD yang dipenuhi.
- Setiap PR wajib menyertakan test baru/regression test terkait perubahan.
- Hindari PR besar lintas banyak modul bila tidak diperlukan.

---

## 9. Closure Snapshot (2026-04-30)
- [x] Checklist eksekusi modul `P0 -> P1 -> P2` tersinkron dengan implementasi saat ini.
- [x] Verifikasi final lulus dengan `docker compose exec -T app php artisan test` (`288 passed`, `1318 assertions`).
- [x] Traceability audit tersedia di `PRDTDD/TRACEABILITY_MATRIX.md`.
