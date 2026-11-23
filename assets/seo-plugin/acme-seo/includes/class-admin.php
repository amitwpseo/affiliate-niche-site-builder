<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** One admin menu with tabs: Settings, Redirects, Monitor, Audit, Reviews, Tools. */
class ACME_SEO_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_menu_page( 'Site SEO', 'Site SEO', 'manage_options', 'acme-seo', array( __CLASS__, 'render' ), 'dashicons-chart-line', 82 );
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_acme-seo' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'acme-seo-admin', ACME_SEO_URL . 'assets/admin.css', array(), ACME_SEO_VERSION );
		wp_enqueue_script( 'acme-seo-admin', ACME_SEO_URL . 'assets/admin.js', array( 'jquery' ), ACME_SEO_VERSION, true );
		wp_localize_script( 'acme-seo-admin', 'acmeSeo', array( 'ajax' => admin_url( 'admin-ajax.php' ) ) );
	}

	private static function tabs() {
		return array(
			'settings'  => 'Settings',
			'redirects' => 'Redirects',
			'monitor'   => 'Monitor',
			'audit'     => 'Audit',
			'reviews'   => 'Reviews',
			'tools'     => 'Tools',
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'settings';
		$res  = get_transient( ACME_SEO::RESULT );
		?>
		<div class="wrap acme-seo-admin">
			<h1>Site SEO</h1>
			<?php if ( ! ACME_SEO::active() ) : ?>
				<div class="notice notice-warning"><p>Rank Math SEO is not active. Redirects, Monitor, Audit and Reviews still work; schema additions need Rank Math.</p></div>
			<?php endif; ?>
			<?php if ( $res ) : ?>
				<div class="notice notice-success is-dismissible"><ul><?php foreach ( (array) $res as $line ) { echo '<li>' . esc_html( $line ) . '</li>'; } ?></ul></div>
				<?php delete_transient( ACME_SEO::RESULT ); ?>
			<?php endif; ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( ACME_SEO::url( $key ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			switch ( $tab ) {
				case 'redirects':
					ACME_SEO_Redirects::panel();
					break;
				case 'monitor':
					ACME_SEO_Monitor::page();
					break;
				case 'audit':
					ACME_SEO_Audit::page();
					break;
				case 'reviews':
					ACME_SEO_Review::page();
					break;
				case 'tools':
					ACME_SEO_Tools::page();
					break;
				default:
					ACME_SEO::page();
			}
			?>
			<p class="description" style="margin-top:24px">Site SEO <?php echo esc_html( ACME_SEO_VERSION ); ?></p>
		</div>
		<?php
	}
}
