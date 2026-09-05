<?php
/**
 * The guest cancellation page (DETAILED-SPEC.md §A4/§A5) — rendered
 * standalone via `template_redirect`, bypassing the active theme
 * entirely (this is a transactional utility page, not site content, the
 * same reasoning the public booking widget's own CSS isolation follows).
 * Overridable via a theme's own bookyzo/manage-booking.php (see
 * byzo_get_template() in includes/functions.php).
 *
 * @var string      $state      'confirm' | 'cancelled' | 'error'
 * @var string      $message    Shown for the 'error' state.
 * @var array|null  $booking    The booking row, if the token verified.
 * @var array|null  $item       The bookable item, if the token verified.
 * @var int         $booking_id
 * @var string      $token
 * @var string      $action     admin-post.php action name for the confirm form.
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'Manage your booking', 'bookyzo' ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 40px 20px; }
		.byzo-manage-card { max-width: 420px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 32px; text-align: center; }
		.byzo-manage-card h1 { font-size: 18px; margin: 0 0 12px; }
		.byzo-manage-card p { font-size: 14px; line-height: 1.5; color: #475569; margin: 0 0 20px; }
		.byzo-manage-summary { background: #f1f5f9; border-radius: 8px; padding: 14px; font-size: 14px; text-align: left; margin-bottom: 20px; }
		.byzo-manage-summary strong { display: block; margin-bottom: 2px; }
		.byzo-manage-btn { display: inline-block; border: none; border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 600; cursor: pointer; }
		.byzo-manage-btn--danger { background: #dc2626; color: #fff; }
		.byzo-manage-icon { font-size: 32px; margin-bottom: 12px; }
	</style>
</head>
<body>
	<div class="byzo-manage-card">
		<?php if ( 'cancelled' === $state ) : ?>
			<div class="byzo-manage-icon">✓</div>
			<h1><?php esc_html_e( 'Booking cancelled', 'bookyzo' ); ?></h1>
			<p><?php esc_html_e( "Your booking has been cancelled. We've sent you a confirmation email.", 'bookyzo' ); ?></p>

		<?php elseif ( 'confirm' === $state ) : ?>
			<h1><?php esc_html_e( 'Cancel this booking?', 'bookyzo' ); ?></h1>
			<div class="byzo-manage-summary">
				<strong><?php echo esc_html( $item['name'] ?? '' ); ?></strong>
				<?php if ( ! empty( $booking['slot_start'] ) ) : ?>
					<?php
					$slot_start = new DateTime( $booking['slot_start'], wp_timezone() );
					echo esc_html( $slot_start->format( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
					?>
				<?php endif; ?>
			</div>
			<p><?php esc_html_e( "This can't be undone. If you just want to change the time, please contact us directly instead.", 'bookyzo' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
				<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking_id ); ?>">
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
				<button type="submit" class="byzo-manage-btn byzo-manage-btn--danger"><?php esc_html_e( 'Yes, cancel my booking', 'bookyzo' ); ?></button>
			</form>

		<?php else : ?>
			<h1><?php esc_html_e( 'Unable to cancel', 'bookyzo' ); ?></h1>
			<p><?php echo esc_html( $message ); ?></p>
		<?php endif; ?>
	</div>
</body>
</html>
