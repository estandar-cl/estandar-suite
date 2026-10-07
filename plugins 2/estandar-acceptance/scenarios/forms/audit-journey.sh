#!/bin/zsh
# Forms — Customer Journey Audit

echo ""
echo "── Forms: Customer Journey ──"

echo "  [Form Processing]"
check_file "${WORKSPACE}/estandar-forms/includes/class-forms.php" "Form handler class exists"
check_source "${WORKSPACE}/estandar-forms/includes/class-forms.php" "case 'tel'" "Form handles tel field type"
check_source "${WORKSPACE}/estandar-forms/includes/class-forms.php" "estandar_forms_validate_phone" "Form invokes phone validation"
check_source "${WORKSPACE}/estandar-forms/includes/class-forms.php" "is_over_length" "Form validates max length"

echo "  [Phone Validation]"
check_file "${WORKSPACE}/estandar-forms/includes/helpers.php" "Helpers file exists"
check_source "${WORKSPACE}/estandar-forms/includes/helpers.php" "function estandar_forms_get_phone_spec" "Phone spec function defined"
check_source "${WORKSPACE}/estandar-forms/includes/helpers.php" "function estandar_forms_validate_phone" "Phone validate function defined"
check_source "${WORKSPACE}/estandar-forms/includes/helpers.php" "function estandar_forms_phone_input_attrs" "Phone input attrs function defined"
check_source "${WORKSPACE}/estandar-forms/includes/helpers.php" "'minlength'.*=>.*9" "Phone minlength set to 9"
check_source "${WORKSPACE}/estandar-forms/includes/helpers.php" "56" "Phone spec references Chile"
