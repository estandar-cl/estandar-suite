<?php
/**
 * Lead Transfer Service — T1 (state + events; no UI, no email, no ingestion gate).
 *
 * Formal commercial action: move a lead's ACTIVE project to another project,
 * reassign the operator per Project Registry, and record it as a commercial event.
 *
 * Frozen rules:
 *  - Manual Transfer > Automatic Routing (the emitted `lead_transferred` event is the lock).
 *  - Never deletes submissions/attribution/history. Purely additive.
 *  - No operator in target → transfer anyway, UNASSIGN, telemetry, result 'transferred_unassigned'.
 *  - Transition: emit BOTH `lead_transferred` (canonical) and `project_assigned_manually` (legacy).
 *  - Reversible only via a new manual transfer.
 *
 * Ownership: estandar-leads. No schema changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Immutable result of a transfer attempt. */
final class Estandar_Leads_Transfer_Result {

    private string $status;   // 'transferred' | 'noop' | 'error'
    private string $code;
    private string $from_project;
    private string $to_project;
    private int $from_operator_id;
    private int $to_operator_id;
    private string $message;

    private function __construct( string $status, string $code, string $message, string $from_project, string $to_project, int $from_operator_id, int $to_operator_id ) {
        $this->status           = $status;
        $this->code             = $code;
        $this->message          = $message;
        $this->from_project     = $from_project;
        $this->to_project       = $to_project;
        $this->from_operator_id = $from_operator_id;
        $this->to_operator_id   = $to_operator_id;
    }

    public static function error( string $code, string $message ): self {
        return new self( 'error', $code, $message, '', '', 0, 0 );
    }
    public static function noop( string $code, string $from, string $to ): self {
        return new self( 'noop', $code, '', $from, $to, 0, 0 );
    }
    public static function transferred( string $code, string $from, string $to, int $from_op, int $to_op ): self {
        return new self( 'transferred', $code, '', $from, $to, $from_op, $to_op );
    }

    public function ok(): bool { return $this->status === 'transferred'; }
    public function status(): string { return $this->status; }
    public function code(): string { return $this->code; }
    public function from_project(): string { return $this->from_project; }
    public function to_project(): string { return $this->to_project; }
    public function from_operator_id(): int { return $this->from_operator_id; }
    public function to_operator_id(): int { return $this->to_operator_id; }
    public function message(): string { return $this->message; }
}

final class Estandar_Leads_Transfer_Service {

    /**
     * Transfer a lead's active project to $to_project.
     *
     * @param int    $lead_id
     * @param string $to_project canonical registry slug
     * @param array  $args reason, source_submission_id, dedupe_window (default 10)
     */
    public static function transfer( int $lead_id, string $to_project, array $args = array() ): Estandar_Leads_Transfer_Result {
        global $wpdb;

        $lead_id = absint( $lead_id );
        if ( $lead_id <= 0 ) {
            return Estandar_Leads_Transfer_Result::error( 'lead_not_found', 'Lead id inválido.' );
        }

        // 2. Lead must exist.
        $lead = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, project_slug, assigned_operator_id, assigned_operator_email, assigned_operator_name
             FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
            $lead_id
        ) );
        if ( ! $lead ) {
            return Estandar_Leads_Transfer_Result::error( 'lead_not_found', 'El lead no existe.' );
        }

        // 3-4. Target must be a canonical, active Registry project.
        $to_project = class_exists( 'Estandar_Leads_Projects' )
            ? Estandar_Leads_Projects::normalize_slug( sanitize_text_field( $to_project ) )
            : sanitize_title( $to_project );
        if ( $to_project === '' ) {
            return Estandar_Leads_Transfer_Result::error( 'invalid_target_project', 'Proyecto destino vacío.' );
        }
        if ( class_exists( 'Estandar_Core_Project_Registry' ) ) {
            $registry_row = Estandar_Core_Project_Registry::get( $to_project );
            if ( ! is_array( $registry_row ) || empty( $registry_row ) ) {
                return Estandar_Leads_Transfer_Result::error( 'invalid_target_project', 'El proyecto destino no existe en el Registry.' );
            }
            if ( isset( $registry_row['active'] ) && ! $registry_row['active'] ) {
                return Estandar_Leads_Transfer_Result::error( 'invalid_target_project', 'El proyecto destino está inactivo.' );
            }
        }

        $from_project     = (string) ( $lead->project_slug ?? '' );
        $from_operator_id = (int) ( $lead->assigned_operator_id ?? 0 );

        // 5. Idempotency: already on target → no-op.
        if ( $from_project === $to_project ) {
            return Estandar_Leads_Transfer_Result::noop( 'already_active', $from_project, $to_project );
        }

        // 6. Dedupe: same-target transfer inside the debounce window → no-op.
        $window = isset( $args['dedupe_window'] ) ? max( 0, (int) $args['dedupe_window'] ) : 10;
        if ( $window > 0 ) {
            $recent = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}es_lead_events
                 WHERE lead_id = %d AND event_type = 'lead_transferred' AND to_status = %s
                   AND created_at > DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d SECOND )
                 LIMIT 1",
                $lead_id, $to_project, $window
            ) );
            if ( $recent ) {
                return Estandar_Leads_Transfer_Result::noop( 'debounced', $from_project, $to_project );
            }
        }

        $reason               = sanitize_text_field( (string) ( $args['reason'] ?? '' ) );
        $source_submission_id = isset( $args['source_submission_id'] ) ? absint( $args['source_submission_id'] ) : 0;
        $now                  = current_time( 'mysql', true );

        // 3. Resolve target operator (may be null → unassign path).
        $operator = class_exists( 'Estandar_Leads_Operator_Resolver' )
            ? Estandar_Leads_Operator_Resolver::first_for_project( $to_project, array( 'require_email' => false ) )
            : null;
        $to_operator_id = is_object( $operator ) ? (int) ( $operator->id ?? 0 ) : 0;

        // 4-6. Single controlled write: active project + operator + feedback re-arm.
        $data = array(
            'project_slug'      => $to_project,
            'feedback_completed'=> 0,
            'feedback_status'   => 'pending',
            'updated_at'        => $now,
        );
        if ( $to_operator_id > 0 ) {
            $data['assigned_operator_id']    = $to_operator_id;
            $data['assigned_operator_email'] = sanitize_email( (string) ( $operator->email ?? '' ) );
            $data['assigned_operator_name']  = sanitize_text_field( (string) ( $operator->name ?? '' ) );
            $data['assigned_at']             = $now;
        } else {
            // Frozen decision #1 — no operator in target: unassign.
            $data['assigned_operator_id']    = null;
            $data['assigned_operator_email'] = '';
            $data['assigned_operator_name']  = '';
            $data['assigned_at']             = null;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL — $wpdb->update handles escaping + NULLs.
        $wpdb->update( $wpdb->prefix . 'es_leads', $data, array( 'id' => $lead_id ) );

        // 7. Events (canonical marker first).
        Estandar_Leads_Repository::insert_event( $lead_id, 'lead_transferred', array(
            'from_status'   => $from_project,
            'to_status'     => $to_project,
            'operator_id'   => $to_operator_id ?: 0,
            'submission_id' => $source_submission_id ?: 0,
            'reason'        => $reason,
            'metadata'      => array(
                'from_project'         => $from_project,
                'to_project'           => $to_project,
                'from_operator_id'     => $from_operator_id,
                'to_operator_id'       => $to_operator_id,
                'reason'               => $reason,
                'source'               => 'manual_transfer',
                'source_submission_id' => $source_submission_id ?: null,
            ),
        ) );

        // 7b. Legacy event for transition compatibility (frozen decision #2).
        Estandar_Leads_Repository::insert_event( $lead_id, 'project_assigned_manually', array(
            'metadata' => array(
                'project_slug' => $to_project,
                'by'           => 'transfer',
            ),
        ) );

        // 8. Operator reassignment audit (only if it changed).
        if ( $from_operator_id !== $to_operator_id ) {
            Estandar_Leads_Repository::insert_event( $lead_id, 'operator_reassigned', array(
                'operator_id' => $to_operator_id ?: 0,
                'to_status'   => $to_project,
                'metadata'    => array(
                    'source' => 'transfer',
                    'from'   => $from_operator_id,
                    'to'     => $to_operator_id,
                ),
            ) );
        }

        // 9. Interest-level assignment for the new operator (only if resolved).
        if ( $to_operator_id > 0 ) {
            Estandar_Leads_Repository::insert_event( $lead_id, 'operator_assigned', array(
                'operator_id'   => $to_operator_id,
                'to_status'     => $to_project,
                'submission_id' => $source_submission_id ?: 0,
                'metadata'      => array(
                    'project_slug' => $to_project,
                    'source'       => 'transfer',
                ),
            ) );
        }

        // 10. Compliance re-arm marker.
        Estandar_Leads_Repository::insert_event( $lead_id, 'feedback_status_changed', array(
            'to_status' => 'pending',
            'metadata'  => array( 'reason' => 'transfer_rearm' ),
        ) );

        if ( $to_operator_id === 0 ) {
            self::telemetry( 'warning', 'LEADS_TRANSFER_NO_OPERATOR', 'Lead transferred but target project has no operator; lead unassigned.', array(
                'lead_id'      => $lead_id,
                'from_project' => $from_project,
                'to_project'   => $to_project,
            ) );
            return Estandar_Leads_Transfer_Result::transferred( 'transferred_unassigned', $from_project, $to_project, $from_operator_id, 0 );
        }

        self::telemetry( 'info', 'LEADS_TRANSFER_COMPLETED', 'Lead transferred to another project.', array(
            'lead_id'      => $lead_id,
            'from_project' => $from_project,
            'to_project'   => $to_project,
            'to_operator'  => $to_operator_id,
        ) );

        return Estandar_Leads_Transfer_Result::transferred( 'ok', $from_project, $to_project, $from_operator_id, $to_operator_id );
    }

    private static function telemetry( string $severity, string $code, string $message, array $context = array() ): void {
        try {
            do_action( 'estandar_telemetry_event', array(
                'source_plugin' => 'estandar-leads',
                'type'          => 'business_event',
                'severity'      => $severity,
                'code'          => $code,
                'message'       => $message,
                'context'       => $context,
            ) );
        } catch ( Throwable $e ) {
            return;
        }
    }
}
