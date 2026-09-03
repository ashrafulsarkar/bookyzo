/**
 * Gutenberg editor script for `bookyzo/booking-form` (DETAILED-SPEC.md
 * §A5). Deliberately plain JS against WP core's own globals
 * (wp.blocks/wp.element/wp.blockEditor/wp.components), not a Vite-built
 * file — the block editor UI here is tiny (a placeholder box + one
 * optional numeric setting) and doesn't need Vue or a build step; the
 * actual booking widget users interact with is a completely separate
 * app (src/widget/) that only renders on the FRONT end via
 * BYZO_Public_Widget::render_block()'s PHP render_callback, never in
 * the editor itself.
 */
( function ( blocks, element, blockEditor, components, i18n, apiFetch ) {
	var el = element.createElement;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;

	// Same 3 types BookableItemTypeStep.vue's Add-bookable-item picker
	// offers (DETAILED-SPEC.md §5 item 6) — Event is left out here too
	// until Phase 1.5 step 5's occurrence model ships; filtering the
	// widget to a type with zero items behind it isn't useful either way.
	var TYPE_OPTIONS = [
		{ label: __( 'Any type', 'bookyzo' ), value: '' },
		{ label: __( 'Appointment', 'bookyzo' ), value: 'appointment' },
		{ label: __( 'Table', 'bookyzo' ), value: 'table' },
		{ label: __( 'Slot', 'bookyzo' ), value: 'slot' },
	];

	blocks.registerBlockType( 'bookyzo/booking-form', {
		title: __( 'Booking Form', 'bookyzo' ),
		description: __( 'Lets visitors book an appointment.', 'bookyzo' ),
		icon: 'calendar-alt',
		category: 'widgets',
		attributes: {
			bookableItemId: { type: 'number', default: 0 },
			type: { type: 'string', default: '' },
		},
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			// Fetches the same guest-allowed GET /bookable-items/public the
			// frontend widget itself uses (DETAILED-SPEC.md §A5) — not the
			// admin-gated /bookable-items — so this selector works for any
			// user who can open the block editor, not just manage_options users.
			var stateItems = useState( [] );
			var items = stateItems[ 0 ];
			var setItems = stateItems[ 1 ];

			useEffect( function () {
				apiFetch( { path: '/bookyzo/v1/bookable-items/public' } )
					.then( setItems )
					.catch( function () {
						setItems( [] );
					} );
			}, [] );

			var filteredItems = attributes.type
				? items.filter( function ( i ) {
						return i.type === attributes.type;
				  } )
				: items;

			var itemOptions = [ { label: __( 'Any active bookable item', 'bookyzo' ), value: 0 } ].concat(
				filteredItems.map( function ( i ) {
					return { label: i.name, value: i.id };
				} )
			);

			// A pinned bookable_item_id skips the picker step entirely
			// (DETAILED-SPEC.md §A5), so `type` has nothing left to filter
			// once a specific item is chosen — matches
			// BYZO_Public_Widget::render()'s own bookable_item_id-over-type
			// precedence server-side.
			var itemIsPinned = !! attributes.bookableItemId;

			return el(
				element.Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Booking Form Settings', 'bookyzo' ) },
						el( SelectControl, {
							label: __( 'Booking type', 'bookyzo' ),
							value: attributes.type,
							options: TYPE_OPTIONS,
							disabled: itemIsPinned,
							onChange: function ( value ) {
								setAttributes( { type: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Bookable item', 'bookyzo' ),
							help: __( 'Pin to one bookable item, or let visitors pick.', 'bookyzo' ),
							value: attributes.bookableItemId || 0,
							options: itemOptions,
							onChange: function ( value ) {
								setAttributes( { bookableItemId: parseInt( value, 10 ) || 0 } );
							},
						} )
					)
				),
				el(
					'div',
					{
						style: {
							border: '1px dashed #c3c4c7',
							borderRadius: '8px',
							padding: '24px',
							textAlign: 'center',
							color: '#50575e',
						},
					},
					__( 'Bookyzo booking form will appear here.', 'bookyzo' )
				)
			);
		},
		// Server-rendered (render_callback in PHP) — the editor doesn't
		// need to reproduce the actual widget markup, just a preview.
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.apiFetch );
