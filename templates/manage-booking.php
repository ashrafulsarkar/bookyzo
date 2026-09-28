<?php
/**
 * The guest cancellation page (DETAILED-SPEC.md §A4/§A5) — rendered
 * standalone via `template_redirect`, bypassing the active theme
 * entirely (this is a transactional utility page, not site content, the
 * same reasoning the public booking widget's own CSS isolation follows).
 * Overridable via a theme's own bookyzo/manage-booking.php (see
 * byzo_get_template() in includes/functions.php).
 *
 * Rewritten 2026-09-22 (QA report §4.5): the page used to name the item
 * and the time only on the "confirm" screen, never said whose site it
 * was, and told people to "contact us directly" without giving them any
 * way to. It now shows the booking in full on every state that has one,
 * with the business's own name and contact details underneath.
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

$byzo_settings      = BYZO_Settings::get();
$byzo_business_name = $byzo_settings['business_name'];
$byzo_contact_email = get_option( 'admin_email' );

$byzo_details = array();
if ( $booking ) {
	if ( ! empty( $booking['slot_start'] ) ) {
		// On the booked item's own clock (QA report §6.1 A1).
		$byzo_start = ( new DateTime( $booking['slot_start'], wp_timezone() ) )->setTimezone( BYZO_Bookable_Item::timezone( (int) $booking['bookable_item_id'] ) );
		$byzo_details[ __( 'When', 'bookyzo' ) ] = $byzo_start->format( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}
	if ( $item && ! empty( $item['location'] ) ) {
		$byzo_details[ __( 'Where', 'bookyzo' ) ] = $item['location'];
	}
	if ( (int) $booking['party_size'] > 1 ) {
		$byzo_details[ __( 'How many', 'bookyzo' ) ] = (string) (int) $booking['party_size'];
	}
	if ( null !== $booking['payment_amount'] ) {
		$byzo_details[ __( 'Total', 'bookyzo' ) ] = $byzo_settings['currency'] . ' ' . number_format( (float) $booking['payment_amount'], 2 );
	}
	$byzo_details[ __( 'Booking', 'bookyzo' ) ] = '#' . (int) $booking['id'];
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'Manage your booking', 'bookyzo' ); ?></title>
	<?php wp_print_styles( 'byzo-standalone-page' ); ?>
</head>
<body>
	<div class="byzo-manage-card">
		<p class="byzo-manage-business"><?php echo esc_html( $byzo_business_name ); ?></p>

		<?php if ( 'cancelled' === $state ) : ?>
			<div class="byzo-manage-icon">✓</div>
			<h1><?php esc_html_e( 'Booking cancelled', 'bookyzo' ); ?></h1>
			<p><?php esc_html_e( "Your booking has been cancelled. We've sent you a confirmation email.", 'bookyzo' ); ?></p>

		<?php elseif ( 'confirm' === $state ) : ?>
			<h1><?php esc_html_e( 'Cancel this booking?', 'bookyzo' ); ?></h1>

		<?php else : ?>
			<h1><?php esc_html_e( 'Unable to cancel', 'bookyzo' ); ?></h1>
			<p><?php echo esc_html( $message ); ?></p>
		<?php endif; ?>

		<?php if ( $byzo_details ) : ?>
			<div class="byzo-manage-summary">
				<strong><?php echo esc_html( $item['name'] ?? '' ); ?></strong>
				<?php foreach ( $byzo_details as $byzo_label => $byzo_value ) : ?>
					<div class="byzo-manage-row">
						<span><?php echo esc_html( $byzo_label ); ?></span>
						<span><?php echo esc_html( $byzo_value ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( 'confirm' === $state ) : ?>
			<!-- Changing the time, not just cancelling (QA report §4.5/§6.2):
			     the same booking, another open time for the same item. The
			     REST route checks the token and the cancellation window again;
			     nothing here is trusted. -->
			<div id="byzo-reschedule">
				<button type="button" class="byzo-manage-btn byzo-manage-btn--primary" id="byzo-reschedule-open"><?php esc_html_e( 'Change the time', 'bookyzo' ); ?></button>
				<div id="byzo-reschedule-panel" hidden>
					<p id="byzo-reschedule-status"><?php esc_html_e( 'Loading available times…', 'bookyzo' ); ?></p>
					<div id="byzo-reschedule-times" class="byzo-manage-times"></div>
				</div>
			</div>

			<p><?php esc_html_e( 'Or cancel it — that one can\'t be undone.', 'bookyzo' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
				<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking_id ); ?>">
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
				<?php wp_nonce_field( $action ); ?>
				<button type="submit" class="byzo-manage-btn byzo-manage-btn--danger"><?php esc_html_e( 'Yes, cancel my booking', 'bookyzo' ); ?></button>
			</form>

		<?php endif; ?>

		<p class="byzo-manage-contact">
			<?php
			printf(
				/* translators: 1: contact email link, 2: link to the site */
				esc_html__( 'Questions? Email %1$s or visit %2$s.', 'bookyzo' ),
				'<a href="mailto:' . esc_attr( $byzo_contact_email ) . '">' . esc_html( $byzo_contact_email ) . '</a>',
				'<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( $byzo_business_name ) . '</a>'
			);
			?>
		</p>
	</div>
	<?php wp_print_scripts( 'byzo-manage-booking' ); ?>
</body>
</html>
