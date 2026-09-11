<?php
defined( 'ABSPATH' ) || exit;

/**
 * Visual configuration for the public booking widget (FEATURES.md §A5),
 * stored as its own option (`byzo_appearance`) rather than inside
 * `byzo_settings` — that option is business/booking behavior, this is
 * purely presentational, and keeping them apart means a theme tweak
 * never risks a bad write landing next to slot-generation math.
 *
 * Every field here maps 1:1 onto a CSS custom property that
 * src/widget/widget.css already declares on `.bookyzo-widget`
 * (--byzo-primary, --byzo-radius, …). Nothing is hardcoded twice: the
 * stylesheet keeps its own values as the defaults, and
 * BYZO_Public_Widget only emits an override for the properties an admin
 * actually changed. That's why defaults() below must stay in sync with
 * widget.css's own `.bookyzo-widget` block.
 *
 * Scope note: this option holds a fixed set of preset controls. An
 * add-on that wants to extend the widget's styling further does so by
 * filtering the emitted CSS (see the filters in build_css_vars() /
 * build_css_declarations()) rather than by this class growing a
 * free-form stylesheet field — which is also why nothing here is ever
 * rendered as an unavailable or placeholder control.
 */
class BYZO_Appearance_Settings {

	const OPTION = 'byzo_appearance';

	/**
	 * Defaults mirror src/widget/widget.css's `.bookyzo-widget` block.
	 * A stored value equal to its default still emits no override, so a
	 * site that never opens the Appearance screen renders byte-identical
	 * markup to before this feature existed.
	 */
	public static function defaults() {
		return array(
			'primary_color'       => '#2563eb',
			'primary_dark_color'  => '#1d4ed8',
			'primary_light_color' => '#eff6ff',
			'text_color'          => '#1d2327',
			'muted_text_color'    => '#6b7280',
			'border_color'        => '#e7e8ec',
			'surface_color'       => '#ffffff',
			'radius'              => 14,
			'radius_sm'           => 8,
			'max_width'           => 460,
			'font_size'           => 14,
			// '' = inherit the host theme's font, which is the right
			// default for a widget embedded in someone else's page. A
			// non-empty value replaces the widget's own system-font stack.
			'font_family'         => '',
			'show_shadow'         => true,
		);
	}

	public static function get() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * @param array $data Partial or full appearance patch.
	 * @return array|WP_Error The full appearance settings after the update.
	 */
	public static function update( $data ) {
		$current = self::get();
		$patch   = array();

		foreach ( self::color_fields() as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$color = self::sanitize_color( $data[ $field ] );
				if ( ! $color ) {
					return new WP_Error(
						'byzo_invalid_color',
						/* translators: %s: settings field name, e.g. primary_color */
						sprintf( __( '%s must be a hex color like #2563eb.', 'bookyzo' ), $field ),
						array( 'status' => 400 )
					);
				}
				$patch[ $field ] = $color;
			}
		}

		// Ranges are generous but bounded — the point is to stop a value
		// that would render the widget unusable (a 0px-wide card, a
		// 400px font) from being saved at all, not to enforce taste.
		foreach ( self::numeric_fields() as $field => $range ) {
			if ( isset( $data[ $field ] ) ) {
				$value = (int) $data[ $field ];
				if ( $value < $range['min'] || $value > $range['max'] ) {
					return new WP_Error(
						'byzo_invalid_appearance_value',
						/* translators: 1: field name, 2: minimum value, 3: maximum value */
						sprintf( __( '%1$s must be between %2$d and %3$d.', 'bookyzo' ), $field, $range['min'], $range['max'] ),
						array( 'status' => 400 )
					);
				}
				$patch[ $field ] = $value;
			}
		}

		if ( isset( $data['font_family'] ) ) {
			// A CSS font-family list, not a URL — no webfont is loaded on
			// the visitor's behalf. Stripping quotes/backslashes and
			// anything that could close the declaration keeps this from
			// becoming a CSS-injection vector when it's interpolated into
			// the inline style block.
			$patch['font_family'] = self::sanitize_font_family( $data['font_family'] );
		}

		if ( isset( $data['show_shadow'] ) ) {
			$patch['show_shadow'] = (bool) $data['show_shadow'];
		}

		$updated = array_merge( $current, $patch );
		update_option( self::OPTION, $updated );

		return $updated;
	}

	/**
	 * The CSS custom properties whose stored value differs from the
	 * stylesheet default, as a `--prop: value;` string ready to inline.
	 * Returns '' when nothing has been customized, so the common case
	 * adds no markup to the page at all.
	 */
	public static function build_css_vars() {
		$settings = self::get();
		$defaults = self::defaults();
		$rules    = array();

		$color_map = array(
			'primary_color'       => '--byzo-primary',
			'primary_dark_color'  => '--byzo-primary-dark',
			'primary_light_color' => '--byzo-primary-light',
			'text_color'          => '--byzo-text',
			'muted_text_color'    => '--byzo-text-muted',
			'border_color'        => '--byzo-border',
			'surface_color'       => '--byzo-surface',
		);

		foreach ( $color_map as $field => $property ) {
			if ( $settings[ $field ] !== $defaults[ $field ] ) {
				$rules[] = $property . ':' . $settings[ $field ];
			}
		}

		$px_map = array(
			'radius'    => '--byzo-radius',
			'radius_sm' => '--byzo-radius-sm',
		);

		foreach ( $px_map as $field => $property ) {
			if ( (int) $settings[ $field ] !== (int) $defaults[ $field ] ) {
				$rules[] = $property . ':' . (int) $settings[ $field ] . 'px';
			}
		}

		// Not a custom property in widget.css — the shadow is a single
		// declaration there, so turning it off means overriding the
		// property with `none` rather than swapping a token value.
		if ( ! $settings['show_shadow'] ) {
			$rules[] = '--byzo-shadow:none';
		}

		/**
		 * Filters the CSS custom property overrides for the booking widget.
		 *
		 * @param string $css      `--prop:value;` pairs, or '' when nothing was customized.
		 * @param array  $settings The current appearance settings.
		 */
		return apply_filters( 'byzo_appearance_css_vars', $rules ? implode( ';', $rules ) . ';' : '', $settings );
	}

	/**
	 * Properties that live on `.bookyzo-widget` itself rather than as
	 * tokens (max-width/font-size/font-family), same
	 * only-if-changed rule as build_css_vars().
	 */
	public static function build_css_declarations() {
		$settings = self::get();
		$defaults = self::defaults();
		$rules    = array();

		if ( (int) $settings['max_width'] !== (int) $defaults['max_width'] ) {
			$rules[] = 'max-width:' . (int) $settings['max_width'] . 'px';
		}

		if ( (int) $settings['font_size'] !== (int) $defaults['font_size'] ) {
			$rules[] = 'font-size:' . (int) $settings['font_size'] . 'px';
		}

		if ( '' !== $settings['font_family'] ) {
			$rules[] = 'font-family:' . $settings['font_family'];
		}

		/**
		 * Filters the plain CSS declarations for the booking widget's root.
		 *
		 * @param string $css      `prop:value;` pairs, or '' when nothing was customized.
		 * @param array  $settings The current appearance settings.
		 */
		return apply_filters( 'byzo_appearance_css_declarations', $rules ? implode( ';', $rules ) . ';' : '', $settings );
	}

	private static function color_fields() {
		return array(
			'primary_color',
			'primary_dark_color',
			'primary_light_color',
			'text_color',
			'muted_text_color',
			'border_color',
			'surface_color',
		);
	}

	private static function numeric_fields() {
		return array(
			'radius'    => array( 'min' => 0, 'max' => 40 ),
			'radius_sm' => array( 'min' => 0, 'max' => 40 ),
			'max_width' => array( 'min' => 280, 'max' => 900 ),
			'font_size' => array( 'min' => 11, 'max' => 22 ),
		);
	}

	/**
	 * @return string|false The normalized `#rrggbb`, or false if unusable.
	 */
	private static function sanitize_color( $value ) {
		$color = sanitize_hex_color( trim( (string) $value ) );

		return $color ? $color : false;
	}

	private static function sanitize_font_family( $value ) {
		$value = sanitize_text_field( (string) $value );

		// Anything that could terminate the declaration or open a new one
		// is removed outright rather than escaped — a legitimate
		// font-family list needs none of these characters.
		$value = str_replace( array( ';', '{', '}', '<', '>', '"', "'", '\\', '(', ')' ), '', $value );

		return trim( $value );
	}
}
