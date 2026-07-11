( function ( blocks, element, blockEditor, serverSideRender ) {
	var el = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;
	var ServerSideRender = serverSideRender;

	blocks.registerBlockType( 'eventmesh/other-provider-links', {
		edit: function ( props ) {
			return el(
				'div',
				useBlockProps(),
				el( ServerSideRender, {
					block: 'eventmesh/other-provider-links',
					attributes: props.attributes,
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
