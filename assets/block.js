/**
 * The three blocks: Amazon Product Box, Amazon Comparison Table and Amazon
 * Bestseller List. Each is drawn by the server
 * (Amazon_Product_Boxes_Render), so the editor shows exactly what visitors
 * see. Written without JSX so there's no build step.
 *
 * Products are added by pasting an ASIN or Amazon link (looked up through
 * /amazon-product-boxes/v1/lookup) or, in API mode, by searching Amazon
 * (/amazon-product-boxes/v1/search). window.apbBlock holds the mode and
 * options (see Amazon_Product_Boxes_Render::block_editor_assets).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.components ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var c = wp.components;
	var be = wp.blockEditor || wp.editor;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var apiFetch = wp.apiFetch;
	var ServerSideRender = wp.serverSideRender || c.ServerSideRender;
	var data = window.apbBlock || { api: false, indexes: {}, sorts: {} };
	var MAX = 10;

	function svg( children ) {
		return el( 'svg', { width: 24, height: 24, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' }, children );
	}

	var icons = {
		box: svg( [
			el( 'path', { key: 1, fill: 'none', d: 'M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z' } ),
			el( 'path', { key: 2, fill: 'none', d: 'M12 22V12' } ),
			el( 'polyline', { key: 3, fill: 'none', points: '3.29 7 12 12 20.71 7' } ),
			el( 'path', { key: 4, fill: 'none', d: 'm7.5 4.27 9 5.15' } ),
		] ),
		table: svg( [
			el( 'rect', { key: 1, fill: 'none', width: 18, height: 18, x: 3, y: 3, rx: 2 } ),
			el( 'path', { key: 2, fill: 'none', d: 'M9 3v18' } ),
			el( 'path', { key: 3, fill: 'none', d: 'M15 3v18' } ),
		] ),
		list: svg( [
			el( 'path', { key: 1, fill: 'none', d: 'M11 5h10' } ),
			el( 'path', { key: 2, fill: 'none', d: 'M11 12h10' } ),
			el( 'path', { key: 3, fill: 'none', d: 'M11 19h10' } ),
			el( 'path', { key: 4, fill: 'none', d: 'M4 4h1v5' } ),
			el( 'path', { key: 5, fill: 'none', d: 'M4 9h2' } ),
			el( 'path', { key: 6, fill: 'none', d: 'M6.5 20H3.4c0-1 2.6-1.925 2.6-3.5a1.5 1.5 0 0 0-2.6-1.02' } ),
		] ),
	};

	function options( map ) {
		return Object.keys( map ).map( function ( key ) {
			return { value: key, label: map[ key ] };
		} );
	}

	function errorText( error ) {
		return ( error && error.message ) || __( 'Something went wrong. Please try again.', 'amazon-product-boxes' );
	}

	/**
	 * Server-drawn preview.
	 */
	function Preview( props ) {
		return el(
			c.Disabled,
			null,
			el( ServerSideRender, { block: props.block, attributes: props.attributes, httpMethod: 'POST' } )
		);
	}

	/**
	 * Mode hint shown in the placeholders and settings.
	 */
	function modeHint() {
		if ( ! data.tag ) {
			return el(
				c.Notice,
				{ status: 'warning', isDismissible: false, className: 'apb-editor-notice' },
				__( 'Add your Amazon Store ID under Content Powered > Amazon Product Boxes, or the links won\'t earn commission.', 'amazon-product-boxes' )
			);
		}
		if ( data.api ) {
			return null;
		}
		return el(
			'p',
			{ className: 'apb-editor-hint' },
			__( 'No-API mode: boxes show your own title and bullet points with Amazon\'s product image, and no price. They switch to live prices by themselves once your site qualifies for Amazon\'s Creators API.', 'amazon-product-boxes' )
		);
	}

	/**
	 * Search Amazon in a modal (API mode).
	 */
	function SearchModal( props ) {
		var s = useState( '' );
		var keywords = s[ 0 ];
		var setKeywords = s[ 1 ];
		var i = useState( 'All' );
		var index = i[ 0 ];
		var setIndex = i[ 1 ];
		var r = useState( null );
		var results = r[ 0 ];
		var setResults = r[ 1 ];
		var b = useState( false );
		var busy = b[ 0 ];
		var setBusy = b[ 1 ];
		var e = useState( '' );
		var error = e[ 0 ];
		var setError = e[ 1 ];

		function run( event ) {
			if ( event ) {
				event.preventDefault();
			}
			if ( ! keywords.trim() || busy ) {
				return;
			}
			setBusy( true );
			setError( '' );
			apiFetch( { path: '/amazon-product-boxes/v1/search?keywords=' + encodeURIComponent( keywords.trim() ) + '&index=' + encodeURIComponent( index ) } ).then(
				function ( response ) {
					setResults( response.results || [] );
					setBusy( false );
				},
				function ( err ) {
					setError( errorText( err ) );
					setBusy( false );
				}
			);
		}

		return el(
			c.Modal,
			{ title: __( 'Search Amazon', 'amazon-product-boxes' ), onRequestClose: props.onClose, className: 'apb-search-modal' },
			el(
				'form',
				{ className: 'apb-search-form', onSubmit: run },
				el( c.TextControl, {
					label: __( 'Search for', 'amazon-product-boxes' ),
					hideLabelFromVision: true,
					placeholder: __( 'e.g. air fryer', 'amazon-product-boxes' ),
					value: keywords,
					onChange: setKeywords,
					autoFocus: true,
				} ),
				el( c.SelectControl, {
					label: __( 'Department', 'amazon-product-boxes' ),
					hideLabelFromVision: true,
					value: index,
					options: options( data.indexes ),
					onChange: setIndex,
				} ),
				el( c.Button, { variant: 'primary', isPrimary: true, type: 'submit', isBusy: busy, disabled: busy }, __( 'Search', 'amazon-product-boxes' ) )
			),
			error ? el( c.Notice, { status: 'error', isDismissible: false }, error ) : null,
			busy && ! results ? el( c.Spinner ) : null,
			results && ! results.length ? el( 'p', null, __( 'Amazon found nothing. Try other words or another department.', 'amazon-product-boxes' ) ) : null,
			results && results.length
				? el(
					'ul',
					{ className: 'apb-results' },
					results.map( function ( item ) {
						var taken = props.taken && props.taken.indexOf( item.asin ) !== -1;
						return el(
							'li',
							{ key: item.asin, className: 'apb-result' },
							el( 'span', { className: 'apb-result-image' }, item.image ? el( 'img', { src: item.image, alt: '', loading: 'lazy' } ) : null ),
							el(
								'span',
								{ className: 'apb-result-text' },
								el( 'strong', null, item.title ),
								el(
									'span',
									{ className: 'apb-result-meta' },
									[ item.unavailable ? __( 'Currently unavailable', 'amazon-product-boxes' ) : item.price, ! item.unavailable && item.prime ? 'Prime' : '', item.rating ? '★ ' + item.rating + ' (' + item.reviews + ')' : '', item.asin ].filter( Boolean ).join( ' · ' )
								)
							),
							el(
								c.Button,
								{
									variant: 'secondary',
									isSecondary: true,
									disabled: taken,
									onClick: function () {
										props.onPick( { asin: item.asin, status: 'ok', product: item, notice: '' } );
									},
								},
								taken ? __( 'Added', 'amazon-product-boxes' ) : __( 'Insert', 'amazon-product-boxes' )
							)
						);
					} )
				)
				: null
		);
	}

	/**
	 * Paste an ASIN or link (and, in API mode, a "Search Amazon" button).
	 */
	function ProductInput( props ) {
		var v = useState( '' );
		var value = v[ 0 ];
		var setValue = v[ 1 ];
		var b = useState( false );
		var busy = b[ 0 ];
		var setBusy = b[ 1 ];
		var e = useState( '' );
		var error = e[ 0 ];
		var setError = e[ 1 ];
		var m = useState( false );
		var open = m[ 0 ];
		var setOpen = m[ 1 ];

		function pick( result ) {
			setOpen( false );
			setValue( '' );
			setError( '' );
			props.onPick( result );
		}

		function lookup( event ) {
			if ( event ) {
				event.preventDefault();
			}
			if ( ! value.trim() || busy ) {
				return;
			}
			setBusy( true );
			setError( '' );
			apiFetch( { path: '/amazon-product-boxes/v1/lookup?q=' + encodeURIComponent( value.trim() ) } ).then(
				function ( result ) {
					setBusy( false );
					if ( props.taken && props.taken.indexOf( result.asin ) !== -1 ) {
						setError( __( 'That product is already in this block.', 'amazon-product-boxes' ) );
						return;
					}
					pick( result );
				},
				function ( err ) {
					setBusy( false );
					setError( errorText( err ) );
				}
			);
		}

		return el(
			'div',
			{ className: 'apb-input' + ( props.compact ? ' is-compact' : '' ) },
			el(
				'form',
				{ className: 'apb-input-row', onSubmit: lookup },
				el( c.TextControl, {
					label: props.label || __( 'ASIN or Amazon link', 'amazon-product-boxes' ),
					hideLabelFromVision: ! props.compact,
					placeholder: __( 'Paste an ASIN or Amazon link', 'amazon-product-boxes' ),
					value: value,
					onChange: setValue,
				} ),
				el( c.Button, { variant: 'primary', isPrimary: true, type: 'submit', isBusy: busy, disabled: busy || ! value.trim() }, props.button || __( 'Add', 'amazon-product-boxes' ) ),
				data.api
					? el(
						c.Button,
						{
							variant: 'secondary',
							isSecondary: true,
							onClick: function () {
								setOpen( true );
							},
						},
						__( 'Search Amazon', 'amazon-product-boxes' )
					)
					: null
			),
			error ? el( c.Notice, { status: 'error', isDismissible: false, className: 'apb-editor-notice' }, error ) : null,
			open ? el( SearchModal, { onClose: function () { setOpen( false ); }, onPick: pick, taken: props.taken } ) : null
		);
	}

	/**
	 * Image choice for one product.
	 */
	function ImageFields( props ) {
		var item = props.item;
		var set = props.onChange;
		var MediaUpload = be.MediaUpload;
		var MediaUploadCheck = be.MediaUploadCheck || Fragment;

		return el(
			Fragment,
			null,
			el( c.SelectControl, {
				label: __( 'Image', 'amazon-product-boxes' ),
				value: item.image || 'auto',
				options: [
					{ value: 'auto', label: __( 'Amazon\'s product image', 'amazon-product-boxes' ) },
					{ value: 'custom', label: __( 'Another image', 'amazon-product-boxes' ) },
					{ value: 'none', label: __( 'No image', 'amazon-product-boxes' ) },
				],
				onChange: function ( value ) {
					set( { image: value } );
				},
			} ),
			'custom' === item.image
				? el(
					'div',
					{ className: 'apb-image-custom' },
					MediaUpload
						? el(
							MediaUploadCheck,
							null,
							el( MediaUpload, {
								allowedTypes: [ 'image' ],
								value: item.imageId || 0,
								onSelect: function ( media ) {
									set( { imageId: media && media.id ? media.id : 0, imageUrl: '' } );
								},
								render: function ( obj ) {
									return el(
										c.Button,
										{ variant: 'secondary', isSecondary: true, onClick: obj.open },
										item.imageId ? __( 'Replace image', 'amazon-product-boxes' ) : __( 'Choose from Media Library', 'amazon-product-boxes' )
									);
								},
							} )
						)
						: null,
					item.imageId
						? el( c.Button, { variant: 'link', isLink: true, isDestructive: true, onClick: function () { set( { imageId: 0 } ); } }, __( 'Remove', 'amazon-product-boxes' ) )
						: el( c.TextControl, {
							label: __( 'Or an Amazon image link', 'amazon-product-boxes' ),
							help: __( 'Must be on Amazon\'s image servers (m.media-amazon.com). Amazon doesn\'t allow downloading its images, so they\'re always linked, never copied.', 'amazon-product-boxes' ),
							value: item.imageUrl || '',
							onChange: function ( value ) {
								set( { imageUrl: value } );
							},
						} )
				)
				: null
		);
	}

	/**
	 * Text fields for one product in a table or list.
	 */
	function ItemFields( props ) {
		var item = props.item;
		var set = props.onChange;
		var fields = [
			el( c.TextControl, {
				key: 'title',
				label: __( 'Title', 'amazon-product-boxes' ),
				help: data.api ? __( 'Leave empty to use Amazon\'s title.', 'amazon-product-boxes' ) : __( 'Needed in no-API mode.', 'amazon-product-boxes' ),
				value: item.title || '',
				onChange: function ( value ) {
					set( { title: value } );
				},
			} ),
			el( c.TextControl, {
				key: 'badge',
				label: __( 'Badge', 'amazon-product-boxes' ),
				placeholder: __( 'e.g. Best overall', 'amazon-product-boxes' ),
				value: item.badge || '',
				onChange: function ( value ) {
					set( { badge: value } );
				},
			} ),
		];

		if ( props.description ) {
			fields.push(
				el( c.TextareaControl, {
					key: 'description',
					label: __( 'Bullet points, one per line', 'amazon-product-boxes' ),
					help: data.api ? __( 'Leave empty to use Amazon\'s features.', 'amazon-product-boxes' ) : '',
					rows: 3,
					value: item.description || '',
					onChange: function ( value ) {
						set( { description: value } );
					},
				} )
			);
		}

		( props.rows || [] ).forEach( function ( label, r ) {
			fields.push(
				el( c.TextControl, {
					key: 'spec' + r,
					label: label,
					value: ( item.specs && item.specs[ r ] ) || '',
					onChange: function ( value ) {
						var specs = ( item.specs || [] ).slice();
						while ( specs.length <= r ) {
							specs.push( '' );
						}
						specs[ r ] = value;
						set( { specs: specs } );
					},
				} )
			);
		} );

		fields.push( el( ImageFields, { key: 'image', item: item, onChange: set } ) );

		return el( Fragment, null, fields );
	}

	/**
	 * Settings panels for a list of products (table and list blocks).
	 */
	function ItemsPanels( props ) {
		var products = props.products;

		function update( index, changes ) {
			var next = products.slice();
			next[ index ] = Object.assign( {}, next[ index ], changes );
			props.onChange( next );
		}

		function move( index, by ) {
			var next = products.slice();
			var item = next.splice( index, 1 )[ 0 ];
			next.splice( index + by, 0, item );
			props.onChange( next );
		}

		return products.map( function ( item, index ) {
			return el(
				c.PanelBody,
				{ key: item.asin + index, title: ( index + 1 ) + '. ' + ( item.title || item.asin ), initialOpen: false },
				el(
					'div',
					{ className: 'apb-item-actions' },
					el( 'code', null, item.asin ),
					el( c.Button, { icon: 'arrow-up-alt2', label: __( 'Move up', 'amazon-product-boxes' ), disabled: 0 === index, onClick: function () { move( index, -1 ); } } ),
					el( c.Button, { icon: 'arrow-down-alt2', label: __( 'Move down', 'amazon-product-boxes' ), disabled: index === products.length - 1, onClick: function () { move( index, 1 ); } } ),
					el( c.Button, {
						icon: 'trash',
						label: __( 'Remove', 'amazon-product-boxes' ),
						isDestructive: true,
						onClick: function () {
							props.onChange( products.filter( function ( p, i ) { return i !== index; } ) );
						},
					} )
				),
				el( ItemFields, {
					item: item,
					rows: props.rows,
					description: props.description,
					onChange: function ( changes ) {
						update( index, changes );
					},
				} )
			);
		} );
	}

	function noticeFor( result ) {
		return result && result.notice ? result.notice : '';
	}

	function blockProps( props ) {
		return be.useBlockProps ? be.useBlockProps() : { className: props.className };
	}

	// Amazon Product Box ---------------------------------------------------------

	wp.blocks.registerBlockType( 'amazon-product-boxes/box', {
		apiVersion: 2,
		title: __( 'Amazon Product Box', 'amazon-product-boxes' ),
		description: __( 'One Amazon product with its image, bullet points, price and a button.', 'amazon-product-boxes' ),
		icon: icons.box,
		category: 'widgets',
		keywords: [ 'amazon', 'affiliate', 'product', 'asin', 'buy' ],
		attributes: {
			asin: { type: 'string', default: '' },
			title: { type: 'string', default: '' },
			description: { type: 'string', default: '' },
			badge: { type: 'string', default: '' },
			buttonText: { type: 'string', default: '' },
			link: { type: 'string', default: '' },
			image: { type: 'string', default: 'auto' },
			imageId: { type: 'number', default: 0 },
			imageUrl: { type: 'string', default: '' },
			prime: { type: 'string', default: 'auto' },
			layout: { type: 'string', default: '' },
			features: { type: 'number', default: -1 },
		},
		supports: { html: false },

		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var n = useState( '' );
			var notice = n[ 0 ];
			var setNotice = n[ 1 ];
			var r = useState( false );
			var replacing = r[ 0 ];
			var setReplacing = r[ 1 ];
			var bp = blockProps( props );

			function pick( result ) {
				set( { asin: result.asin } );
				setNotice( noticeFor( result ) );
				setReplacing( false );
			}

			var featureOptions = [ { value: -1, label: sprintf( __( 'Default (%d)', 'amazon-product-boxes' ), data.features ) } ];
			for ( var f = 0; f <= 10; f++ ) {
				featureOptions.push( { value: f, label: String( f ) } );
			}

			var inspector = el(
				be.InspectorControls,
				null,
				el(
					c.PanelBody,
					{ title: __( 'Product', 'amazon-product-boxes' ) },
					a.asin ? el( 'p', { className: 'apb-asin-line' }, __( 'ASIN', 'amazon-product-boxes' ) + ' ', el( 'code', null, a.asin ) ) : null,
					el( ProductInput, { compact: true, label: a.asin ? __( 'Replace with', 'amazon-product-boxes' ) : __( 'ASIN or Amazon link', 'amazon-product-boxes' ), button: a.asin ? __( 'Replace', 'amazon-product-boxes' ) : __( 'Add', 'amazon-product-boxes' ), onPick: pick } ),
					modeHint()
				),
				el(
					c.PanelBody,
					{ title: __( 'Text', 'amazon-product-boxes' ) },
					el( c.TextControl, {
						label: __( 'Title', 'amazon-product-boxes' ),
						help: data.api ? __( 'Leave empty to use Amazon\'s title.', 'amazon-product-boxes' ) : __( 'Needed in no-API mode: Amazon\'s title only comes through the API.', 'amazon-product-boxes' ),
						value: a.title,
						onChange: function ( value ) { set( { title: value } ); },
					} ),
					el( c.TextareaControl, {
						label: __( 'Bullet points, one per line', 'amazon-product-boxes' ),
						help: data.api ? __( 'Leave empty to use Amazon\'s feature bullets.', 'amazon-product-boxes' ) : __( 'Why it\'s worth buying, in your words.', 'amazon-product-boxes' ),
						rows: 4,
						value: a.description,
						onChange: function ( value ) { set( { description: value } ); },
					} ),
					el( c.TextControl, {
						label: __( 'Badge', 'amazon-product-boxes' ),
						placeholder: __( 'e.g. Best overall', 'amazon-product-boxes' ),
						value: a.badge,
						onChange: function ( value ) { set( { badge: value } ); },
					} ),
					el( c.TextControl, {
						label: __( 'Button text', 'amazon-product-boxes' ),
						placeholder: __( 'From the plugin settings', 'amazon-product-boxes' ),
						value: a.buttonText,
						onChange: function ( value ) { set( { buttonText: value } ); },
					} )
				),
				el(
					c.PanelBody,
					{ title: __( 'Image', 'amazon-product-boxes' ), initialOpen: false },
					el( ImageFields, { item: a, onChange: set } )
				),
				el(
					c.PanelBody,
					{ title: __( 'Display', 'amazon-product-boxes' ), initialOpen: false },
					el( c.SelectControl, {
						label: __( 'Layout', 'amazon-product-boxes' ),
						value: a.layout,
						options: [
							{ value: '', label: __( 'Default (from settings)', 'amazon-product-boxes' ) },
							{ value: 'horizontal', label: __( 'Side by side', 'amazon-product-boxes' ) },
							{ value: 'vertical', label: __( 'Card', 'amazon-product-boxes' ) },
							{ value: 'compact', label: __( 'Compact', 'amazon-product-boxes' ) },
						],
						onChange: function ( value ) { set( { layout: value } ); },
					} ),
					el( c.SelectControl, {
						label: __( 'Amazon bullet points to show', 'amazon-product-boxes' ),
						value: a.features,
						options: featureOptions,
						onChange: function ( value ) { set( { features: parseInt( value, 10 ) } ); },
					} ),
					el( c.SelectControl, {
						label: __( 'Prime badge', 'amazon-product-boxes' ),
						help: __( 'Automatic shows it on products Amazon sells itself (API mode).', 'amazon-product-boxes' ),
						value: a.prime,
						options: [
							{ value: 'auto', label: __( 'Automatic', 'amazon-product-boxes' ) },
							{ value: 'show', label: __( 'Always show', 'amazon-product-boxes' ) },
							{ value: 'hide', label: __( 'Never show', 'amazon-product-boxes' ) },
						],
						onChange: function ( value ) { set( { prime: value } ); },
					} ),
					el( c.TextControl, {
						label: __( 'Custom Amazon link', 'amazon-product-boxes' ),
						help: __( 'Optional, e.g. an amzn.to link from SiteStripe. Only Amazon links are used.', 'amazon-product-boxes' ),
						value: a.link,
						onChange: function ( value ) { set( { link: value } ); },
					} )
				)
			);

			if ( ! a.asin ) {
				return el(
					'div',
					bp,
					inspector,
					el(
						c.Placeholder,
						{
							icon: icons.box,
							label: __( 'Amazon Product Box', 'amazon-product-boxes' ),
							instructions: data.api
								? __( 'Paste an ASIN or Amazon product link, or search Amazon.', 'amazon-product-boxes' )
								: __( 'Paste an ASIN or Amazon product link. amzn.to short links work too.', 'amazon-product-boxes' ),
							className: 'apb-placeholder',
						},
						el( ProductInput, { onPick: pick } ),
						modeHint()
					)
				);
			}

			return el(
				'div',
				bp,
				inspector,
				be.BlockControls
					? el(
						be.BlockControls,
						null,
						el(
							c.ToolbarGroup || c.Toolbar,
							null,
							el( c.ToolbarButton || c.Button, {
								icon: 'update',
								label: __( 'Replace product', 'amazon-product-boxes' ),
								onClick: function () { setReplacing( ! replacing ); },
							} )
						)
					)
					: null,
				replacing ? el( 'div', { className: 'apb-inline-replace' }, el( ProductInput, { onPick: pick, button: __( 'Replace', 'amazon-product-boxes' ) } ) ) : null,
				notice ? el( c.Notice, { status: 'warning', onRemove: function () { setNotice( '' ); }, className: 'apb-editor-notice' }, notice ) : null,
				! data.api && ! a.title
					? el( c.Notice, { status: 'info', isDismissible: false, className: 'apb-editor-notice' }, __( 'Add a title and a few bullet points in the block settings (no-API mode can\'t get them from Amazon).', 'amazon-product-boxes' ) )
					: null,
				el( Preview, { block: 'amazon-product-boxes/box', attributes: a } )
			);
		},

		save: function () {
			return null;
		},
	} );

	// Amazon Comparison Table ------------------------------------------------------

	wp.blocks.registerBlockType( 'amazon-product-boxes/table', {
		apiVersion: 2,
		title: __( 'Amazon Comparison Table', 'amazon-product-boxes' ),
		description: __( 'Compare Amazon products side by side, with your own spec rows.', 'amazon-product-boxes' ),
		icon: icons.table,
		category: 'widgets',
		keywords: [ 'amazon', 'comparison', 'compare', 'versus', 'table', 'affiliate' ],
		attributes: {
			products: { type: 'array', default: [] },
			rows: { type: 'array', default: [] },
			showRating: { type: 'boolean', default: true },
		},
		supports: { html: false },

		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var n = useState( '' );
			var notice = n[ 0 ];
			var setNotice = n[ 1 ];
			var bp = blockProps( props );
			var products = a.products || [];
			var taken = products.map( function ( p ) { return p.asin; } );

			function add( result ) {
				set( { products: products.concat( [ { asin: result.asin } ] ).slice( 0, MAX ) } );
				setNotice( noticeFor( result ) );
			}

			var inspector = el(
				be.InspectorControls,
				null,
				el(
					c.PanelBody,
					{ title: __( 'Rows', 'amazon-product-boxes' ) },
					el( c.TextareaControl, {
						label: __( 'Your spec rows, one per line', 'amazon-product-boxes' ),
						help: __( 'e.g. Battery life, Weight, Warranty. Fill in each product\'s values below.', 'amazon-product-boxes' ),
						rows: 4,
						value: ( a.rows || [] ).join( '\n' ),
						onChange: function ( value ) {
							set( { rows: value.split( '\n' ).slice( 0, 20 ) } );
						},
					} ),
					el( c.ToggleControl, {
						label: __( 'Star ratings (API mode)', 'amazon-product-boxes' ),
						checked: a.showRating,
						onChange: function ( value ) { set( { showRating: value } ); },
					} ),
					modeHint()
				),
				ItemsPanels( {
					products: products,
					rows: ( a.rows || [] ).filter( function ( row ) { return row.trim(); } ).length ? a.rows : [],
					onChange: function ( next ) { set( { products: next } ); },
				} )
			);

			if ( ! products.length ) {
				return el(
					'div',
					bp,
					inspector,
					el(
						c.Placeholder,
						{ icon: icons.table, label: __( 'Amazon Comparison Table', 'amazon-product-boxes' ), instructions: __( 'Add the products to compare (up to 10). Put your pick first and give it a badge.', 'amazon-product-boxes' ), className: 'apb-placeholder' },
						el( ProductInput, { onPick: add, taken: taken } ),
						modeHint()
					)
				);
			}

			return el(
				'div',
				bp,
				inspector,
				notice ? el( c.Notice, { status: 'warning', onRemove: function () { setNotice( '' ); }, className: 'apb-editor-notice' }, notice ) : null,
				el( Preview, { block: 'amazon-product-boxes/table', attributes: a } ),
				props.isSelected && products.length < MAX
					? el( 'div', { className: 'apb-add-more' }, el( ProductInput, { onPick: add, taken: taken, button: __( 'Add product', 'amazon-product-boxes' ) } ), el( 'p', { className: 'apb-editor-hint' }, __( 'Titles, badges and spec values are in the block settings, under each product.', 'amazon-product-boxes' ) ) )
					: null
			);
		},

		save: function () {
			return null;
		},
	} );

	// Amazon Bestseller List ---------------------------------------------------------

	wp.blocks.registerBlockType( 'amazon-product-boxes/list', {
		apiVersion: 2,
		title: __( 'Amazon Bestseller List', 'amazon-product-boxes' ),
		description: __( 'A numbered top list of Amazon products, picked by you or (API mode) Amazon\'s top results for a search.', 'amazon-product-boxes' ),
		icon: icons.list,
		category: 'widgets',
		keywords: [ 'amazon', 'bestseller', 'top', 'list', 'ranking', 'affiliate' ],
		attributes: {
			source: { type: 'string', default: 'manual' },
			products: { type: 'array', default: [] },
			keywords: { type: 'string', default: '' },
			index: { type: 'string', default: 'All' },
			count: { type: 'number', default: 5 },
			sort: { type: 'string', default: '' },
			features: { type: 'number', default: 2 },
		},
		supports: { html: false },

		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var n = useState( '' );
			var notice = n[ 0 ];
			var setNotice = n[ 1 ];
			var bp = blockProps( props );
			var products = a.products || [];
			var taken = products.map( function ( p ) { return p.asin; } );
			var search = 'search' === a.source;

			function add( result ) {
				set( { products: products.concat( [ { asin: result.asin } ] ).slice( 0, MAX ) } );
				setNotice( noticeFor( result ) );
			}

			var source = el(
				c.PanelBody,
				{ title: __( 'Products', 'amazon-product-boxes' ) },
				el( c.SelectControl, {
					label: __( 'Products come from', 'amazon-product-boxes' ),
					value: a.source,
					options: [
						{ value: 'manual', label: __( 'Products I pick', 'amazon-product-boxes' ) },
						{ value: 'search', label: __( 'Amazon\'s top results for a search (API mode)', 'amazon-product-boxes' ) },
					],
					onChange: function ( value ) { set( { source: value } ); },
				} ),
				search && ! data.api
					? el( c.Notice, { status: 'warning', isDismissible: false, className: 'apb-editor-notice' }, __( 'Search lists need API mode. Until the site qualifies for the Creators API, visitors see nothing here; pick products by hand instead.', 'amazon-product-boxes' ) )
					: null,
				search
					? el(
						Fragment,
						null,
						el( c.TextControl, {
							label: __( 'Search for', 'amazon-product-boxes' ),
							placeholder: __( 'e.g. air fryer', 'amazon-product-boxes' ),
							value: a.keywords,
							onChange: function ( value ) { set( { keywords: value } ); },
						} ),
						el( c.SelectControl, {
							label: __( 'Department', 'amazon-product-boxes' ),
							value: a.index,
							options: options( data.indexes ),
							onChange: function ( value ) { set( { index: value } ); },
						} ),
						el( c.SelectControl, {
							label: __( 'Order', 'amazon-product-boxes' ),
							value: a.sort,
							options: options( data.sorts ),
							onChange: function ( value ) { set( { sort: value } ); },
						} ),
						el( c.RangeControl, {
							label: __( 'How many', 'amazon-product-boxes' ),
							value: a.count,
							min: 1,
							max: 10,
							onChange: function ( value ) { set( { count: value || 5 } ); },
						} )
					)
					: null,
				el( c.RangeControl, {
					label: __( 'Bullet points per product', 'amazon-product-boxes' ),
					value: a.features,
					min: 0,
					max: 5,
					onChange: function ( value ) { set( { features: undefined === value ? 2 : value } ); },
				} ),
				modeHint()
			);

			var inspector = el(
				be.InspectorControls,
				null,
				source,
				search
					? null
					: ItemsPanels( {
						products: products,
						description: true,
						onChange: function ( next ) { set( { products: next } ); },
					} )
			);

			if ( ! search && ! products.length ) {
				return el(
					'div',
					bp,
					inspector,
					el(
						c.Placeholder,
						{ icon: icons.list, label: __( 'Amazon Bestseller List', 'amazon-product-boxes' ), instructions: __( 'Add products in the order you rank them (up to 10).', 'amazon-product-boxes' ), className: 'apb-placeholder' },
						el( ProductInput, { onPick: add, taken: taken } ),
						data.api
							? el( c.Button, { variant: 'link', isLink: true, onClick: function () { set( { source: 'search' } ); } }, __( 'Or list Amazon\'s top results for a search', 'amazon-product-boxes' ) )
							: null,
						modeHint()
					)
				);
			}

			if ( search && ! a.keywords.trim() ) {
				return el(
					'div',
					bp,
					inspector,
					el(
						c.Placeholder,
						{ icon: icons.list, label: __( 'Amazon Bestseller List', 'amazon-product-boxes' ), instructions: __( 'Type what to search for in the block settings. The list shows Amazon\'s top results and refreshes by itself.', 'amazon-product-boxes' ), className: 'apb-placeholder' },
						el( c.TextControl, {
							label: __( 'Search for', 'amazon-product-boxes' ),
							placeholder: __( 'e.g. air fryer', 'amazon-product-boxes' ),
							value: a.keywords,
							onChange: function ( value ) { set( { keywords: value } ); },
						} )
					)
				);
			}

			return el(
				'div',
				bp,
				inspector,
				notice ? el( c.Notice, { status: 'warning', onRemove: function () { setNotice( '' ); }, className: 'apb-editor-notice' }, notice ) : null,
				el( Preview, { block: 'amazon-product-boxes/list', attributes: a } ),
				! search && props.isSelected && products.length < MAX
					? el( 'div', { className: 'apb-add-more' }, el( ProductInput, { onPick: add, taken: taken, button: __( 'Add product', 'amazon-product-boxes' ) } ) )
					: null
			);
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp );
