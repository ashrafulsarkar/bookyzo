<?php
/**
 * The unsubscribe landing page (QA report 2026-09-22 §4.6) — same
 * standalone, theme-bypassing shape as manage-booking.php, and
 * overridable the same way (a theme's own bookyzo/unsubscribe.php).
 *
 * @var bool       $valid        Whether the link's token matched the customer.
 * @var array|null $customer
 * @var bool       $unsubscribed Whether they're off the marketing list now.
 * @var string     $done         'unsubscribed' | 'resubscribed' right after a change, else ''.
 * @var int        $customer_id
 * @var string     $token
 * @var string     $action       admin-post.php action name for the form.
 */

defined( 'ABSPATH' ) || exit;

$byzo_settings      = BYZO_Settings::get();
$byzo_business_name = $byzo_settings['business_name'];
$byzo_contact_email = get_option( 'admin_email' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'Email preferences', 'bookyzo' ); ?></title>
	<?php wp_print_styles( 'byzo-standalone-page' ); ?>
</head>
<body>
	<div class="byzo-unsub-card">
		<p class="byzo-unsub-business"><?php echo esc_html( $byzo_business_name ); ?></p>

		<?php if ( ! $valid ) : ?>
			<h1><?php esc_html_e( 'This link has expired', 'bookyzo' ); ?></h1>
			<p><?php esc_html_e( "We couldn't match this link to anyone. If you're still getting emails you don't want, reply to one of them and we'll take you off the list.", 'bookyzo' ); ?></p>

		<?php elseif ( $unsubscribed ) : ?>
			<?php if ( 'unsubscribed' === $done ) : ?>
				<div class="byzo-unsub-icon">✓</div>
			<?php endif; ?>
			<h1><?php esc_html_e( "You're unsubscribed", 'bookyzo' ); ?></h1>
			<?php /* translators: %s: the customer's email address */ ?>
			<p><?php printf( esc_html__( "We won't send marketing emails to %s any more. Emails about your own bookings — confirmations, reminders, cancellations — still come through.", 'bookyzo' ), '<strong>' . esc_html( $customer['email'] ) . '</strong>' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
				<input type="hidden" name="customer_id" value="<?php echo esc_attr( $customer_id ); ?>">
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
				<input type="hidden" name="choice" value="resubscribe">
				<?php wp_nonce_field( $action ); ?>
				<button type="submit" class="byzo-unsub-link"><?php esc_html_e( 'Changed your mind? Resubscribe', 'bookyzo' ); ?></button>
			</form>

		<?php elseif ( 'resubscribed' === $done ) : ?>
			<div class="byzo-unsub-icon">✓</div>
			<h1><?php esc_html_e( "You're back on the list", 'bookyzo' ); ?></h1>
			<?php /* translators: %s: the customer's email address */ ?>
			<p><?php printf( esc_html__( "We'll send offers and news to %s again.", 'bookyzo' ), '<strong>' . esc_html( $customer['email'] ) . '</strong>' ); ?></p>

		<?php else : ?>
			<h1><?php esc_html_e( 'Unsubscribe from marketing emails?', 'bookyzo' ); ?></h1>
			<?php /* translators: %s: the customer's email address */ ?>
			<p><?php printf( esc_html__( "We'll stop sending offers and news to %s. Emails about your own bookings — confirmations, reminders, cancellations — still come through.", 'bookyzo' ), '<strong>' . esc_html( $customer['email'] ) . '</strong>' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
				<input type="hidden" name="customer_id" value="<?php echo esc_attr( $customer_id ); ?>">
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
				<input type="hidden" name="choice" value="unsubscribe">
				<?php wp_nonce_field( $action ); ?>
				<button type="submit" class="byzo-unsub-btn"><?php esc_html_e( 'Unsubscribe', 'bookyzo' ); ?></button>
			</form>
		<?php endif; ?>

		<p class="byzo-unsub-contact">
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
</body>
</html>
