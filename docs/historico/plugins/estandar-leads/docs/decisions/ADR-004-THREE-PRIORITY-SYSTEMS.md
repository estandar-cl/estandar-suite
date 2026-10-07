# ADR-004: Three Parallel Priority Systems (Architectural Decision)

**Status:** Accepted  
**Date:** 2026-07-21  
**Deciders:** Architecture  

## Context

The lead management system has three independent priority/scoring systems that run in parallel and can produce different outputs for the same lead:

1. **Scoring Engine** (`class-estandar-leads-scoring.php:372-399`) — computes `priority_for()` with signals: temperature, sin_gestion, has_recontact, workspace_opened, deep_engagement, profile_high_intent. Produces CRITICA/ALTA/MEDIA/BAJA.

2. **Notifier** (`class-estandar-leads-notifier.php:258-272`) — computes binary signals: `behavior_high_intent` (behavior_score>0 && temperature=CALIENTE), `commercial_risk_signal` (repeated && sin_gestion), `repeated_lead`. These drive email subject line and commercial risk display.

3. **Workspace Queue** (`class-estandar-leads-operator-workspace.php:644-652`) — `priority_label()` maps queue name to label: classification_pending→Media, action_required→Alta, waiting_response→Media, closed→Baja.

## Decision

**Keep all three systems independent.** Do not unify.

## Rationale

Each system serves a different concern with different temporal and accuracy requirements:

| System | Consumer | Update frequency | Tolerance | Purpose |
|--------|----------|-----------------|-----------|---------|
| Scoring Engine | AI Context, future analytics | On qualifying events (event-driven) | High (batch, retrospective) | Long-term commercial intelligence |
| Notifier | Operator email | Per notification (real-time) | Low (drives immediate action) | Tactical dispatch priority |
| Workspace | Operator dashboard | Per page load | Medium (session-level) | Work organization and queue management |

Unifying would:
- Couple a real-time dispatch concern (notifier) with a batch analytics engine (scoring).
- Force the workspace queue into a classification model it was never designed for.
- Create unnecessary deployment risk for zero functional gain.

## Consequences

- Operators may see different priority labels in email vs. workspace vs. AI Context.
- This is intentional: each reflects the appropriate level of analysis for its channel.
- Future improvements can add scoring data to the workspace view (as a supplementary badge, not a replacement for queue-based priority).
