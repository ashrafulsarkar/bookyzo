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
( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;

	blocks.registerBlockType( 'bookyzo/booking-form', {
		title: __( 'Booking Form', 'bookyzo' ),
		description: __( 'Lets visitors book an appointment.', 'bookyzo' ),
		icon: 'calendar-alt',
		category: 'widgets',
		attributes: {
			resourceId: { type: 'number', default: 0 },
		},
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el(
				element.Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Booking Form Settings', 'bookyzo' ) },
						el( TextControl, {
							label: __( 'Resource ID (optional)', 'bookyzo' ),
							help: __( 'Leave empty to let visitors pick from all active resources.', 'bookyzo' ),
							type: 'number',
							value: attributes.resourceId || '',
							onChange: function ( value ) {
								setAttributes( { resourceId: value ? parseInt( value, 10 ) : 0 } );
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
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
