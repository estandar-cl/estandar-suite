# QA Protection V1

## Ownership

`Estandar_Leads_QA_Policy` is the single test-lead detector. Ingestion evaluates it once, stores the decision in the Submission `context_json.qa_protection`, and passes the same snapshot to Commercial Qualification and downstream consumers. No Lead schema or historical row is changed.

## Configuration

The installation option is `estandar_leads_qa_policy_v1`; project overrides live under `projects.{project_slug}`. The filter `estandar_leads_qa_policy` may supply the same structure. With no configuration, detection is disabled.

Supported keys are `enabled`, `detect_authenticated_users`, `internal_actors`, `email_domains`, `phone_patterns`, `tokens`, `explicit_flags`, `autoresponse`, and `projects`. Rules are generic and contain no client identifiers.

## Decision

A detected QA Submission persists `is_test_lead=true`, `test_reason`, `score_eligible=false`, `qualification=test`, and skip policies for routing, notification, ads feedback, SLA and escalation. Autoresponse defaults to `skip` and may be configured as `allow`. The first Submission supplies the stable lead-level QA gate used by deferred ads/compliance consumers; later QA activity on an existing real lead does not overwrite its score. Audit events remain readable, but QA re-submissions do not emit commercial `lead_recontacted` or Journey shadows.
