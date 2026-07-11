( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var RangeControl = components.RangeControl;
	var SelectControl = components.SelectControl;
	var ServerSideRender = serverSideRender;

	blocks.registerBlockType( 'eventmesh/event-list', {
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
						{ title: __( 'Event list settings', 'eventmesh' ) },
						el( RangeControl, {
							label: __( 'Number of events', 'eventmesh' ),
							value: attributes.count,
							onChange: function ( value ) {
								setAttributes( { count: value } );
							},
							min: 1,
							max: 12,
						} ),
						el( SelectControl, {
							label: __( 'Layout', 'eventmesh' ),
							value: attributes.template,
							options: [
								{ label: __( 'List', 'eventmesh' ), value: 'events-list' },
								{ label: __( 'Cards', 'eventmesh' ), value: 'events-card' },
							],
							onChange: function ( value ) {
								setAttributes( { template: value } );
							},
						} )
					)
				),
				el( ServerSideRender, {
					block: 'eventmesh/event-list',
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
