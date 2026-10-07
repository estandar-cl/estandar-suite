#!/bin/zsh
# Leads — Customer Journey Audit

echo ""
echo "── Leads: Customer Journey ──"

echo "  [Lead Capture]"
check_file "${WORKSPACE}/estandar-leads/includes/class-estandar-leads-ingestion.php" "Lead ingestion handler exists"
check_source "${WORKSPACE}/estandar-leads/includes/class-estandar-leads-ingestion.php" "payload_hash\|form_fields_json" "Ingestion stores form_fields_json"

echo "  [Notifications]"
check_source "${WORKSPACE}/estandar-leads/includes/class-estandar-leads-notifier.php" "operator_notified" "Notifier creates operator_notified event"
check_source "${WORKSPACE}/estandar-leads/includes/class-estandar-leads-notifier.php" "operator_notification_deferred" "Notifier handles deferred notifications"
check_source "${WORKSPACE}/estandar-leads/includes/class-estandar-leads-notifier.php" "LEADS_NOTIFIER_DEFERRED_SKIP" "Deferred notification idempotency exists"

echo "  [AI Context]"
check_file "${WORKSPACE}/estandar-leads/includes/class-estandar-leads-ai-context.php" "AI Context class exists"
check_source "${WORKSPACE}/estandar-leads/includes/class-estandar-leads-ai-context.php" "deferred_by_policy" "AI Context handles deferred_by_policy"

echo "  [Operator Portal]"
check_file "${WORKSPACE}/estandar-leads/templates/admin/operator-portal.php" "Operator portal template exists"
check_source "${WORKSPACE}/estandar-leads/templates/admin/operator-portal.php" "lead_id\|lead->id" "Portal renders lead data"
