( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var __ = i18n.__;
	var ServerSideRender = serverSideRender;

	blocks.registerBlockType( 'eventmesh/provider-embed', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el(
				Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Provider embed', 'eventmesh' ) },
						el( TextControl, {
							label: __( 'Play button text', 'eventmesh' ),
							help: __(
								'Shown on the button that loads the player. Only used while "Show a play button and load the player only when clicked" is on under Settings.',
								'eventmesh'
							),
							// Deliberately not "value || default": an empty box
							// is a real state meaning "fall back to the
							// default", so filling it back in here would undo
							// the clearing as fast as it was typed.
							value:
								attributes.playLabel === undefined
									? ''
									: attributes.playLabel,
							onChange: function ( value ) {
								setAttributes( { playLabel: value } );
							},
						} )
					)
				),
				el(
					'div',
					useBlockProps(),
					// Rendered server-side, so the editor preview is the real
					// button with the text above already applied.
					el( ServerSideRender, {
						block: 'eventmesh/provider-embed',
						attributes: attributes,
						EmptyResponsePlaceholder: function () {
							return null;
						},
					} )
				)
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
