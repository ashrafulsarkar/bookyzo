<?php
defined( 'ABSPATH' ) || exit;

/**
 * Staff logins (QA report 2026-09-22 §6.2.9, FEATURES.md B1): a WordPress
 * user linked to one or more bookable items (`bookable_items.wp_user_id`)
 * who is not a site admin gets a narrow view of the admin app — the
 * Bookings and Calendar screens, showing only the bookings of their own
 * items. They can confirm, complete, cancel, reschedule and mark those
 * bookings paid; everything else (settings, other items, customers,
 * reports) stays admin-only.
 *
 * Nothing here is ever true on Free alone: BYZO_Capabilities::
 * staff_logins_enabled() is false until an add-on turns it on, and only
 * that add-on links users to items.
 */
class BYZO_Staff {

	/**
	 * Per-request cache of item ids by user id.
	 *
	 * @var array<int,int[]>
	 */
	private static $cache = array();

	/**
	 * The item ids a user works, or an empty array for anyone who isn't a
	 * staff login (including while staff logins are switched off).
	 *
	 * @param int $user_id WordPress user id; 0 = the current user.
	 * @return int[]
	 */
	public static function item_ids( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id || ! BYZO_Capabilities::staff_logins_enabled() ) {
			return array();
		}

		if ( ! isset( self::$cache[ $user_id ] ) ) {
			self::$cache[ $user_id ] = BYZO_Bookable_Item::ids_for_wp_user( $user_id );
		}

		return self::$cache[ $user_id ];
	}

	/**
	 * Whether the user gets the staff view: linked to at least one item
	 * and not an admin (an admin linked to an item keeps the full app).
	 *
	 * @param int $user_id WordPress user id; 0 = the current user.
	 * @return bool
	 */
	public static function is_staff( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		return $user_id && ! user_can( $user_id, 'manage_options' ) && (bool) self::item_ids( $user_id );
	}

	/**
	 * Whether the current user may work with this item's bookings: any
	 * admin, or a staff login linked to it.
	 *
	 * @param int $item_id Bookable item id.
	 * @return bool
	 */
	public static function can_access_item( $item_id ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return self::is_staff() && in_array( (int) $item_id, self::item_ids(), true );
	}

	/**
	 * Whether the current user may work with this booking.
	 *
	 * @param int $booking_id Booking id.
	 * @return bool
	 */
	public static function can_access_booking( $booking_id ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$booking = BYZO_Booking::get( (int) $booking_id );

		return $booking && self::can_access_item( $booking['bookable_item_id'] );
	}

	/**
	 * Forgets cached links (tests, and after an add-on changes a link
	 * mid-request).
	 */
	public static function flush() {
		self::$cache = array();
	}
}
