/**
 * Editor side of the PushMails subscribe form block. No build step: plain
 * JavaScript against the globals WordPress provides. The block is rendered on
 * the server, so the preview is the real form.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var ToggleControl = wp.components.ToggleControl;
	var TextControl = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'pushmails/subscribe-form', {
		edit: function ( props ) {
			var attributes = props.attributes;
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Form', 'pushmails-for-wordpress' ) },
						el( ToggleControl, {
							label: __( 'Ask for first name', 'pushmails-for-wordpress' ),
							checked: !! attributes.showName,
							onChange: function ( value ) {
								props.setAttributes( { showName: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Button text', 'pushmails-for-wordpress' ),
							value: attributes.buttonText,
							placeholder: __( 'Subscribe', 'pushmails-for-wordpress' ),
							onChange: function ( value ) {
								props.setAttributes( { buttonText: value } );
							},
						} )
					)
				),
				el( 'div', { style: { pointerEvents: 'none' } },
					el( ServerSideRender, { block: 'pushmails/subscribe-form', attributes: attributes } )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
