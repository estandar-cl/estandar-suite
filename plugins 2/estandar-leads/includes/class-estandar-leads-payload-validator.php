<?php

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Payload_Validator
{
    public static function is_valid($payload)
    {
        if (!is_array($payload)) {
            return false;
        }

        if (($payload['schema_version'] ?? '') !== '1.0') {
            return false;
        }

        $source = isset($payload['source_plugin']) ? sanitize_key((string) $payload['source_plugin']) : '';
        if ($source === '') {
            return false;
        }

        $contact = isset($payload['contact']) && is_array($payload['contact']) ? $payload['contact'] : array();
        $email = Estandar_Leads_Normalizer::email($contact['email'] ?? '');
        $phone = Estandar_Leads_Normalizer::phone_storage($contact['phone'] ?? '');

        return $email !== '' || $phone !== '';
    }
}
