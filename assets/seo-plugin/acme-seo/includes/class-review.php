<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reviews: named reviewer fields per page, a visible "Reviewed by" line and matching schema (only when a real
 * name, credentials and date are all filled in), plus a freshness table that shows what has not been looked at for 180 days.
 * Nothing is shown to visitors until you fill the fields in, so no claim is ever made automatically.
 */
class ACME_SEO_Review {

	const DUE = 180; // days

	public static function post_types() {
		return ACME_SEO::content_types();
	}

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'box' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'line' ), 25 );
		add_filter( 'rank_math/json_ld', array( __CLASS__, 'schema' ), 99, 2 );
		add_action( 'admin_post_acme_seo_reviewed', array( __CLASS__, 'handle_reviewed' ) );
	}

	/* ------------------------------------------------------------- metabox */

	public static function box() {
		foreach ( self::post_types() as $pt ) {
			add_meta_box( 'acme_seo_review', 'Reviewed by (Site SEO)', array( __CLASS__, 'box_html' ), $pt, 'side', 'default' );
		}
	}

	public static function box_html( $post ) {
		wp_nonce_field( 'acme_seo_review_' . $post->ID, 'acme_seo_review_nonce' );
		$n = get_post_meta( $post->ID, '_acme_rv_name', true );
		$c = get_post_meta( $post->ID, '_acme_rv_cred', true );
		$d = get_post_meta( $post->ID, '_acme_rv_date', true );
		echo '<p class="description">Fill in all three only if a real, named person actually reviewed this page. Then a "Reviewed by" line and matching schema appear. Leave blank otherwise: nothing is shown.</p>';
		echo '<p><label>Name<br><input type="text" name="acme_rv_name" value="' . esc_attr( $n ) . '" class="widefat"></label></p>';
		echo '<p><label>Credentials (e.g. MD, RD, PE)<br><input type="text" name="acme_rv_cred" value="' . esc_attr( $c ) . '" class="widefat"></label></p>';
		echo '<p><label>Date reviewed<br><input type="date" name="acme_rv_date" value="' . esc_attr( $d ) . '" class="widefat"></label></p>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['acme_seo_review_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['acme_seo_review_nonce'] ) ), 'acme_seo_review_' . $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$d = isset( $_POST['acme_rv_date'] ) ? sanitize_text_field( wp_unslash( $_POST['acme_rv_date'] ) ) : '';
		update_post_meta( $post_id, '_acme_rv_name', isset( $_POST['acme_rv_name'] ) ? sanitize_text_field( wp_unslash( $_POST['acme_rv_name'] ) ) : '' );
		update_post_meta( $post_id, '_acme_rv_cred', isset( $_POST['acme_rv_cred'] ) ? sanitize_text_field( wp_unslash( $_POST['acme_rv_cred'] ) ) : '' );
		update_post_meta( $post_id, '_acme_rv_date', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '' );
	}

	/** Returns array( name, cred, date ) only when all three are present. */
	private static function reviewer( $id ) {
		$n = trim( (string) get_post_meta( $id, '_acme_rv_name', true ) );
		$c = trim( (string) get_post_meta( $id, '_acme_rv_cred', true ) );
		$d = trim( (string) get_post_meta( $id, '_acme_rv_date', true ) );
		return ( '' !== $n && '' !== $c && '' !== $d ) ? array( $n, $c, $d ) : null;
	}

	/* ------------------------------------------------------- front and schema */

	public static function line( $content ) {
		if ( is_admin() || ! is_singular( self::post_types() ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$r = self::reviewer( get_the_ID() );
		if ( ! $r ) {
			return $content;
		}
		$line = '<p class="acme-reviewed" style="font-size:.9em;opacity:.85;margin:1.2em 0 0">Reviewed by ' . esc_html( $r[0] ) . ', ' . esc_html( $r[1] ) . ', on ' . esc_html( wp_date( 'F j, Y', strtotime( $r[2] ) ) ) . '.</p>';
		return $content . $line;
	}

	public static function schema( $data, $jsonld = null ) {
		if ( ! is_singular( self::post_types() ) || ! is_array( $data ) ) {
			return $data;
		}
		$r = self::reviewer( get_queried_object_id() );
		if ( ! $r ) {
			return $data;
		}
		foreach ( $data as $k => $node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) && in_array( 'WebPage', (array) $node['@type'], true ) ) {
				$data[ $k ]['reviewedBy']   = array( '@type' => 'Person', 'name' => $r[0], 'jobTitle' => $r[1] );
				$data[ $k ]['lastReviewed'] = $r[2];
			}
		}
		return $data;
	}

	/* ------------------------------------------------------------ freshness */

	public static function handle_reviewed() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'acme_seo_reviewed' );
		$id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0; // phpcs:ignore
		if ( $id && current_user_can( 'edit_post', $id ) ) {
			update_post_meta( $id, '_acme_rv_checked', gmdate( 'Y-m-d' ) );
			ACME_SEO_Tools::log( 'Marked "' . get_the_title( $id ) . '" as checked' );
		}
		set_transient( ACME_SEO::RESULT, array( 'Marked as checked today.' ), 60 );
		wp_safe_redirect( ACME_SEO::url( 'reviews' ) );
		exit;
	}

	public static function page() {
		$posts = get_posts( array( 'post_type' => self::post_types(), 'post_status' => 'publish', 'numberposts' => 300, 'orderby' => 'modified', 'order' => 'ASC' ) );
		$rows  = array();
		$due   = 0;
		foreach ( $posts as $p ) {
			$checked = (string) get_post_meta( $p->ID, '_acme_rv_checked', true );
			$best    = max( strtotime( $p->post_modified_gmt . ' UTC' ), $checked ? strtotime( $checked ) : 0 );
			$age     = (int) floor( ( time() - $best ) / DAY_IN_SECONDS );
			$late    = $age > self::DUE;
			$due    += $late ? 1 : 0;
			$rows[]  = array( $p, $age, $late, self::reviewer( $p->ID ) );
		}
		usort( $rows, function ( $a, $b ) {
			return $b[1] <=> $a[1];
		} );
		?>
		<p style="max-width:820px">Pages that give advice (health, safety, money, how-to) should be looked at again from time to time. This list shows when each page was last edited or marked as checked. Pages untouched for <?php echo (int) self::DUE; ?> days are flagged. A named reviewer (with real credentials) is optional: fill it in on the page's edit screen, in the "Reviewed by" box, only if it is true.</p>
		<div class="acme-cards">
			<div class="acme-card acme-card--ok"><span class="acme-card__k">Pages</span><strong class="acme-card__v"><?php echo (int) count( $rows ); ?></strong><span class="acme-card__s">published</span></div>
			<div class="acme-card acme-card--<?php echo $due ? 'warn' : 'ok'; ?>"><span class="acme-card__k">Due for a look</span><strong class="acme-card__v"><?php echo (int) $due; ?></strong><span class="acme-card__s">older than <?php echo (int) self::DUE; ?> days</span></div>
			<div class="acme-card acme-card--ok"><span class="acme-card__k">With named reviewer</span><strong class="acme-card__v"><?php echo (int) count( array_filter( array_column( $rows, 3 ) ) ); ?></strong><span class="acme-card__s">shown on the page</span></div>
		</div>
		<table class="widefat striped"><thead><tr><th>Page</th><th>Type</th><th>Last changed or checked</th><th>Reviewer</th><th></th></tr></thead><tbody>
		<?php foreach ( $rows as $r ) : ?>
			<tr>
				<td><a href="<?php echo esc_url( get_edit_post_link( $r[0]->ID ) ); ?>"><?php echo esc_html( get_the_title( $r[0] ) ); ?></a></td>
				<td><?php echo esc_html( $r[0]->post_type ); ?></td>
				<td><?php echo $r[2] ? '<span class="acme-pill acme-pill--warn">Due</span> ' : '<span class="acme-pill acme-pill--ok">Fresh</span> '; echo (int) $r[1] . ' days ago'; // phpcs:ignore ?></td>
				<td><?php echo $r[3] ? esc_html( $r[3][0] . ', ' . $r[3][1] . ' (' . $r[3][2] . ')' ) : '<span class="description">none</span>'; ?></td>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="acme_seo_reviewed"><input type="hidden" name="post_id" value="<?php echo (int) $r[0]->ID; ?>">
						<?php wp_nonce_field( 'acme_seo_reviewed' ); ?>
						<button class="button button-small">Mark checked today</button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
	}
}
