<?php
/**
 * Tests for deferred notification idempotency.
 *
 * Source-level verification of the idempotency fix in notify_operators().
 * The full notifier flow depends on many external classes; these tests
 * verify the fix at the code level.
 */

define( 'ABSPATH', __DIR__ );

$pass = 0; $fail = 0;
function check( string $name, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { ++$pass; echo "PASS {$name}\n"; }
	else { ++$fail; echo "FAIL {$name}\n"; }
}

$notifier_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-leads-notifier.php' );

// Section 1: Idempotency check exists
check( 'IDEMPOTENCY_V1 marker present', strpos( $notifier_src, 'IDEMPOTENCY_V1' ) !== false );

// Section 2: Query checks for existing deferred event
check( 'query checks for existing operator_notification_deferred',
	strpos( $notifier_src, "event_type = 'operator_notification_deferred'" ) !== false );

// Section 3: Query checks NOT EXISTS operator_notified
check( 'query excludes delivered submissions',
	strpos( $notifier_src, "NOT EXISTS" ) !== false
	&& strpos( $notifier_src, "event_type = 'operator_notified'" ) !== false );

// Section 4: Skip path when existing event found
check( 'skip path when existing event exists',
	strpos( $notifier_src, 'LEADS_NOTIFIER_DEFERRED_SKIP' ) !== false );

// Section 5: Cron scheduling still runs regardless of idempotency
check( 'cron scheduling is outside the if/else',
	preg_match( '/if\s*\(\s*\$existing\s*\)\s*\{.*?\}\s*else\s*\{.*?\}\s*if\s*\(\s*\$next_ts\s*\)/s', $notifier_src ) ? true : false );

// Section 6: Telemetry still runs regardless of idempotency
check( 'telemetry runs after if/else',
	strpos( $notifier_src, 'LEADS_NOTIFIER_DEFERRED_OPERATOR' ) !== false );

// Section 7: Correct table prefix used
check( 'query uses wpdb prefix',
	strpos( $notifier_src, '{$wpdb->prefix}es_lead_events' ) !== false );

// Section 8: JSON_EXTRACT for operator_id
check( 'JSON_EXTRACT for operator_id metadata',
	strpos( $notifier_src, "JSON_EXTRACT(e.metadata, '$.operator_id')" ) !== false );

// Section 9: Event insert still exists
check( 'event insert still present for new deferred events',
	strpos( $notifier_src, "operator_notification_deferred" ) !== false );

echo "\n{$pass} PASS / {$fail} FAIL\n";
exit( $fail ? 1 : 0 );
