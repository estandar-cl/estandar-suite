#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

echo "[1/3] PHP lint"
find . -maxdepth 3 -name '*.php' -print0 | xargs -0 -n1 php -l > /tmp/estandar-forms-php-lint.log
echo "OK"

echo "[2/3] Archivos frontend críticos"
test -f assets/js/estandar-rut.js
test -f assets/js/estandar-recaptcha.js
echo "OK"

echo "[3/3] Runtime de captura"
rg -q "estandar_forms_validation_errors" includes/class-forms.php
rg -q "estandar_forms_pre_insert_data" includes/class-forms.php
rg -q "estandar_lead_captured" includes/class-forms.php
! rg -q "wp_mail|phpmailer_init|wp_mail_content_type|Estandar_Forms_Webhook|estandar_forms_webhook" includes estandar-forms.php admin
echo "OK"

echo "Checks completados"
