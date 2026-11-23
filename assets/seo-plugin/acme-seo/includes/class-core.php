<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared settings and helpers. Everything site-specific (tracking ID, which post types to check,
 * the product post type and its meta keys, which "expert approved" words to flag) lives in one option,
 * edited on the Settings tab, so the same code works for any niche.
 */
class ACME_SEO {

	const OPT    = 'acme_seo_settings';
	const RESULT = 'acme_seo_result';

	public static function defaults() {
		return array(
			'partner_tag'   => '',
			'extra_tags'    => '',
			'content_types' => 'page,post',
			'product_type'  => 'acme_product',
			'meta_asin'     => '_acme_asin',
			'meta_stock'    => '_acme_stock',
			'meta_checked'  => '_acme_checked',
			'claim_words'   => 'expert|doctor|dentist|nutritionist|dietitian|vet|veterinarian|dermatologist|pharmacist|engineer',
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPT, array() ), self::defaults() );
	}

	public static function url( $tab = 'settings' ) {
		return admin_url( 'admin.php?page=acme-seo&tab=' . rawurlencode( $tab ) );
	}

	public static function active() {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	/** Post types the audit, reviews and fix menus look at. */
	public static function content_types() {
		$t = array_filter( array_map( 'sanitize_key', explode( ',', self::settings()['content_types'] ) ) );
		return (array) apply_filters( 'acme_seo_content_types', $t ? array_values( $t ) : array( 'page', 'post' ) );
	}

	public static function partner_tag() {
		return trim( (string) self::settings()['partner_tag'] );
	}

	public static function extra_tags() {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) self::settings()['extra_tags'] ) ) ) );
	}

	/** Regex alternation of words that must never be followed by "approved/recommended" unless true. */
	public static function claim_words() {
		$w = array_filter( array_map( 'trim', explode( '|', (string) self::settings()['claim_words'] ) ) );
		return implode( '|', array_map( function ( $x ) {
			return preg_quote( $x, '/' );
		}, $w ? $w : array( 'expert' ) ) );
	}

	public static function init() {
		add_action( 'admin_post_acme_seo_settings', array( __CLASS__, 'handle_save' ) );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'acme_seo_settings' );
		$out = array();
		foreach ( self::defaults() as $k => $v ) {
			$out[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : $v; // phpcs:ignore
		}
		update_option( self::OPT, $out, false );
		ACME_SEO_Tools::log( 'Saved plugin settings' );
		set_transient( self::RESULT, array( 'Settings saved.' ), 60 );
		wp_safe_redirect( self::url( 'settings' ) );
		exit;
	}

	public static function page() {
		$s      = self::settings();
		$fields = array(
			'partner_tag'   => array( 'Amazon tracking ID', 'Your main Associates tracking ID (looks like name-20). The audit flags Amazon links without it.' ),
			'extra_tags'    => array( 'Extra tracking IDs', 'Comma separated. Section-specific IDs that are also allowed.' ),
			'content_types' => array( 'Post types to check', 'Comma separated, e.g. page,post,my_reviews.' ),
			'product_type'  => array( 'Product post type', 'The post type that stores your Amazon products.' ),
			'meta_asin'     => array( 'ASIN meta key', 'Meta key holding the 10-character ASIN.' ),
			'meta_stock'    => array( 'Stock meta key', 'Meta key holding in / out / gone (optional).' ),
			'meta_checked'  => array( 'Last-checked meta key', 'Meta key holding a Unix time of the last check (optional).' ),
			'claim_words'   => array( 'Expert words to watch', 'Separated by |. The audit flags "<word> approved / recommended / endorsed / certified / tested".' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="acme_seo_settings"><?php wp_nonce_field( 'acme_seo_settings' ); ?>
			<table class="form-table" role="presentation">
			<?php foreach ( $fields as $k => $f ) : ?>
				<tr><th scope="row"><label for="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $f[0] ); ?></label></th>
				<td><input type="text" class="regular-text" id="<?php echo esc_attr( $k ); ?>" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $s[ $k ] ); ?>"><p class="description"><?php echo esc_html( $f[1] ); ?></p></td></tr>
			<?php endforeach; ?>
			</table>
			<?php submit_button( 'Save settings' ); ?>
		</form>
		<?php
	}
}
