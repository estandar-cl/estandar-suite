<?php
/**
 * Run: php tests/run-ai-context-export.php
 */

define( 'ABSPATH', __DIR__ . '/' );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $v ) { return is_scalar( $v ) ? trim( (string) $v ) : ''; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_list_pluck( $list, $field ) {
	return array_map( static function( $row ) use ( $field ) {
		return is_object( $row ) ? ( $row->{$field} ?? null ) : ( $row[ $field ] ?? null );
	}, (array) $list );
}

class Estandar_Forms_Dataset_Provider {
	public static function get( $dataset ) {
		if ( $dataset === 'cl_regions' ) {
			return array( array( 'value' => 'rm', 'label' => 'Region Metropolitana' ) );
		}
		if ( $dataset === 'cl_regions_communes' ) {
			return array( array( 'value' => 'santiago', 'label' => 'Santiago' ) );
		}
		return array();
	}
}

class Estandar_Leads_Compliance {
	public static function hours_since_public( string $datetime ): int {
		return 50;
	}
}

class Estandar_Leads_SEO_Intelligence {
	public static function context_for_lead( $lead_id ) {
		return array(
			'status' => 'available', 'normalized_landing_url' => 'https://example.test/altojaramillo/',
			'period' => array( 'from' => '2026-06-01', 'to' => '2026-06-28', 'timezone' => 'America/Santiago' ),
			'metrics' => array( 'clicks' => 12, 'impressions' => 100, 'ctr' => .12, 'average_position' => 2.3 ),
			'top_queries' => array( array( 'query' => 'alto jaramillo' ) ),
			'freshness' => array( 'age_days' => 3, 'stale' => false ),
		);
	}
}

class AI_Context_Test_DB {
	public $prefix = 'wp_';
	public $qa = false;
	public $historical = false;
	public $gclid_only = false;
	public $numeric_utm = false;
	public $organic_fbp = false;
	public $interaction = false;
	public $meta_lead = false;   // Meta acquisition: fbclid + utm facebook, session sin landing
	public $fb_pending = false;  // Compliance Engine activo (feedback_status = pending)
	public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }
	public function suppress_errors( $v ) { return false; }
	public function get_col( $q ) {
		$sql = is_array( $q ) ? $q['q'] : $q;
		if ( strpos( $sql, 'DESC wp_es_leads' ) !== false ) {
			return array( 'id', 'project_slug' );
		}
		return array();
	}
	public function get_var( $q ) {
		$sql = is_array( $q ) ? $q['q'] : $q;
		if ( strpos( $sql, 'SELECT context_json FROM wp_es_lead_submissions' ) !== false ) {
			return wp_json_encode( array( 'qa_protection' => array( 'is_test_lead' => $this->qa ) ) );
		}
		if ( strpos( $sql, 'COUNT(*) FROM wp_ea_events' ) !== false ) {
			return 3;
		}
		if ( strpos( $sql, 'wp_ea_ads_daily' ) !== false ) {
			return 'Brand Campaign';
		}
		return null;
	}
	public function get_row( $q ) {
		$sql = is_array( $q ) ? $q['q'] : $q;
		if ( strpos( $sql, 'FROM wp_es_leads' ) !== false ) {
			return (object) array(
				'id' => 7, 'lead_uuid' => 'uuid-7', 'name' => 'Lead', 'email' => 'l@example.test', 'phone' => '123',
				'project_slug' => 'project-a', 'assigned_operator_name' => '', 'status' => 'NEW', 'quality' => '',
				'assigned_operator_id' => 0, 'first_session_id' => 'sid-12345',
				'first_seen_at' => '2026-06-01 00:00:00', 'last_seen_at' => '2026-06-01 00:00:00',
				'created_at' => '2026-06-01 00:00:00', 'first_contact_at' => '', 'last_commercial_action' => '',
				'sales_stage' => '', 'lead_intent' => 'lead_capture', 'is_valid_lead' => null, 'invalid_reason' => '',
				'would_buy_again' => '', 'score_components_json' => $this->interaction ? '{"behavior":15}' : '{}', 'behavioral_score' => null,
				'temperature' => '', 'priority' => '', 'score_updated_at' => '',
				'feedback_status' => $this->fb_pending ? 'pending' : 'overdue',
				'feedback_completed' => 0, 'assigned_at' => '', 'contact_attempts' => 0, 'experience_data_json' => '',
				'first_landing_url' => $this->meta_lead ? 'https://ak.test/first-landing/' : '',
			);
		}
		if ( strpos( $sql, 'FROM wp_ea_sessions' ) !== false ) {
			if ( $this->gclid_only || $this->numeric_utm ) { return null; }
			if ( $this->meta_lead ) {
				// Sesión INCOMPLETA: existe pero sin landing (caso B).
				return (object) array(
					'session_id' => 'sid-12345', 'landing_page' => '',
					'page_count' => 1, 'duration' => 12, 'source' => 'facebook',
					'campaign' => '', 'campaign_id' => '',
				);
			}
			return (object) array(
				'session_id' => 'sid-12345', 'landing_page' => 'https://example.test/acacios/inmobiliaria/parronales/',
				'page_count' => 2, 'duration' => 91, 'source' => 'google',
				'campaign' => 'fallback-campaign', 'campaign_id' => 'cmp-999',
			);
		}
		return null;
	}
	public function get_results( $q ) {
		$sql = is_array( $q ) ? $q['q'] : $q;
		if ( strpos( $sql, 'FROM wp_es_lead_submissions' ) !== false && strpos( $sql, 'ORDER BY created_at ASC' ) !== false ) {
			if ( $this->meta_lead ) {
				return array( (object) array(
					'id' => 11, 'lead_id' => 7, 'source_plugin' => 'forms', 'source_form_id' => 'f1',
					'submitted_at' => '2026-06-01 00:00:00', 'created_at' => '2026-06-01 00:00:00',
					'utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_campaign' => 'ak-meta-junio',
					'utm_content' => 'ad-m', 'gclid' => '', 'gbraid' => '', 'wbraid' => '',
					'fbclid' => 'fb-click-1', 'meta_fbc' => '', 'meta_fbp' => 'fb.1.1700000000.999', 'session_id' => 'sid-12345',
					'landing_url' => '',
					'context_json' => '{"region":"rm","city":"santiago","source_page_url":"https://ak.test/contadores/","initial_sales_stage":"NEW"}',
					'form_fields_json' => '{"region":"rm","city":"santiago"}',
				) );
			}
			return array( (object) array(
				'id' => 11, 'lead_id' => 7, 'source_plugin' => 'forms', 'source_form_id' => 'f1',
				'submitted_at' => '2026-06-01 00:00:00', 'created_at' => '2026-06-01 00:00:00',
				'utm_source' => 'google', 'utm_medium' => $this->organic_fbp ? 'organic' : 'cpc', 'utm_campaign' => $this->gclid_only ? '' : ( $this->numeric_utm ? '1234567890' : 'fallback-campaign' ),
				'utm_content' => 'ad-a', 'gclid' => $this->organic_fbp ? '' : 'gclid-1', 'gbraid' => $this->organic_fbp ? '' : 'gbraid-1', 'wbraid' => $this->organic_fbp ? '' : 'wbraid-1',
				'fbclid' => '', 'meta_fbp' => $this->organic_fbp ? 'fb.1.browser' : '', 'session_id' => 'sid-12345',
				'context_json' => $this->historical
					? '{"region":"rm","city":"santiago","initial_sales_stage":"NEW"}'
					: '{"region":"rm","city":"santiago","qualification":{"profile_id":"investor"},"initial_sales_stage":"NEW","google_ads_attribution":{"campaign_id":"explicit-123","adgroup_id":"ag-12","asset_group_id":"","creative_id":"cr-34","keyword":"casa","match_type":"e","network":"g","device":"m","placement":"","campaign_type":"search"},"google_ads_attribution_provenance":{"campaign_id":"explicit_url","adgroup_id":"explicit_url"}}',
				'form_fields_json' => '{"region":"rm","city":"santiago"}',
			) );
		}
		return array();
	}
}

$GLOBALS['wpdb'] = new AI_Context_Test_DB();
require dirname( __DIR__ ) . '/includes/class-estandar-leads-attribution.php';
require dirname( __DIR__ ) . '/includes/class-estandar-leads-qa-policy.php';
require dirname( __DIR__ ) . '/includes/class-estandar-leads-ai-context.php';

$md = Estandar_Leads_AI_Context::build_for_lead( 7 );
$checks = array(
	'events count' => strpos( $md, 'Events Recorded: 3' ) !== false,
	'gbraid' => strpos( $md, 'GBRAID:** gbraid-1' ) !== false,
	'wbraid' => strpos( $md, 'WBRAID:** wbraid-1' ) !== false,
	'campaign name' => strpos( $md, 'Campaign:** Brand Campaign' ) !== false,
	'ValueTrack Origin fields' => strpos( $md, 'Campaign ID:** explicit-123 (provenance: explicit_url)' ) !== false
		&& strpos( $md, 'Ad Group ID:** ag-12 (provenance: explicit_url)' ) !== false
		&& strpos( $md, 'Asset Group ID:** No disponible' ) !== false
		&& strpos( $md, 'Campaign Type:** search (provenance: explicit_capture)' ) !== false,
	'explicit attribution diagnostic' => strpos( $md, 'Attribution Status:** explicit captured attribution' ) !== false,
	'sla' => strpos( $md, 'SLA status:** overdue' ) !== false && strpos( $md, 'SLA remaining:** 0h' ) !== false,
	'location' => strpos( $md, 'Region:** Region Metropolitana' ) !== false && strpos( $md, 'City:** Santiago' ) !== false,
	'inference' => strpos( $md, 'Initial Stage:** NEW' ) !== false,
	'AI Context channels use canonical resolver' => strpos( $md, 'Source Channel:** Google Ads' ) !== false
		&& strpos( $md, 'Channel:** Google Ads' ) !== false,
	'commercial snapshot' => strpos( $md, '## 2. Commercial Snapshot' ) !== false
		&& strpos( $md, 'Lead:** Lead' ) !== false
		&& strpos( $md, 'Channel:** Google Ads' ) !== false
		&& strpos( $md, 'Next Action:** Contactar por teléfono.' ) !== false
		&& strpos( $md, 'Waiting for contact:** 50h (no contact recorded)' ) !== false
		&& strpos( $md, 'SLA:** overdue · 0h remaining' ) !== false,
	'SEO landing context is aggregate and not exact keyword' => strpos( $md, '### SEO Landing Context' ) !== false
		&& strpos( $md, 'alto jaramillo' ) !== false
		&& strpos( $md, 'exact query known: false' ) !== false
		&& strpos( $md, 'no representan necesariamente la búsqueda exacta') !== false,
	'optional snapshot values stay unavailable' => strpos( $md, 'Operator:** No disponible' ) !== false
		&& strpos( $md, 'Temperature:** No disponible' ) !== false
		&& strpos( $md, 'Priority:** No disponible' ) !== false,
	'redundant editorial blocks removed' => strpos( $md, '## Commercial Readiness' ) === false
		&& strpos( $md, '## Action' ) === false
		&& strpos( $md, '## Machine Context' ) === false
		&& strpos( $md, '## Lead Intelligence' ) === false
		&& strpos( $md, '## Missing Context' ) === false
		&& strpos( $md, 'Rules pending (PENDING)' ) === false,
	'canonical evidence retained' => strpos( $md, '## 5. Engine Pipeline' ) !== false
		&& strpos( $md, '## 6. Decision Log' ) !== false
		&& strpos( $md, '### Timeline (merged chronological)' ) !== false
		&& strpos( $md, '### Recommended Operational Action (v5.1)' ) !== false
		&& strpos( $md, '## 11. Raw Sources' ) !== false
		&& strpos( $md, '<details><summary>Lead (row)</summary>') !== false,
	// COMMERCIAL_QUALIFICATION_V1 · Part 7: one lead + one submission + one
	// normalized identity must NOT be an identity collision; the campaign
	// mismatch is an informational attribution notice instead.
	'no false-positive collision' => strpos( $md, 'Identity Collision Warning' ) === false,
	'attribution notice' => strpos( $md, 'Attribution Consistency Notice' ) !== false && strpos( $md, 'cmp-999' ) !== false,
	'commercial policy defaults' => strpos( $md, '## Commercial Policy' ) !== false
		&& strpos( $md, 'Routing Decision:** ROUTE (default policy)' ) !== false
		&& strpos( $md, 'Notification Decision:** NOTIFY (default policy)' ) !== false,
);
$failed = 0;
foreach ( $checks as $label => $ok ) {
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$failed += $ok ? 0 : 1;
}
$GLOBALS['wpdb']->organic_fbp = true;
$organic_md = Estandar_Leads_AI_Context::build_for_lead( 7 );
$organic_channel_safe = strpos( $organic_md, 'Source Channel:** Google Organic' ) !== false
	&& strpos( $organic_md, 'Channel:** Google Organic' ) !== false
	&& strpos( $organic_md, 'Source Channel:** Meta Ads' ) === false;
echo ( $organic_channel_safe ? 'PASS  ' : 'FAIL  ' ) . "Google organic + _fbp uses canonical channel in both AI sections\n";
$failed += $organic_channel_safe ? 0 : 1;
$organic_eligibility = Estandar_Leads_Attribution::platform_eligibility( 'google', $GLOBALS['wpdb']->get_results( array( 'q' => 'FROM wp_es_lead_submissions ORDER BY created_at ASC' ) )[0] );
$organic_not_eligible = empty( $organic_eligibility['eligible'] );
echo ( $organic_not_eligible ? 'PASS  ' : 'FAIL  ' ) . "Google organic is not eligible for ads feedback\n";
$failed += $organic_not_eligible ? 0 : 1;
$GLOBALS['wpdb']->organic_fbp = false;
$GLOBALS['wpdb']->qa = true;
$qa_md = Estandar_Leads_AI_Context::build_for_lead( 7 );
$qa_sla = strpos( $qa_md, 'SLA status:** excluded_test' ) !== false
	&& strpos( $qa_md, 'SLA elapsed:**' ) === false
	&& strpos( $qa_md, 'SLA remaining:**' ) === false;
echo ( $qa_sla ? 'PASS  ' : 'FAIL  ' ) . "QA excludes commercial SLA\n";
$failed += $qa_sla ? 0 : 1;
$GLOBALS['wpdb']->historical = true;
$historical_md = Estandar_Leads_AI_Context::build_for_lead( 7 );
$historical_safe = strpos( $historical_md, 'Ad Group ID:** No disponible' ) !== false
	&& strpos( $historical_md, 'Asset Group ID:** No disponible' ) !== false;
echo ( $historical_safe ? 'PASS  ' : 'FAIL  ' ) . "historical attribution renders safely\n";
$failed += $historical_safe ? 0 : 1;
$GLOBALS['wpdb']->gclid_only = true;
$gclid_only_md = Estandar_Leads_AI_Context::build_for_lead( 7 );
$gclid_only_safe = strpos( $gclid_only_md, 'Campaign ID:** No disponible' ) !== false
	&& strpos( $gclid_only_md, 'Attribution Status:** unavailable' ) !== false;
echo ( $gclid_only_safe ? 'PASS  ' : 'FAIL  ' ) . "gclid alone does not infer campaign IDs\n";
$failed += $gclid_only_safe ? 0 : 1;
$GLOBALS['wpdb']->gclid_only = false;
$GLOBALS['wpdb']->numeric_utm = true;
$numeric_utm_md = Estandar_Leads_AI_Context::build_for_lead( 7 );
$numeric_utm_safe = strpos( $numeric_utm_md, 'Campaign ID:** No disponible' ) !== false;
echo ( $numeric_utm_safe ? 'PASS  ' : 'FAIL  ' ) . "numeric utm_campaign does not infer campaign ID\n";
$failed += $numeric_utm_safe ? 0 : 1;
$GLOBALS['wpdb']->numeric_utm = false;
$GLOBALS['wpdb']->qa = false;
$GLOBALS['wpdb']->historical = false;

// ── E · Admission Policy vs Qualification (sin contradicción) ────────────────
$base_md = Estandar_Leads_AI_Context::build_for_lead( 7 );
$admission_ok = strpos( $base_md, '**Qualification:** PENDING (no operator qualification recorded)' ) !== false
	&& strpos( $base_md, '**Admission Policy:** ALLOWED' ) !== false
	&& strpos( $base_md, '**Admission Reason:** no exclusion rule matched' ) !== false
	&& strpos( $base_md, 'DEFAULT (qualified' ) === false;
echo ( $admission_ok ? 'PASS  ' : 'FAIL  ' ) . "E: admission policy replaces DEFAULT (qualified)\n";
$failed += $admission_ok ? 0 : 1;

// ── E · Timezone inequívoca (UTC + America/Santiago) ─────────────────────────
$tz_ok = strpos( $base_md, 'First Seen:** 2026-06-01 00:00:00 UTC (2026-05-31 20:00 America/Santiago)' ) !== false
	&& strpos( $base_md, 'Timestamp Convention' ) !== false;
echo ( $tz_ok ? 'PASS  ' : 'FAIL  ' ) . "E: timestamps carry explicit timezone (UTC + America/Santiago)\n";
$failed += $tz_ok ? 0 : 1;

// ── E · Compliance no pendiente cuando el engine está PASS ───────────────────
$GLOBALS['wpdb']->fb_pending = true;
$pending_md = Estandar_Leads_AI_Context::build_for_lead( 7 );
preg_match( '/Pending: (.*)/', $pending_md, $pm );
$compliance_ok = strpos( $pending_md, "Compliance Engine — PASS · compliance active" ) !== false
	&& strpos( (string) ( $pm[1] ?? '' ), 'Compliance' ) === false;
echo ( $compliance_ok ? 'PASS  ' : 'FAIL  ' ) . "E: AI Summary never lists Compliance pending when engine is PASS\n";
$failed += $compliance_ok ? 0 : 1;
$GLOBALS['wpdb']->fb_pending = false;

// ── B + E · Lead Meta: attribution status + landing fallback + WARNING ───────
$GLOBALS['wpdb']->meta_lead = true;
$meta_md = Estandar_Leads_AI_Context::build_for_lead( 7 );

$meta_attr_ok = strpos( $meta_md, 'Attribution Status:** explicit_meta_attribution (evidence: fbclid · provenance: explicit_capture)' ) !== false
	&& strpos( $meta_md, 'Attribution Status:** unavailable' ) === false;
echo ( $meta_attr_ok ? 'PASS  ' : 'FAIL  ' ) . "E: Meta lead never renders Attribution Status unavailable\n";
$failed += $meta_attr_ok ? 0 : 1;

$landing_fallback_ok = strpos( $meta_md, 'Landing: https://ak.test/contadores/ (fallback source: submission.source_page_url — session landing unavailable)' ) !== false;
echo ( $landing_fallback_ok ? 'PASS  ' : 'FAIL  ' ) . "B: incomplete session resolves landing via fallback with provenance\n";
$failed += $landing_fallback_ok ? 0 : 1;

$warning_kept = strpos( $meta_md, 'Analytics Quality: WARNING' ) !== false;
echo ( $warning_kept ? 'PASS  ' : 'FAIL  ' ) . "B: Analytics Quality WARNING preserved despite landing fallback\n";
$failed += $warning_kept ? 0 : 1;

$meta_channel_ok = strpos( $meta_md, 'Source Channel:** Meta Ads' ) !== false;
echo ( $meta_channel_ok ? 'PASS  ' : 'FAIL  ' ) . "B: Meta lead resolves canonical Meta Ads channel\n";
$failed += $meta_channel_ok ? 0 : 1;
$meta_submission = $GLOBALS['wpdb']->get_results( array( 'q' => 'FROM wp_es_lead_submissions ORDER BY created_at ASC' ) )[0];
$meta_eligibility = Estandar_Leads_Attribution::platform_eligibility( 'meta', $meta_submission );
$meta_eligible = ! empty( $meta_eligibility['eligible'] ) && strpos( $meta_md, 'Channel:** Meta Ads' ) !== false;
echo ( $meta_eligible ? 'PASS  ' : 'FAIL  ' ) . "Meta Ads eligible snapshot uses canonical channel\n";
$failed += $meta_eligible ? 0 : 1;
$GLOBALS['wpdb']->meta_lead = false;

// Snapshot interaction is a presentation of existing score evidence, not a new score.
$GLOBALS['wpdb']->interaction = true;
$interaction_md = Estandar_Leads_AI_Context::build_for_lead( 7 );
$interaction_ok = strpos( $interaction_md, '## 2. Commercial Snapshot' ) !== false
	&& strpos( $interaction_md, 'Behavior Engine — PASS · behavior=15' ) !== false;
echo ( $interaction_ok ? 'PASS  ' : 'FAIL  ' ) . "snapshot preserves lead with interaction evidence\n";
$failed += $interaction_ok ? 0 : 1;
$GLOBALS['wpdb']->interaction = false;

exit( $failed === 0 ? 0 : 1 );
