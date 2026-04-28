# PHASE_CONTEXT_MATRIX.md

## Purpose

File ini membatasi konteks agar Claude Code tidak membaca seluruh PRD/TDD setiap kali bekerja.

## Global Always Read

- `CLAUDE.md`
- `PART_A_PRD.md`
- Current phase file only

## Context Rule Per Lane

| Lane | Read Context | Forbidden |
|---|---|---|
| Foundation | docker, Laravel bootstrap, health | AI engine, WA business rules |
| Infra | tenant, auth, plan, activation | conversation pipeline |
| WhatsApp | wa account/session/inbound/outbound | AI decision logic |
| Conversation | conversation/message/state/lead | WA gateway internals |
| Data Knowledge | package/price/faq/asset/version | LLM prompt |
| AI Layer | intent/entity/LLM adapter | action dispatch |
| Core Engine | pipeline/decision/state transition | UI implementation |
| Validation | policy/grounding/permission/mode | direct outbound |
| Action | dispatcher/action logs/outbound | classifier logic |
| Admin UI | dashboard/inbox/context panel | pipeline internals except DTO read |
| Integration | calendar/invoice/notification | unrelated analytics |
| Memory | summary/memory/dormant retrieval | follow-up automation |
| Analytics | metrics/snapshot/token usage | decision mutation |
| Hardening | security/rate limit/idempotency/log | product feature expansion |

## Anti-Drift

If a task requires future lane code, create an interface/stub only when necessary and explicitly report it as a boundary dependency.
