# TDD — SaaS WhatsApp AI Sales Agent

## 1. Recommended Architecture
Recommended stack:
- Backend: Laravel Modular Monolith
- Database: MySQL 8
- Cache/Queue: Redis
- Queue Worker: Laravel Queue Worker
- Scheduler: Laravel Scheduler
- Realtime dashboard: Laravel Reverb / WebSocket server
- WhatsApp Gateway: Node.js + Baileys
- Object Storage local: filesystem/minio-compatible
- Object Storage production: S3-compatible or server disk with backup
- LLM Provider: pluggable provider adapter
- Reverse Proxy: Nginx
- Container: Docker Compose for local, Docker Compose or container orchestration for production

---

## 2. Service Boundary

### 2.1 Laravel App
Responsible for:
- Tenant management
- Admin dashboard
- Business data
- Conversation state
- AI orchestration
- Policy validation
- Action permission
- Invoice
- Notification
- Analytics
- API for WA gateway

### 2.2 Node Baileys Gateway
Responsible for:
- QR generation
- WA session persistence
- Incoming message listener
- Outbound message sender
- Session status event
- Reconnect handling

### 2.3 Redis
Responsible for:
- Queue
- Cache
- Temporary locks
- Rate limit
- Idempotency keys

### 2.4 MySQL
Responsible for:
- Source of truth data
- Conversation logs
- Decision traces
- Audit logs

---

## 3. High-Level Module Structure
Laravel modules:

```text
app/Modules/
  Platform/
  Tenancy/
  Billing/
  Plans/
  Activation/
  Auth/
  WhatsApp/
  Conversation/
  AgentCore/
  Knowledge/
  Lead/
  Booking/
  Calendar/
  Invoice/
  Handoff/
  Notification/
  Analytics/
  Audit/
  Shared/
```

Recommended folder inside module:

```text
ModuleName/
  Actions/
  DTOs/
  Enums/
  Events/
  Jobs/
  Models/
  Policies/
  Repositories/
  Services/
  Tests/
  routes.php
```

---

## 4. Database Schema Overview

### 4.1 Tenancy
Tables:
- tenants
- tenant_users
- tenant_settings
- tenant_policies
- tenant_preferences
- activation_tokens
- payment_logs
- plans
- plan_features
- tenant_subscriptions

Important fields:

`tenants`:
- id
- name
- slug
- status
- industry
- created_at
- updated_at

`plans`:
- id
- code
- name
- is_active

`plan_features`:
- id
- plan_id
- feature_key
- feature_value

`tenant_subscriptions`:
- id
- tenant_id
- plan_id
- status
- starts_at
- ends_at
- trial_ends_at

---

### 4.2 WhatsApp
Tables:
- wa_accounts
- wa_sessions
- wa_inbound_messages
- wa_outbound_messages
- wa_message_delivery_logs

Important fields:

`wa_accounts`:
- id
- tenant_id
- label
- phone_number
- status
- session_key
- last_connected_at
- last_disconnected_at

`wa_inbound_messages`:
- id
- tenant_id
- wa_account_id
- conversation_id
- provider_message_id
- from_phone
- message_type
- body
- raw_payload
- received_at
- processed_at

`wa_outbound_messages`:
- id
- tenant_id
- wa_account_id
- conversation_id
- to_phone
- message_type
- body
- media_url
- status
- provider_message_id
- queued_at
- sent_at
- failed_at

---

### 4.3 Conversation
Tables:
- conversations
- messages
- conversation_states
- conversation_summaries
- memory_entries
- decision_traces
- action_logs
- conversation_replay_logs

Important fields:

`conversations`:
- id
- tenant_id
- wa_account_id
- customer_phone
- customer_name
- status
- current_stage
- active_goal
- agent_mode
- memory_mode
- retention_until
- lead_temperature
- source
- last_message_at

`messages`:
- id
- tenant_id
- conversation_id
- direction
- message_type
- body
- raw_payload
- grounding_refs
- decision_trace_id
- created_at

`decision_traces`:
- id
- tenant_id
- conversation_id
- message_id
- input_snapshot_json
- interpretation_json
- decision_json
- validators_json
- blocked_actions_json
- grounding_refs_json
- final_reply
- model_name
- token_usage_json

---

### 4.4 Knowledge
Tables:
- service_catalogs
- products
- packages
- package_items
- prices
- discounts
- faqs
- knowledge_documents
- tenant_assets
- knowledge_versions

Important fields:

`packages`:
- id
- tenant_id
- service_catalog_id
- name
- slug
- description
- version
- effective_from
- effective_until
- is_active

`prices`:
- id
- tenant_id
- package_id
- currency
- amount
- discount_amount
- version
- effective_from
- effective_until
- is_active

`tenant_assets`:
- id
- tenant_id
- type
- filename
- path
- mime_type
- version
- effective_from
- effective_until
- is_active

---

### 4.5 Lead
Tables:
- lead_profiles
- lead_sources
- lead_scores

Important fields:

`lead_profiles`:
- id
- tenant_id
- conversation_id
- customer_phone
- name
- event_type
- event_date
- location
- budget_min
- budget_max
- package_interest_id
- objection_summary
- last_intent
- lead_temperature

`lead_scores`:
- id
- lead_profile_id
- interest_score
- closing_readiness
- engagement_score
- trust_level
- urgency_level

---

### 4.6 Booking / Calendar / Invoice
Tables:
- booking_settings
- calendar_connections
- calendar_settings
- calendar_availability_checks
- invoices
- invoice_send_logs

Important fields:

`booking_settings`:
- id
- tenant_id
- booking_link
- is_active

`calendar_settings`:
- id
- tenant_id
- enabled
- max_events_per_date
- timezone

`invoices`:
- id
- tenant_id
- conversation_id
- file_path
- status
- send_count
- max_send_count
- uploaded_by_admin_id
- uploaded_at

---

### 4.7 Handoff / Notification
Tables:
- handoffs
- notifications

Important fields:

`handoffs`:
- id
- tenant_id
- conversation_id
- lead_profile_id
- reason
- priority
- status
- summary
- recommended_next_action

`notifications`:
- id
- tenant_id
- conversation_id
- type
- reason
- priority
- payload_json
- is_read

---

## 5. Core Services

### 5.1 TurnPipelineService
Main service for every inbound message.

Pseudo flow:

```php
handleInboundMessage(InboundMessageDTO $message): TurnResultDTO
{
    acquireLock($message->tenantId, $message->customerPhone);

    $tenant = loadTenant();
    validateTenantStatus();
    validatePlanLimit();

    $conversation = findOrCreateConversation();
    $state = loadState();
    $lead = loadLeadProfile();

    if ($state->agent_mode === 'paused') {
        createNotification('message_while_paused');
        storeInboundOnly();
        return noReply();
    }

    $interpretation = interpretMessage();
    $entities = extractEntities();
    $entityMatches = matchTenantKnowledgeEntities();
    $lead = updateLeadCandidate();

    $knowledge = retrieveGroundedKnowledge();
    $decision = buildDecision();

    $policyResult = policyValidator->validate($decision);
    $groundingResult = groundingValidator->validate($decision, $knowledge);
    $permissionResult = actionPermissionValidator->validate($decision, $state, $tenant);
    $modeResult = modeValidator->validate($decision, $state);

    $final = resolveFinalActionOrFallback();
    $reply = composeReply($final);

    storeDecisionTrace();
    dispatchAllowedActions();
    updateConversationState();

    return TurnResultDTO::from($reply, $actions);
}
```

---

### 5.2 IntentClassifierService
Responsibilities:
- Return intent, confidence, reason
- Never make final action
- Must output JSON only
- Must support fallback unclear_message

---

### 5.3 EntityExtractionService
Responsibilities:
- Extract global entity
- Normalize dates
- Normalize budget
- Detect corrections
- Detect previous reference

---

### 5.4 EntityMatcherService
Responsibilities:
- Match tenant package/service names dynamically
- Match aliases
- Return package_id, slug, label
- No hardcoded wedding package names

---

### 5.5 KnowledgeRetrievalService
Responsibilities:
- Load structured data first
- Load unstructured data only if needed
- Return grounding refs
- Respect effective date/version
- Tenant-isolated

---

### 5.6 DecisionEngineService
Responsibilities:
- Convert interpretation + state + knowledge into structured decision
- Determine allowed/desired actions
- Determine handoff and notification need
- Produce blocked action candidates with reason

---

### 5.7 PolicyValidatorService
Checks:
- Global platform policy
- Tenant policy
- Business hours
- Follow-up policy
- Dormant memory rule

---

### 5.8 GroundingValidatorService
Checks:
- Price exists in structured data
- Package exists
- Booking link exists
- Pricelist file exists and belongs to tenant
- Calendar result exists for availability claim
- Invoice status exists

---

### 5.9 ActionPermissionValidatorService
Checks action-specific preconditions:
- send_text
- send_file
- send_pricelist_file
- send_booking_link
- check_calendar
- create_handoff
- trigger_notification
- send_invoice
- resend_invoice

---

### 5.10 ModeValidatorService
Checks:
- paused: block all AI reply/action
- handoff: block normal AI reply, allow notification/admin context only
- limited: allow light reply only
- active: allow normal validated action

---

## 6. API Contracts

### 6.1 Baileys → Laravel Inbound Message
Endpoint:

```http
POST /internal/wa/inbound-message
```

Payload:

```json
{
  "wa_account_id": "uuid",
  "provider_message_id": "string",
  "from_phone": "628xxxx",
  "message_type": "text",
  "body": "boleh minta pricelist?",
  "raw_payload": {}
}
```

Response:

```json
{
  "accepted": true,
  "conversation_id": "uuid"
}
```

---

### 6.2 Laravel → Baileys Send Message
Endpoint:

```http
POST /internal/send-message
```

Payload:

```json
{
  "wa_account_id": "uuid",
  "to_phone": "628xxxx",
  "message_type": "text",
  "body": "Boleh kak, atas nama siapa ya?",
  "media_url": null,
  "metadata": {
    "conversation_id": "uuid",
    "outbound_message_id": "uuid"
  }
}
```

Response:

```json
{
  "queued": true,
  "provider_message_id": null
}
```

---

### 6.3 Decision Trace Contract
```json
{
  "input": {
    "message": "boleh minta pricelist?",
    "conversation_state": {},
    "lead_profile": {},
    "tenant_policy": {}
  },
  "interpretation": {
    "intent": "ask_pricelist",
    "confidence": 0.91,
    "entities": {}
  },
  "decision": {
    "decision": "ask_missing_required_info",
    "desired_actions": ["send_pricelist_file"],
    "allowed_actions": ["reply_text"],
    "blocked_actions": [
      {
        "action": "send_pricelist_file",
        "reason": "name_required"
      }
    ]
  },
  "validators": {
    "policy": "passed",
    "grounding": "passed",
    "permission": "blocked_partial",
    "mode": "passed"
  },
  "final_reply": "Boleh kak, aku kirimkan ya. Boleh tahu atas nama siapa dulu?"
}
```

---

## 7. Local Docker Development Requirement

### 7.1 Containers
Recommended local containers:
- app Laravel PHP-FPM
- nginx Reverse proxy local
- mysql MySQL 8
- redis Cache and queue
- queue Laravel queue worker
- scheduler Laravel scheduler
- wa-gateway Node.js Baileys service
- mailpit Local email testing
- minio Local object storage optional

### 7.2 docker-compose.yml Concept
```yaml
services:
  app:
    build: ./docker/php
    volumes:
      - .:/var/www/html
    depends_on:
      - mysql
      - redis

  nginx:
    image: nginx:alpine
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - app

  mysql:
    image: mysql:8
    environment:
      MYSQL_DATABASE: wa_agent
      MYSQL_USER: wa_agent
      MYSQL_PASSWORD: secret
      MYSQL_ROOT_PASSWORD: root
    ports:
      - "3306:3306"
    volumes:
      - mysql_data:/var/lib/mysql

  redis:
    image: redis:alpine
    ports:
      - "6379:6379"

  queue:
    build: ./docker/php
    command: php artisan queue:work --tries=3 --timeout=120
    volumes:
      - .:/var/www/html
    depends_on:
      - app
      - redis
      - mysql

  scheduler:
    build: ./docker/php
    command: sh -c "while true; do php artisan schedule:run; sleep 60; done"
    volumes:
      - .:/var/www/html
    depends_on:
      - app

  wa-gateway:
    build: ./wa-gateway
    ports:
      - "3001:3001"
    volumes:
      - ./wa-gateway:/app
      - wa_sessions:/app/sessions
    depends_on:
      - redis
      - app

  mailpit:
    image: axllent/mailpit
    ports:
      - "8025:8025"
      - "1025:1025"

volumes:
  mysql_data:
  wa_sessions:
```

### 7.3 Local Setup Command
```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan storage:link
docker compose exec app php artisan test
```

### 7.4 Local Testing Requirement
Local must support:
- Login superadmin
- Create tenant
- Activation email captured by Mailpit
- Tenant activation
- Tenant login
- QR scan from Baileys gateway
- Send simulated inbound message
- Process AI turn in queue
- See conversation inbox
- See decision trace
- Takeover/resume
- Upload pricelist
- Upload invoice

---

## 8. Production Deployment Requirement

### 8.1 Production Components
Minimum production server:
- Nginx
- PHP-FPM container
- Laravel queue worker container
- Laravel scheduler container
- MySQL managed/self-hosted
- Redis
- Node Baileys gateway
- Persistent volume for WA sessions
- Persistent storage for uploads
- SSL certificate
- Backup system
- Log rotation

### 8.2 Production Environment Variables
Important env:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=mysql
DB_DATABASE=wa_agent
DB_USERNAME=wa_agent
DB_PASSWORD=strong-password

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=redis

WA_GATEWAY_URL=http://wa-gateway:3001
WA_INTERNAL_SECRET=strong-internal-secret

LLM_PROVIDER=openai_or_other
LLM_API_KEY=secret

MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email
MAIL_PASSWORD=app-password
MAIL_ENCRYPTION=tls

FILESYSTEM_DISK=public_or_s3
```

### 8.3 Production Deploy Steps
1. Provision server
2. Install Docker and Docker Compose
3. Clone repository
4. Create production `.env`
5. Build containers
6. Run migrations
7. Seed superadmin
8. Start queue workers
9. Start scheduler
10. Start WA gateway
11. Configure Nginx and SSL
12. Configure backup
13. Configure monitoring
14. Test health endpoints
15. Test one tenant activation
16. Test WA QR
17. Test inbound/outbound message

### 8.4 Health Checks
Required endpoints:
- GET /health
- GET /health/db
- GET /health/redis
- GET /health/queue
- GET /health/wa-gateway

### 8.5 Backup Requirement
Backup:
- MySQL daily
- Uploaded files daily
- WA sessions daily or after status change
- Env/secrets not stored in repo
- Retain at least 7 daily backups

### 8.6 Monitoring Requirement
Monitor:
- Queue failed jobs
- WA disconnected accounts
- LLM errors
- Token usage spike
- MySQL storage
- Redis memory
- Disk usage
- Error rate per tenant

---

## 9. Technical Principle
System ini adalah controlled workflow engine.

LLM boleh membantu interpretasi dan komposisi balasan, tetapi final authority harus selalu dari:
- Database tenant
- Conversation state
- Validator
- Policy
- Permission
- Agent mode
