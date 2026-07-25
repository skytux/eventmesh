( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
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
						} ),
						el( TextControl, {
							label: __( 'Prefix', 'eventmesh' ),
							help: __(
								'Shown before the text above. Spacing is used exactly as typed.',
								'eventmesh'
							),
							value: attributes.prefix || '',
							onChange: function ( value ) {
								setAttributes( { prefix: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Suffix', 'eventmesh' ),
							help: __(
								'Shown after the text above, e.g. " on " to join it to the platform name below. Spacing is used exactly as typed.',
								'eventmesh'
							),
							value: attributes.suffix || '',
							onChange: function ( value ) {
								setAttributes( { suffix: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Add the platform name', 'eventmesh' ),
							help: __(
								'Ends the button with the platform the player comes from, e.g. "Spotify". On an embed from a platform EventMesh cannot name, the suffix is left off too, so the button never trails off mid-sentence.',
								'eventmesh'
							),
							checked: attributes.showPlatform === true,
							onChange: function ( value ) {
								setAttributes( { showPlatform: value } );
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
