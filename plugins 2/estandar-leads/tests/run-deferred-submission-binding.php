<?php

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $value ) { return (string) $value; }

class Estandar_Operational_Hours {
    public static function is_open() { return true; }
}

class Deferred_Submission_Wpdb {
    public $prefix = 'wp_';
    public $sql = '';
    public $get_row_calls = 0;

    public function get_results( $sql ) {
        $this->sql = $sql;
        return array( (object) array( 'lead_id' => 7, 'submission_id' => 0, 'metadata' => '{}' ) );
    }

    public function get_row( $sql ) {
        $this->get_row_calls++;
        return null;
    }
}

$GLOBALS['wpdb'] = new Deferred_Submission_Wpdb();
require dirname( __DIR__ ) . '/includes/class-estandar-leads-notifier.php';

Estandar_Leads_Notifier::sweep_deferred_notifications();

$sql_bound = strpos( $GLOBALS['wpdb']->sql, 'n.submission_id = e.submission_id' ) !== false;
$legacy_skipped = $GLOBALS['wpdb']->get_row_calls === 0;

echo ( $sql_bound ? 'PASS' : 'FAIL' ) . "  dedupe bound to submission\n";
echo ( $legacy_skipped ? 'PASS' : 'FAIL' ) . "  incomplete legacy event does not borrow latest submission\n";

exit( $sql_bound && $legacy_skipped ? 0 : 1 );
