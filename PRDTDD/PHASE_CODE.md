# PHASE CODE — PRD/TDD Alignment Sprint Board

## Purpose
Dokumen ini adalah board eksekusi teknis untuk menutup gap implementasi terhadap `PRD.md` dan `TDD.md`.

Prinsip:
- PRD = source of truth perilaku bisnis.
- TDD = kontrak teknis arsitektur, tabel, service, dan API.
- Semua task harus traceable ke PRD/TDD section.
- Semua action sensitif wajib fail-closed dan tervalidasi.

---

## Working Rules (Mandatory)
- Kerjakan berdasarkan prioritas fase (`P0 -> P1 -> P2`).
- Tidak mengimplementasikan fase berikutnya sebelum exit criteria fase aktif terpenuhi.
- Tests dibuat/diupdate bersamaan dengan code perubahan.
- Tidak ada bypass validator.
- Tidak ada query/action lintas tenant.
- Tidak menaruh Baileys runtime logic di Laravel process.

---

## Status Legend
- `TODO` belum dikerjakan
- `IN_PROGRESS` sedang dikerjakan
- `BLOCKED` ada blocker teknis/dependency
- `DONE` selesai + test pass

---

## Sprint Board P0 (Release Blocker)

### P0-A — Inbound Pipeline Orchestrator
- ID: `P0-A1`
- Status: `DONE`
- Module owner: `AgentCore`, `WhatsApp`, `Conversation`
- PRD refs: 7.2, Final Principle
- TDD refs: 5.1, 6.1, 6.3
- Dependency: none
- Tasks:
  - [x] Jalur inbound endpoint WA masuk ke satu orchestrator decision-driven.
  - [x] Urutan pipeline wajib: receive -> dedupe -> load tenant/plan -> load conversation/state/lead -> interpret -> entity -> knowledge -> decision -> validators -> compose -> send -> persist.
  - [x] Hapus/disable static shortcut reply path.
- Tests:
  - [x] E2E inbound turn mengikuti urutan pipeline.
  - [x] Turn menyimpan trace/log/message sesuai kontrak.

### P0-B — Dedupe & Idempotency Safety
- ID: `P0-B1`
- Status: `DONE`
- Module owner: `WhatsApp`
- PRD refs: 5.1 deduplicate inbound message
- TDD refs: 4.2 wa_inbound_messages, 5.1 acquire lock
- Dependency: `P0-A1`
- Tasks:
  - [x] Dedupe key deterministic `provider + provider_message_id + tenant_id`.
  - [x] Retry inbound tidak menghasilkan action/outbound duplikat.
- Tests:
  - [x] Duplicate inbound message diabaikan aman.
  - [x] Outbound/action log tidak dobel saat replay payload.

### P0-C — Decision Contract Standardization
- ID: `P0-C1`
- Status: `DONE`
- Module owner: `AgentCore`
- PRD refs: 7.3, 7.4
- TDD refs: 5.6, 6.3
- Dependency: `P0-A1`
- Tasks:
  - [x] Samakan shape decision JSON field wajib PRD.
  - [x] Enforce rule classifier output bukan final action.
  - [x] Tambah fail-safe invalid JSON/system error -> safe fallback.
- Tests:
  - [x] Contract validation untuk decision payload.
  - [x] Invalid JSON classifier tidak menyebabkan action liar.

### P0-D — Validator Chain Enforcement
- ID: `P0-D1`
- Status: `DONE`
- Module owner: `AgentCore`
- PRD refs: 19, 12, 13, 14, 15
- TDD refs: 5.7, 5.8, 5.9, 5.10
- Dependency: `P0-C1`
- Tasks:
  - [x] Enforce urutan validator: policy -> grounding -> permission -> mode.
  - [x] Fail-closed pada data/grounding tidak cukup.
  - [x] Standarkan reason code blocked action lintas validator.
- Tests:
  - [x] Order validator diverifikasi eksplisit.
  - [x] Action blocked bila satu validator gagal.

### P0-E — Anti-Hallucination Hard Rules
- ID: `P0-E1`
- Status: `DONE`
- Module owner: `Knowledge`, `Booking`, `Calendar`, `Invoice`, `AgentCore`
- PRD refs: 19 forbidden actions, 12/13/14/15
- TDD refs: 5.8, 5.9
- Dependency: `P0-D1`
- Tasks:
  - [x] Block harga tanpa structured price valid.
  - [x] Block availability claim tanpa calendar result valid.
  - [x] Block booking link tanpa booking config aktif + preconditions.
  - [x] Block file/pricelist tanpa asset valid dan tenant-owned.
  - [x] Block invoice resend > `max_send_count`.
- Tests:
  - [x] Matrix anti-hallucination inti pass.

### P0-F — Tenant Isolation & Internal Security
- ID: `P0-F1`
- Status: `DONE`
- Module owner: `Security`, `Audit`, semua query owner module
- PRD refs: Non-negotiable no cross-tenant access
- TDD refs: 4.x tenant_id across tables, 8.2 env/internal secret
- Dependency: parallel dengan `P0-A` s/d `P0-E`
- Tasks:
  - [x] Audit semua query/action sensitif tenant-scoped.
  - [x] Internal WA endpoint wajib secret check.
  - [x] Pastikan audit/action logging stabil di path health/runtime.
- Tests:
  - [x] Cross-tenant data access ditolak.
  - [x] Internal endpoint tanpa secret ditolak.

### P0 Exit Criteria
- [x] Tidak ada action sensitif yang bypass validator.
- [x] Tidak ada klaim harga/availability/link/file tanpa grounding valid.
- [x] Inbound pipeline end-to-end berjalan deterministic.
- [x] Semua test P0 pass.

---

## Sprint Board P1 (Operational Safety & Monetization)

### P1-A — Plan/Feature Gate Runtime
- ID: `P1-A1`
- Status: `DONE`
- Module owner: `Billing`, `Plans`, `AgentCore`, `WhatsApp`
- PRD refs: 3, 13, 17
- TDD refs: 4.1, 5.1
- Dependency: `P0` done
- Tasks:
  - [x] Gate runtime untuk tenant status (`trial/active/expired/suspended`).
  - [x] Gate WA active account sesuai plan.
  - [x] Gate calendar availability feature.
- Tests:
  - [x] Tenant expired/suspended tidak boleh automation normal.
  - [x] Starter/Growth/Pro gate sesuai feature table.

### P1-B — Monthly Unique Lead Limit
- ID: `P1-B1`
- Status: `DONE`
- Module owner: `Billing`, `Lead`, `AgentCore`
- PRD refs: 3.3
- TDD refs: 4.5 lead tables, 5.1 validate plan limit
- Dependency: `P1-A1`
- Tasks:
  - [x] Hitung unique customer per tenant per billing period.
  - [x] Saat limit habis: block automation + fallback/handoff policy.
- Tests:
  - [x] Nomor sama dalam period tidak dihitung ulang.
  - [x] Nomor baru setelah limit habis memicu block automation.

### P1-C — Stage/Goal/Mode/Memory Consistency
- ID: `P1-C1`
- Status: `DONE`
- Module owner: `Conversation`, `AgentCore`, `Invoice`
- PRD refs: 10, 16, 15
- TDD refs: 4.3, 5.10
- Dependency: `P0` done
- Tasks:
  - [x] Correction tidak reset stage.
  - [x] Topic switch hanya ubah active goal.
  - [x] Invoice sent -> `agent_mode=limited` + `memory_mode=dormant`.
  - [x] Dormant memory hanya aktif via explicit trigger.
- Tests:
  - [x] Transition stage/mode sesuai rule PRD.
  - [x] Dormant memory tidak dipakai default.

### P1-D — Handoff & Notification Policy Matrix
- ID: `P1-D1`
- Status: `DONE`
- Module owner: `Handoff`, `Notification`, `AgentCore`
- PRD refs: 17, 18
- TDD refs: 4.7, 5.6
- Dependency: `P0-D1`, `P1-A1`
- Tasks:
  - [x] Trigger handoff untuk complaint, low confidence, paused/handoff message, calendar unavailable, lead limit exhausted.
  - [x] Trigger notification payload sesuai reason/priority.
- Tests:
  - [x] Handoff created dengan context minimum lengkap.
  - [x] Notification event kritikal terbuat konsisten.

### P1 Exit Criteria
- [x] Monetization gates berjalan di runtime, bukan hanya UI.
- [x] Mode/stage/memory/invoice transitions tervalidasi test.
- [x] Handoff/notification trigger kritikal sesuai PRD.

---

## Sprint Board P2 (Coverage & Scale Readiness)

### P2-A — Intent Coverage Expansion
- ID: `P2-A1`
- Status: `DONE`
- Module owner: `AgentCore` (Interpretation)
- PRD refs: 8
- TDD refs: 5.2
- Dependency: `P1` done
- Tasks:
  - [x] Tambah intent prioritas: `ask_pricelist`, `ask_price`, `ask_package_detail`, `ask_availability`, `booking_intent`, `request_handoff`, `complaint`, `payment_related`, `topic_switch`, `correction`, `unclear_message`.
- Tests:
  - [x] Regression classifier untuk intent prioritas pass.

### P2-B — Entity Normalization Expansion
- ID: `P2-B1`
- Status: `DONE`
- Module owner: `AgentCore`, `Knowledge`, `Lead`
- PRD refs: 9
- TDD refs: 5.3, 5.4
- Dependency: `P2-A1`
- Tasks:
  - [x] Normalisasi `customer_name`, `event_type`, `event_date`, `location`, `package_interest`, `budget_min/max`, `invoice_reference`.
  - [x] Tenant-specific entity matching dinamis (tanpa hardcode nama paket).
- Tests:
  - [x] Entity correction update field relevan tanpa reset flow.

### P2-C — Analytics & Replay Completeness
- ID: `P2-C1`
- Status: `DONE`
- Module owner: `Analytics`, `Audit`, `Conversation`
- PRD refs: 20
- TDD refs: 4.3, 4.7, 8.6
- Dependency: `P1` done
- Tasks:
  - [x] Lengkapi metrik dashboard tenant/superadmin dari event runtime.
  - [x] Pastikan replay turn merekonstruksi keputusan dan validator result.
- Tests:
  - [x] Metrics aggregation valid minimal untuk KPI utama.
  - [x] Replay data integrity check pass.

### P2 Exit Criteria
- [x] Coverage intent/entity prioritas tercapai.
- [x] Analytics dan replay cukup untuk audit produksi.
- [x] Tidak ada gap kritikal tersisa antara PRD/TDD dan runtime.

---

## Recommended PR Order
1. `agentcore/pipeline-orchestrator-p0`
2. `whatsapp/inbound-to-pipeline`
3. `agentcore/decision-contract-and-failsafe`
4. `agentcore/validator-chain-enforcement`
5. `knowledge/structured-grounding-hardening`
6. `booking-precondition-and-calendar-claim-block`
7. `security/tenant-isolation-and-internal-secret`
8. `plans-feature-gate-runtime`
9. `billing-monthly-unique-lead-limit`
10. `conversation-stage-mode-memory-consistency`
11. `handoff-notification-policy-matrix`
12. `intent-entity-coverage-expansion`
13. `analytics-replay-completeness`

---

## Per-PR Definition of Done
- [x] Ada mapping eksplisit PRD/TDD section yang dipenuhi.
- [x] Ada test baru/regression sesuai risiko perubahan.
- [x] Tidak ada bypass validator atau leak tenant data.
- [x] Logging/trace cukup untuk audit keputusan.
- [x] CI test relevan pass.

---

## Closure Verification (2026-04-30)
- [x] Full suite pass via `docker compose exec -T app php artisan test`.
- [x] Result snapshot: `288 passed`, `1318 assertions`, duration `7.24s`.
- [x] Status seluruh fase `P0 -> P1 -> P2` ditutup berdasarkan bukti test + implementasi runtime saat ini.
- [x] Mapping audit requirement -> runtime -> test didokumentasikan pada `PRDTDD/TRACEABILITY_MATRIX.md`.
