# PART A — PRD (ENTERPRISE FINAL VERSION)

## SYSTEM OVERVIEW

SaaS WhatsApp AI Sales Agent for Vendor Wedding.

Multi-tenant, AI-assisted, decision-controlled system.

---

## CORE PRINCIPLES

- AI is NOT decision maker
- System is state-driven
- Data is source of truth
- Validation is mandatory
- No hallucination allowed

---

## CORE FLOW

Inbound Message →
Interpretation →
Entity Extraction →
State Evaluation →
Decision Engine →
Validation →
Action →
Response

---

## USER ROLES

### Superadmin
- Manage tenants
- Manage plans
- Monitor system

### Tenant Admin
- Manage business data
- Handle conversations
- Upload assets (pricelist, invoice)

---

## CORE FEATURES

- WhatsApp automation
- Lead management
- Booking system
- Invoice system
- Handoff system
- Decision trace system

---

## CRITICAL RULES

1. No booking without:
   - Name
   - Package
   - Availability
   - Intent

2. No price without structured data

3. No file without ownership validation

4. No availability without calendar

---

## DATA RULES

- Tenant isolation REQUIRED
- Structured data = source of truth
- Versioning must be respected

---

## CONVERSATION SYSTEM

Must track:
- current_stage
- active_goal
- agent_mode
- memory_mode

---

## ACTION RULES

All actions must:
- Pass validators
- Be allowed by tenant
- Be valid for mode

---

## SAFETY POLICY

GLOBAL FORBIDDEN:
- Hallucinated response
- Unauthorized action
- Cross-tenant data
- Invalid booking

---

## FINAL PRINCIPLE

SYSTEM must behave as:

CONTROLLED WORKFLOW ENGINE

NOT AI chatbot
