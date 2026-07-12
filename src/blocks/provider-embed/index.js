( function ( blocks, element, blockEditor, serverSideRender ) {
	var el = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;
	var ServerSideRender = serverSideRender;

	blocks.registerBlockType( 'eventmesh/provider-embed', {
		edit: function ( props ) {
			return el(
				'div',
				useBlockProps(),
				el( ServerSideRender, {
					block: 'eventmesh/provider-embed',
					attributes: props.attributes,
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
	window.wp.serverSideRender
);
