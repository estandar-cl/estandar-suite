<?php

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Normalizer
{
    public static function email($email)
    {
        $email = sanitize_email((string) $email);
        return strtolower(trim($email));
    }

    public static function phone($phone)
    {
        $digits = self::phone_storage($phone);
        return $digits === '' ? '' : '+' . $digits;
    }

    public static function phone_storage($phone)
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return '';
        }
        if (strpos($digits, '0056') === 0) {
            $digits = substr($digits, 2);
        }
        if (preg_match('/^0?9\d{8}$/', $digits)) {
            return '56' . ltrim($digits, '0');
        }
        if (strpos($digits, '56') === 0) {
            return $digits;
        }
        if (strlen($digits) === 8 || strlen($digits) === 9) {
            return '56' . $digits;
        }
        return $digits;
    }

    public static function phone_display($phone)
    {
        $digits = self::phone_storage($phone);
        if ($digits === '') {
            return '';
        }
        if (preg_match('/^569(\d{4})(\d{4})$/', $digits, $m)) {
            return '+56 9 ' . $m[1] . ' ' . $m[2];
        }
        if (preg_match('/^56(\d)(\d{4})(\d{4})$/', $digits, $m)) {
            return '+56 ' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
        }
        return '+' . $digits;
    }

    public static function hash($value)
    {
        $value = trim((string) $value);
        return $value === '' ? '' : hash_hmac('sha256', $value, Estandar_Leads_Schema::secret());
    }

    /**
     * NAME_ENRICHMENT_V1 — canonical name with deduplication.
     *
     * Tokenizes by whitespace, removes consecutive duplicate tokens, and
     * returns the shortest unique representation.
     *
     * "Fernanda Berasain Berasain Flores" → "Fernanda Berasain Flores"
     * "Juan Juan Pérez" → "Juan Pérez"
     *
     * @return string
     */
    public static function name( string $name ): string {
        $name = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( $name ) ) );
        if ( $name === '' ) {
            return '';
        }

        $tokens = explode( ' ', $name );
        if ( count( $tokens ) < 2 ) {
            return $name;
        }

        $deduped = array();
        $prev = '';
        foreach ( $tokens as $token ) {
            $token_lower = mb_strtolower( $token );
            if ( $token_lower !== $prev ) {
                $deduped[] = $token;
                $prev = $token_lower;
            }
        }

        return implode( ' ', $deduped );
    }

    public static function payload_hash($payload)
    {
        return self::hash(wp_json_encode($payload));
    }

    public static function submitted_at($value)
    {
        $now = time();
        $timestamp = is_string($value) && $value !== '' ? strtotime($value) : false;
        if ($timestamp === false || $timestamp > $now + 300 || $timestamp < $now - YEAR_IN_SECONDS) {
            $timestamp = $now;
        }
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    public static function datetime_local($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return wp_date('d/m/Y H:i', $dt->getTimestamp(), wp_timezone());
        } catch (Exception $e) {
            return $value;
        }
    }

    /**
     * LEAD_RESPONSE_OBSERVABILITY_V1 — Compact relative time.
     * Returns "hace X min", "hace X h", "hace X d", or "" if $value is empty.
     *
     * @param string $value  UTC datetime string.
     * @return string
     */
    public static function time_ago( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }

        try {
            $dt = new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
        } catch ( Exception $e ) {
            return '';
        }

        $seconds = max( 0, time() - $dt->getTimestamp() );

        if ( $seconds < MINUTE_IN_SECONDS ) {
            return 'hace menos de 1 min';
        }
        if ( $seconds < HOUR_IN_SECONDS ) {
            $m = (int) floor( $seconds / MINUTE_IN_SECONDS );
            return 'hace ' . $m . ' min';
        }
        if ( $seconds < DAY_IN_SECONDS ) {
            $h = (int) floor( $seconds / HOUR_IN_SECONDS );
            return 'hace ' . $h . ' h';
        }
        $d = (int) floor( $seconds / DAY_IN_SECONDS );
        return 'hace ' . $d . ' d';
    }

    /**
     * All valid status values — includes legacy real-estate statuses for backward
     * compatibility with existing DB records in production.
     * Use display_statuses() for building UI dropdowns.
     */
    public static function statuses()
    {
        return array(
            // Generic set (current)
            'NEW', 'CONTACTED', 'NO_RESPONSE', 'UNREACHABLE', 'QUALIFIED',
            'MEETING_BOOKED', 'PROPOSAL_SENT', 'NEGOTIATION', 'CONVERTED',
            'CLIENT', 'CLIENT_SIGNED', 'LOST', 'INVALID',
            // Legacy real-estate statuses — kept for backward compat with existing DB records.
            'VISIT_SCHEDULED', 'VISITED', 'BANK_EVALUATION', 'RESERVED', 'DEED_SIGNED', 'DELIVERED',
        );
    }

    /**
     * Generic status set for UI dropdowns.
     * Excludes legacy real-estate statuses that should no longer be offered as new choices.
     */
    public static function display_statuses(): array
    {
        return array(
            'NEW'           => 'Nuevo',
            'CONTACTED'     => 'Contactado',
            'NO_RESPONSE'   => 'No responde',
            'UNREACHABLE'   => 'No ubicable',
            'QUALIFIED'     => 'Calificado',
            'MEETING_BOOKED'  => 'Reunión agendada',
            'PROPOSAL_SENT'   => 'Propuesta enviada',
            'NEGOTIATION'     => 'Negociación',
            'VISIT_SCHEDULED' => 'Visita agendada',
            'VISITED'         => 'Visita realizada',
            'RESERVED'        => 'Reserva confirmada',
            'CLIENT'          => 'Cliente',
            'CLIENT_SIGNED'   => 'Cliente firmado',
            'DEED_SIGNED'     => 'Escritura firmada',
            'DELIVERED'       => 'Entregado',
            'LOST'            => 'Perdido',
            'INVALID'         => 'Descartado / Spam',
        );
    }

    /**
     * Full label map — includes legacy values so existing DB records display correctly.
     */
    public static function status_labels()
    {
        return array_merge(
            self::display_statuses(),
            array(
                // Legacy real-estate labels — readable for existing DB records.
                'CONVERTED'       => 'Convertido',
                'BANK_EVALUATION' => 'Evaluación bancaria',
            )
        );
    }

    /**
     * Lost / disqualification reason labels.
     * Generic — applicable to any service or product category.
     * Legacy real-estate keys kept at the end for existing DB records.
     */
    public static function lost_reason_labels()
    {
        return array(
            // Generic reasons
            'SPAM'              => 'Spam / contacto equivocado',
            'NOT_TARGET'        => 'No es cliente objetivo',
            'OTHER_SERVICE'     => 'Busca otro servicio',
            'OUTSIDE_AREA'      => 'Fuera del área de servicio',
            'NO_BUDGET'         => 'Sin presupuesto',
            'PRICE'             => 'Precio fuera de rango',
            'NOT_INTERESTED'    => 'Sin interés',
            'CHOSE_COMPETITOR'  => 'Eligió a la competencia',
            'NO_RESPONSE'       => 'No responde',
            'OTHER'             => 'Otro motivo',
            // Legacy real-estate keys — display-only, not offered in new dropdowns.
            'BANK_REJECTED'       => 'Rechazo bancario',
            'FINANCING'           => 'Financiamiento',
            'SUBSIDY'             => 'Subsidio',
            'WAITING_SUBSIDY'     => 'Esperando subsidio',
            'BOUGHT_OTHER_PROJECT'=> 'Compró otro proyecto',
            'LOCATION'            => 'Ubicación',
        );
    }

    /**
     * Lost reason keys offered in new UI dropdowns (excludes legacy real-estate ones).
     */
    public static function display_lost_reasons(): array
    {
        return array(
            'SPAM'           => 'Spam / contacto equivocado',
            'NOT_TARGET'     => 'No es cliente objetivo',
            'OTHER_SERVICE'  => 'Busca otro servicio',
            'OUTSIDE_AREA'   => 'Fuera del área de servicio',
            'NO_BUDGET'      => 'Sin presupuesto',
            'PRICE'          => 'Precio fuera de rango',
            'NOT_INTERESTED' => 'Sin interés',
            'CHOSE_COMPETITOR' => 'Eligió a la competencia',
            'NO_RESPONSE'    => 'No responde',
            'OTHER'          => 'Otro motivo',
        );
    }

    /**
     * Commercial stage labels — generic terminology.
     * Legacy real-estate keys (VISIT_SCHEDULED, VISITED, RESERVED) are retained
     * with more universal labels so existing Acacios records still read correctly.
     */
    public static function commercial_stage_labels()
    {
        return array(
            'NEW'             => 'Nuevo',
            'CONTACTED'       => 'Contactado',
            'INTERESTED'      => 'Interesado',
            'QUALIFIED'       => 'Calificado',
            'MEETING_BOOKED'  => 'Reunión agendada',
            'PROPOSAL_SENT'   => 'Propuesta enviada',
            'NEGOTIATION'     => 'Negociación',
            'CONVERTED'       => 'Convertido',
            'VISIT_SCHEDULED' => 'Visita agendada',
            'VISITED'         => 'Visita realizada',
            'RESERVED'        => 'Reserva confirmada',
            'CLIENT'          => 'Cliente',
            'CLIENT_SIGNED'   => 'Cliente firmado',
            'DEED_SIGNED'     => 'Escritura firmada',
            'DELIVERED'       => 'Entregado',
            'LOST'            => 'Perdido',
            'DISQUALIFIED'    => 'Descalificado / Spam',
            'SALE'            => 'Venta / Conversión',
        );
    }

    /**
     * Commercial stage keys offered in new UI dropdowns (excludes legacy real-estate ones).
     */
    public static function display_commercial_stages(): array
    {
        return array(
            'NEW'           => 'Nuevo',
            'CONTACTED'     => 'Contactado',
            'INTERESTED'    => 'Interesado',
            'QUALIFIED'     => 'Calificado',
            'MEETING_BOOKED'  => 'Reunión agendada',
            'PROPOSAL_SENT'   => 'Propuesta enviada',
            'NEGOTIATION'     => 'Negociación',
            'VISIT_SCHEDULED' => 'Visita agendada',
            'VISITED'         => 'Visita realizada',
            'RESERVED'        => 'Reserva confirmada',
            'CLIENT'          => 'Cliente',
            'CLIENT_SIGNED'   => 'Cliente firmado',
            'DEED_SIGNED'     => 'Escritura firmada',
            'DELIVERED'       => 'Entregado',
            'LOST'            => 'Perdido',
            'DISQUALIFIED'    => 'Descalificado / Spam',
        );
    }

    public static function status_from_commercial_stage($stage)
    {
        $map = array(
            'NEW'             => 'NEW',
            'CONTACTED'       => 'CONTACTED',
            'INTERESTED'      => 'QUALIFIED',
            'QUALIFIED'       => 'QUALIFIED',
            'MEETING_BOOKED'  => 'MEETING_BOOKED',
            'PROPOSAL_SENT'   => 'PROPOSAL_SENT',
            'NEGOTIATION'     => 'NEGOTIATION',
            'CONVERTED'       => 'CONVERTED',
            'LOST'            => 'LOST',
            'DISQUALIFIED'    => 'INVALID',
            // Legacy real-estate mappings.
            'VISIT_SCHEDULED' => 'VISIT_SCHEDULED',
            'VISITED'         => 'VISITED',
            'RESERVED'        => 'RESERVED',
            'CLIENT'          => 'CLIENT',
            'CLIENT_SIGNED'   => 'CLIENT_SIGNED',
            'DEED_SIGNED'     => 'DEED_SIGNED',
            'DELIVERED'       => 'DELIVERED',
            'SALE'            => 'DEED_SIGNED',
        );
        return $map[$stage] ?? '';
    }
}
