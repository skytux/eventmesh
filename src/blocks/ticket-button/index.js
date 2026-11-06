( function ( blocks, element, blockEditor, components, i18n, data ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var RichText = blockEditor.RichText;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var ToggleControl = components.ToggleControl;
	var TextControl = components.TextControl;
	var __ = i18n.__;
	var sprintf = i18n.sprintf;
	var useSelect = data.useSelect;

	// Shown when the editor has no event to read a price from (a template,
	// a pattern), so the preview still looks like the front-end button.
	var SAMPLE_PRICE = '22.00 EUR';

	// The event's price the way the front end resolves it (EventMeta): the
	// price typed on the edit screen, else the synced one; '' when the
	// event has no price.
	function eventPrice( meta ) {
		if ( ! meta ) {
			return '';
		}

		var manual = String( meta._eventmesh_manual_price || '' ).trim();

		return '' !== manual ? manual : String( meta._eventmesh_price || '' ).trim();
	}

	blocks.registerBlockType( 'eventmesh/ticket-button', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			// Absent means a button saved before this toggle existed; treat
			// it as "on" so the price still shows, matching the render side.
			var showPrice = attributes.showPrice !== false;

			var postId = props.context && props.context.postId;
			var postType = ( props.context && props.context.postType ) || 'eventmesh_event';
			// null while there is no event to read (or it is still loading).
			var price = useSelect(
				function ( select ) {
					if ( ! postId || 'eventmesh_event' !== postType ) {
						return null;
					}

					var record = select( 'core' ).getEntityRecord( 'postType', postType, postId );

					return record ? eventPrice( record.meta ) : null;
				},
				[ postId, postType ]
			);

			if ( null === price ) {
				price = SAMPLE_PRICE;
			}

			// The same label as the front end (EventListBlock::
			// ticketButtonLabel()): with "Show price" on and a price known,
			// "Tickets 22.00 EUR", or the price alone after an own prefix.
			var priceLabel = '';

			if ( showPrice && '' !== price ) {
				var prefix = attributes.prefix || '';

				priceLabel =
					prefix +
					( '' === prefix
						? sprintf(
								/* translators: %s: the ticket price, e.g. "22.00 EUR". */
								__( 'Tickets %s', 'eventmesh' ),
								price
						  )
						: price ) +
					( attributes.suffix || '' );
			}

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
					'' !== priceLabel
						? el( 'span', {}, priceLabel )
						: el( RichText, {
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
	window.wp.i18n,
	window.wp.data
);
