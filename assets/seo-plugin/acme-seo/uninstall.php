<?php
/** Removes this plugin's options, review meta and log tables when the plugin is deleted. Rank Math data is never touched. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
foreach ( array( 'acme_seo_settings', 'acme_seo_redirects', 'acme_seo_mon', 'acme_seo_tools', 'acme_seo_changelog', 'acme_seo_db', 'acme_seo_installed', 'acme_seo_redirect_test', 'acme_seo_sitemap_health', 'acme_seo_audit_results', 'acme_seo_audit_links' ) as $acme_seo_opt ) {
	delete_option( $acme_seo_opt );
}
foreach ( array( '_acme_rv_name', '_acme_rv_cred', '_acme_rv_date', '_acme_rv_checked' ) as $acme_seo_key ) {
	delete_post_meta_by_key( $acme_seo_key );
}
global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'acme_seo_urls' ); // phpcs:ignore
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'acme_seo_bots' ); // phpcs:ignore
