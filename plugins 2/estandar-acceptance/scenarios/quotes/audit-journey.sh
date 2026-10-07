#!/bin/zsh
# Quotes — Customer Journey Audit

echo ""
echo "── Quotes: Customer Journey ──"

echo "  [Quote Creation]"
check_source "${WORKSPACE}/estandar-quotes/includes/class-estandar-quotes-admin.php" "Hydration_Pipeline::hydrate_new" "Quote creation uses HydrationPipeline"
check_source "${WORKSPACE}/estandar-quotes/includes/class-estandar-quotes-document-engine.php" "render_preview" "Document Engine renders preview"
check_source "${WORKSPACE}/estandar-quotes/includes/class-estandar-quotes-document-engine.php" "render_print" "Document Engine renders print"

echo "  [PDF Generation]"
check_source "${WORKSPACE}/estandar-quotes/includes/class-estandar-quotes-artifacts.php" "strip_tags" "Artifact validates HTML content"
check_source "${WORKSPACE}/estandar-quotes/includes/class-estandar-quotes-artifacts.php" "%PDF" "Artifact validates PDF header"
check_source "${WORKSPACE}/estandar-quotes/includes/class-estandar-quotes-artifacts.php" "byte_size" "Artifact stores byte_size"
check_source "${WORKSPACE}/estandar-quotes/includes/class-estandar-quotes-artifacts.php" "pdf_sha256" "Artifact stores sha256"

echo "  [Delivery]"
check_source "${WORKSPACE}/estandar-quotes/includes/class-estandar-quotes-delivery-service.php" "resend" "Delivery supports resend"
check_source "${WORKSPACE}/estandar-quotes/includes/class-estandar-quotes-wp-mail-transport.php" "byte_size" "Transport validates byte_size"
check_source "${WORKSPACE}/estandar-quotes/includes/class-estandar-quotes-delivery-email.php" "footer_legal" "Email has legal footer"
check_source "${WORKSPACE}/estandar-quotes/includes/class-estandar-quotes-delivery-email.php" "company_name" "Email has company name"

echo "  [History & Revisions]"
check_source "${WORKSPACE}/estandar-quotes/includes/class-estandar-quotes-admin.php" "Historial" "Admin shows history section"
check_source "${WORKSPACE}/estandar-quotes/includes/class-estandar-quotes-admin.php" "Cotizacion-" "Human-readable filename"
check_source "${WORKSPACE}/estandar-quotes/includes/class-estandar-quotes-repository.php" "add_revision" "Repository adds revisions"
check_source "${WORKSPACE}/estandar-quotes/includes/class-estandar-quotes-schema.php" "quote_number" "Schema has quote_number column"
