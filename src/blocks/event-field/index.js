( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var TextControl = components.TextControl;
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
								{ label: __( 'Date & time (full)', 'eventmesh' ), value: 'starts_at' },
								{ label: __( 'Title (date removed)', 'eventmesh' ), value: 'title' },
								{ label: __( 'Venue', 'eventmesh' ), value: 'venue' },
								{ label: __( 'Price', 'eventmesh' ), value: 'price' },
								{ label: __( 'Date range', 'eventmesh' ), value: 'date_range' },
								{ label: __( 'Start date', 'eventmesh' ), value: 'start_date' },
								{ label: __( 'End date', 'eventmesh' ), value: 'end_date' },
								{ label: __( 'Start time', 'eventmesh' ), value: 'start_time' },
								{ label: __( 'End time', 'eventmesh' ), value: 'end_time' },
								{ label: __( 'Time range', 'eventmesh' ), value: 'time_range' },
							],
							onChange: function ( value ) {
								setAttributes( { field: value } );
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
						} ),
						el( ToggleControl, {
							label: __( 'Link to the event page', 'eventmesh' ),
							checked: !! attributes.linked,
							onChange: function ( value ) {
								setAttributes( { linked: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Prefix', 'eventmesh' ),
							help: __(
								'Shown before the value, only when the value exists. Include your own spacing, e.g. "at ".',
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
								'Shown after the value, only when the value exists. Include your own spacing, e.g. " onwards".',
								'eventmesh'
							),
							value: attributes.suffix || '',
							onChange: function ( value ) {
								setAttributes( { suffix: value } );
							},
						} )
					)
				),
				el( ServerSideRender, {
					block: 'eventmesh/event-field',
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
