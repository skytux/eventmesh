( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var ServerSideRender = serverSideRender;

	blocks.registerBlockType( 'eventmesh/event-field', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Event field', 'eventmesh' ) },
						el( SelectControl, {
							label: __( 'Field', 'eventmesh' ),
							value: attributes.field,
							options: [
								{ label: __( 'Start date', 'eventmesh' ), value: 'starts_at' },
								{ label: __( 'Title (date removed)', 'eventmesh' ), value: 'title' },
								{ label: __( 'Venue', 'eventmesh' ), value: 'venue' },
							],
							onChange: function ( value ) {
								setAttributes( { field: value } );
							},
						} )
					)
				),
				el( ServerSideRender, {
					block: 'eventmesh/event-field',
					attributes: attributes,
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n,
	window.wp.serverSideRender
);
