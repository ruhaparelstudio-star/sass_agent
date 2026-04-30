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
- Status: `TODO`
- Module owner: `AgentCore`, `WhatsApp`, `Conversation`
- PRD refs: 7.2, Final Principle
- TDD refs: 5.1, 6.1, 6.3
- Dependency: none
- Tasks:
  - [ ] Jalur inbound endpoint WA masuk ke satu orchestrator decision-driven.
  - [ ] Urutan pipeline wajib: receive -> dedupe -> load tenant/plan -> load conversation/state/lead -> interpret -> entity -> knowledge -> decision -> validators -> compose -> send -> persist.
  - [ ] Hapus/disable static shortcut reply path.
- Tests:
  - [ ] E2E inbound turn mengikuti urutan pipeline.
  - [ ] Turn menyimpan trace/log/message sesuai kontrak.

### P0-B — Dedupe & Idempotency Safety
- ID: `P0-B1`
- Status: `TODO`
- Module owner: `WhatsApp`
- PRD refs: 5.1 deduplicate inbound message
- TDD refs: 4.2 wa_inbound_messages, 5.1 acquire lock
- Dependency: `P0-A1`
- Tasks:
  - [ ] Dedupe key deterministic `provider + provider_message_id + tenant_id`.
  - [ ] Retry inbound tidak menghasilkan action/outbound duplikat.
- Tests:
  - [ ] Duplicate inbound message diabaikan aman.
  - [ ] Outbound/action log tidak dobel saat replay payload.

### P0-C — Decision Contract Standardization
- ID: `P0-C1`
- Status: `TODO`
- Module owner: `AgentCore`
- PRD refs: 7.3, 7.4
- TDD refs: 5.6, 6.3
- Dependency: `P0-A1`
- Tasks:
  - [ ] Samakan shape decision JSON field wajib PRD.
  - [ ] Enforce rule classifier output bukan final action.
  - [ ] Tambah fail-safe invalid JSON/system error -> safe fallback.
- Tests:
  - [ ] Contract validation untuk decision payload.
  - [ ] Invalid JSON classifier tidak menyebabkan action liar.

### P0-D — Validator Chain Enforcement
- ID: `P0-D1`
- Status: `TODO`
- Module owner: `AgentCore`
- PRD refs: 19, 12, 13, 14, 15
- TDD refs: 5.7, 5.8, 5.9, 5.10
- Dependency: `P0-C1`
- Tasks:
  - [ ] Enforce urutan validator: policy -> grounding -> permission -> mode.
  - [ ] Fail-closed pada data/grounding tidak cukup.
  - [ ] Standarkan reason code blocked action lintas validator.
- Tests:
  - [ ] Order validator diverifikasi eksplisit.
  - [ ] Action blocked bila satu validator gagal.

### P0-E — Anti-Hallucination Hard Rules
- ID: `P0-E1`
- Status: `TODO`
- Module owner: `Knowledge`, `Booking`, `Calendar`, `Invoice`, `AgentCore`
- PRD refs: 19 forbidden actions, 12/13/14/15
- TDD refs: 5.8, 5.9
- Dependency: `P0-D1`
- Tasks:
  - [ ] Block harga tanpa structured price valid.
  - [ ] Block availability claim tanpa calendar result valid.
  - [ ] Block booking link tanpa booking config aktif + preconditions.
  - [ ] Block file/pricelist tanpa asset valid dan tenant-owned.
  - [ ] Block invoice resend > `max_send_count`.
- Tests:
  - [ ] Matrix anti-hallucination inti pass.

### P0-F — Tenant Isolation & Internal Security
- ID: `P0-F1`
- Status: `TODO`
- Module owner: `Security`, `Audit`, semua query owner module
- PRD refs: Non-negotiable no cross-tenant access
- TDD refs: 4.x tenant_id across tables, 8.2 env/internal secret
- Dependency: parallel dengan `P0-A` s/d `P0-E`
- Tasks:
  - [ ] Audit semua query/action sensitif tenant-scoped.
  - [ ] Internal WA endpoint wajib secret check.
  - [ ] Pastikan audit/action logging stabil di path health/runtime.
- Tests:
  - [ ] Cross-tenant data access ditolak.
  - [ ] Internal endpoint tanpa secret ditolak.

### P0 Exit Criteria
- [ ] Tidak ada action sensitif yang bypass validator.
- [ ] Tidak ada klaim harga/availability/link/file tanpa grounding valid.
- [ ] Inbound pipeline end-to-end berjalan deterministic.
- [ ] Semua test P0 pass.

---

## Sprint Board P1 (Operational Safety & Monetization)

### P1-A — Plan/Feature Gate Runtime
- ID: `P1-A1`
- Status: `TODO`
- Module owner: `Billing`, `Plans`, `AgentCore`, `WhatsApp`
- PRD refs: 3, 13, 17
- TDD refs: 4.1, 5.1
- Dependency: `P0` done
- Tasks:
  - [ ] Gate runtime untuk tenant status (`trial/active/expired/suspended`).
  - [ ] Gate WA active account sesuai plan.
  - [ ] Gate calendar availability feature.
- Tests:
  - [ ] Tenant expired/suspended tidak boleh automation normal.
  - [ ] Starter/Growth/Pro gate sesuai feature table.

### P1-B — Monthly Unique Lead Limit
- ID: `P1-B1`
- Status: `TODO`
- Module owner: `Billing`, `Lead`, `AgentCore`
- PRD refs: 3.3
- TDD refs: 4.5 lead tables, 5.1 validate plan limit
- Dependency: `P1-A1`
- Tasks:
  - [ ] Hitung unique customer per tenant per billing period.
  - [ ] Saat limit habis: block automation + fallback/handoff policy.
- Tests:
  - [ ] Nomor sama dalam period tidak dihitung ulang.
  - [ ] Nomor baru setelah limit habis memicu block automation.

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
- [ ] Monetization gates berjalan di runtime, bukan hanya UI.
- [ ] Mode/stage/memory/invoice transitions tervalidasi test.
- [ ] Handoff/notification trigger kritikal sesuai PRD.

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
- Status: `TODO`
- Module owner: `AgentCore`, `Knowledge`, `Lead`
- PRD refs: 9
- TDD refs: 5.3, 5.4
- Dependency: `P2-A1`
- Tasks:
  - [ ] Normalisasi `customer_name`, `event_type`, `event_date`, `location`, `package_interest`, `budget_min/max`, `invoice_reference`.
  - [ ] Tenant-specific entity matching dinamis (tanpa hardcode nama paket).
- Tests:
  - [ ] Entity correction update field relevan tanpa reset flow.

### P2-C — Analytics & Replay Completeness
- ID: `P2-C1`
- Status: `TODO`
- Module owner: `Analytics`, `Audit`, `Conversation`
- PRD refs: 20
- TDD refs: 4.3, 4.7, 8.6
- Dependency: `P1` done
- Tasks:
  - [ ] Lengkapi metrik dashboard tenant/superadmin dari event runtime.
  - [ ] Pastikan replay turn merekonstruksi keputusan dan validator result.
- Tests:
  - [ ] Metrics aggregation valid minimal untuk KPI utama.
  - [ ] Replay data integrity check pass.

### P2 Exit Criteria
- [ ] Coverage intent/entity prioritas tercapai.
- [ ] Analytics dan replay cukup untuk audit produksi.
- [ ] Tidak ada gap kritikal tersisa antara PRD/TDD dan runtime.

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
- [ ] Ada mapping eksplisit PRD/TDD section yang dipenuhi.
- [ ] Ada test baru/regression sesuai risiko perubahan.
- [ ] Tidak ada bypass validator atau leak tenant data.
- [ ] Logging/trace cukup untuk audit keputusan.
- [ ] CI test relevan pass.
