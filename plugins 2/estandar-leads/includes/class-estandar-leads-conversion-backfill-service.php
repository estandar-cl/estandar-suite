<?php
/**
 * Conversion Backfill V1.
 *
 * Accepts validated historical buyers and dispatches audited Meta Purchase
 * events through the existing Meta Adapter.
 *
 * @package EstandarLeads
 * @since   13.0.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Leads_Conversion_Backfill_Service {

	const TABLE      = 'es_conversion_backfill_audit';
	const ADAPTER    = 'meta';
	const EVENT_NAME = 'Purchase';
	const MAX_RETRIES = 3;

	public function enqueue( array $buyers ): array {
		global $wpdb;

		$created = 0;
		$skipped = 0;

		foreach ( $buyers as $buyer ) {
			$record = $this->normalize_buyer( $buyer );
			if ( empty( $record['approved'] ) ) {
				$skipped++;
				continue;
			}

			$event_id = $this->event_id( $record );
			if ( $event_id === '' ) {
				$skipped++;
				continue;
			}
			if ( $this->event_exists( $event_id ) ) {
				$skipped++;
				continue;
			}

			$inserted = $wpdb->insert(
				$wpdb->prefix . self::TABLE,
				array(
					'conversion_uuid' => $this->deterministic_uuid( $event_id ),
					'buyer_uuid'      => $record['buyer_uuid'],
					'lead_uuid'       => $record['lead_uuid'],
					'adapter'         => self::ADAPTER,
					'event_name'      => self::EVENT_NAME,
					'event_id'        => $event_id,
					'buyer_json'      => wp_json_encode( $record ),
					'status'          => 'pending',
					'created_at'      => gmdate( 'Y-m-d H:i:s' ),
					'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			$inserted ? $created++ : $skipped++;
		}

		return array( 'created' => $created, 'skipped' => $skipped );
	}

	public function dispatch( int $limit = 100 ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::TABLE . "
			 WHERE status = 'pending'
			    OR (status = 'retry' AND retry_count < %d)
			 ORDER BY
			   CASE WHEN status = 'retry' THEN 1 ELSE 0 END ASC,
			   id ASC
			 LIMIT %d",
			self::MAX_RETRIES,
			max( 1, $limit )
		) );

		$sent    = 0;
		$failed  = 0;
		$retry   = 0;
		$skipped = 0;

		$now_ts = time();

		foreach ( (array) $rows as $row ) {
			// Retry backoff: skip if the backoff window hasn't elapsed since last attempt.
			if ( $row->status === 'retry' ) {
				$attempt     = (int) ( $row->retry_count ?? 0 );
				$backoff_sec = min( pow( 2, $attempt ) * 900, 86400 ); // 15min → 1h → 4h → 16h → 24h
				$updated_ts  = strtotime( (string) ( $row->updated_at ?? '' ) );
				if ( $updated_ts > 0 && ( $now_ts - $updated_ts ) < $backoff_sec ) {
					continue; // not yet eligible for retry
				}
			}
			$record = json_decode( (string) ( $row->buyer_json ?? '' ), true );
			if ( ! is_array( $record ) ) {
				$this->update_audit( $row, array(), array( 'success' => false, 'http_code' => 0, 'body' => 'Invalid buyer_json' ) );
				$failed++;
				continue;
			}

			// Canonical hydration path (opt-in via root lead_id). When the caller
			// identifies a persisted lead, identity and attribution come from es_leads —
			// never from the buyer record. Callers without lead_id keep the original
			// caller-supplied behavior untouched.
			$lead_id     = (int) ( $record['lead_id'] ?? 0 );
			$customer_id = (int) ( $record['customer_id'] ?? 0 );
			if ( $lead_id > 0 ) {
				// Canonical ads-feedback gate, same policy the live Meta adapter applies.
				// Checked here (not only upstream) because the audit row may have been
				// enqueued before the lead was flagged as QA/test.
				if ( $this->qa_blocked( $lead_id ) ) {
					$this->mark_skipped( $row, 'QA policy: ads feedback blocked' );
					$skipped++;
					continue;
				}

				$lead = $this->hydrate_lead( $lead_id, $record );
				if ( $lead === null ) {
					$this->update_audit( $row, array(), array( 'success' => false, 'http_code' => 0, 'body' => 'Lead not found' ) );
					$failed++;
					continue;
				}
			} elseif ( $customer_id > 0 ) {
				// Historical customer path (opt-in via root customer_id, no lead exists).
				// Identity is hydrated fresh from es_customers at dispatch time — buyer_json
				// stays PII-free, mirroring the es_leads hydration contract. No attribution
				// is fabricated: the resulting object carries no lead_uuid/fbc/fbp/utm/ip/ua,
				// so the payload builder emits action_source=system_generated and omits them.
				$lead = $this->hydrate_customer( $customer_id, $record );
				if ( $lead === null ) {
					$this->update_audit( $row, array(), array( 'success' => false, 'http_code' => 0, 'body' => 'Customer not found' ) );
					$failed++;
					continue;
				}
				if ( trim( (string) ( $lead->email_normalized ?? '' ) ) === '' && trim( (string) ( $lead->phone_normalized ?? '' ) ) === '' ) {
					// Identity removed between enqueue and dispatch — fail closed.
					$this->update_audit( $row, array(), array( 'success' => false, 'http_code' => 0, 'body' => 'Customer identity missing' ) );
					$failed++;
					continue;
				}
			} else {
				$lead = (object) $record['lead'];
			}

			$result = Estandar_Leads_Meta_Adapter::dispatch_backfill_purchase( $lead, (string) $row->event_id );
			if ( ! empty( $result['response']['skipped'] ) ) {
				$this->mark_skipped( $row, (string) ( $result['response']['message'] ?? 'Meta platform eligibility blocked' ) );
				$skipped++;
				continue;
			}

			$this->update_audit( $row, $result['payload'], $result['response'] );

			if ( ! empty( $result['response']['success'] ) ) {
				$sent++;
			} elseif ( ! empty( $result['response']['temporary'] ) ) {
				$retry++;
			} else {
				$failed++;
			}
		}

		return array( 'sent' => $sent, 'failed' => $failed, 'retry' => $retry, 'skipped' => $skipped );
	}

	/**
	 * Load the canonical lead row and overlay ONLY the commercial facts the caller owns
	 * (value, currency, event_time). Identity and attribution — email/phone/name, fbc,
	 * fbp, fbclid, utm_*, ip, user agent, lead_uuid — are never overridden: they are read
	 * from es_leads, which is the single source of truth the live Meta adapter also uses.
	 */
	private function hydrate_lead( int $lead_id, array $record ): ?object {
		global $wpdb;

		$lead = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
			$lead_id
		) );

		if ( ! $lead ) {
			return null;
		}

		$commercial = $this->as_array( $record['lead'] ?? array() );
		foreach ( array( 'conversion_value', 'currency', 'event_time' ) as $key ) {
			$value = $commercial[ $key ] ?? '';
			if ( $value !== '' && $value !== null ) {
				$lead->$key = $value;
			}
		}

		return $lead;
	}

	/**
	 * Historical customer hydration (no lead). Builds a lead-shaped object carrying
	 * ONLY what the customer registry actually owns: name, email, phone, project and
	 * the commercial facts (value/currency/event_time). Email/phone are normalized
	 * with the same helpers the matching engine uses; SHA-256 happens downstream in
	 * the payload builder. Nothing else is invented — no lead_uuid, fbc, fbp, fbclid,
	 * utm_*, ip, user agent or landing URL keys exist on the object at all.
	 */
	private function hydrate_customer( int $customer_id, array $record ): ?object {
		global $wpdb;

		$customer = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}es_customers WHERE id = %d LIMIT 1",
			$customer_id
		) );

		if ( ! $customer ) {
			return null;
		}

		$email = trim( (string) ( $customer->email ?? '' ) );
		$phone = trim( (string) ( $customer->phone ?? '' ) );

		$lead = (object) array(
			'email'            => $email,
			'email_normalized' => class_exists( 'Estandar_Leads_Normalizer' ) ? Estandar_Leads_Normalizer::email( $email ) : strtolower( $email ),
			'phone'            => $phone,
			'phone_normalized' => class_exists( 'Estandar_Leads_Normalizer' ) ? Estandar_Leads_Normalizer::phone_storage( $phone ) : preg_replace( '/\D+/', '', $phone ),
			'first_name'       => trim( (string) ( $customer->first_name ?? '' ) ),
			'last_name'        => trim( (string) ( $customer->last_name ?? '' ) ),
			'project_slug'     => trim( (string) ( $customer->project_slug ?? '' ) ),
		);

		$commercial = $this->as_array( $record['lead'] ?? array() );

		$event_time = trim( (string) ( $commercial['event_time'] ?? '' ) );
		if ( $event_time !== '' ) {
			$lead->event_time = $event_time;
		}

		// Amount/currency only when the customer record actually carries a value —
		// no value means neither key reaches the payload.
		$value = $commercial['conversion_value'] ?? '';
		if ( $value !== '' && $value !== null && is_numeric( $value ) ) {
			$lead->conversion_value = $value;
			$currency = trim( (string) ( $commercial['currency'] ?? '' ) );
			if ( $currency !== '' ) {
				$lead->currency = $currency;
			}
		}

		return $lead;
	}

	private function qa_blocked( int $lead_id ): bool {
		return class_exists( 'Estandar_Leads_QA_Policy' )
			&& Estandar_Leads_QA_Policy::ads_feedback_blocked( $lead_id );
	}

	/**
	 * Terminal, non-error outcome: the row is closed without a send and never
	 * re-dispatched (dispatch() only drains 'pending').
	 */
	private function mark_skipped( $row, string $reason ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . self::TABLE,
			array(
				'status'        => 'skipped',
				'response_body' => $reason,
				'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function audit( int $limit = 50 ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::TABLE . ' ORDER BY id DESC LIMIT %d',
			max( 1, $limit )
		) );
	}

	public function status(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$wpdb->prefix}" . self::TABLE . ' GROUP BY status' );
		$out  = array();
		foreach ( $rows as $row ) {
			$status = is_array( $row ) ? ( $row['status'] ?? '' ) : ( $row->status ?? '' );
			$total  = is_array( $row ) ? ( $row['total'] ?? 0 ) : ( $row->total ?? 0 );
			$out[ (string) $status ] = (int) $total;
		}
		return $out;
	}

	private function update_audit( $row, array $payload, array $response ): void {
		global $wpdb;

		// Transport outcome is recorded with its true nature: a retryable failure
		// (timeout, 429, 5xx) is distinguishable from a terminal one (4xx, invalid
		// payload). Neither is re-dispatched in this phase — dispatch() only drains
		// 'pending' — but the distinction is preserved for the operator and for a
		// future retry drain.
		if ( ! empty( $response['success'] ) ) {
			$status = 'sent';
		} elseif ( ! empty( $response['temporary'] ) ) {
			$status = 'retry';
		} else {
			$status = 'failed';
		}

		$body = (string) ( $response['body'] ?? $response['message'] ?? '' );

		$wpdb->update(
			$wpdb->prefix . self::TABLE,
			array(
				'payload_hash'  => hash( 'sha256', wp_json_encode( $payload ) ),
				'sent_at'       => gmdate( 'Y-m-d H:i:s' ),
				'status'        => $status,
				'response_code' => (int) ( $response['http_code'] ?? 0 ),
				'response_body' => $body,
				'retry_count'   => (int) ( $row->retry_count ?? 0 ) + 1,
				'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	private function normalize_buyer( $buyer ): array {
		$data = is_object( $buyer ) ? get_object_vars( $buyer ) : (array) $buyer;
		$lead = $this->as_array( $data['lead'] ?? array() );
		$meta = $this->as_array( $data['purchase_metadata'] ?? array() );

		$lead_uuid  = $this->text( $data['lead_uuid'] ?? $lead['lead_uuid'] ?? $meta['lead_uuid'] ?? '' );
		$buyer_uuid = $this->text( $data['buyer_uuid'] ?? $meta['buyer_uuid'] ?? $this->field( $data['buyer'] ?? array(), 'buyer_uuid', $this->field( $data['buyer'] ?? array(), 'uuid', '' ) ) );

		$lead['lead_uuid']        = $lead_uuid;
		$lead['conversion_value'] = $data['purchase_value'] ?? $meta['purchase_value'] ?? $lead['conversion_value'] ?? '';
		$lead['currency']         = $data['currency'] ?? $meta['currency'] ?? $lead['currency'] ?? 'CLP';
		$lead['event_time']       = $data['event_time'] ?? $meta['event_time'] ?? $meta['purchase_time'] ?? $lead['event_time'] ?? '';

		return array(
			'approved'    => $this->is_approved( $data ),
			'buyer_uuid'  => $buyer_uuid,
			'lead_uuid'   => $lead_uuid,
			// Opt-in canonical hydration. Read ONLY from the record root — never from
			// $lead['id'] — so callers that supply a full lead array keep the original
			// behavior and no legacy path starts hitting es_leads unexpectedly.
			'lead_id'     => max( 0, (int) ( $data['lead_id'] ?? 0 ) ),
			// Historical customer path: hydration source when no lead exists, plus the
			// caller-supplied deterministic event_id (root-only, same opt-in rule).
			'customer_id' => max( 0, (int) ( $data['customer_id'] ?? 0 ) ),
			'event_id'    => $this->text( $data['event_id'] ?? '' ),
			'lead'        => $lead,
		);
	}

	private function is_approved( array $data ): bool {
		if ( isset( $data['approved'] ) ) {
			return filter_var( $data['approved'], FILTER_VALIDATE_BOOLEAN );
		}
		foreach ( array( 'match_status', 'approval_status', 'status' ) as $key ) {
			if ( strtolower( trim( (string) ( $data[ $key ] ?? '' ) ) ) === 'approved' ) {
				return true;
			}
		}
		return false;
	}

	private function event_id( array $record ): string {
		// Caller-supplied deterministic id wins (historical customer path). The
		// legacy derivation below requires a lead_uuid, which that path lacks.
		if ( ( $record['event_id'] ?? '' ) !== '' ) {
			return (string) $record['event_id'];
		}
		if ( $record['lead_uuid'] === '' || $record['buyer_uuid'] === '' ) {
			return '';
		}
		return 'conversion-backfill:' . $record['lead_uuid'] . ':' . $record['buyer_uuid'] . ':Purchase';
	}

	private function event_exists( string $event_id ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE . ' WHERE event_id = %s',
			$event_id
		) ) > 0;
	}

	private function deterministic_uuid( string $seed ): string {
		$hash = hash( 'sha256', $seed );
		return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-' . substr( $hash, 12, 4 ) . '-' . substr( $hash, 16, 4 ) . '-' . substr( $hash, 20, 12 );
	}

	private function as_array( $value ): array {
		return is_object( $value ) ? get_object_vars( $value ) : ( is_array( $value ) ? $value : array() );
	}

	private function field( $source, string $key, $default = '' ) {
		$arr = $this->as_array( $source );
		return array_key_exists( $key, $arr ) ? $arr[ $key ] : $default;
	}

	private function text( $value ): string {
		return trim( (string) $value );
	}
}
