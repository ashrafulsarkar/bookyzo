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

$settings      = BYZO_Settings::get();
$business_name = $settings['business_name'];
$contact_email = get_option( 'admin_email' );

$details = array();
if ( $booking ) {
	if ( ! empty( $booking['slot_start'] ) ) {
		// On the booked item's own clock (QA report §6.1 A1).
		$start = ( new DateTime( $booking['slot_start'], wp_timezone() ) )->setTimezone( BYZO_Bookable_Item::timezone( (int) $booking['bookable_item_id'] ) );
		$details[ __( 'When', 'bookyzo' ) ] = $start->format( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}
	if ( $item && ! empty( $item['location'] ) ) {
		$details[ __( 'Where', 'bookyzo' ) ] = $item['location'];
	}
	if ( (int) $booking['party_size'] > 1 ) {
		$details[ __( 'How many', 'bookyzo' ) ] = (string) (int) $booking['party_size'];
	}
	if ( null !== $booking['payment_amount'] ) {
		$details[ __( 'Total', 'bookyzo' ) ] = $settings['currency'] . ' ' . number_format( (float) $booking['payment_amount'], 2 );
	}
	$details[ __( 'Booking', 'bookyzo' ) ] = '#' . (int) $booking['id'];
}
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
		.byzo-manage-card { max-width: 440px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 32px; text-align: center; }
		.byzo-manage-business { font-size: 13px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: #64748b; margin: 0 0 18px; }
		.byzo-manage-card h1 { font-size: 18px; margin: 0 0 12px; }
		.byzo-manage-card p { font-size: 14px; line-height: 1.5; color: #475569; margin: 0 0 20px; }
		.byzo-manage-summary { background: #f1f5f9; border-radius: 8px; padding: 14px 16px; font-size: 14px; text-align: left; margin-bottom: 20px; }
		.byzo-manage-summary strong { display: block; margin-bottom: 8px; font-size: 15px; }
		.byzo-manage-row { display: flex; justify-content: space-between; gap: 12px; padding: 3px 0; }
		.byzo-manage-row span:first-child { color: #64748b; }
		.byzo-manage-row span:last-child { text-align: right; }
		.byzo-manage-btn { display: inline-block; border: none; border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 600; cursor: pointer; }
		.byzo-manage-btn--danger { background: #dc2626; color: #fff; }
		.byzo-manage-btn--primary { background: #2563eb; color: #fff; margin-bottom: 20px; }
		.byzo-manage-times { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin-bottom: 20px; }
		.byzo-manage-time { border: 1px solid #cbd5e1; background: #fff; border-radius: 8px; padding: 8px 12px; font-size: 13px; cursor: pointer; }
		.byzo-manage-time:hover { border-color: #2563eb; color: #2563eb; }
		.byzo-manage-icon { font-size: 32px; margin-bottom: 12px; }
		.byzo-manage-contact { margin: 24px 0 0; padding-top: 16px; border-top: 1px solid #e2e8f0; font-size: 13px; color: #64748b; }
		.byzo-manage-contact a { color: #2563eb; }
	</style>
</head>
<body>
	<div class="byzo-manage-card">
		<p class="byzo-manage-business"><?php echo esc_html( $business_name ); ?></p>

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

		<?php if ( $details ) : ?>
			<div class="byzo-manage-summary">
				<strong><?php echo esc_html( $item['name'] ?? '' ); ?></strong>
				<?php foreach ( $details as $label => $value ) : ?>
					<div class="byzo-manage-row">
						<span><?php echo esc_html( $label ); ?></span>
						<span><?php echo esc_html( $value ); ?></span>
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
				<button type="submit" class="byzo-manage-btn byzo-manage-btn--danger"><?php esc_html_e( 'Yes, cancel my booking', 'bookyzo' ); ?></button>
			</form>

			<script>
			(function () {
				var config = <?php echo wp_json_encode(
					array(
						'rest'      => esc_url_raw( rest_url( 'bookyzo/v1' ) ),
						'bookingId' => (int) $booking_id,
						'itemId'    => (int) $booking['bookable_item_id'],
						'currentId' => (int) $booking['slot_id'],
						'token'     => $token,
						'i18n'      => array(
							'none'    => __( 'No other times are open right now.', 'bookyzo' ),
							'pick'    => __( 'Pick a new time:', 'bookyzo' ),
							'failed'  => __( 'Could not load available times. Please get in touch.', 'bookyzo' ),
							'saving'  => __( 'Moving your booking…', 'bookyzo' ),
							'done'    => __( 'Your booking has been moved.', 'bookyzo' ),
						),
					)
				); ?>;
				var openBtn = document.getElementById('byzo-reschedule-open');
				var panel = document.getElementById('byzo-reschedule-panel');
				var status = document.getElementById('byzo-reschedule-status');
				var times = document.getElementById('byzo-reschedule-times');
				var loaded = false;

				function label(slot) {
					var date = new Date((slot.local_start_datetime || slot.start_datetime).replace(' ', 'T'));
					return date.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
				}

				function move(slot) {
					status.textContent = config.i18n.saving;
					times.innerHTML = '';
					fetch(config.rest + '/bookings/' + config.bookingId + '/reschedule', {
						method: 'PUT',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({ slot_id: slot.id, token: config.token })
					}).then(function (response) {
						return response.json().then(function (data) { return { ok: response.ok, data: data }; });
					}).then(function (result) {
						if (!result.ok) { throw new Error(result.data && result.data.message); }
						status.textContent = config.i18n.done;
						window.location.reload();
					}).catch(function (error) {
						status.textContent = error.message || config.i18n.failed;
					});
				}

				openBtn.addEventListener('click', function () {
					panel.hidden = false;
					openBtn.hidden = true;
					if (loaded) { return; }
					loaded = true;
					fetch(config.rest + '/slots?bookable_item_id=' + config.itemId)
						.then(function (response) { return response.json(); })
						.then(function (slots) {
							var open = (slots || []).filter(function (slot) { return slot.id !== config.currentId; }).slice(0, 30);
							if (!open.length) { status.textContent = config.i18n.none; return; }
							status.textContent = config.i18n.pick;
							open.forEach(function (slot) {
								var button = document.createElement('button');
								button.type = 'button';
								button.className = 'byzo-manage-time';
								button.textContent = label(slot);
								button.addEventListener('click', function () { move(slot); });
								times.appendChild(button);
							});
						})
						.catch(function () { status.textContent = config.i18n.failed; });
				});
			})();
			</script>
		<?php endif; ?>

		<p class="byzo-manage-contact">
			<?php
			printf(
				/* translators: 1: contact email link, 2: link to the site */
				esc_html__( 'Questions? Email %1$s or visit %2$s.', 'bookyzo' ),
				'<a href="mailto:' . esc_attr( $contact_email ) . '">' . esc_html( $contact_email ) . '</a>',
				'<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( $business_name ) . '</a>'
			);
			?>
		</p>
	</div>
</body>
</html>
