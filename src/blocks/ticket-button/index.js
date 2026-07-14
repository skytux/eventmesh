( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var RichText = blockEditor.RichText;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var ToggleControl = components.ToggleControl;
	var TextControl = components.TextControl;
	var __ = i18n.__;

	blocks.registerBlockType( 'eventmesh/ticket-button', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			// Absent means a button saved before this toggle existed; treat
			// it as "on" so the price still shows, matching the render side.
			var showPrice = attributes.showPrice !== false;

			// Reuses core/button's own CSS classes so this looks like a
			// normal button under any theme without needing its own
			// stylesheet - the classes are purely presentational, this
			// isn't a real core/button instance.
			var blockProps = useBlockProps( {
				className: 'wp-block-button__link wp-element-button',
			} );

			return el(
				Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Ticket button', 'eventmesh' ) },
						el( ToggleControl, {
							label: __( 'Show price', 'eventmesh' ),
							help: __(
								'When on, the button shows the event price if one is known, otherwise the label below. When off, it always shows the label.',
								'eventmesh'
							),
							checked: showPrice,
							onChange: function ( value ) {
								setAttributes( { showPrice: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Prefix', 'eventmesh' ),
							help: __(
								'Shown before the button label, e.g. "From ". Skipped on the "Sold out" state.',
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
								'Shown after the button label, e.g. " →". Skipped on the "Sold out" state.',
								'eventmesh'
							),
							value: attributes.suffix || '',
							onChange: function ( value ) {
								setAttributes( { suffix: value } );
							},
						} )
					)
				),
				el(
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
	window.wp.i18n
);
