/**
 * PayzCore payment method for the WooCommerce Checkout block.
 *
 * No build step: uses the globals WooCommerce and WordPress expose
 * (window.wc.wcBlocksRegistry, window.wc.wcSettings, window.wp.element).
 * Data comes from PayzCore_Blocks_Payment_Method::get_payment_method_data().
 *
 * @package PayzCore
 */
( function () {
	'use strict';

	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings || ! window.wp || ! window.wp.element ) {
		return;
	}

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var el = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function ( s ) { return s; };

	var settings = getSetting( 'payzcore_data', {} );
	var title = decode( settings.title || 'Stablecoin (USDT/USDC)' );
	var networks = settings.networks || [];
	var networkTokens = settings.network_tokens || {};
	var tokenLabels = settings.token_labels || {};
	var defaultToken = settings.default_token || 'USDT';
	var i18n = settings.i18n || {};

	function tokensFor( network ) {
		return networkTokens[ network ] && networkTokens[ network ].length ? networkTokens[ network ] : [ 'USDT' ];
	}

	function pickToken( network, preferred ) {
		var list = tokensFor( network );
		return list.indexOf( preferred ) !== -1 ? preferred : list[ 0 ];
	}

	function Select( props ) {
		return el(
			'div',
			{ className: 'wc-block-components-select-input payzcore-block-field' },
			el( 'label', { htmlFor: props.id }, props.label ),
			el(
				'select',
				{
					id: props.id,
					value: props.value,
					onChange: function ( e ) { props.onChange( e.target.value ); },
					className: 'payzcore-select-full',
				},
				props.options.map( function ( o ) {
					return el( 'option', { key: o.value, value: o.value }, o.label );
				} )
			)
		);
	}

	function Content( props ) {
		var firstNetwork = networks.length ? networks[ 0 ].value : '';
		var networkState = useState( firstNetwork );
		var network = networkState[ 0 ];
		var setNetwork = networkState[ 1 ];
		var tokenState = useState( pickToken( firstNetwork, defaultToken ) );
		var token = tokenState[ 0 ];
		var setToken = tokenState[ 1 ];

		var onPaymentSetup = props.eventRegistration.onPaymentSetup;
		var responseTypes = props.emitResponse.responseTypes;

		useEffect( function () {
			return onPaymentSetup( function () {
				if ( ! network ) {
					return { type: responseTypes.ERROR, message: i18n.unavailable || 'Payment configuration is not available.' };
				}
				// Converted to $_POST by the Checkout block; read by the gateway.
				return {
					type: responseTypes.SUCCESS,
					meta: { paymentMethodData: { payzcore_network: network, payzcore_token: token } },
				};
			} );
		}, [ onPaymentSetup, responseTypes, network, token ] );

		var children = [];
		if ( settings.description ) {
			children.push( el( 'p', { key: 'desc' }, decode( settings.description ) ) );
		}
		if ( ! networks.length ) {
			children.push( el( 'p', { key: 'na', className: 'payzcore-error-text' }, i18n.unavailable ) );
			return el( 'div', { className: 'payzcore-block' }, children );
		}
		if ( networks.length > 1 ) {
			children.push( el( Select, {
				key: 'network',
				id: 'payzcore-block-network',
				label: i18n.network || 'Blockchain Network',
				value: network,
				options: networks,
				onChange: function ( value ) {
					setNetwork( value );
					setToken( pickToken( value, token ) );
				},
			} ) );
		}
		var tokens = tokensFor( network );
		if ( tokens.length > 1 ) {
			children.push( el( Select, {
				key: 'token',
				id: 'payzcore-block-token',
				label: i18n.token || 'Stablecoin',
				value: token,
				options: tokens.map( function ( t ) { return { value: t, label: tokenLabels[ t ] || t }; } ),
				onChange: setToken,
			} ) );
		}
		return el( 'div', { className: 'payzcore-block' }, children );
	}

	function Label() {
		return el( 'span', { className: 'payzcore-block-label' }, title );
	}

	function Edit() {
		return el( 'div', { className: 'payzcore-block' }, decode( settings.description || '' ) );
	}

	registerPaymentMethod( {
		name: 'payzcore',
		label: el( Label ),
		ariaLabel: title,
		content: el( Content ),
		edit: el( Edit ),
		canMakePayment: function () { return networks.length > 0; },
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )();
