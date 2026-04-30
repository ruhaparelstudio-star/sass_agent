# PRD — SaaS WhatsApp AI Sales Agent

## 1. Product Summary
Produk adalah SaaS WhatsApp AI Sales Agent untuk vendor wedding.

Produk ini bukan chatbot FAQ biasa, tetapi:
- WhatsApp Conversation Orchestrator
- AI Sales Workflow Engine
- Tenant-based SaaS Platform
- Admin-assisted Booking System
- Invoice and Post-sales Controller
- Conversation Audit and Replay System

Prinsip utama:
- AI tidak boleh menjadi final authority.
- Semua balasan/action wajib dikontrol oleh data tenant, conversation state, policy, grounding, permission, dan agent mode.
- Semua data tenant bersifat dinamis dari admin panel.

---

## 2. User Roles

### 2.1 Superadmin
Superadmin adalah owner platform.

Kemampuan utama:
- Membuat tenant
- Mengatur paket tenant
- Mengatur trial, active, expired, suspended
- Generate activation link
- Resend activation email
- Suspend/reactivate tenant
- Melihat semua tenant
- Mengatur global plan limits
- Melihat usage analytics
- Melihat LLM token usage per tenant
- Mengatur default retention
- Mengatur default business hours
- Melihat error rate, WA agent status, dan volume conversation

### 2.2 Tenant Admin
Tenant admin adalah pemilik/vendor wedding.

Kemampuan utama:
- Login dashboard tenant
- Connect WhatsApp agent via QR
- Kelola paket, harga, FAQ, diskon, produk, booking link
- Upload pricelist PDF
- Mengatur tone, business hours, delay reply, follow-up preference
- Melihat conversation inbox
- Takeover conversation
- Resume AI conversation
- Close conversation
- Upload invoice
- Resend invoice sesuai limit
- Melihat admin context panel
- Melihat decision trace sederhana
- Melihat analytics lead

### 2.3 Future Roles
Belum masuk MVP, tetapi schema sebaiknya mendukung:
- Staff
- Customer service
- Finance
- Manager

---

## 3. Subscription / Plan Requirement

### 3.1 Plans

#### Starter
- 1 WA agent
- Google Calendar disabled
- Monthly lead limit rendah
- Dashboard basic

#### Growth
- 2 WA agents
- Google Calendar enabled
- Monthly lead limit medium
- Dashboard

#### Pro
- Multi WA agents
- Google Calendar enabled
- Monthly lead limit tinggi/unlimited by setting
- Dashboard advanced

### 3.2 Feature Gating
Fitur yang wajib dibatasi:
- Jumlah WA agent aktif
- Google Calendar availability
- Monthly unique lead count
- Automation behavior ketika lead limit habis

### 3.3 Lead Limit Rule
Lead bulanan dihitung dari unique customer per tenant per billing period.

Rules:
- Nomor baru dalam billing period dihitung 1 lead
- Nomor lama yang chat lagi dalam billing period sama tidak dihitung ulang
- Jika limit habis, automation normal diblokir
- Sistem masuk fallback atau handoff sesuai tenant policy

---

## 4. Tenant Activation Requirement

### 4.1 Flow
1. Superadmin membuat tenant
2. Sistem membuat activation token
3. Sistem membuat activation link
4. Link tampil di dashboard superadmin
5. Link dikirim via email ke tenant
6. Tenant klik link
7. Tenant set password
8. Token menjadi used
9. Tenant aktif

### 4.2 Rules
- Token harus random dan secure
- Token punya expiry
- Token hanya bisa dipakai sekali
- Token invalid setelah dipakai
- Resend activation membuat token baru atau memperpanjang token sesuai policy

---

## 5. WhatsApp Agent Requirement

### 5.1 Core Function
- Connect nomor via QR
- Simpan WA account per tenant
- Terima inbound message
- Kirim outbound message
- Simpan raw payload
- Support multi WA agent sesuai plan
- Handle reconnect
- Handle session expired
- Deduplicate inbound message
- Queue outbound message

### 5.2 WA Account Status
Status minimal:
- disconnected
- qr_pending
- connecting
- connected
- reconnecting
- failed
- banned_or_restricted

### 5.3 Baileys Service Boundary
Baileys tidak boleh ditanam langsung di Laravel PHP process.

Rekomendasi production:
- Laravel app: business logic, API, dashboard, queue
- Node.js Baileys Gateway: QR, session, inbound/outbound WA
- Redis queue / HTTP internal API: komunikasi Laravel ↔ Baileys Gateway

---

## 6. Business Data Requirement

### 6.1 Structured Data
Structured data adalah source of truth.

Contoh:
- Products
- Service catalog
- Packages
- Package items
- Prices
- Discounts
- FAQ structured
- Booking link
- Calendar setting
- Pricelist asset metadata
- Tenant policy
- Business hours

### 6.2 Unstructured Data
Unstructured data dipakai untuk narasi dan semantic retrieval.

Contoh:
- Brand story
- Selling points
- Objection handling
- Long-form business explanation
- Style guide
- Custom notes

### 6.3 Versioning Requirement
Setiap perubahan penting harus versioned.

Entity yang wajib mendukung version/effective date:
- Package
- Price
- Discount
- FAQ
- Asset
- Policy
- Booking-related config

Field minimal:
- version
- effective_from
- effective_until
- is_active
- created_by
- change_note

---

## 7. AI Sales Engine Requirement

### 7.1 Responsibilities
AI Sales Engine bertanggung jawab untuk:
- Interpret inbound message
- Detect intent
- Extract entities
- Read current conversation state
- Read lead profile
- Retrieve relevant knowledge
- Determine active goal
- Produce structured decision
- Validate decision
- Compose grounded reply
- Trigger allowed action
- Store decision trace

### 7.2 Non-Negotiable Flow
Setiap inbound message harus melewati flow:
1. Receive inbound message
2. Deduplicate
3. Load tenant + WA account
4. Check tenant status and plan
5. Load conversation
6. Load conversation state
7. Load lead profile
8. Interpret message
9. Extract entities
10. Update candidate memory
11. Retrieve structured knowledge
12. Retrieve unstructured knowledge if needed
13. Build decision JSON
14. Validate policy
15. Validate grounding
16. Validate action permission
17. Validate mode
18. Compose response
19. Send WhatsApp reply/action
20. Store message, trace, action log, metrics

### 7.3 Decision JSON Contract
```json
{
  "intent": "ask_pricelist",
  "confidence": 0.86,
  "entities": {
    "name": null,
    "event_date": null,
    "event_type": null,
    "location": null,
    "package_interest": null,
    "budget": null
  },
  "current_stage": "qualification",
  "active_goal": "collect_missing_info",
  "decision": "ask_missing_required_info",
  "allowed_actions": ["reply_text"],
  "blocked_actions": [
    {
      "action": "send_pricelist_file",
      "reason": "minimum_requirement_not_met"
    }
  ],
  "handoff_required": false,
  "notification_required": false,
  "grounding_refs": [],
  "reply_strategy": "short_contextual_question"
}
```

### 7.4 Decision Rule
Classifier output bukan final decision.

Final decision harus ditentukan oleh:
- Intent result
- Entity result
- Stage
- Active goal
- Lead profile
- Tenant policy
- Available knowledge
- Action permission
- Mode validator

---

## 8. Intent Requirement

### 8.1 Intent Groups

Opening:
- greeting
- first_contact
- intro_interest

Information request:
- ask_pricelist
- ask_package_list
- ask_package_detail
- ask_package_comparison
- ask_price
- ask_faq
- ask_booking_flow
- ask_policy

Qualification:
- provide_name
- provide_date
- provide_event_type
- provide_budget
- provide_preference

Conversion:
- ask_availability
- booking_intent
- confirm_booking
- ask_booking_link
- ask_lock_date

Objection:
- objection_price
- objection_trust
- objection_timing
- objection_competitor
- objection_need_discussion

Administrative:
- ask_invoice
- invoice_related
- payment_related
- ask_admin
- request_handoff
- complaint

Context recovery:
- refer_previous_chat
- continue_previous_discussion
- repeat_question

Conversation repair:
- change_date
- change_package
- change_budget
- correction
- topic_switch
- interrupt_current_flow
- resume_previous_goal
- clarify_ambiguous_message

Control:
- thanks
- end_conversation
- stop_followup
- unclear_message
- off_topic

---

## 9. Entity Requirement
Entity minimal:
- customer_name
- event_type
- event_date
- event_time_start
- event_time_end
- location
- package_interest
- package_slug
- budget_min
- budget_max
- preference
- objection
- booking_intent_signal
- previous_conversation_reference
- payment_topic
- invoice_reference

Entity rule:
- Entity extraction boleh berbasis LLM + deterministic parser
- Tanggal harus distandardisasi
- Tanggal ambigu harus ditanya ulang
- Entity correction tidak boleh reset flow
- Tenant-specific entity seperti nama paket harus berasal dari tenant knowledge, bukan hardcoded

---

## 10. Conversation Stage Requirement
Stage minimal:
- new_lead
- exploration
- qualification
- recommendation
- consideration
- booking
- waiting_booking
- closed
- invoice_phase
- post_invoice_limited
- handoff
- paused_admin

Setiap conversation wajib punya:
- current_stage
- active_goal
- agent_mode
- memory_mode
- retention_until
- lead_temperature

Rules:
- Stage boleh maju mundur
- Correction tidak reset stage
- Topic switch hanya mengubah active_goal
- Admin takeover mengubah agent_mode menjadi paused
- Invoice sent mengubah agent_mode menjadi limited dan memory_mode menjadi dormant

---

## 11. Lead Intelligence Requirement
Score minimal:
- interest_score
- closing_readiness
- engagement_score
- trust_level
- urgency_level
- lead_temperature

Temperature:
- cold
- warm
- hot

Dipakai untuk:
- Recommendation timing
- Booking push timing
- Follow-up eligibility
- Handoff priority
- Admin context panel

---

## 12. Booking Requirement
Booking link hanya boleh dikirim jika semua syarat terpenuhi:
- Package selected
- Customer name available
- Availability checked and valid
- User menunjukkan booking intent
- Closing readiness tinggi
- Booking link tenant tersedia
- Agent mode active
- Tenant status active/trial

Jika salah satu gagal:
- send_booking_link harus diblokir
- Sistem pilih fallback aman
- Jika butuh admin, create handoff

---

## 13. Google Calendar Requirement
Google Calendar adalah gated feature.

Jika enabled dan connected:
- Agent boleh cek availability
- Availability harus tenant-aware
- Tenant bisa set max event per date
- Jika jumlah event >= limit, tanggal unavailable

Jika disabled/not connected/error:
- Agent tidak boleh mengklaim available
- Agent harus fallback ke manual confirmation/handoff

---

## 14. Pricelist Requirement
Tenant bisa:
- Upload pricelist PDF
- Set mode text_first/file_first
- Set minimum requirement name_only/name_date
- Enable/disable file

Flow:
1. Detect ask_pricelist
2. Check minimum requirement
3. Jika belum lengkap, tanya data kurang
4. Jika lengkap, cek asset file
5. Cek preference
6. Kirim text/file sesuai rule
7. Fallback aman jika file/text tidak tersedia

Forbidden:
- Mengarang file
- Mengirim file tenant lain
- Mengirim file sebelum syarat minimum terpenuhi

---

## 15. Invoice Requirement
Invoice di-handle admin.

Flow:
1. Admin close conversation
2. Admin upload invoice
3. Sistem kirim invoice ke WhatsApp
4. Status invoice_sent
5. Agent mode limited
6. Memory mode dormant
7. Admin bisa resend maksimal 2 kali total
8. Admin bisa set invoice_close setelah paid

Rules:
- Resend > max_send_count harus ditolak
- Invoice action harus logged
- User reply setelah invoice diarahkan ke admin jika administratif

---

## 16. Memory Requirement
Mode:
- active
- dormant

Active:
- Dipakai normal
- Summary berkala

Dormant:
- Tidak otomatis dipakai sebagai konteks utama
- Hanya retrieve jika ada trigger eksplisit

Trigger dormant retrieval:
- refer_previous_chat
- continue_previous_discussion
- invoice_related
- complaint
- previous_booking_reference
- admin_context_needed

Summary minimal:
- Lead profile
- Need
- Entities
- Objection
- Stage terakhir
- Active goal terakhir
- Unresolved action

---

## 17. Handoff Requirement
Trigger:
- User minta admin
- Complaint
- Invoice/payment sensitive
- Negotiation special
- AI confidence low
- Booking needs admin
- Calendar unavailable but user asks availability
- Booking link missing
- Lead limit exhausted
- Message arrives during paused/handoff

Handoff data:
- tenant_id
- conversation_id
- lead_id
- reason
- priority
- current_stage
- active_goal
- summary
- recommended_next_action
- status

Admin Context Panel:
- Name
- Phone
- Package interest
- Event date
- Location
- Budget
- Objection
- Stage
- Active goal
- Summary
- Reason handoff
- Recommended next action

---

## 18. Notification Requirement
Trigger:
- Handoff required
- Booking-related action
- Complaint
- Invoice phase reply
- Resend invoice request
- Message while paused/handoff
- WA session disconnected
- Calendar integration error

Payload:
- reason
- tenant_id
- conversation_id
- customer_name
- customer_phone
- priority
- related_stage
- recommended_admin_action
- is_read

Channel:
- In-app notification
- Browser notification
- Sound alert

---

## 19. Safety / Policy Requirement
Evaluation order:
1. Decision Engine
2. Global Policy Check
3. Tenant Policy Check
4. Tenant Preference Formatting
5. Response Composer
6. Grounding Validator
7. Action Permission Validator
8. Mode Validator
9. Send

Global policy cannot be disabled.

Global forbidden actions:
- Hallucinate price
- Hallucinate availability
- Hallucinate booking link
- Hallucinate file
- Hallucinate invoice status
- Reply while paused
- Send invoice more than max
- Use dormant memory by default
- Use old price unless context explicitly requires
- Execute action without permission

---

## 20. Analytics Requirement
Tenant dashboard:
- Total leads
- New leads
- Hot leads
- Conversion rate
- Average response time
- Most asked questions
- Most requested package
- Handoff count
- Booking link sent count
- Invoice sent count
- Closed leads
- Lost leads

Superadmin dashboard:
- Active tenants
- Expired tenants
- WA agents connected
- Monthly conversations
- LLM token usage
- Tenant usage by plan
- Error rate
- Top tenants by volume

---

## Final Product Principle
Build this system as a controlled workflow engine first, AI responder second.

Final authority must always be:
- Tenant data
- Conversation state
- Policy
- Grounding
- Action permission
- Agent mode
- Human admin when needed
