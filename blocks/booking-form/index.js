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

	// Same 3 types ResourceTypeStep.vue's Add-resource picker offers
	// (DETAILED-SPEC.md §5 item 6) — Event is left out here too until
	// Phase 1.5 step 5's occurrence model ships; filtering the widget to a
	// type with zero resources behind it isn't useful either way.
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
			resourceId: { type: 'number', default: 0 },
			type: { type: 'string', default: '' },
		},
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			// Fetches the same guest-allowed GET /resources/public the
			// frontend widget itself uses (DETAILED-SPEC.md §A5) — not the
			// admin-gated /resources — so this selector works for any user
			// who can open the block editor, not just manage_options users.
			var stateResources = useState( [] );
			var resources = stateResources[ 0 ];
			var setResources = stateResources[ 1 ];

			useEffect( function () {
				apiFetch( { path: '/bookyzo/v1/resources/public' } )
					.then( setResources )
					.catch( function () {
						setResources( [] );
					} );
			}, [] );

			var filteredResources = attributes.type
				? resources.filter( function ( r ) {
						return r.type === attributes.type;
				  } )
				: resources;

			var resourceOptions = [ { label: __( 'Any active resource', 'bookyzo' ), value: 0 } ].concat(
				filteredResources.map( function ( r ) {
					return { label: r.name, value: r.id };
				} )
			);

			// A pinned resource_id skips the picker step entirely
			// (DETAILED-SPEC.md §A5), so `type` has nothing left to filter
			// once a specific resource is chosen — matches
			// BYZO_Public_Widget::render()'s own resource_id-over-type
			// precedence server-side.
			var resourceIsPinned = !! attributes.resourceId;

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
							disabled: resourceIsPinned,
							onChange: function ( value ) {
								setAttributes( { type: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Resource', 'bookyzo' ),
							help: __( 'Pin to one resource, or let visitors pick.', 'bookyzo' ),
							value: attributes.resourceId || 0,
							options: resourceOptions,
							onChange: function ( value ) {
								setAttributes( { resourceId: parseInt( value, 10 ) || 0 } );
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
