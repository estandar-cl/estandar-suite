<?php
/**
 * CUSTOMER_REGISTRY_V1 — Service
 *
 * Orchestrates create/update and matching for es_customers.
 * Delegates persistence to Customer_Repository and matching to Customer_Matching.
 * Never modifies es_leads.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Estandar_Leads_Customer_Service {

    /**
     * Create or update a customer record.
     *
     * If $data contains 'id' and that row exists, it is updated.
     * Otherwise a new row is created.
     *
     * @param  array $data  Field map. See Customer_Repository::sanitize().
     * @return int          Customer id (new or existing), or 0 on failure.
     */
    public static function save( array $data ): int {
        $id = isset( $data['id'] ) ? (int) $data['id'] : 0;

        if ( $id > 0 && Estandar_Leads_Customer_Repository::find( $id ) !== null ) {
            unset( $data['id'], $data['created_at'] );
            Estandar_Leads_Customer_Repository::update( $id, $data );
            return $id;
        }

        unset( $data['id'] );
        return Estandar_Leads_Customer_Repository::create( $data );
    }

    /**
     * Run matching for a single customer record and persist the result.
     *
     * If a match is found, matched_lead_id / matched_by / matched_confidence
     * are written to es_customers. es_leads is never modified.
     *
     * @param  int $customer_id
     * @return bool  True when a match was found and written.
     */
    public static function reconcile( int $customer_id ): bool {
        $customer = Estandar_Leads_Customer_Repository::find( $customer_id );
        if ( $customer === null ) {
            return false;
        }

        $result = Estandar_Leads_Customer_Matching::match( $customer );
        if ( $result === null ) {
            return false;
        }

        Estandar_Leads_Customer_Repository::update( $customer_id, array(
            'matched_lead_id'    => $result['lead_id'],
            'matched_by'         => $result['matched_by'],
            'matched_confidence' => $result['confidence'],
            'matched_at'         => gmdate( 'Y-m-d H:i:s' ),
        ) );

        return true;
    }

    /**
     * Return the customer record matched to a lead, or null if none.
     *
     * @param  int $lead_id
     * @return array|null
     */
    public static function get_by_lead( int $lead_id ): ?array {
        return Estandar_Leads_Customer_Repository::find_by_lead( $lead_id );
    }
}
