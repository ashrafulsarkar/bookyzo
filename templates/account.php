<?php
/**
 * Minimal customer login/register/dashboard-stub page (Phase 1.7 step 2,
 * DETAILED-SPEC.md §A11) — rendered standalone via `template_redirect`,
 * bypassing the active theme, same reasoning as manage-booking.php.
 * Overridable via a theme's own bookyzo/account.php (byzo_get_template()).
 *
 * Deliberately minimal: this is step 2's placeholder landing target for
 * the admin-post auth handlers. Phase 1.7 step 4 replaces the markup here
 * with the real `[bookyzo_account]` Vue-mounted dashboard; the 'dashboard'
 * state below is just enough to prove login/logout round-trip correctly
 * until then.
 *
 * @var string $state            'login' | 'register' | 'dashboard' | 'disabled'
 * @var string $error_message
 * @var string $register_action  admin-post.php action name for the register form.
 * @var string $login_action     admin-post.php action name for the login form.
 * @var string $logout_action    admin-post.php action name for the logout link.
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'My Account', 'bookyzo' ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 40px 20px; }
		.byzo-account-card { max-width: 380px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 32px; }
		.byzo-account-card h1 { font-size: 18px; margin: 0 0 20px; text-align: center; }
		.byzo-account-error { background: #fef2f2; color: #b91c1c; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin-bottom: 16px; }
		.byzo-account-field { margin-bottom: 14px; }
		.byzo-account-field label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
		.byzo-account-field input { width: 100%; box-sizing: border-box; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
		.byzo-account-btn { display: block; width: 100%; border: none; border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 600; cursor: pointer; background: #2563eb; color: #fff; }
		.byzo-account-switch { text-align: center; font-size: 13px; margin-top: 16px; }
		.byzo-account-switch a { color: #2563eb; text-decoration: none; }
	</style>
</head>
<body>
	<div class="byzo-account-card">
		<?php if ( $error_message ) : ?>
			<div class="byzo-account-error"><?php echo esc_html( $error_message ); ?></div>
		<?php endif; ?>

		<?php if ( 'disabled' === $state ) : ?>
			<h1><?php esc_html_e( 'Accounts unavailable', 'bookyzo' ); ?></h1>
			<p><?php esc_html_e( 'Customer accounts are not enabled on this site.', 'bookyzo' ); ?></p>

		<?php elseif ( 'dashboard' === $state ) : ?>
			<h1><?php esc_html_e( 'Welcome back', 'bookyzo' ); ?></h1>
			<p><?php echo esc_html( sprintf(
				/* translators: %s: the logged-in user's display name */
				__( 'Logged in as %s.', 'bookyzo' ),
				wp_get_current_user()->display_name
			) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $logout_action ); ?>">
				<button type="submit" class="byzo-account-btn"><?php esc_html_e( 'Log out', 'bookyzo' ); ?></button>
			</form>

		<?php elseif ( 'register' === $state ) : ?>
			<h1><?php esc_html_e( 'Create an account', 'bookyzo' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $register_action ); ?>">
				<div class="byzo-account-field">
					<label for="byzo-name"><?php esc_html_e( 'Name', 'bookyzo' ); ?></label>
					<input type="text" id="byzo-name" name="name" required>
				</div>
				<div class="byzo-account-field">
					<label for="byzo-email"><?php esc_html_e( 'Email', 'bookyzo' ); ?></label>
					<input type="email" id="byzo-email" name="email" required>
				</div>
				<div class="byzo-account-field">
					<label for="byzo-password"><?php esc_html_e( 'Password', 'bookyzo' ); ?></label>
					<input type="password" id="byzo-password" name="password" minlength="8" required>
				</div>
				<button type="submit" class="byzo-account-btn"><?php esc_html_e( 'Create account', 'bookyzo' ); ?></button>
			</form>
			<p class="byzo-account-switch">
				<a href="<?php echo esc_url( BYZO_Customer_Auth::build_link( array( 'mode' => 'login' ) ) ); ?>"><?php esc_html_e( 'Already have an account? Log in', 'bookyzo' ); ?></a>
			</p>

		<?php else : ?>
			<h1><?php esc_html_e( 'Log in', 'bookyzo' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $login_action ); ?>">
				<div class="byzo-account-field">
					<label for="byzo-email"><?php esc_html_e( 'Email', 'bookyzo' ); ?></label>
					<input type="email" id="byzo-email" name="email" required>
				</div>
				<div class="byzo-account-field">
					<label for="byzo-password"><?php esc_html_e( 'Password', 'bookyzo' ); ?></label>
					<input type="password" id="byzo-password" name="password" required>
				</div>
				<button type="submit" class="byzo-account-btn"><?php esc_html_e( 'Log in', 'bookyzo' ); ?></button>
			</form>
			<p class="byzo-account-switch">
				<a href="<?php echo esc_url( BYZO_Customer_Auth::build_link( array( 'mode' => 'register' ) ) ); ?>"><?php esc_html_e( "Don't have an account? Create one", 'bookyzo' ); ?></a>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( wp_lostpassword_url( BYZO_Customer_Auth::build_link() ) ); ?>"><?php esc_html_e( 'Forgot password?', 'bookyzo' ); ?></a>
			</p>
		<?php endif; ?>
	</div>
</body>
</html>
