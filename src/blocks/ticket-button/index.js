( function ( blocks, element, blockEditor, i18n ) {
	var el = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;
	var RichText = blockEditor.RichText;
	var __ = i18n.__;

	blocks.registerBlockType( 'eventmesh/ticket-button', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			// Reuses core/button's own CSS classes so this looks like a
			// normal button under any theme without needing its own
			// stylesheet - the classes are purely presentational, this
			// isn't a real core/button instance.
			var blockProps = useBlockProps( {
				className: 'wp-block-button__link wp-element-button',
			} );

			return el(
				'a',
				Object.assign( {}, blockProps, {
					href: '#',
					onClick: function ( event ) {
						event.preventDefault();
					},
				} ),
				el( RichText, {
					tagName: 'span',
					value: attributes.text,
					onChange: function ( value ) {
						setAttributes( { text: value } );
					},
					placeholder: __( 'Tickets', 'eventmesh' ),
					allowedFormats: [],
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.i18n );
