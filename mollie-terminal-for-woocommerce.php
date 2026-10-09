<?php
/**
 * Plugin Name: Mollie Terminal for WooCommerce
 * Description: Adds Mollie Terminal support to WooCommerce for in-person payments.
 * Version:     0.5.9
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

define( 'MTFWC_VERSION', '0.5.9' );
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
	if ( PHP_VERSION_ID < MTFWC_MINIMUM_PHP_VERSION_ID ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( esc_html( sprintf( __( 'Mollie Terminal for WooCommerce requires PHP %1$s or newer. Your server is running PHP %2$s.', 'mollie-terminal-for-woocommerce' ), MTFWC_MINIMUM_PHP_VERSION, PHP_VERSION ) ) );
	}
	// Terminal extensions are Pro-only at 2.0; Pro records the requirement for its own notice.
	if ( function_exists( 'wcpos_pro_requires' ) ) {
		wcpos_pro_requires( Server\Registration::REQUIRED_PRO_VERSION, __FILE__ );
	}
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\mtfwc_activate' );

function mtfwc_deactivate(): void {
	PaymentSweeper::unschedule();
	PaymentCleanup::unschedule();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\mtfwc_deactivate' );

function load_textdomain(): void {
	load_plugin_textdomain( 'mollie-terminal-for-woocommerce', false, dirname( plugin_basename( MTFWC_PLUGIN_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );

function init(): void {
	// Terminal extensions are Pro-only at 2.0: the keypad tile and the order-pay page both rely
	// on Pro's shared payments base.
	if ( ! function_exists( 'wcpos_pro_requires' ) || ! wcpos_pro_requires( Server\Registration::REQUIRED_PRO_VERSION, __FILE__ ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Mollie Terminal for WooCommerce needs WooCommerce POS Pro 2.0.0 or newer.', 'mollie-terminal-for-woocommerce' ) . '</p></div>';
			}
		);
		return;
	}
	add_filter( 'woocommerce_payment_gateways', array( Gateway::class, 'register_gateway' ) );
	add_action( 'woocommerce_create_refund', array( RefundHandler::class, 'remember_refund' ), 10, 2 );
	// The keypad's server mode, on Pro's shared base.
	Server\Registration::register();
	new AjaxHandler();
	new WebhookHandler();
	new PaymentCleanup();
	new PaymentSweeper();
}
// Pro defines wcpos_pro_requires() and the provider registration API from its own
// plugins_loaded hook at priority 20; the gate must run after that.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init', 30 );
