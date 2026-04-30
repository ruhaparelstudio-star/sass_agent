# PART A — PRD

## SYSTEM OVERVIEW

SaaS WhatsApp AI Sales Agent untuk vendor wedding.

Sistem adalah multi-tenant, controlled AI workflow engine, dan deterministic decision system.

Sistem BUKAN chatbot generatif.

---

## CORE PRINCIPLES

- LLM bukan otoritas final
- Control > Intelligence
- State-driven workflow
- Structured data sebagai source of truth
- Semua action wajib tervalidasi
- Tenant isolation wajib
- Tidak boleh ada halusinasi

---

## MANDATORY TURN PIPELINE

Receive →
Deduplicate →
Tenant Load →
State Load →
Interpretation →
Entity Extraction →
Knowledge Retrieval →
Decision Engine →
Validators →
Action Resolver →
Response →
Store Trace

---

## VALIDATION LAYERS (STRICT ORDER)

1. PolicyValidator
2. GroundingValidator
3. ActionPermissionValidator
4. ModeValidator

Jika salah satu gagal, action harus diblok.

---

## USER ROLES

### Superadmin
- Kelola tenant
- Kelola plan/subscription
- Monitor sistem

### Tenant Admin
- Kelola knowledge & data bisnis tenant
- Kelola percakapan
- Kelola aset tenant (mis. pricelist, invoice)

---

## CORE FEATURES

- WhatsApp automation
- Lead management
- Booking workflow
- Invoice workflow
- Handoff workflow
- Decision trace & auditability

---

## NON-NEGOTIABLE BUSINESS RULES

1. Booking dilarang tanpa data minimum:
   - Nama
   - Paket
   - Cek ketersediaan
   - Intent valid
2. Harga dilarang jika tidak ada data terstruktur dari DB/knowledge resmi
3. File/asset dilarang tanpa validasi kepemilikan tenant
4. Ketersediaan dilarang tanpa sumber kalender yang valid

---

## DATA GOVERNANCE

- Isolasi tenant wajib untuk semua read/write
- Structured data selalu mengalahkan generasi LLM
- Versioning data/knowledge harus dihormati
- Dilarang cross-tenant access

---

## CONVERSATION STATE

State minimal yang harus dilacak:
- current_stage
- current_intent
- last_user_message
- last_agent_message
- filled_slots
- asked_fields
- next_best_action

---

## DECISION & ACTION POLICY

- Output classifier bukan keputusan final
- Keputusan final wajib mempertimbangkan:
  - Intent
  - Entities
  - State
  - Policy
  - Knowledge
  - Permission
  - Mode
- Semua action wajib lolos validator sebelum dieksekusi

---

## FAILURE HANDLING

- Invalid JSON: safe fallback response
- Missing data: block action
- Low confidence: handoff
- System error: safe fallback

---

## SAFETY POLICY (GLOBAL FORBIDDEN)

- Hallucinated response
- Unauthorized action
- Cross-tenant data leakage
- Invalid booking execution

---

## FINAL PRINCIPLE

Sistem harus selalu berperilaku sebagai controlled workflow engine yang deterministic, aman, dan tervalidasi.
