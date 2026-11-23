<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site audit: opens every published page and post the way a visitor would and checks SEO basics,
 * schema, affiliate rules and honesty rules, then builds an internal-link report.
 * Runs a few pages per request from the admin screen, so it never times out. Nothing is changed on the site.
 */
class ACME_SEO_Audit {

	const RES   = 'acme_seo_audit_results';
	const QUEUE = 'acme_seo_audit_queue';
	const LINKS = 'acme_seo_audit_links';
	const SIZE  = 4;

	public static function init() {
		add_action( 'wp_ajax_acme_seo_audit_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_acme_seo_audit_batch', array( __CLASS__, 'ajax_batch' ) );
		add_action( 'admin_post_acme_seo_audit_csv', array( __CLASS__, 'handle_csv' ) );
	}

	private static function ajax_guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Not allowed.', 403 );
		}
		check_ajax_referer( 'acme_seo_audit', 'nonce' );
	}

	public static function ajax_start() {
		self::ajax_guard();
		$ids = get_posts(
			array(
				'post_type'   => ACME_SEO::content_types(),
				'post_status' => 'publish',
				'numberposts' => 300,
				'fields'      => 'ids',
				'orderby'     => 'type title',
				'order'       => 'ASC',
			)
		);
		set_transient( self::QUEUE, array_map( 'intval', $ids ), HOUR_IN_SECONDS );
		update_option( self::RES, array( 'time' => time(), 'done' => false, 'pages' => array(), 'links' => array() ), false );
		update_option( self::LINKS, array(), false );
		wp_send_json_success( array( 'total' => count( $ids ) ) );
	}

	public static function ajax_batch() {
		self::ajax_guard();
		@set_time_limit( 120 ); // phpcs:ignore
		$queue  = (array) get_transient( self::QUEUE );
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0; // phpcs:ignore
		$res    = (array) get_option( self::RES, array() );
		$links  = (array) get_option( self::LINKS, array() );
		foreach ( array_slice( $queue, $offset, self::SIZE ) as $id ) {
			$r = self::audit_page( (int) $id );
			if ( $r ) {
				$links[ $id ]  = $r['links'];
				unset( $r['links'] );
				$res['pages'][ $id ] = $r;
			}
		}
		$next = $offset + self::SIZE;
		$done = $next >= count( $queue );
		update_option( self::LINKS, $links, false );
		if ( $done ) {
			$res['links'] = self::link_report( $res['pages'], $links );
			$res['done']  = true;
			$res['time']  = time();
		}
		update_option( self::RES, $res, false );
		wp_send_json_success( array( 'next' => $next, 'total' => count( $queue ), 'done' => $done ) );
	}

	/* ------------------------------------------------------------ one page */

	private static function text( $html ) {
		return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html ) ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	private static function partner_tag() {
		return ACME_SEO::partner_tag();
	}

	private static function extra_tag() {
		return implode( '|', array_map( function ( $t ) {
			return preg_quote( $t, '#' );
		}, ACME_SEO::extra_tags() ) );
	}

	private static function audit_page( $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		$url = get_permalink( $id );
		$res = wp_remote_get( $url, array( 'timeout' => 25, 'redirection' => 0, 'user-agent' => 'Site audit', 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		$out = array( 'id' => $id, 'url' => $url, 'title' => get_the_title( $id ), 'type' => $post->post_type, 'issues' => array(), 'links' => array(), 'amazon' => 0 );
		if ( is_wp_error( $res ) ) {
			$out['issues'][] = array( 'bad', 'Page could not be opened: ' . $res->get_error_message() );
			return $out;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$out['issues'][] = array( 'bad', 'Page returns ' . $code . ' instead of 200' );
			return $out;
		}
		$html = (string) wp_remote_retrieve_body( $res );
		$i    = array();

		// Title and description.
		$title = '';
		if ( preg_match( '#<title>(.*?)</title>#is', $html, $m ) ) {
			$title = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}
		$tl = mb_strlen( $title );
		if ( ! $tl ) {
			$i[] = array( 'bad', 'No title tag' );
		} elseif ( $tl < 30 || $tl > 65 ) {
			$i[] = array( 'warn', 'Title is ' . $tl . ' characters (aim for 30 to 65)' );
		}
		preg_match_all( '#<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)["\']#i', $html, $dm );
		if ( ! $dm[1] ) {
			$i[] = array( 'bad', 'No meta description' );
		} else {
			if ( count( $dm[1] ) > 1 ) {
				$i[] = array( 'bad', count( $dm[1] ) . ' meta descriptions on the page (should be one)' );
			}
			$dl = mb_strlen( html_entity_decode( $dm[1][0], ENT_QUOTES, 'UTF-8' ) );
			if ( $dl < 110 || $dl > 165 ) {
				$i[] = array( 'warn', 'Meta description is ' . $dl . ' characters (aim for 110 to 165)' );
			}
		}
		$h1 = preg_match_all( '#<h1\b#i', $html );
		if ( 1 !== $h1 ) {
			$i[] = array( 'bad', $h1 . ' H1 headings (should be exactly one)' );
		}
		if ( preg_match( '#<meta\s+name=["\']robots["\']\s+content=["\'][^"\']*noindex#i', $html ) ) {
			$i[] = array( 'bad', 'Page has noindex but is published' );
		}
		if ( preg_match( '#<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)#i', $html, $c ) ) {
			if ( untrailingslashit( $c[1] ) !== untrailingslashit( $url ) ) {
				$i[] = array( 'warn', 'Canonical points to ' . $c[1] );
			}
		} else {
			$i[] = array( 'warn', 'No canonical link' );
		}

		// Main region.
		$main = $html;
		if ( preg_match( '#<main\b.*?</main>#is', $html, $mm ) ) {
			$main = $mm[0];
		}
		$mtext = self::text( $main );

		// Schema.
		$types = array();
		$faqq  = array();
		$all    = array();
		preg_match_all( '#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#is', $html, $sc );
		foreach ( $sc[1] as $json ) {
			$j = json_decode( $json, true );
			if ( null === $j ) {
				$i[] = array( 'bad', 'A JSON-LD block is not valid JSON' );
				continue;
			}
			$nodes = isset( $j['@graph'] ) ? $j['@graph'] : ( isset( $j[0] ) ? $j : array( $j ) );
			foreach ( (array) $nodes as $n ) {
				if ( ! is_array( $n ) || empty( $n['@type'] ) ) {
					continue;
				}
				foreach ( (array) $n['@type'] as $ty ) {
					$types[] = $ty;
				}
				if ( in_array( 'FAQPage', (array) $n['@type'], true ) && ! empty( $n['mainEntity'] ) ) {
					foreach ( (array) $n['mainEntity'] as $q ) {
						if ( ! empty( $q['name'] ) ) {
							$faqq[] = (string) $q['name'];
						}
					}
				}
				$all[] = $n;
			}
		}
		$out['schema'] = implode( ', ', array_unique( $types ) );
		if ( $faqq ) {
			$low = strtolower( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $main ), ENT_QUOTES, 'UTF-8' ) ) );
			$miss = 0;
			foreach ( $faqq as $q ) {
				if ( false === strpos( $low, strtolower( trim( $q ) ) ) ) {
					$miss++;
				}
			}
			if ( $miss ) {
				$i[] = array( 'bad', $miss . ' of ' . count( $faqq ) . ' FAQ questions in the schema are not shown on the page' );
			}
		}
		// Niche checks (cooking temperatures, safety notes, required schema type...). See references/niche-modules.md.
		$i = (array) apply_filters( 'acme_seo_audit_checks', $i, $post, $main, $mtext, $all );

		// Affiliate rules.
		$tag   = self::partner_tag();
		$extra = self::extra_tag();
		preg_match_all( '#<a\b([^>]*)>#i', $main, $am );
		$amz = 0;
		$bad_rel = 0;
		$bad_tag = 0;
		foreach ( $am[1] as $attrs ) {
			if ( ! preg_match( '#href=["\']([^"\']+)#i', $attrs, $h ) ) {
				continue;
			}
			$href = html_entity_decode( $h[1] );
			if ( ! preg_match( '#^https?://([a-z0-9.-]*\.)?(amazon\.com|amzn\.to)/#i', $href ) ) {
				continue;
			}
			$amz++;
			if ( ! preg_match( '#rel=["\'][^"\']*sponsored#i', $attrs ) || ! preg_match( '#rel=["\'][^"\']*nofollow#i', $attrs ) ) {
				$bad_rel++;
			}
			if ( preg_match( '#amazon\.com#i', $href ) && ! preg_match( '#[?&]tag=(' . preg_quote( $tag, '#' ) . ( $extra ? '|' . $extra : '' ) . ')(&|$)#i', $href ) ) {
				$bad_tag++;
			}
		}
		$out['amazon'] = $amz;
		if ( $bad_rel ) {
			$i[] = array( 'bad', $bad_rel . ' Amazon link(s) are missing rel="sponsored nofollow"' );
		}
		if ( $bad_tag ) {
			$i[] = array( 'bad', $bad_tag . ' Amazon link(s) do not carry your tracking ID' );
		}
		if ( $amz && ! preg_match( '/as an amazon associate|earn from qualifying|commission|affiliate/i', $html ) ) {
			$i[] = array( 'bad', 'Amazon links but no affiliate disclosure on the page' );
		}
		if ( preg_match( '#<img[^>]+src=["\'][^"\']*(m\.media-amazon\.com|images-na\.ssl-images-amazon\.com|images-amazon\.com)#i', $html ) ) {
			$i[] = array( 'bad', 'Page shows an image hosted by Amazon (not allowed unless via the official API)' );
		}

		// Honesty rules.
		$claims = '/\b(' . ACME_SEO::claim_words() . ')[- ](approved|recommended|endorsed|certified|formulated|tested)\b/i';
		if ( preg_match_all( $claims, $mtext, $cls, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $cls[0] as $cl ) {
				$before = strtolower( substr( $mtext, max( 0, $cl[1] - 60 ), min( 60, $cl[1] ) ) );
				if ( preg_match( "/\\b(not|no|never|don't|do not|does not|doesn't|isn't|aren't|without|nor)\\b/", $before ) ) {
					continue; // a negation such as "we do not call any product expert approved"
				}
				$i[] = array( 'bad', 'Claim found: "' . $cl[0] . '". Only keep it if a named professional really did this' );
				break;
			}
		}
		if ( preg_match( '/editor note|lorem ipsum|todo\b|coming soon/i', $mtext, $en ) ) {
			$i[] = array( 'warn', 'Draft-looking text on the page: "' . $en[0] . '"' );
		}
		$noalt = preg_match_all( '#<img\b(?![^>]*\balt=)[^>]*>#i', $main ); // alt="" is fine for decorative images
		if ( $noalt ) {
			$i[] = array( 'warn', $noalt . ' image(s) without alt text' );
		}

		// Internal links from the main content.
		$home = untrailingslashit( home_url() );
		$seen = array();
		foreach ( $am[1] as $attrs ) {
			if ( preg_match( '#href=["\']([^"\']+)#i', $attrs, $h ) ) {
				$href = html_entity_decode( $h[1] );
				if ( 0 === strpos( $href, $home ) || ( '/' === substr( $href, 0, 1 ) && '//' !== substr( $href, 0, 2 ) ) ) {
					$p = ACME_SEO_Redirects::norm( $href );
					if ( ! preg_match( '#^/(wp-content|wp-json|wp-admin|feed)#', $p ) && $p !== ACME_SEO_Redirects::norm( $url ) ) {
						$seen[ $p ] = 1;
					}
				}
			}
		}
		$out['links']  = array_keys( $seen );
		$out['issues'] = $i;
		$out['words']  = str_word_count( $mtext );
		return $out;
	}

	/* ----------------------------------------------------------- link report */

	private static function link_report( array $pages, array $links ) {
		$map = array();
		foreach ( $pages as $id => $p ) {
			$map[ ACME_SEO_Redirects::norm( $p['url'] ) ] = (int) $id;
		}
		$inbound = array_fill_keys( array_keys( $pages ), 0 );
		$unknown = array();
		foreach ( $links as $from => $list ) {
			foreach ( $list as $path ) {
				if ( isset( $map[ $path ] ) ) {
					$inbound[ $map[ $path ] ]++;
				} else {
					$unknown[ $path ][] = (int) $from;
				}
			}
		}
		$front   = (int) get_option( 'page_on_front' );
		$orphans = array();
		foreach ( $inbound as $id => $n ) {
			if ( 0 === $n && (int) $id !== $front ) {
				$orphans[] = (int) $id;
			}
		}
		$broken = array();
		$checked = 0;
		foreach ( $unknown as $path => $froms ) {
			if ( $checked >= 30 || '/' === $path ) {
				continue;
			}
			$checked++;
			$r = wp_remote_head( home_url( $path ), array( 'timeout' => 15, 'redirection' => 0, 'user-agent' => 'Site audit' ) );
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			if ( 200 === $code ) {
				continue;
			}
			$pid  = url_to_postid( home_url( $path ) );
			$note = $code >= 300 && $code < 400 ? 'Redirects (' . $code . '): link to the final address instead' : ( 404 === $code || 410 === $code ? 'Does not exist (' . $code . ')' : 'Returns ' . $code );
			if ( $pid && 'publish' !== get_post_status( $pid ) ) {
				$note = 'Points to an unpublished page';
			}
			$broken[] = array( 'path' => $path, 'note' => $note, 'from' => array_values( array_unique( $froms ) ) );
		}
		return array( 'inbound' => $inbound, 'orphans' => $orphans, 'broken' => $broken );
	}

	/* ----------------------------------------------------------------- CSV */

	public static function handle_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'acme_seo_audit' );
		$res  = (array) get_option( self::RES, array() );
		$rows = array( array( 'Page', 'Address', 'Type', 'Level', 'Issue' ) );
		foreach ( (array) ( $res['pages'] ?? array() ) as $p ) {
			if ( ! $p['issues'] ) {
				$rows[] = array( $p['title'], $p['url'], $p['type'], 'ok', '' );
			}
			foreach ( $p['issues'] as $is ) {
				$rows[] = array( $p['title'], $p['url'], $p['type'], 'bad' === $is[0] ? 'problem' : 'check', $is[1] );
			}
		}
		ACME_SEO_Tools::send_csv( 'acme-audit-' . gmdate( 'Y-m-d' ) . '.csv', $rows );
	}

	/* ------------------------------------------------------------------ UI */

	public static function page() {
		$res   = (array) get_option( self::RES, array() );
		$pages = (array) ( $res['pages'] ?? array() );
		$bad   = 0;
		$warn  = 0;
		$clean = 0;
		foreach ( $pages as $p ) {
			$b = 0;
			$w = 0;
			foreach ( $p['issues'] as $is ) {
				'bad' === $is[0] ? $b++ : $w++;
			}
			$bad   += $b;
			$warn  += $w;
			$clean += ( $b || $w ) ? 0 : 1;
		}
		$lr = (array) ( $res['links'] ?? array() );
		?>
		<p style="max-width:820px">Opens every published page and post the way a visitor does and checks SEO basics, schema, affiliate rules (disclosure, <code>sponsored</code> links, your tracking ID, no Amazon-hosted pictures) and honesty rules (no unproven expert claims, no draft text) and any niche checks you added. It changes nothing on your site.</p>
		<p>
			<button type="button" class="button button-primary" id="acme-audit-run" data-nonce="<?php echo esc_attr( wp_create_nonce( 'acme_seo_audit' ) ); ?>">Run site audit</button>
			<?php if ( $pages ) : ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=acme_seo_audit_csv' ), 'acme_seo_audit' ) ); ?>">Download CSV</a>
				<span class="description">Last run <?php echo esc_html( wp_date( 'M j, g:i a', (int) ( $res['time'] ?? time() ) ) ); ?></span>
			<?php endif; ?>
			<span id="acme-audit-status" class="description" style="margin-left:10px"></span>
		</p>
		<?php
		if ( ! $pages ) {
			echo '<p>No audit yet. Press "Run site audit". It takes about a minute.</p>';
			return;
		}
		?>
		<div class="acme-cards">
			<div class="acme-card acme-card--ok"><span class="acme-card__k">Pages checked</span><strong class="acme-card__v"><?php echo (int) count( $pages ); ?></strong><span class="acme-card__s"><?php echo (int) $clean; ?> with nothing to fix</span></div>
			<div class="acme-card acme-card--<?php echo $bad ? 'bad' : 'ok'; ?>"><span class="acme-card__k">Problems</span><strong class="acme-card__v"><?php echo (int) $bad; ?></strong><span class="acme-card__s">fix these first</span></div>
			<div class="acme-card acme-card--<?php echo $warn ? 'warn' : 'ok'; ?>"><span class="acme-card__k">Worth a look</span><strong class="acme-card__v"><?php echo (int) $warn; ?></strong><span class="acme-card__s">smaller things</span></div>
			<div class="acme-card acme-card--<?php echo ! empty( $lr['orphans'] ) ? 'warn' : 'ok'; ?>"><span class="acme-card__k">Pages nothing links to</span><strong class="acme-card__v"><?php echo (int) count( $lr['orphans'] ?? array() ); ?></strong><span class="acme-card__s">orphan pages</span></div>
			<div class="acme-card acme-card--<?php echo ! empty( $lr['broken'] ) ? 'bad' : 'ok'; ?>"><span class="acme-card__k">Weak internal links</span><strong class="acme-card__v"><?php echo (int) count( $lr['broken'] ?? array() ); ?></strong><span class="acme-card__s">broken, redirected or unpublished</span></div>
		</div>

		<h2>Page by page</h2>
		<table class="widefat striped"><thead><tr><th>Page</th><th>Type</th><th>Words</th><th>Amazon links</th><th>Links in</th><th>Schema</th><th>Result</th></tr></thead><tbody>
		<?php foreach ( $pages as $id => $p ) : ?>
			<tr>
				<td><a href="<?php echo esc_url( get_edit_post_link( (int) $id ) ); ?>"><?php echo esc_html( $p['title'] ); ?></a><br><span class="description"><?php echo esc_html( wp_make_link_relative( $p['url'] ) ); ?></span></td>
				<td><?php echo esc_html( $p['type'] ); ?></td>
				<td><?php echo (int) ( $p['words'] ?? 0 ); ?></td>
				<td><?php echo (int) $p['amazon']; ?></td>
				<td><?php echo isset( $lr['inbound'][ $id ] ) ? (int) $lr['inbound'][ $id ] : '-'; ?></td>
				<td><span class="description"><?php echo esc_html( $p['schema'] ?? '' ); ?></span></td>
				<td>
					<?php if ( ! $p['issues'] ) : ?>
						<span class="acme-pill acme-pill--ok">All good</span>
					<?php else : ?>
						<ul style="margin:0;padding-left:16px;list-style:disc">
						<?php foreach ( $p['issues'] as $is ) : ?>
							<li><?php echo 'bad' === $is[0] ? '<span class="acme-pill acme-pill--bad">Fix</span> ' : '<span class="acme-pill acme-pill--warn">Check</span> '; echo esc_html( $is[1] ); // phpcs:ignore ?></li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>

		<?php if ( ! empty( $lr['orphans'] ) ) : ?>
			<h2>Pages with no link pointing to them</h2>
			<p class="description">Add a link from a related page or guide. Search engines find pages through links.</p>
			<ul style="list-style:disc;padding-left:20px"><?php foreach ( $lr['orphans'] as $oid ) { echo '<li><a href="' . esc_url( get_edit_post_link( $oid ) ) . '">' . esc_html( get_the_title( $oid ) ) . '</a></li>'; } ?></ul>
		<?php endif; ?>
		<?php if ( ! empty( $lr['broken'] ) ) : ?>
			<h2>Internal links that need attention</h2>
			<table class="widefat striped"><thead><tr><th>Link</th><th>Problem</th><th>Found on</th></tr></thead><tbody>
			<?php foreach ( $lr['broken'] as $b ) : ?>
				<tr><td><code><?php echo esc_html( $b['path'] ); ?></code></td><td><?php echo esc_html( $b['note'] ); ?></td><td><?php foreach ( $b['from'] as $f ) { echo '<a href="' . esc_url( get_edit_post_link( $f ) ) . '">' . esc_html( get_the_title( $f ) ) . '</a><br>'; } ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
		<?php
	}
}
