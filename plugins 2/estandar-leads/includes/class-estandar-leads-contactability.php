<?php
/**
 * CONTACTABILITY_V1 — Adapter v1 (estandar-leads) al ContactabilityEngine de core.
 *
 * DELEGA la lógica al motor reutilizable Estandar\Core\Quality\ContactabilityEngine.
 * NO duplica lógica de validación: este adapter solo traduce el contrato del
 * motor a la convención de estandar-leads y proporciona un fallback mínimamente
 * seguro (syntax-only) cuando core no está actualizado (ausencia = default).
 *
 * Contrato de salida (idéntico en v1 y v2):
 *   syntax_valid · contactability_status · validation_reason
 */

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Contactability
{
    const VERSION = 'CONTACTABILITY_V1';

    // Estados (espejo del motor; se conservan como constantes de conveniencia).
    const PHONE_VALID      = 'valid';
    const PHONE_PLAUSIBLE  = 'plausible';
    const PHONE_SUSPICIOUS = 'suspicious';
    const PHONE_INVALID    = 'invalid';
    const EMAIL_VALID      = 'valid';
    const EMAIL_INVALID    = 'invalid';

    /**
     * Evalúa contactabilidad de un teléfono.
     *
     * @param string $phone
     * @return array { syntax_valid, contactability_status, validation_reason, normalized }
     */
    public static function phone($phone)
    {
        if (class_exists('Estandar\\Core\\Quality\\ContactabilityEngine')) {
            $r = Estandar\Core\Quality\ContactabilityEngine::phone((string) $phone);
            return array(
                'syntax_valid'          => (bool) $r['syntax_valid'],
                'contactability_status' => (string) $r['contactability_status'],
                'validation_reason'     => (string) $r['validation_reason'],
                'normalized'            => Estandar_Leads_Normalizer::phone_storage((string) $phone),
            );
        }
        // Fallback syntax-only (no rompe instalaciones sin core actualizado).
        $digits = Estandar_Leads_Normalizer::phone_storage((string) $phone);
        return array(
            'syntax_valid'          => $digits !== '',
            'contactability_status' => $digits !== '' ? self::PHONE_PLAUSIBLE : self::PHONE_INVALID,
            'validation_reason'     => $digits !== '' ? 'syntax_only_fallback' : 'empty',
            'normalized'            => $digits,
        );
    }

    /**
     * Evalúa contactabilidad de un email.
     *
     * @param string $email
     * @return array { syntax_valid, contactability_status, validation_reason, normalized }
     */
    public static function email($email)
    {
        if (class_exists('Estandar\\Core\\Quality\\ContactabilityEngine')) {
            $r = Estandar\Core\Quality\ContactabilityEngine::email((string) $email);
            return array(
                'syntax_valid'          => (bool) $r['syntax_valid'],
                'contactability_status' => (string) $r['contactability_status'],
                'validation_reason'     => (string) $r['validation_reason'],
                'normalized'            => Estandar_Leads_Normalizer::email((string) $email),
            );
        }
        // Fallback syntax-only.
        $normalized = Estandar_Leads_Normalizer::email((string) $email);
        return array(
            'syntax_valid'          => $normalized !== '',
            'contactability_status' => $normalized !== '' ? self::EMAIL_VALID : self::EMAIL_INVALID,
            'validation_reason'     => $normalized !== '' ? 'syntax_only_fallback' : 'empty',
            'normalized'            => $normalized,
        );
    }
}
