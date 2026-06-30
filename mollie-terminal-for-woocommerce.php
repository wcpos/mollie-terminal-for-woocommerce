<?php
/**
 * Plugin Name: Mollie Terminal for WooCommerce
 * Description: Adds Mollie Terminal support to WooCommerce for in-person payments.
 * Version:     0.1.2
 * Author:      kilbot
 * Author URI:  https://kilbot.com/
 * Update URI:  https://github.com/wcpos/mollie-terminal-for-woocommerce
 * License:     GPL v3 or later
 * Text Domain: mollie-terminal-for-woocommerce
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 */

namespace WCPOS\WooCommercePOS\MollieTerminal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MTFWC_VERSION', '0.1.2' );
define( 'MTFWC_PLUGIN_FILE', __FILE__ );
define( 'MTFWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MTFWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MTFWC_MINIMUM_PHP_VERSION', '7.4' );
define( 'MTFWC_MINIMUM_PHP_VERSION_ID', 70400 );

if ( file_exists( MTFWC_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once MTFWC_PLUGIN_DIR . 'vendor/autoload.php';
}

spl_autoload_register(
	function ( $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		$len    = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}
		$file = MTFWC_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

function mtfwc_activate(): void {
	if ( PHP_VERSION_ID >= MTFWC_MINIMUM_PHP_VERSION_ID ) {
		return;
	}
	deactivate_plugins( plugin_basename( __FILE__ ) );
	wp_die( esc_html( sprintf( __( 'Mollie Terminal for WooCommerce requires PHP %1$s or newer. Your server is running PHP %2$s.', 'mollie-terminal-for-woocommerce' ), MTFWC_MINIMUM_PHP_VERSION, PHP_VERSION ) ) );
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\mtfwc_activate' );

function init(): void {
	add_filter( 'woocommerce_payment_gateways', array( Gateway::class, 'register_gateway' ) );
	new AjaxHandler();
	new WebhookHandler();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init', 11 );
