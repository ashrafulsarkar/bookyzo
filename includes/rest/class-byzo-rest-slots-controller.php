<?php
defined( 'ABSPATH' ) || exit;

/**
 * /slots — public read endpoint the booking widget calls to show
 * available times for a bookable item (DETAILED-SPEC.md §4). Guest-allowed
 * by design: this is browse-only (no write), so `public_permissions_check()`
 * is the right gate, not `admin_permissions_check()`.
 */
class BYZO_REST_Slots_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'slots';
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base(),
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'public_permissions_check' ),
					// bookable_item_id and service_id are mutually exclusive
					// alternatives (Phase 2 step 5, DETAILED-SPEC.md §3 B7) —
					// neither is marked `required` at the schema level since
					// which one applies depends on the other; get_items()
					// itself 400s if neither is present.
					'args'                => array(
						'bookable_item_id' => array( 'type' => 'integer' ),
						'service_id'       => array( 'type' => 'integer' ),
						'from'             => array( 'type' => 'string' ),
						'to'               => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	public function get_items( WP_REST_Request $request ) {
		$service_id = (int) $request->get_param( 'service_id' );

		// Neither identifying param present — this used to silently fall
		// through to the bookable_item_id branch below with an implicit
		// id of 0, which BYZO_Bookable_Item::get() correctly treats as
		// "not found" (404) — the same response a genuinely wrong id
		// gets, which is the wrong signal for a caller that simply forgot
		// the param. This method's own docblock in register_routes()
		// already documented the intended 400 here; the check itself was
		// just never actually written (found stale 2026-09-13, Phase 5's
		// own regression pass).
		if ( ! $service_id && ! $request->get_param( 'bookable_item_id' ) ) {
			return new WP_Error( 'byzo_missing_param', __( 'bookable_item_id or service_id is required.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		// Phase 2 step 5 (DETAILED-SPEC.md §3 B7) — "any available staff"
		// browsing: a combined list across every bookable item offering
		// the service, resolved the same way BYZO_Booking::create_for_service()
		// resolves candidates for the actual booking. Free never populates
		// this filter, so this path simply returns an empty list without
		// Pro — no separate "is Pro active" check needed here either.
		if ( $service_id ) {
			$candidates = apply_filters( 'byzo_bookable_items_offering_service', array(), $service_id );

			$slots = BYZO_Slot::query_for_bookable_items(
				$candidates,
				array(
					'from'          => $request->get_param( 'from' ),
					'to'            => $request->get_param( 'to' ),
					'upcoming_only' => BYZO_Slot::lead_time_applies(),
				)
			);

			if ( is_wp_error( $slots ) ) {
				return $slots;
			}

			return rest_ensure_response( $slots );
		}

		$item = BYZO_Bookable_Item::get( (int) $request->get_param( 'bookable_item_id' ) );

		// Same "not found" response whether the item never existed or is
		// inactive/archived — the public widget has no legitimate need to
		// distinguish those, and archived items shouldn't leak via
		// enumeration either.
		if ( ! $item || 'active' !== $item['status'] ) {
			return new WP_Error( 'byzo_not_found', __( 'Bookable item not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		// Places held by abandoned online checkouts whose hold has run out
		// are given back before the times are listed (1.0.3), instead of
		// showing as taken until the hourly sweep. Nothing a visitor sends
		// decides what is released: only bookings already past their hold.
		BYZO_Booking::release_expired_holds( array( 'bookable_item_id' => $item['id'] ) );

		$slots = BYZO_Slot::query(
			array(
				'bookable_item_id' => $item['id'],
				'from'             => $request->get_param( 'from' ),
				'to'               => $request->get_param( 'to' ),
				'open_only'        => true,
				// Full slots too, marked status "full", when the site keeps a
				// waitlist and the widget asks (QA report §6.2.6).
				'include_full'     => $request->get_param( 'include_full' ) && BYZO_Capabilities::waitlist_enabled(),
				// Past slots and ones inside the minimum advance notice stay
				// hidden from customers; admins still see the whole day
				// (walk-ins, phone bookings, rescheduling).
				'upcoming_only'    => BYZO_Slot::lead_time_applies( $item['id'] ),
			)
		);

		if ( is_wp_error( $slots ) ) {
			return $slots;
		}

		// A time whose places are held for this visitor (1.0.3, a "your place
		// is held" link with hold_id + hold_token) is listed for them as
		// bookable, with just the held places, even though it reads full to
		// everyone else. Free holds nothing itself; an add-on answers.
		$hold_id = absint( $request->get_param( 'hold_id' ) );
		if ( $hold_id ) {
			/**
			 * Filters which of this item's slots are held for the visitor
			 * carrying this hold, and how many places each.
			 *
			 * @param array<int,int> $held    slot_id => places.
			 * @param int            $item_id
			 * @param array          $hold    [ 'id' => int, 'token' => string ].
			 */
			$held = (array) apply_filters( 'byzo_slots_held_for_visitor', array(), (int) $item['id'], array( 'id' => $hold_id, 'token' => substr( sanitize_text_field( (string) $request->get_param( 'hold_token' ) ), 0, 64 ) ) );
			$listed = array();
			foreach ( $slots as $index => $slot ) {
				$listed[ (int) $slot['id'] ] = $index;
			}
			foreach ( $held as $slot_id => $places ) {
				$slot = BYZO_Slot::get( (int) $slot_id );
				if ( ! $slot || (int) $slot['bookable_item_id'] !== (int) $item['id'] || 'blocked' === $slot['status'] ) {
					continue;
				}
				$slot['status']             = 'open';
				$slot['capacity_remaining'] = max( 1, (int) $places );
				$slot['held_for_you']       = true;
				if ( isset( $listed[ (int) $slot_id ] ) ) {
					$slots[ $listed[ (int) $slot_id ] ] = $slot;
				} else {
					$slots[] = $slot;
				}
			}
			usort(
				$slots,
				static function ( $a, $b ) {
					return strcmp( $a['start_datetime'], $b['start_datetime'] );
				}
			);
		}

		return rest_ensure_response( $slots );
	}
}
