<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends visitors and search engines from the previous site's addresses to the right page on this site.
 *
 * - Only runs when WordPress finds nothing at the requested address (a real page always wins).
 * - Built-in rules come from the previous site's sitemap (includes/redirect-map.php); you can add your own.
 * - 301 = moved permanently, 410 = gone for good (no equivalent page).
 * - Never sends anyone to an unpublished page: such a rule is skipped until the target is published.
 */
class ACME_SEO_Redirects {

	const OPTION = 'acme_seo_redirects';

	public static function init() {
		// Priority 0 runs before the engine plugin's older, smaller map (priority 1) and WordPress canonical redirects (10).
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 0 );
		add_action( 'admin_post_acme_seo_redirects', array( __CLASS__, 'handle_save' ) );
	}

	public static function settings() {
		$o = (array) get_option( self::OPTION, array() );
		return wp_parse_args( $o, array( 'enabled' => 1, 'custom' => '' ) );
	}

	private static function map() {
		static $m = null;
		if ( null === $m ) {
			$m = include ACME_SEO_DIR . 'includes/redirect-map.php';
		}
		return $m;
	}

	/** Lower-case path with one leading and one trailing slash (files such as .xml keep no trailing slash). */
	public static function norm( $path ) {
		$path = (string) wp_parse_url( (string) $path, PHP_URL_PATH );
		$path = '/' . ltrim( strtolower( rawurldecode( $path ) ), '/' );
		if ( preg_match( '/\.[a-z0-9]{2,5}$/', $path ) ) {
			return $path;
		}
		return trailingslashit( $path );
	}

	/** Built-in rules followed by your own. Your own win when they target the same old address. */
	public static function rules() {
		$rules = array();
		foreach ( self::map()['rules'] as $r ) {
			$rules[ self::norm( $r['from'] ) ] = array( 'to' => $r['to'], 'code' => (int) $r['code'], 'note' => $r['note'], 'src' => 'built-in' );
		}
		foreach ( self::parse_custom( self::settings()['custom'] ) as $r ) {
			$rules[ self::norm( $r['from'] ) ] = array( 'to' => $r['to'], 'code' => $r['code'], 'note' => 'Your rule', 'src' => 'yours' );
		}
		return $rules;
	}

	/** One rule per line: "/old/ => /new/" (301) or "/old/ => 410". Lines starting with # are ignored. */
	public static function parse_custom( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] || false === strpos( $line, '=>' ) ) {
				continue;
			}
			list( $from, $to ) = array_map( 'trim', explode( '=>', $line, 2 ) );
			if ( '' === $from || '/' !== $from[0] ) {
				continue;
			}
			if ( '410' === $to ) {
				$out[] = array( 'from' => $from, 'to' => null, 'code' => 410 );
			} elseif ( '' !== $to && ( '/' === $to[0] || 0 === strpos( $to, home_url() ) ) ) {
				$out[] = array( 'from' => $from, 'to' => $to, 'code' => 301 );
			}
		}
		return $out;
	}

	/** True when the target address is a published page on this site. */
	public static function target_live( $to ) {
		if ( null === $to ) {
			return true;
		}
		$path = self::norm( $to );
		if ( '/' === $path || '/sitemap_index.xml' === $path ) {
			return true;
		}
		$id = url_to_postid( home_url( $path ) );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return true;
		}
		// Taxonomy archives and similar pages have no post ID; accept a term whose page exists.
		$slug = trim( $path, '/' );
		$page = get_page_by_path( $slug, OBJECT, ACME_SEO::content_types() );
		return $page && 'publish' === $page->post_status;
	}

	public static function maybe_redirect() {
		if ( ! is_404() || is_admin() || empty( self::settings()['enabled'] ) ) {
			return;
		}
		$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore
		if ( '' === $req ) {
			return;
		}
		$key   = self::norm( $req );
		$rules = self::rules();
		if ( ! isset( $rules[ $key ] ) ) {
			return;
		}
		$r = $rules[ $key ];
		if ( 410 === $r['code'] ) {
			if ( class_exists( 'ACME_SEO_Monitor' ) ) {
				ACME_SEO_Monitor::log_hit( $key, 410 );
			}
			status_header( 410 );
			nocache_headers();
			header( 'X-Robots-Tag: noindex' );
			wp_die( '<h1>This page is gone</h1><p>It was removed on purpose and is not coming back. <a href="' . esc_url( home_url( '/' ) ) . '">Go to the home page</a>.</p>', 'Gone', array( 'response' => 410 ) );
		}
		if ( ! self::target_live( $r['to'] ) ) {
			return; // Never send people to a draft or missing page; the normal 404 shows instead.
		}
		$to = 0 === strpos( $r['to'], 'http' ) ? $r['to'] : home_url( $r['to'] );
		if ( class_exists( 'ACME_SEO_Monitor' ) ) {
			ACME_SEO_Monitor::log_hit( $key, 301, $r['to'] );
		}
		wp_safe_redirect( $to, 301, 'ACME' );
		exit;
	}

	/** Append one rule to "Your own rules" (used by the Monitor's Fix button). $to is a path or '410'. */
	public static function add_custom_rule( $from, $to ) {
		$s    = self::settings();
		$line = $from . ' => ' . $to;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $s['custom'] ) as $l ) {
			if ( 0 === strpos( trim( $l ), $from . ' =>' ) ) {
				return;
			}
		}
		$s['custom'] = trim( (string) $s['custom'] . "\n" . $line );
		update_option( self::OPTION, $s, false );
	}

	/* ------------------------------------------------------------ admin UI */

	public static function panel() {
		$s     = self::settings();
		$rules = self::rules();
		$same  = self::map()['same'];
		?>
		<h2 id="redirects">Redirects from the previous site</h2>
		<p class="description" style="max-width:760px">Built from the previous site's sitemap. Visitors and search engines who arrive on an old address are sent to the nearest page here. A rule only runs when nothing exists at the old address, and never points to an unpublished page. Keep this on for at least a year after the move.</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="acme_seo_redirects">
			<?php wp_nonce_field( 'acme_seo_redirects' ); ?>
			<p><label><input type="checkbox" name="enabled" value="1" <?php checked( (int) $s['enabled'], 1 ); ?>> <strong>Turn redirects on</strong></label></p>
			<p><label for="acme_custom"><strong>Your own rules</strong> (optional). One per line: <code>/old-address/ =&gt; /new-address/</code> or <code>/old-address/ =&gt; 410</code> for a page that is gone.</label></p>
			<textarea id="acme_custom" name="custom" rows="4" class="large-text code" placeholder="/old-page/ => /new-page/"><?php echo esc_textarea( $s['custom'] ); ?></textarea>
			<?php submit_button( 'Save redirects', 'secondary', 'submit', false ); ?>
		</form>

		<h3 style="margin-top:24px">Rules in use (<?php echo (int) count( $rules ); ?>)</h3>
		<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Old address</th><th>Goes to</th><th>Code</th><th>Target</th><th>Why</th></tr></thead><tbody>
		<?php foreach ( $rules as $from => $r ) : ?>
			<?php $live = self::target_live( $r['to'] ); ?>
			<tr>
				<td><code><?php echo esc_html( $from ); ?></code></td>
				<td><?php echo null === $r['to'] ? '<em>none</em>' : '<code>' . esc_html( $r['to'] ) . '</code>'; ?></td>
				<td><?php echo (int) $r['code']; ?></td>
				<td><?php echo $live ? '<span style="color:#1a7f37">published</span>' : '<span style="color:#b32d2e"><strong>not published</strong>: rule paused</span>'; ?></td>
				<td><?php echo esc_html( $r['note'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<p class="description" style="margin-top:10px">These old addresses keep the same address on the new site, so they need no redirect: <?php echo esc_html( implode( ', ', $same ) ); ?></p>
		<p class="description">Rank Math has its own "Redirections" module. Use one or the other for the same address, not both. If you turn on Rank Math's, leave this on only for addresses Rank Math does not list.</p>
		<?php
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'acme_seo_redirects' ) ) {
			wp_die( 'Not allowed.' );
		}
		$custom = isset( $_POST['custom'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom'] ) ) : '';
		update_option( self::OPTION, array( 'enabled' => empty( $_POST['enabled'] ) ? 0 : 1, 'custom' => $custom ), false );
		ACME_SEO_Tools::log( 'Saved redirect rules' );
		set_transient( ACME_SEO::RESULT, array( 'Redirect settings saved.' ), 120 );
		wp_safe_redirect( ACME_SEO::url( 'redirects' ) );
		exit;
	}
}
