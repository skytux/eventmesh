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

	// The event's sold-out state the way the front end resolves it
	// (EventMeta::isSoldOut()): the edit screen's choice ('1' sold out, '0'
	// available), else the synced value.
	function eventSoldOut( meta ) {
		if ( ! meta ) {
			return false;
		}

		var manual = String( meta._eventmesh_manual_sold_out || '' );

		if ( '1' === manual || '0' === manual ) {
			return '1' === manual;
		}

		return '1' === String( meta._eventmesh_sold_out || '' );
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
			var event = useSelect(
				function ( select ) {
					if ( ! postId || 'eventmesh_event' !== postType ) {
						return null;
					}

					var record = select( 'core' ).getEntityRecord( 'postType', postType, postId );

					return record
						? { price: eventPrice( record.meta ), soldOut: eventSoldOut( record.meta ) }
						: null;
				},
				[ postId, postType ]
			);

			var price = event ? event.price : SAMPLE_PRICE;
			var soldOut = !! ( event && event.soldOut );

			// WordPress fills in block.json's English default ("Tickets")
			// for a label that was never changed; the front end translates
			// that value (EventListBlock::ticketButtonLabel()), so show it
			// translated here too. An emptied label shows the placeholder
			// and gives "Tickets" on the front end.
			var label =
				'Tickets' === attributes.text
					? __( 'Tickets', 'eventmesh' )
					: attributes.text || '';

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
			//
			// Sold out is a status that replaces the label, price, prefix and
			// suffix, the same as on the front end, which also draws it as
			// an outline (frontend.css, which the editor does not load).
			var blockProps = useBlockProps(
				soldOut
					? {
							className:
								'wp-block-button__link wp-element-button eventmesh-ticket-button--secondary',
							style: {
								background: 'transparent',
								color: 'inherit',
								boxShadow: 'inset 0 0 0 1px currentColor',
								opacity: 0.75,
							},
					  }
					: { className: 'wp-block-button__link wp-element-button' }
			);

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
							label: __( 'Label', 'eventmesh' ),
							help: __(
								'Shown on the button when the price is not shown.',
								'eventmesh'
							),
							value: label,
							placeholder: __( 'Tickets', 'eventmesh' ),
							onChange: function ( value ) {
								setAttributes( { text: value } );
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
				soldOut
					? el( 'span', blockProps, __( 'Sold out', 'eventmesh' ) )
					: el(
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
									value: label,
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
