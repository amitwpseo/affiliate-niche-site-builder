<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monitor tab: verified Googlebot/Bingbot visits, 404 log with a one-click fix, redirect tester,
 * temporary old-URL sitemap, sitemap health check.
 *
 * Privacy: no IP address is stored (only a yes/no result of the bot check), no cookies, no outside services.
 * Limit: LiteSpeed serves cached pages without running WordPress, so only responses that are NOT cached
 * (redirects, 404s, 410s) can be seen here. For crawling of normal pages use Search Console > Settings > Crawl stats.
 */
class ACME_SEO_Monitor {

	const DBV   = '1';
	const OPT   = 'acme_seo_mon';
	const CRON  = 'acme_seo_daily';
	const KEEP  = 5000; // most rows kept in the URL table

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ), 20 );
		add_action( 'init', array( __CLASS__, 'maybe_old_sitemap' ), 1 );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'template_redirect', array( __CLASS__, 'log_404' ), 99 );
		add_action( self::CRON, array( __CLASS__, 'daily' ) );
		add_action( 'admin_post_acme_seo_mon_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_acme_seo_fix_404', array( __CLASS__, 'handle_fix' ) );
		add_action( 'admin_post_acme_seo_test_redirects', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_acme_seo_check_sitemap', array( __CLASS__, 'handle_sitemap' ) );
		add_action( 'admin_post_acme_seo_csv', array( __CLASS__, 'handle_csv' ) );
	}

	/* -------------------------------------------------------------- data */

	private static function urls_t() {
		global $wpdb;
		return $wpdb->prefix . 'acme_seo_urls';
	}

	private static function bots_t() {
		global $wpdb;
		return $wpdb->prefix . 'acme_seo_bots';
	}

	public static function settings() {
		return wp_parse_args(
			(array) get_option( self::OPT, array() ),
			array( 'ip_source' => 'remote_addr', 'retention' => 30, 'move_date' => gmdate( 'Y-m-d', (int) get_option( 'acme_seo_installed', time() ) ), 'old_sitemap' => 1, 'log_404' => 1 )
		);
	}

	public static function maybe_install() {
		if ( get_option( 'acme_seo_db' ) === self::DBV ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::urls_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  path_hash char(32) NOT NULL,
  path text NOT NULL,
  code smallint(5) NOT NULL DEFAULT 404,
  target varchar(255) NOT NULL DEFAULT '',
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  first_hit datetime NOT NULL,
  last_hit datetime NOT NULL,
  g_hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_g datetime NULL DEFAULT NULL,
  b_hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_b datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY path_hash (path_hash),
  KEY code (code),
  KEY last_hit (last_hit)
) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::bots_t() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  hit_at datetime NOT NULL,
  bot varchar(10) NOT NULL DEFAULT '',
  code smallint(5) NOT NULL DEFAULT 0,
  path text NOT NULL,
  PRIMARY KEY  (id),
  KEY hit_at (hit_at)
) {$c};"
		);
		update_option( 'acme_seo_db', self::DBV, false );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/* ------------------------------------------------------------- bots */

	private static function client_ip() {
		$src = self::settings()['ip_source'];
		$h   = 'REMOTE_ADDR';
		if ( 'cf' === $src ) {
			$h = 'HTTP_CF_CONNECTING_IP';
		} elseif ( 'xff' === $src ) {
			$h = 'HTTP_X_FORWARDED_FOR';
		}
		$v = isset( $_SERVER[ $h ] ) ? trim( explode( ',', (string) $_SERVER[ $h ] )[0] ) : ''; // phpcs:ignore
		return filter_var( $v, FILTER_VALIDATE_IP ) ? $v : '';
	}

	/** 'google', 'bing' or '' (not a bot, or a fake that failed the DNS check). Only runs when the user agent claims to be a bot. */
	public static function bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore
		if ( '' === $ua ) {
			return '';
		}
		if ( false !== strpos( $ua, 'googlebot' ) || false !== strpos( $ua, 'google-inspectiontool' ) || false !== strpos( $ua, 'storebot-google' ) ) {
			$name = 'google';
			$suf  = array( '.googlebot.com', '.google.com', '.googleusercontent.com' );
		} elseif ( false !== strpos( $ua, 'bingbot' ) ) {
			$name = 'bing';
			$suf  = array( '.search.msn.com' );
		} else {
			return '';
		}
		$ip = self::client_ip();
		if ( '' === $ip ) {
			return '';
		}
		$key = 'acme_seo_bot_' . md5( $ip . $name );
		$hit = get_transient( $key );
		if ( false === $hit ) {
			$hit = self::verify_ip( $ip, $suf ) ? 'yes' : 'no';
			set_transient( $key, $hit, 12 * HOUR_IN_SECONDS );
		}
		return 'yes' === $hit ? $name : '';
	}

	/** Reverse DNS must end in a Google/Microsoft host name, and the forward lookup must return the same address. */
	private static function verify_ip( $ip, array $suffixes ) {
		$host = @gethostbyaddr( $ip ); // phpcs:ignore
		if ( ! $host || $host === $ip ) {
			return false;
		}
		$host = strtolower( $host );
		$ok   = false;
		foreach ( $suffixes as $s ) {
			if ( substr( $host, -strlen( $s ) ) === $s ) {
				$ok = true;
			}
		}
		if ( ! $ok ) {
			return false;
		}
		if ( false !== strpos( $ip, ':' ) ) {
			$recs = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore
			foreach ( (array) $recs as $r ) {
				if ( isset( $r['ipv6'] ) && @inet_pton( $r['ipv6'] ) === @inet_pton( $ip ) ) { // phpcs:ignore
					return true;
				}
			}
			return false;
		}
		$fwd = @gethostbynamel( $host ); // phpcs:ignore
		return is_array( $fwd ) && in_array( $ip, $fwd, true );
	}

	/* ---------------------------------------------------------- logging */

	/** Record one visit to a redirect (301), gone (410) or not-found (404) address. */
	public static function log_hit( $path, $code, $target = '' ) {
		if ( is_user_logged_in() || is_admin() ) {
			return;
		}
		global $wpdb;
		$t    = self::urls_t();
		$path = substr( (string) $path, 0, 1000 );
		$hash = md5( $code . '|' . $path );
		$now  = current_time( 'mysql', true );
		$bot  = self::bot();
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$t} WHERE path_hash = %s", $hash ) );
		if ( $row ) {
			$sql = "UPDATE {$t} SET hits = hits + 1, last_hit = %s, target = %s";
			$arg = array( $now, substr( (string) $target, 0, 255 ) );
			if ( 'google' === $bot ) {
				$sql .= ', g_hits = g_hits + 1, last_g = %s';
				$arg[] = $now;
			} elseif ( 'bing' === $bot ) {
				$sql .= ', b_hits = b_hits + 1, last_b = %s';
				$arg[] = $now;
			}
			$arg[] = (int) $row->id;
			$wpdb->query( $wpdb->prepare( $sql . ' WHERE id = %d', $arg ) ); // phpcs:ignore
		} else {
			$wpdb->insert(
				$t,
				array(
					'path_hash' => $hash,
					'path'      => $path,
					'code'      => (int) $code,
					'target'    => substr( (string) $target, 0, 255 ),
					'hits'      => 1,
					'first_hit' => $now,
					'last_hit'  => $now,
					'g_hits'    => 'google' === $bot ? 1 : 0,
					'last_g'    => 'google' === $bot ? $now : null,
					'b_hits'    => 'bing' === $bot ? 1 : 0,
					'last_b'    => 'bing' === $bot ? $now : null,
				)
			);
		}
		if ( '' !== $bot ) {
			$wpdb->insert( self::bots_t(), array( 'hit_at' => $now, 'bot' => $bot, 'code' => (int) $code, 'path' => $path ) );
		}
	}

	public static function log_404() {
		if ( ! is_404() || ! self::settings()['log_404'] || ! empty( $_POST ) ) { // phpcs:ignore
			return;
		}
		$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore
		if ( '' === $req ) {
			return;
		}
		self::log_hit( ACME_SEO_Redirects::norm( $req ), 404 );
	}

	public static function daily() {
		global $wpdb;
		$days = max( 7, (int) self::settings()['retention'] );
		$cut  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::bots_t() . ' WHERE hit_at < %s', $cut ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::urls_t() . ' WHERE last_hit < %s AND hits < 3', gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
		$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::urls_t() ); // phpcs:ignore
		if ( $n > self::KEEP ) {
			$wpdb->query( 'DELETE FROM ' . self::urls_t() . ' ORDER BY last_hit ASC LIMIT ' . (int) ( $n - self::KEEP ) ); // phpcs:ignore
		}
		self::check_sitemap();
		if ( class_exists( 'ACME_SEO_Tools' ) ) {
			ACME_SEO_Tools::maybe_weekly();
		}
	}

	/* ------------------------------------------------------ old sitemap */

	public static function old_sitemap_active() {
		$s = self::settings();
		if ( empty( $s['old_sitemap'] ) ) {
			return false;
		}
		return gmdate( 'Y-m-d' ) <= gmdate( 'Y-m-d', strtotime( $s['move_date'] . ' +45 days' ) );
	}

	public static function maybe_old_sitemap() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' ) : ''; // phpcs:ignore
		if ( 'old-urls.xml' !== $path || ! self::old_sitemap_active() ) {
			return;
		}
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, follow', true );
		$lm = gmdate( 'c', strtotime( self::settings()['move_date'] . ' 00:00:00' ) );
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( ACME_SEO_Redirects::rules() as $from => $r ) {
			echo '<url><loc>' . esc_url( home_url( $from ) ) . '</loc><lastmod>' . esc_html( $lm ) . '</lastmod></url>' . "\n";
		}
		echo '</urlset>';
		exit;
	}

	/* -------------------------------------------------- redirect tester */

	/** Follow a URL by hand for up to 5 hops. Returns array( chain[], final_code, final_url ). */
	private static function follow( $url ) {
		$chain = array();
		$cur   = $url;
		$code  = 0;
		for ( $i = 0; $i < 6; $i++ ) {
			$res = wp_remote_get( $cur, array( 'redirection' => 0, 'timeout' => 15, 'user-agent' => 'Site redirect test', 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
			if ( is_wp_error( $res ) ) {
				return array( $chain, 0, $cur );
			}
			$code  = (int) wp_remote_retrieve_response_code( $res );
			$loc   = wp_remote_retrieve_header( $res, 'location' );
			$chain[] = array( $cur, $code );
			if ( $code >= 300 && $code < 400 && $loc ) {
				$cur = ( 0 === strpos( $loc, 'http' ) ) ? $loc : home_url( $loc );
				continue;
			}
			break;
		}
		return array( $chain, $code, $cur );
	}

	public static function run_redirect_test() {
		@set_time_limit( 180 ); // phpcs:ignore
		$rows = array();
		foreach ( ACME_SEO_Redirects::rules() as $from => $r ) {
			list( $chain, $final, $url ) = self::follow( home_url( $from ) );
			$first = $chain ? (int) $chain[0][1] : 0;
			$hops  = max( 0, count( $chain ) - 1 );
			if ( 410 === (int) $r['code'] ) {
				$ok  = 410 === $first;
				$msg = $ok ? 'Returns 410 Gone' : 'Expected 410, got ' . $first;
			} elseif ( ! ACME_SEO_Redirects::target_live( $r['to'] ) ) {
				$ok  = true;
				$msg = 'Paused: target is not published';
			} else {
				$ok  = 301 === $first && 1 === $hops && 200 === $final;
				$msg = $ok ? 'One 301 to a live page' : ( 301 !== $first ? 'First step is ' . $first . ', expected 301' : ( $hops > 1 ? 'Redirect chain of ' . $hops . ' steps' : 'Ends with ' . $final ) );
			}
			$rows[] = array( 'from' => $from, 'to' => $r['to'], 'ok' => $ok, 'msg' => $msg, 'final' => $url );
		}
		update_option( 'acme_seo_redirect_test', array( 'time' => time(), 'rows' => $rows ), false );
		return $rows;
	}

	/* -------------------------------------------------- sitemap health */

	public static function check_sitemap() {
		@set_time_limit( 240 ); // phpcs:ignore
		$res = wp_remote_get( home_url( '/sitemap_index.xml' ), array( 'timeout' => 20, 'redirection' => 3 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			update_option( 'acme_seo_sitemap_health', array( 'time' => time(), 'error' => 'The sitemap could not be read (/sitemap_index.xml).', 'rows' => array() ), false );
			return;
		}
		preg_match_all( '#<loc>(.*?)</loc>#', wp_remote_retrieve_body( $res ), $m );
		$urls = array();
		foreach ( array_slice( $m[1], 0, 8 ) as $sm ) {
			$r = wp_remote_get( $sm, array( 'timeout' => 20 ) );
			if ( is_wp_error( $r ) ) {
				continue;
			}
			preg_match_all( '#<loc>(.*?)</loc>#', wp_remote_retrieve_body( $r ), $mm );
			$urls = array_merge( $urls, $mm[1] );
		}
		$urls = array_slice( array_unique( $urls ), 0, 120 );
		$rows = array();
		foreach ( $urls as $u ) {
			$r = wp_remote_get( $u, array( 'redirection' => 0, 'timeout' => 15, 'user-agent' => 'Site sitemap check' ) );
			if ( is_wp_error( $r ) ) {
				$rows[] = array( 'url' => $u, 'code' => 0, 'issue' => 'Request failed' );
				continue;
			}
			$code  = (int) wp_remote_retrieve_response_code( $r );
			$body  = wp_remote_retrieve_body( $r );
			$issue = '';
			if ( 200 !== $code ) {
				$issue = 'Returns ' . $code . ' (a sitemap should list only 200 pages)';
			} else {
				if ( preg_match( '#<meta name="robots" content="[^"]*noindex#i', $body ) ) {
					$issue = 'Has noindex';
				} elseif ( preg_match( '#<link rel="canonical" href="([^"]+)"#', $body, $c ) && untrailingslashit( $c[1] ) !== untrailingslashit( $u ) ) {
					$issue = 'Canonical points elsewhere: ' . $c[1];
				}
			}
			$rows[] = array( 'url' => $u, 'code' => $code, 'issue' => $issue );
		}
		update_option( 'acme_seo_sitemap_health', array( 'time' => time(), 'rows' => $rows ), false );
	}

	/* ---------------------------------------------------------- handlers */

	private static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to do this.', 403 );
		}
		check_admin_referer( $nonce );
	}

	public static function handle_save() {
		self::guard( 'acme_seo_mon' );
		$ip = isset( $_POST['ip_source'] ) ? sanitize_key( wp_unslash( $_POST['ip_source'] ) ) : 'remote_addr'; // phpcs:ignore
		$md = isset( $_POST['move_date'] ) ? sanitize_text_field( wp_unslash( $_POST['move_date'] ) ) : ''; // phpcs:ignore
		update_option(
			self::OPT,
			array(
				'ip_source'   => in_array( $ip, array( 'remote_addr', 'cf', 'xff' ), true ) ? $ip : 'remote_addr',
				'retention'   => isset( $_POST['retention'] ) ? max( 7, min( 90, (int) $_POST['retention'] ) ) : 30, // phpcs:ignore
				'move_date'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $md ) ? $md : self::settings()['move_date'],
				'old_sitemap' => empty( $_POST['old_sitemap'] ) ? 0 : 1, // phpcs:ignore
				'log_404'     => empty( $_POST['log_404'] ) ? 0 : 1, // phpcs:ignore
			),
			false
		);
		ACME_SEO_Tools::log( 'Saved Monitor settings' );
		self::done( 'Monitor settings saved.' );
	}

	public static function handle_fix() {
		self::guard( 'acme_seo_fix' );
		$from = isset( $_POST['from'] ) ? ACME_SEO_Redirects::norm( sanitize_text_field( wp_unslash( $_POST['from'] ) ) ) : ''; // phpcs:ignore
		$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : ''; // phpcs:ignore
		if ( '' === $from || '' === $to ) {
			self::done( 'Choose where the address should go.' );
		}
		ACME_SEO_Redirects::add_custom_rule( $from, '410' === $to ? '410' : $to );
		ACME_SEO_Tools::log( 'Added rule ' . $from . ' => ' . $to );
		self::done( 'Rule added: ' . $from . ' => ' . ( '410' === $to ? 'Gone (410)' : $to ) );
	}

	public static function handle_test() {
		self::guard( 'acme_seo_mon' );
		self::run_redirect_test();
		self::done( 'Redirect test finished.' );
	}

	public static function handle_sitemap() {
		self::guard( 'acme_seo_mon' );
		self::check_sitemap();
		self::done( 'Sitemap check finished.' );
	}

	private static function done( $msg ) {
		set_transient( ACME_SEO::RESULT, array( $msg ), 60 );
		wp_safe_redirect( ACME_SEO::url( 'monitor' ) );
		exit;
	}

	public static function handle_csv() {
		self::guard( 'acme_seo_mon' );
		global $wpdb;
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'urls'; // phpcs:ignore
		$rows = array();
		if ( 'urls' === $type ) {
			$rows[] = array( 'Address', 'Code', 'Goes to', 'Visits', 'First seen', 'Last seen', 'Googlebot visits', 'Last Googlebot', 'Bingbot visits', 'Last Bingbot' );
			foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::urls_t() . ' ORDER BY last_hit DESC', ARRAY_A ) as $r ) { // phpcs:ignore
				$rows[] = array( $r['path'], $r['code'], $r['target'], $r['hits'], $r['first_hit'], $r['last_hit'], $r['g_hits'], $r['last_g'], $r['b_hits'], $r['last_b'] );
			}
		} elseif ( 'tests' === $type ) {
			$rows[] = array( 'Old address', 'Goes to', 'Result', 'OK', 'Final address' );
			$t = get_option( 'acme_seo_redirect_test', array() );
			foreach ( (array) ( $t['rows'] ?? array() ) as $r ) {
				$rows[] = array( $r['from'], (string) $r['to'], $r['msg'], $r['ok'] ? 'yes' : 'no', $r['final'] );
			}
		} else {
			$rows[] = array( 'Time (UTC)', 'Bot', 'Code', 'Address' );
			foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::bots_t() . ' ORDER BY hit_at DESC LIMIT 5000', ARRAY_A ) as $r ) { // phpcs:ignore
				$rows[] = array( $r['hit_at'], $r['bot'], $r['code'], $r['path'] );
			}
		}
		ACME_SEO_Tools::send_csv( 'site-' . $type . '-' . gmdate( 'Y-m-d' ) . '.csv', $rows );
	}

	/* -------------------------------------------------------------- UI */

	public static function page() {
		global $wpdb;
		$s     = self::settings();
		$ut    = self::urls_t();
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$g30   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::bots_t() . " WHERE bot = 'google' AND hit_at >= %s", $since ) ); // phpcs:ignore
		$b30   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::bots_t() . " WHERE bot = 'bing' AND hit_at >= %s", $since ) ); // phpcs:ignore
		$n404  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ut} WHERE code = 404 AND last_hit >= %s", $since ) ); // phpcs:ignore
		$redir = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ut} WHERE code IN (301,410) AND last_hit >= %s", $since ) ); // phpcs:ignore
		$keep  = gmdate( 'Y-m-d', strtotime( $s['move_date'] . ' +180 days' ) );
		$days  = (int) floor( ( strtotime( $keep ) - time() ) / DAY_IN_SECONDS );
		$health = (array) get_option( 'acme_seo_sitemap_health', array() );
		$bad    = 0;
		foreach ( (array) ( $health['rows'] ?? array() ) as $r ) {
			$bad += '' === $r['issue'] ? 0 : 1;
		}
		$test = (array) get_option( 'acme_seo_redirect_test', array() );
		?>
		<div class="acme-cards">
			<div class="acme-card acme-card--<?php echo $g30 ? 'ok' : 'warn'; ?>"><span class="acme-card__k">Verified Googlebot visits</span><strong class="acme-card__v"><?php echo (int) $g30; ?></strong><span class="acme-card__s">last 30 days, uncached pages</span></div>
			<div class="acme-card acme-card--ok"><span class="acme-card__k">Verified Bingbot visits</span><strong class="acme-card__v"><?php echo (int) $b30; ?></strong><span class="acme-card__s">last 30 days</span></div>
			<div class="acme-card acme-card--<?php echo $n404 ? 'warn' : 'ok'; ?>"><span class="acme-card__k">Not found (404)</span><strong class="acme-card__v"><?php echo (int) $n404; ?></strong><span class="acme-card__s">addresses, last 30 days</span></div>
			<div class="acme-card acme-card--ok"><span class="acme-card__k">Old addresses used</span><strong class="acme-card__v"><?php echo (int) $redir; ?></strong><span class="acme-card__s">redirects and 410s hit</span></div>
			<div class="acme-card acme-card--<?php echo $days > 30 ? 'ok' : 'warn'; ?>"><span class="acme-card__k">Keep redirects until</span><strong class="acme-card__v"><?php echo esc_html( wp_date( 'M j, Y', strtotime( $keep ) ) ); ?></strong><span class="acme-card__s"><?php echo $days > 0 ? (int) $days . ' days left' : 'period over'; ?> (Google advises 180+ days)</span></div>
			<div class="acme-card acme-card--<?php echo $health && ! $bad ? 'ok' : 'warn'; ?>"><span class="acme-card__k">Sitemap health</span><strong class="acme-card__v"><?php echo $health ? ( $bad ? (int) $bad . ' issue(s)' : 'Clean' ) : 'Not run'; ?></strong><span class="acme-card__s"><?php echo ! empty( $health['time'] ) ? esc_html( wp_date( 'M j, g:i a', (int) $health['time'] ) ) : 'press Check now'; ?></span></div>
		</div>
		<p class="description" style="max-width:820px">Pages that are served from the cache (most normal page views) never reach WordPress, so no plugin can see Googlebot on them. This tab sees redirects, 404s and 410s, which are the pages that matter after a move. For crawling of your normal pages, use Search Console &rarr; Settings &rarr; Crawl stats.</p>

		<h2>Old addresses and 404s</h2>
		<?php
		$rows = $wpdb->get_results( "SELECT * FROM {$ut} ORDER BY (code = 404) DESC, g_hits DESC, hits DESC, last_hit DESC LIMIT 80" ); // phpcs:ignore
		if ( ! $rows ) {
			echo '<p>No visits recorded yet. Visits to old addresses and missing pages will appear here.</p>';
		} else {
			$pages = get_posts( array( 'post_type' => ACME_SEO::content_types(), 'post_status' => 'publish', 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );
			echo '<table class="widefat striped"><thead><tr><th>Address</th><th>Result</th><th>Visits</th><th>Googlebot</th><th>Bingbot</th><th>Last seen (UTC)</th><th>Fix</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><td><code>' . esc_html( $r->path ) . '</code>' . ( $r->target ? '<br><span class="description">&rarr; ' . esc_html( $r->target ) . '</span>' : '' ) . '</td>';
				echo '<td>' . ( 404 === (int) $r->code ? '<span class="acme-pill acme-pill--bad">404</span>' : '<span class="acme-pill acme-pill--ok">' . (int) $r->code . '</span>' ) . '</td>';
				echo '<td>' . (int) $r->hits . '</td>';
				echo '<td>' . ( $r->g_hits ? '<strong>' . (int) $r->g_hits . '</strong><br><span class="description">' . esc_html( $r->last_g ) . '</span>' : '-' ) . '</td>';
				echo '<td>' . ( $r->b_hits ? (int) $r->b_hits : '-' ) . '</td><td>' . esc_html( $r->last_hit ) . '</td><td>';
				if ( 404 === (int) $r->code ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:6px;flex-wrap:wrap">';
					echo '<input type="hidden" name="action" value="acme_seo_fix_404"><input type="hidden" name="from" value="' . esc_attr( $r->path ) . '">';
					wp_nonce_field( 'acme_seo_fix' );
					echo '<select name="to" style="max-width:190px"><option value="410">Gone (410)</option><option value="/">Home page</option>';
					foreach ( $pages as $p ) {
						echo '<option value="' . esc_attr( wp_make_link_relative( get_permalink( $p ) ) ) . '">' . esc_html( get_the_title( $p ) ) . '</option>';
					}
					echo '</select><button class="button button-small">Fix</button></form>';
				} else {
					echo '-';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		?>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acme_seo_csv&type=urls' ), 'acme_seo_mon' ) ); ?>">Download CSV</a>
		<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acme_seo_csv&type=bots' ), 'acme_seo_mon' ) ); ?>">Verified bot visits (CSV)</a></p>

		<h2>Redirect tester</h2>
		<p>Checks that every old address returns one 301 straight to a live page (or 410 where planned), with no chains, loops or dead ends.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="acme_seo_test_redirects"><?php wp_nonce_field( 'acme_seo_mon' ); ?>
			<?php submit_button( 'Test all redirects', 'secondary', 'submit', false ); ?>
			<?php if ( ! empty( $test['time'] ) ) : ?> <span class="description">Last run <?php echo esc_html( wp_date( 'M j, g:i a', (int) $test['time'] ) ); ?></span><?php endif; ?>
		</form>
		<?php if ( ! empty( $test['rows'] ) ) : ?>
			<table class="widefat striped" style="margin-top:10px"><thead><tr><th>Old address</th><th>Goes to</th><th>Result</th></tr></thead><tbody>
			<?php foreach ( $test['rows'] as $r ) : ?>
				<tr><td><code><?php echo esc_html( $r['from'] ); ?></code></td><td><?php echo null === $r['to'] ? '<em>410</em>' : '<code>' . esc_html( $r['to'] ) . '</code>'; ?></td><td><?php echo $r['ok'] ? '<span class="acme-pill acme-pill--ok">OK</span> ' : '<span class="acme-pill acme-pill--bad">Problem</span> '; echo esc_html( $r['msg'] ); // phpcs:ignore ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<p><a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acme_seo_csv&type=tests' ), 'acme_seo_mon' ) ); ?>">Download test results (CSV)</a></p>
		<?php endif; ?>

		<h2>Sitemap health</h2>
		<p>Every address in your sitemap should return 200, be indexable and point to itself as the canonical. It also runs once a day.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="acme_seo_check_sitemap"><?php wp_nonce_field( 'acme_seo_mon' ); ?>
			<?php submit_button( 'Check now', 'secondary', 'submit', false ); ?>
		</form>
		<?php
		if ( ! empty( $health['error'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $health['error'] ) . '</p></div>';
		} elseif ( ! empty( $health['rows'] ) ) {
			echo '<p>' . (int) count( $health['rows'] ) . ' addresses checked. ' . ( $bad ? '<strong>' . (int) $bad . ' need attention:</strong>' : 'All clean.' ) . '</p>';
			if ( $bad ) {
				echo '<table class="widefat striped"><thead><tr><th>Address</th><th>Code</th><th>Issue</th></tr></thead><tbody>';
				foreach ( $health['rows'] as $r ) {
					if ( '' !== $r['issue'] ) {
						echo '<tr><td><code>' . esc_html( $r['url'] ) . '</code></td><td>' . (int) $r['code'] . '</td><td>' . esc_html( $r['issue'] ) . '</td></tr>';
					}
				}
				echo '</tbody></table>';
			}
		}
		?>

		<h2>Move settings</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="acme_seo_mon_save"><?php wp_nonce_field( 'acme_seo_mon' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="move_date">Date the new site went live</label></th><td><input type="date" id="move_date" name="move_date" value="<?php echo esc_attr( $s['move_date'] ); ?>"><p class="description">Used for the 180-day redirect reminder and the old-address sitemap.</p></td></tr>
				<tr><th scope="row">Old-address sitemap</th><td><label><input type="checkbox" name="old_sitemap" value="1" <?php checked( (int) $s['old_sitemap'], 1 ); ?>> Publish <code><?php echo esc_html( home_url( '/old-urls.xml' ) ); ?></code> for 45 days after the move</label><p class="description">Lists the old addresses so Google re-crawls them and finds the redirects sooner. Submit it in Search Console, then remove it when the time is up. Warnings about redirecting URLs in it are normal.</p></td></tr>
				<tr><th scope="row">Log 404 pages</th><td><label><input type="checkbox" name="log_404" value="1" <?php checked( (int) $s['log_404'], 1 ); ?>> Record addresses that are not found (visitors who are not logged in)</label></td></tr>
				<tr><th scope="row"><label for="retention">Keep bot visits for</label></th><td><input type="number" id="retention" name="retention" min="7" max="90" value="<?php echo (int) $s['retention']; ?>" class="small-text"> days</td></tr>
				<tr><th scope="row"><label for="ip_source">Where the visitor's address comes from</label></th><td><select id="ip_source" name="ip_source"><option value="remote_addr" <?php selected( $s['ip_source'], 'remote_addr' ); ?>>Server (default)</option><option value="cf" <?php selected( $s['ip_source'], 'cf' ); ?>>Cloudflare header</option><option value="xff" <?php selected( $s['ip_source'], 'xff' ); ?>>X-Forwarded-For header</option></select><p class="description">Only change this if the Googlebot count stays at zero even though Search Console shows crawling. No address is stored; it is only used to check the visitor really is Googlebot or Bingbot.</p></td></tr>
			</table>
			<?php submit_button( 'Save Monitor settings', 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}
}
