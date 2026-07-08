<?php
// Covers the opt-in reuse of the official Mollie plugin's API key and the
// checkout log-tools toggle.
function expect( $condition, $message = 'expectation failed' ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }

$GLOBALS['wp_options'] = array(
	'mollie-payments-for-woocommerce_live_api_key' => 'live_sharedKeyABCDEFGHIJKLMNOP',
	'mollie-payments-for-woocommerce_test_api_key' => 'test_sharedKeyABCDEFGHIJKLMNOP',
);
function get_option( $key, $default = false ) { return $GLOBALS['wp_options'][ $key ] ?? $default; }
function __( $text, $domain = null ) { return $text; }
function add_query_arg( array $args, $url ) { return $url; }
function admin_url( $path = '' ) { return $path; }

require_once __DIR__ . '/../../includes/Settings.php';

use WCPOS\WooCommercePOS\MollieTerminal\Settings;

// Default source: this plugin's own key is used.
$own = new Settings( array( 'api_key' => 'live_ownKeyABCDEFGHIJKLMNOP', 'mode' => 'live' ) );
expect( 'live_ownKeyABCDEFGHIJKLMNOP' === $own->api_key(), 'default source should use the plugin\'s own key' );
expect( 'own' === $own->api_key_source(), 'default api_key_source should be own' );

// Shared source, live mode: read the Mollie plugin's live key.
$shared_live = new Settings( array( 'api_key_source' => 'mollie', 'api_key' => 'live_ownKeyABCDEFGHIJKLMNOP', 'mode' => 'live' ) );
expect( 'live_sharedKeyABCDEFGHIJKLMNOP' === $shared_live->api_key(), 'shared source (live) should read the Mollie plugin live key' );
expect( 'mollie' === $shared_live->api_key_source(), 'api_key_source should report mollie' );

// Shared source, test mode: read the Mollie plugin's test key.
$shared_test = new Settings( array( 'api_key_source' => 'mollie', 'mode' => 'test' ) );
expect( 'test_sharedKeyABCDEFGHIJKLMNOP' === $shared_test->api_key(), 'shared source (test) should read the Mollie plugin test key' );

// Shared source but the Mollie plugin has no key: fall back to the own key.
$GLOBALS['wp_options'] = array();
$fallback = new Settings( array( 'api_key_source' => 'mollie', 'api_key' => 'live_ownKeyABCDEFGHIJKLMNOP', 'mode' => 'live' ) );
expect( 'live_ownKeyABCDEFGHIJKLMNOP' === $fallback->api_key(), 'shared source should fall back to the own key when no shared key exists' );

// Log tools toggle.
$logs_off = new Settings( array() );
expect( false === $logs_off->show_logs(), 'log tools should be hidden by default' );
$logs_on = new Settings( array( 'show_logs' => 'yes' ) );
expect( true === $logs_on->show_logs(), 'log tools should show when enabled' );

echo "settings-shared-key ok\n";
