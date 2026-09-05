<?php
defined( 'ABSPATH' ) || exit;

/**
 * Elementor widget for the booking form (DETAILED-SPEC.md §A5, added
 * 2026-09-05, Phase 1.5 step 9 competitor gap-fill) — mirrors the
 * Gutenberg block (`blocks/booking-form/index.js`): same
 * bookable_item_id/type config, same mount point
 * (BYZO_Public_Widget::render_widget()), no new PHP rendering logic of
 * its own. Purely another editor-side entry point onto the existing
 * widget bundle — this class only exists at all when Elementor is
 * active (see BYZO_Public_Widget::maybe_register_elementor_widget()),
 * so it can safely extend \Elementor\Widget_Base.
 */
class BYZO_Elementor_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'bookyzo-booking-form';
	}

	public function get_title() {
		return __( 'Bookyzo Booking Form', 'bookyzo' );
	}

	public function get_icon() {
		return 'eicon-calendar';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'booking', 'appointment', 'reservation', 'event', 'calendar' );
	}

	/**
	 * Same 4 types the Gutenberg block/BookableItemTypeStep.vue offer
	 * (DETAILED-SPEC.md §5 item 6). The bookable-item options are built
	 * server-side here (an admin-context editor screen, unlike the
	 * Gutenberg block's client-side REST fetch) — both end up filtering
	 * to the same guest-allowed data set BYZO_Bookable_Item::query()
	 * already serves.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'byzo_section',
			array(
				'label' => __( 'Booking Form Settings', 'bookyzo' ),
			)
		);

		$this->add_control(
			'byzo_type',
			array(
				'label'   => __( 'Booking type', 'bookyzo' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''            => __( 'Any type', 'bookyzo' ),
					'appointment' => __( 'Appointment', 'bookyzo' ),
					'table'       => __( 'Table', 'bookyzo' ),
					'event'       => __( 'Event', 'bookyzo' ),
					'slot'        => __( 'Slot', 'bookyzo' ),
				),
			)
		);

		$this->add_control(
			'byzo_bookable_item_id',
			array(
				'label'       => __( 'Bookable item', 'bookyzo' ),
				'description' => __( 'Pin to one bookable item, or leave on "Let visitors pick" to show the type filter above instead.', 'bookyzo' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '0',
				'options'     => $this->bookable_item_options(),
			)
		);

		$this->end_controls_section();
	}

	private function bookable_item_options() {
		$options = array( '0' => __( 'Let visitors pick', 'bookyzo' ) );

		if ( ! class_exists( 'BYZO_Bookable_Item' ) ) {
			return $options;
		}

		$items = BYZO_Bookable_Item::query( array( 'status' => 'active', 'per_page' => 100 ) );
		foreach ( $items['items'] as $item ) {
			$options[ (string) $item['id'] ] = $item['name'];
		}

		return $options;
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		// bookable_item_id takes precedence over type, same as the
		// shortcode/block's own precedence (BYZO_Public_Widget::render()'s
		// elseif) — a pinned item already skips the picker step, so a
		// type has nothing left to filter.
		echo BYZO_Public_Widget::render_widget( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_widget() returns a fixed, already-escaped mount-div markup string, same as the shortcode/block callbacks that echo it directly too.
			array(
				'bookable_item_id' => ! empty( $settings['byzo_bookable_item_id'] ) ? $settings['byzo_bookable_item_id'] : '',
				'type'             => ! empty( $settings['byzo_type'] ) ? $settings['byzo_type'] : '',
			)
		);
	}
}
