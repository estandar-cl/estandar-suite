<?php

namespace Estandar\Core\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marker for destination adapters that do NOT need OAuth credentials at
 * runtime. Their transport is a plain POST to a public web-app URL (e.g. an
 * Apps Script Web App published as "Anyone can access"). No connection row is
 * required to deliver; delivery uses only the destination config.
 */
interface NoOAuth
{
}
