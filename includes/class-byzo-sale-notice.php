<?php
/**
 * Limited-time Codeholt Black Friday promotion for Bookyzo Pro.
 *
 * A dismissible admin notice with a live countdown and a copyable coupon.
 * Shown to administrators only, only during the sale window (site time),
 * and never once Bookyzo Pro is active. Dismissing it hides it until the
 * next day. Free reads no license state: "Pro is active" is
 * `defined( 'BYZOP_VERSION' )`, the same check byzo_show_get_pro() uses.
 *
 * @package Bookyzo
 */

defined( 'ABSPATH' ) || exit;

class BYZO_Sale_Notice {

	const COUPON     = 'BLACKFRIDAY';
	const FIRST_DAY  = '2026-11-01';
	const LAST_DAY   = '2026-11-30';
	// Midnight after LAST_DAY, site time.
	const ENDS_AT    = '2026-12-01 00:00:00';
	const UPGRADE_URL = 'https://codeholt.com/products/bookyzo-pro/#price';

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
	}

	private static function cookie_name() {
		return 'byzo_black_friday_sale_' . get_current_blog_id() . '_' . get_current_user_id();
	}

	/**
	 * @return bool
	 */
	private static function should_show() {
		if ( defined( 'BYZOP_VERSION' ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$today = current_time( 'Y-m-d' );
		if ( $today < self::FIRST_DAY || $today > self::LAST_DAY ) {
			return false;
		}

		$cookie = self::cookie_name();
		if ( isset( $_COOKIE[ $cookie ] ) && $today === sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) ) ) {
			return false;
		}

		return true;
	}

	public static function enqueue() {
		if ( ! self::should_show() ) {
			return;
		}

		wp_enqueue_style( 'byzo-sale-notice', BYZO_PLUGIN_URL . 'assets/css/sale-notice.css', array( 'dashicons' ), BYZO_VERSION );
		wp_enqueue_script( 'byzo-sale-notice', BYZO_PLUGIN_URL . 'assets/js/sale-notice.js', array(), BYZO_VERSION, true );

		$tomorrow = new DateTimeImmutable( 'tomorrow', wp_timezone() );
		$sale_end = new DateTimeImmutable( self::ENDS_AT, wp_timezone() );

		$config = array(
			'coupon'        => self::COUPON,
			// Seconds left (not an absolute date) so a wrong clock on the
			// admin's computer can't skew the countdown.
			'remaining'     => max( 0, $sale_end->getTimestamp() - time() ),
			'cookieName'    => self::cookie_name(),
			'cookieValue'   => current_time( 'Y-m-d' ),
			'cookieExpires' => gmdate( 'D, d M Y H:i:s', $tomorrow->getTimestamp() ) . ' GMT',
			'cookiePath'    => wp_parse_url( admin_url(), PHP_URL_PATH ),
			'copied'        => __( 'Coupon code copied.', 'bookyzo' ),
			/* translators: %s: coupon code */
			'copyFailed'    => sprintf( __( 'Please select and copy %s manually.', 'bookyzo' ), self::COUPON ),
		);

		wp_add_inline_script( 'byzo-sale-notice', 'window.byzoSaleNotice = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	public static function render() {
		if ( ! self::should_show() ) {
			return;
		}
		?>
		<div id="byzo-bf-sale" class="notice is-dismissible">
			<span class="byzo-sale-tag"><?php esc_html_e( 'Black Friday Sale', 'bookyzo' ); ?></span>
			<p class="byzo-sale-text">
				<strong><?php esc_html_e( '60% OFF', 'bookyzo' ); ?></strong>
				<?php esc_html_e( 'Bookyzo Pro', 'bookyzo' ); ?>
			</p>
			<div class="byzo-sale-countdown" role="timer" aria-label="<?php esc_attr_e( 'Time left in the sale', 'bookyzo' ); ?>">
				<span class="dashicons dashicons-clock" aria-hidden="true"></span>
				<span class="byzo-sale-unit"><b data-unit="d">00</b><?php echo esc_html_x( 'd', 'days, countdown', 'bookyzo' ); ?></span>
				<span class="byzo-sale-unit"><b data-unit="h">00</b><?php echo esc_html_x( 'h', 'hours, countdown', 'bookyzo' ); ?></span>
				<span class="byzo-sale-unit"><b data-unit="m">00</b><?php echo esc_html_x( 'm', 'minutes, countdown', 'bookyzo' ); ?></span>
				<span class="byzo-sale-unit"><b data-unit="s">00</b><?php echo esc_html_x( 's', 'seconds, countdown', 'bookyzo' ); ?></span>
			</div>
			<div class="byzo-sale-actions">
				<?php
				/* translators: %s: coupon code */
				$byzo_copy_label = sprintf( __( 'Copy coupon code %s', 'bookyzo' ), self::COUPON );
				?>
				<button type="button" class="byzo-sale-copy" title="<?php esc_attr_e( 'Copy coupon code', 'bookyzo' ); ?>" aria-label="<?php echo esc_attr( $byzo_copy_label ); ?>"><span><?php echo esc_html( self::COUPON ); ?></span> <span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button>
				<a class="byzo-sale-upgrade" href="<?php echo esc_url( self::UPGRADE_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Upgrade', 'bookyzo' ); ?> &rarr;</a>
				<span class="byzo-sale-status screen-reader-text" role="status" aria-live="polite"></span>
			</div>
		</div>
		<?php
	}
}
