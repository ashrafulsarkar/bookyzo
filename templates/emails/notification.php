<?php
/**
 * Free's notification email body — deliberately plain text (no HTML
 * wrapper/visual builder, DETAILED-SPEC.md §A6: "plain-text template
 * editing... no visual builder (Pro feature)"). Overridable via a
 * theme's own bookyzo/emails/notification.php (see byzo_get_template()
 * in includes/functions.php) for a site that wants different wrapping,
 * without needing Pro.
 *
 * @var string $body Already placeholder-substituted plain-text content,
 *     already sanitized as plain text at save-time (sanitize_textarea_field
 *     on the template, sanitize_text_field on the substituted booking
 *     fields) — NOT esc_html()'d here, since the destination is a
 *     text/plain email body, not an HTML context; escaping here would
 *     corrupt legitimate input like "Smith & Sons" into visible HTML
 *     entities in the recipient's inbox.
 */

defined( 'ABSPATH' ) || exit;

echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email body, see docblock above
