<?php
/**
 * Plugin Name:       Site SEO (Rank Math companion)
 * Description:       Redirects from a previous site, a Monitor (verified Googlebot/Bingbot visits to redirects and 404s, redirect tester, sitemap health, temporary old-address sitemap), a site audit (SEO, schema, affiliate and honesty rules, internal links), page reviews and extra tools.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACME_SEO_VERSION', '1.0.0' );
define( 'ACME_SEO_FILE', __FILE__ );
define( 'ACME_SEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACME_SEO_URL', plugin_dir_url( __FILE__ ) );

require_once ACME_SEO_DIR . 'includes/class-core.php';
require_once ACME_SEO_DIR . 'includes/class-redirects.php';
require_once ACME_SEO_DIR . 'includes/class-monitor.php';
require_once ACME_SEO_DIR . 'includes/class-audit.php';
require_once ACME_SEO_DIR . 'includes/class-review.php';
require_once ACME_SEO_DIR . 'includes/class-tools.php';
require_once ACME_SEO_DIR . 'includes/class-admin.php';

register_activation_hook(
	__FILE__,
	function () {
		add_option( 'acme_seo_installed', time() );
	}
);
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'acme_seo_daily' );
	}
);

foreach ( array( 'ACME_SEO', 'ACME_SEO_Redirects', 'ACME_SEO_Monitor', 'ACME_SEO_Audit', 'ACME_SEO_Review', 'ACME_SEO_Tools', 'ACME_SEO_Admin' ) as $acme_seo_class ) {
	add_action( 'plugins_loaded', array( $acme_seo_class, 'init' ) );
}
