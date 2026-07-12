( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var SelectControl = components.SelectControl;
	var ServerSideRender = serverSideRender;

	blocks.registerBlockType( 'eventmesh/past-events-marker', {
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
						{ title: __( 'Past events marker', 'eventmesh' ) },
						el( TextControl, {
							label: __( 'Text', 'eventmesh' ),
							value: attributes.text,
							onChange: function ( value ) {
								setAttributes( { text: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'HTML tag', 'eventmesh' ),
							value: attributes.tag,
							options: [
								{ label: __( 'Heading 1', 'eventmesh' ), value: 'h1' },
								{ label: __( 'Heading 2', 'eventmesh' ), value: 'h2' },
								{ label: __( 'Heading 3', 'eventmesh' ), value: 'h3' },
								{ label: __( 'Heading 4', 'eventmesh' ), value: 'h4' },
								{ label: __( 'Heading 5', 'eventmesh' ), value: 'h5' },
								{ label: __( 'Heading 6', 'eventmesh' ), value: 'h6' },
								{ label: __( 'Paragraph', 'eventmesh' ), value: 'p' },
							],
							onChange: function ( value ) {
								setAttributes( { tag: value } );
							},
						} )
					)
				),
				el( ServerSideRender, {
					block: 'eventmesh/past-events-marker',
					attributes: attributes,
					EmptyResponsePlaceholder: function () {
						return null;
					},
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
