<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extra tools: AI crawler rules for robots.txt, Rank Math module switches, weekly e-mail summary,
 * Amazon product health, tracking-ID report and a change log. Also shared helpers (log, CSV download).
 */
class ACME_SEO_Tools {

	const OPT = 'acme_seo_tools';
	const LOG = 'acme_seo_changelog';

	/** Crawler name => what it is for. */
	public static function bots() {
		return array(
			'GPTBot'           => 'OpenAI: collects pages to train models',
			'OAI-SearchBot'    => 'OpenAI: finds pages for ChatGPT search answers',
			'ChatGPT-User'     => 'OpenAI: opens a page when a person asks ChatGPT to',
			'ClaudeBot'        => 'Anthropic: collects pages to train models',
			'Claude-SearchBot' => 'Anthropic: finds pages for Claude search answers',
			'Claude-User'      => 'Anthropic: opens a page when a person asks Claude to',
			'PerplexityBot'    => 'Perplexity: finds pages for its search answers',
			'Google-Extended'  => 'Google: lets Gemini training and grounding use your pages (does not affect Google Search)',
			'CCBot'            => 'Common Crawl: open dataset used to train many models',
			'Applebot-Extended' => 'Apple: lets Apple use your pages to train its models',
			'Bytespider'       => 'ByteDance: collects pages to train models',
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPT, array() ), array( 'block' => array(), 'weekly' => 0, 'email' => '', 'last_sent' => 0 ) );
	}

	public static function init() {
		add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 99, 2 );
		add_action( 'admin_post_acme_seo_tools_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_acme_seo_module', array( __CLASS__, 'handle_module' ) );
		add_action( 'admin_post_acme_seo_weekly_test', array( __CLASS__, 'handle_weekly_test' ) );
	}

	/* ------------------------------------------------------------- helpers */

	public static function log( $note ) {
		$l = (array) get_option( self::LOG, array() );
		$u = wp_get_current_user();
		array_unshift( $l, array( 't' => time(), 'u' => $u && $u->exists() ? $u->user_login : 'system', 'n' => (string) $note ) );
		update_option( self::LOG, array_slice( $l, 0, 100 ), false );
	}

	public static function send_csv( $name, array $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
		$o = fopen( 'php://output', 'w' );
		fwrite( $o, "\xEF\xBB\xBF" );
		foreach ( $rows as $r ) {
			// Stop spreadsheet programs from running a cell that starts with = + - @ as a formula.
			$r = array_map( function ( $v ) {
				$v = (string) $v;
				return ( '' !== $v && false !== strpos( "=+-@", $v[0] ) ) ? "'" . $v : $v;
			}, $r );
			fputcsv( $o, $r, ',', '"', '' );
		}
		fclose( $o );
		exit;
	}

	/* -------------------------------------------------------------- robots */

	public static function robots( $output, $public ) {
		$block = (array) self::settings()['block'];
		if ( ! $block ) {
			return $output;
		}
		$add = "\n";
		foreach ( self::bots() as $bot => $why ) {
			if ( in_array( $bot, $block, true ) ) {
				$add .= 'User-agent: ' . $bot . "\nDisallow: /\n\n";
			}
		}
		return $output . $add;
	}

	/* ------------------------------------------------------------ handlers */

	private static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to do this.', 403 );
		}
		check_admin_referer( $nonce );
	}

	private static function done( $msg ) {
		set_transient( ACME_SEO::RESULT, array( $msg ), 60 );
		wp_safe_redirect( ACME_SEO::url( 'tools' ) );
		exit;
	}

	public static function handle_save() {
		self::guard( 'acme_seo_tools_save' );
		$allowed = array_keys( self::bots() );
		$block   = array();
		foreach ( (array) ( $_POST['block'] ?? array() ) as $b ) { // phpcs:ignore
			$b = sanitize_text_field( wp_unslash( $b ) );
			if ( in_array( $b, $allowed, true ) ) {
				$block[] = $b;
			}
		}
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore
		$old   = self::settings();
		update_option(
			self::OPT,
			array(
				'block'     => $block,
				'weekly'    => ( ! empty( $_POST['weekly'] ) && is_email( $email ) ) ? 1 : 0, // phpcs:ignore
				'email'     => $email,
				'last_sent' => $old['last_sent'],
			),
			false
		);
		self::log( 'Saved Tools settings (blocked AI crawlers: ' . ( $block ? implode( ', ', $block ) : 'none' ) . ')' );
		self::done( 'Tools settings saved.' );
	}

	public static function handle_module() {
		self::guard( 'acme_seo_module' );
		$id = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : ''; // phpcs:ignore
		if ( ! in_array( $id, array( '404-monitor', 'redirections', 'instant-indexing' ), true ) ) {
			self::done( 'Unknown module.' );
		}
		$mods = (array) get_option( 'rank_math_modules', array() );
		if ( ! in_array( $id, $mods, true ) ) {
			$mods[] = $id;
			update_option( 'rank_math_modules', $mods );
			self::log( 'Turned on Rank Math module ' . $id );
		}
		self::done( 'Rank Math module "' . $id . '" turned on. If it does not appear in Rank Math, switch it on there once.' );
	}

	/* -------------------------------------------------------------- weekly */

	public static function summary() {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$g     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "acme_seo_bots WHERE bot = 'google' AND hit_at >= %s", $since ) ); // phpcs:ignore
		$n404  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'acme_seo_urls WHERE code = 404 AND last_hit >= %s', $since ) ); // phpcs:ignore
		$au    = (array) get_option( ACME_SEO_Audit::RES, array() );
		$bad   = 0;
		foreach ( (array) ( $au['pages'] ?? array() ) as $p ) {
			foreach ( $p['issues'] as $is ) {
				$bad += 'bad' === $is[0] ? 1 : 0;
			}
		}
		$sm  = (array) get_option( 'acme_seo_sitemap_health', array() );
		$sbad = 0;
		foreach ( (array) ( $sm['rows'] ?? array() ) as $r ) {
			$sbad += '' === $r['issue'] ? 0 : 1;
		}
		return "Weekly summary for " . home_url() . "\n\n"
			. "Verified Googlebot visits to redirects/404s (7 days): {$g}\n"
			. "Addresses that returned 404 (7 days): {$n404}\n"
			. "Sitemap addresses with a problem: {$sbad}\n"
			. 'Problems from the last site audit: ' . ( $au ? $bad : 'audit not run yet' ) . "\n\n"
			. 'Open the dashboard: ' . ACME_SEO::url( 'monitor' ) . "\n";
	}

	public static function maybe_weekly() {
		$s = self::settings();
		if ( empty( $s['weekly'] ) || ! is_email( $s['email'] ) || time() - (int) $s['last_sent'] < 6 * DAY_IN_SECONDS ) {
			return;
		}
		wp_mail( $s['email'], 'Site SEO weekly summary', self::summary() );
		$s['last_sent'] = time();
		update_option( self::OPT, $s, false );
	}

	public static function handle_weekly_test() {
		self::guard( 'acme_seo_tools_save' );
		$s = self::settings();
		if ( ! is_email( $s['email'] ) ) {
			self::done( 'Save an e-mail address first.' );
		}
		$ok = wp_mail( $s['email'], 'Site SEO weekly summary (test)', self::summary() );
		self::done( $ok ? 'Test e-mail sent to ' . $s['email'] . '.' : 'The e-mail could not be sent. Check your site mail setup.' );
	}

	/* ----------------------------------------------------------------- UI */

	public static function page() {
		$s = self::settings();
		?>
		<h2>AI crawlers (robots.txt)</h2>
		<p class="description" style="max-width:820px">Choose which AI crawlers may NOT read the site. None are blocked by default, because being quoted in AI answers can send readers to you. Blocking is a business choice, not an SEO requirement. This only adds lines to the virtual robots.txt WordPress serves. Honest crawlers follow it; it is not a lock.</p>
		<?php if ( file_exists( ABSPATH . 'robots.txt' ) ) : ?>
			<div class="notice notice-warning inline"><p>A real <code>robots.txt</code> file exists in your site folder. WordPress ignores its own robots.txt while that file exists, so the choices below will have no effect until the file is removed.</p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="acme_seo_tools_save"><?php wp_nonce_field( 'acme_seo_tools_save' ); ?>
			<table class="widefat striped" style="max-width:820px"><thead><tr><th style="width:90px">Block</th><th>Crawler</th><th>What it does</th></tr></thead><tbody>
			<?php foreach ( self::bots() as $bot => $why ) : ?>
				<tr><td><input type="checkbox" name="block[]" value="<?php echo esc_attr( $bot ); ?>" <?php checked( in_array( $bot, (array) $s['block'], true ) ); ?>></td><td><code><?php echo esc_html( $bot ); ?></code></td><td><?php echo esc_html( $why ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<h3>Weekly e-mail summary</h3>
			<p><label><input type="checkbox" name="weekly" value="1" <?php checked( (int) $s['weekly'], 1 ); ?>> Send me a short summary once a week</label> &nbsp; <input type="email" name="email" value="<?php echo esc_attr( $s['email'] ); ?>" class="regular-text" placeholder="you@example.com"></p>
			<p class="description">Off by default. Covers Googlebot visits to redirects, 404s, sitemap problems and audit problems.</p>
			<?php submit_button( 'Save', 'secondary', 'submit', false ); ?>
			<button type="submit" class="button" name="action" value="acme_seo_weekly_test">Send a test e-mail</button>
		</form>

		<h2>Rank Math modules</h2>
		<?php
		$mods = (array) get_option( 'rank_math_modules', array() );
		if ( ! ACME_SEO::active() ) {
			echo '<p>Rank Math is not active.</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:820px"><thead><tr><th>Module</th><th>Why</th><th>Status</th></tr></thead><tbody>';
			$list = array(
				'404-monitor'      => array( '404 Monitor', 'Rank Math\'s own list of missing pages. This plugin\'s Monitor tab already logs 404s, so you only need one.' ),
				'redirections'     => array( 'Redirections', 'Rank Math\'s redirect manager. This plugin already handles redirects from the previous site, so only turn this on if you want its screen.' ),
				'instant-indexing' => array( 'Instant Indexing (IndexNow)', 'Tells Bing and others the moment you publish or change a page. Does not notify Google.' ),
			);
			foreach ( $list as $id => $m ) {
				echo '<tr><td><strong>' . esc_html( $m[0] ) . '</strong></td><td>' . esc_html( $m[1] ) . '</td><td>';
				if ( in_array( $id, $mods, true ) ) {
					echo '<span class="acme-pill acme-pill--ok">On</span>';
				} else {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="acme_seo_module"><input type="hidden" name="module" value="' . esc_attr( $id ) . '">';
					wp_nonce_field( 'acme_seo_module' );
					echo '<button class="button button-small">Turn on</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		?>

		<h2>Amazon product health</h2>
		<?php
		$prods = get_posts( array( 'post_type' => ACME_SEO::settings()['product_type'], 'post_status' => 'any', 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );
		if ( ! $prods ) {
			echo '<p>No products found.</p>';
		} else {
			echo '<p class="description" style="max-width:820px">Read-only. "Not checked" is normal while the Amazon API is not connected: open each product link yourself now and then to confirm it still works.</p>';
			echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Product</th><th>ASIN</th><th>Status</th><th>Last checked</th><th>Problem</th></tr></thead><tbody>';
			foreach ( $prods as $p ) {
				$asin  = (string) get_post_meta( $p->ID, ACME_SEO::settings()['meta_asin'], true );
				$stock = (string) get_post_meta( $p->ID, ACME_SEO::settings()['meta_stock'], true );
				$chk   = (int) get_post_meta( $p->ID, ACME_SEO::settings()['meta_checked'], true );
				$prob  = array();
				if ( ! preg_match( '/^[A-Z0-9]{10}$/', $asin ) ) {
					$prob[] = 'ASIN looks wrong';
				}
				if ( 'out' === $stock || 'gone' === $stock ) {
					$prob[] = 'Unavailable on Amazon';
				}
				if ( 'publish' !== $p->post_status ) {
					$prob[] = 'Not published (' . $p->post_status . ')';
				}
				echo '<tr><td><a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></td><td><code>' . esc_html( $asin ) . '</code></td><td>' . esc_html( $stock ? $stock : 'unknown' ) . '</td><td>' . ( $chk ? esc_html( wp_date( 'M j, Y', $chk ) ) : '<span class="description">Not checked</span>' ) . '</td><td>' . ( $prob ? '<span class="acme-pill acme-pill--bad">' . esc_html( implode( ', ', $prob ) ) . '</span>' : '<span class="acme-pill acme-pill--ok">OK</span>' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		?>

		<h2>Tracking IDs</h2>
		<?php
		$main  = ACME_SEO::partner_tag();
		$extra = implode( ', ', ACME_SEO::extra_tags() );
		?>
		<table class="widefat striped" style="max-width:820px"><tbody>
			<tr><th style="width:240px">Main tracking ID</th><td><code><?php echo esc_html( $main ? $main : 'not set' ); ?></code> on all links</td></tr>
			<tr><th>Extra tracking IDs (per section)</th><td><?php echo $extra ? '<code>' . esc_html( $extra ) . '</code>' : 'none: every link uses the main ID'; ?></td></tr>
		</tbody></table>
		<p class="description" style="max-width:820px">Earnings are only shown inside Amazon Associates Central (Reports &rarr; Tracking ID Summary). This plugin does not receive them. It only makes sure each section's links carry the ID you chose.</p>

		<h2>Change log</h2>
		<?php
		$log = (array) get_option( self::LOG, array() );
		if ( ! $log ) {
			echo '<p>Nothing recorded yet.</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:820px"><thead><tr><th style="width:170px">When</th><th style="width:120px">Who</th><th>What</th></tr></thead><tbody>';
			foreach ( array_slice( $log, 0, 40 ) as $l ) {
				echo '<tr><td>' . esc_html( wp_date( 'M j, Y g:i a', (int) $l['t'] ) ) . '</td><td>' . esc_html( $l['u'] ) . '</td><td>' . esc_html( $l['n'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}
}
