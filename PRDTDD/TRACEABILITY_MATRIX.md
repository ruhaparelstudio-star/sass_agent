# TRACEABILITY MATRIX — PRD/TDD to Runtime/Test Evidence

Date: 2026-04-30
Source baseline:
- `PRDTDD/PRD.md`
- `PRDTDD/TDD.md`
- `PRDTDD/PHASE_CODE.md`
- `PRDTDD/PLAN.md`

Verification command:
- `docker compose exec -T app php artisan test`
- Snapshot: `288 passed`, `1318 assertions`.

---

## A. Phase-Based Traceability (P0 -> P2)

| Phase Item | PRD/TDD Reference | Runtime Evidence (Implementation) | Test Evidence (Key Cases) | Status |
|---|---|---|---|---|
| P0-A Inbound pipeline orchestrator | PRD 7.2, Final Principle; TDD 5.1, 6.1, 6.3 | `app/Modules/WhatsApp/Services/WaInboundTurnOrchestratorService.php`, `app/Modules/CoreEngine/Services/TurnPipelineService.php` | `tests/Unit/WhatsApp/WaInboundTurnOrchestratorServiceTest.php::test_inbound_turn_orchestrator_runs_pipeline_and_persists_trace_contract`, `tests/Feature/WhatsApp/WaInternalApiTest.php::test_inbound_message_is_tenant_scoped_deduplicated_and_stored` | Closed |
| P0-B Dedupe & idempotency | PRD 5.1 deduplicate inbound; TDD 4.2, 5.1 | `app/Modules/WhatsApp/Services/WaSyncService.php`, `app/Modules/WhatsApp/Services/WaInboundTurnOrchestratorService.php` | `tests/Feature/WhatsApp/WaInternalApiTest.php::test_duplicate_inbound_replay_does_not_duplicate_outbound_or_action_logs`, `tests/Unit/WhatsApp/WaInboundTurnOrchestratorServiceTest.php::test_inbound_turn_orchestrator_is_idempotent_per_inbound_message` | Closed |
| P0-C Decision contract & fail-safe | PRD 7.3, 7.4; TDD 5.6, 6.3 | `app/Modules/CoreEngine/Services/TurnPipelineService.php`, `app/Modules/AiLayer/Services/InterpretationService.php`, `app/Modules/AiLayer/Services/LlmJsonGuard.php` | `tests/Unit/CoreEngine/TurnPipelineServiceTest.php::test_decision_contract_contains_required_prd_fields_and_entity_shape`, `tests/Unit/CoreEngine/TurnPipelineServiceTest.php::test_invalid_classifier_json_fails_safe_and_does_not_enable_sensitive_action`, `tests/Unit/AiLayer/InterpretationServiceTest.php::test_invalid_provider_json_uses_deterministic_message_fallback` | Closed |
| P0-D Validator chain strict order | PRD 19; TDD 5.7, 5.8, 5.9, 5.10 | `app/Modules/Validation/Services/PolicyValidatorService.php`, `GroundingValidatorService.php`, `ActionPermissionValidatorService.php`, `ModeValidatorService.php`, `app/Modules/CoreEngine/Services/TurnPipelineService.php` | `tests/Unit/CoreEngine/TurnPipelineServiceTest.php::test_validator_order_stops_at_first_failure`, validator suites under `tests/Unit/Validation/*` | Closed |
| P0-E Anti-hallucination hard rules | PRD 12, 13, 14, 15, 19; TDD 5.8, 5.9 | `app/Modules/Validation/Services/GroundingValidatorService.php`, `app/Modules/Action/Services/ActionDispatcherService.php`, `app/Modules/Calendar/Services/CalendarAvailabilityService.php` | `tests/Unit/Validation/GroundingValidatorServiceTest.php::test_blocks_booking_link_when_calendar_not_grounded`, `::test_blocks_send_file_when_price_is_not_grounded`, `::test_blocks_send_invoice_when_invoice_claim_is_not_grounded`, `tests/Unit/Action/ActionDispatcherServiceTest.php::test_send_invoice_resend_is_blocked_when_max_count_is_reached` | Closed |
| P0-F Tenant isolation & internal security | PRD non-negotiable no cross-tenant; TDD 4.x, 8.2 | `app/Modules/WhatsApp/Http/Middleware/EnsureInternalSecret.php`, `app/Modules/Tenancy/Services/TenantContextResolver.php`, `app/Modules/Shared/Services/AuditLogger.php` | `tests/Feature/WhatsApp/WaInternalApiTest.php::test_shared_secret_is_required_when_configured`, `tests/Feature/Security/AuditLoggingTest.php::test_cross_tenant_forbidden_is_logged_once_per_request`, `tests/Unit/Conversation/ConversationServiceTest.php::test_tenant_isolation_prevents_cross_tenant_reuse_and_write` | Closed |
| P1-A Plan/feature gating runtime | PRD 3, 13, 17; TDD 4.1, 5.1 | `app/Modules/Plans/Services/FeatureGateService.php`, `app/Modules/WhatsApp/Services/WaInboundTurnOrchestratorService.php`, `app/Modules/AdminUi/Http/Controllers/TenantWhatsappQrController.php` | `tests/Unit/FeatureGateServiceTest.php::test_it_resolves_fail_closed_defaults_without_eligible_current_subscription`, `::test_it_resolves_from_current_active_or_trial_subscription_and_ignores_other_tenants`, `tests/Feature/AdminUi/TenantWhatsappQrTest.php::test_connect_endpoint_is_blocked_when_subscription_limit_reached` | Closed |
| P1-B Monthly unique lead limit | PRD 3.3; TDD 4.5, 5.1 | `app/Modules/Plans/Services/MonthlyUniqueLeadLimitService.php`, `app/Modules/WhatsApp/Services/WaInboundTurnOrchestratorService.php`, `app/Modules/CoreEngine/Services/TurnPipelineService.php` | `tests/Unit/Plans/MonthlyUniqueLeadLimitServiceTest.php::test_same_number_in_same_billing_period_is_not_counted_twice`, `::test_new_number_after_limit_exhausted_is_blocked_for_automation`, `tests/Unit/CoreEngine/TurnPipelineServiceTest.php::test_lead_limit_exhausted_triggers_handoff_signal_with_high_priority` | Closed |
| P1-C Stage/goal/mode/memory consistency | PRD 10, 15, 16; TDD 4.3, 5.10 | `app/Modules/Conversation/Services/ConversationService.php`, `app/Modules/CoreEngine/Services/TurnPipelineService.php`, `app/Modules/Validation/Services/ModeValidatorService.php` | `tests/Unit/CoreEngine/TurnPipelineServiceTest.php::test_correction_updates_only_targeted_entity_without_reset`, `::test_topic_switch_updates_active_goal_only_when_topic_changes`, `::test_pipeline_loads_dormant_summary_only_on_explicit_trigger_with_valid_retention`, `tests/Unit/Validation/ModeValidatorServiceTest.php::test_limited_mode_blocks_sensitive_action` | Closed |
| P1-D Handoff & notification policy matrix | PRD 17, 18; TDD 4.7, 5.6 | `app/Modules/CoreEngine/Services/TurnPipelineService.php`, `app/Modules/Action/Services/ActionDispatcherService.php`, `app/Modules/AdminUi/Services/TenantHandoffResolutionService.php` | `tests/Unit/CoreEngine/TurnPipelineServiceTest.php::test_complaint_intent_triggers_handoff_signal_with_high_priority`, `::test_calendar_unavailable_triggers_handoff_signal_with_high_priority`, `::test_paused_mode_triggers_critical_handoff_signal`, `tests/Unit/WhatsApp/WaInboundTurnOrchestratorServiceTest.php::test_inbound_turn_orchestrator_creates_handoff_and_notification_when_handoff_required` | Closed |
| P2-A Intent coverage expansion | PRD 8; TDD 5.2 | `app/Modules/AiLayer/Enums/Intent.php`, `app/Modules/AiLayer/Services/DeterministicIntentClassifier.php`, `app/Modules/AiLayer/Services/InterpretationService.php` | `tests/Unit/AiLayer/IntentClassifierTest.php::test_ask_pricelist_intent_is_recognized`, `::test_priority_intent_fallback_regression_from_message_pattern` | Closed |
| P2-B Entity normalization expansion | PRD 9; TDD 5.3, 5.4 | `app/Modules/AiLayer/Services/DeterministicIntentClassifier.php`, `app/Modules/CoreEngine/Services/TurnPipelineService.php`, `app/Modules/DataKnowledge/Services/CatalogResolver.php` | `tests/Unit/AiLayer/IntentClassifierTest.php::test_entity_normalization_expands_for_phase_p2_b1`, `::test_correction_fields_accept_expanded_entity_keys`, `::test_package_alias_is_resolved_from_same_tenant_only` | Closed |
| P2-C Analytics & replay completeness | PRD 20; TDD 4.3, 4.7, 8.6 | `app/Modules/Analytics/Services/TenantMetricsQueryService.php`, `SuperadminMetricsQueryService.php`, `MetricsSnapshotWriter.php`, `ReplayIntegrityService.php` | `tests/Unit/Analytics/MetricsServicesTest.php::test_tenant_metrics_query_returns_correct_counts`, `::test_superadmin_metrics_query_aggregates_across_tenants`, `tests/Unit/Analytics/ReplayIntegrityServiceTest.php::test_integrity_passes_for_consistent_trace_and_action_links` | Closed |

---

## B. Supporting Platform Requirements Traceability

| Requirement Area | PRD/TDD Reference | Runtime Evidence | Test Evidence | Status |
|---|---|---|---|---|
| Tenant activation lifecycle | PRD 4; TDD 4.1 | `app/Modules/Activation/Services/ActivationService.php`, `app/Modules/Activation/Http/Controllers/ActivationController.php` | `tests/Feature/Infra/ActivationSystemTest.php`, `tests/Feature/Auth/ActivationWebFlowTest.php`, `tests/Unit/ActivationTokenLifecycleTest.php` | Closed |
| Plan/subscription administration | PRD 3; TDD 4.1 | `app/Modules/Plans/Services/PlanService.php`, `TenantSubscriptionService.php`, controllers under `app/Modules/Plans/Http/Controllers` | `tests/Feature/Infra/PlanSubscriptionSystemTest.php`, `tests/Feature/AdminUi/SuperadminPlanManagementTest.php`, `tests/Unit/TenantSubscriptionRulesTest.php` | Closed |
| Auth and tenant-context isolation | PRD 2, Final Principle; TDD 4.1 | `app/Modules/Auth/*`, `app/Modules/Tenancy/Services/TenantContextResolver.php` | `tests/Feature/Infra/AuthTenantCoreTest.php`, `tests/Feature/Auth/WebLoginTest.php` | Closed |
| Knowledge window/version determinism | PRD 6.3, 14; TDD 4.4, 5.5 | `app/Modules/DataKnowledge/Services/KnowledgeVersionResolver.php`, `CatalogResolver.php`, `BookingLinkResolver.php`, `PricelistAssetResolver.php` | `tests/Feature/DataKnowledge/StructuredKnowledgeResolverTest.php`, `tests/Feature/DataKnowledge/PricelistAssetResolverTest.php`, `tests/Unit/DataKnowledge/ResolverDeterminismTest.php` | Closed |
| Calendar claim safety | PRD 13, 19; TDD 5.8 | `app/Modules/Calendar/Services/CalendarAvailabilityService.php`, `app/Modules/WhatsApp/Services/WaInboundTurnOrchestratorService.php` | `tests/Unit/Calendar/CalendarAvailabilityServiceTest.php`, `tests/Feature/Calendar/CalendarAvailabilityIntegrationTest.php` | Closed |
| Admin inbox, handoff, invoice flow | PRD 15, 17, 18; TDD 4.3, 4.6, 4.7 | `app/Modules/AdminUi/Services/TenantConversationInboxQueryService.php`, `TenantHandoffResolutionService.php`, `app/Modules/Action/Services/ActionDispatcherService.php` | `tests/Feature/AdminUi/TenantConversationInboxTest.php`, `tests/Unit/Action/ActionDispatcherServiceTest.php` | Closed |
| Operational hardening and auditability | PRD 19, 20; TDD 8.4, 8.6 | `app/Modules/Shared/Services/AuditLogger.php`, health/internal middleware/controllers | `tests/Feature/Security/AuditLoggingTest.php`, `tests/Feature/HealthEndpointsTest.php`, `tests/Feature/Security/ErrorResponseSanitizationTest.php` | Closed |

---

## C. Notes for Audit

- Matrix ini memetakan evidence utama untuk audit cepat, bukan daftar seluruh test detail.
- Jika dibutuhkan level lebih granular, audit bisa drill-down per method test yang disebut di kolom test evidence.
- Semua status `Closed` di sini diverifikasi pada snapshot test tanggal 2026-04-30.
