/**
 * PayzCore payment fields - network/token selector.
 *
 * Handles dynamic token options based on selected network on the checkout page.
 * Data is passed via wp_localize_script() as payzcore_fields_params.
 *
 * @package PayzCore
 */
(function () {
	'use strict';

	var networkEl = document.getElementById( 'payzcore_network' );
	var tokenField = document.getElementById( 'payzcore_token_field' );
	var tokenEl = document.getElementById( 'payzcore_token' );

	if ( ! networkEl || ! tokenEl || typeof payzcore_fields_params === 'undefined' ) {
		return;
	}

	var networkTokens = payzcore_fields_params.network_tokens || {};
	var tokenLabels = payzcore_fields_params.token_labels || {};
	var defaultToken = payzcore_fields_params.default_token || 'USDT';

	function updateTokenOptions() {
		var tokens = networkTokens[ networkEl.value ] || [ 'USDT' ];

		while ( tokenEl.firstChild ) {
			tokenEl.removeChild( tokenEl.firstChild );
		}

		for ( var i = 0; i < tokens.length; i++ ) {
			var opt = document.createElement( 'option' );
			opt.value = tokens[ i ];
			opt.textContent = tokenLabels[ tokens[ i ] ] || tokens[ i ];
			if ( tokens[ i ] === defaultToken ) {
				opt.selected = true;
			}
			tokenEl.appendChild( opt );
		}

		if ( tokenField ) {
			tokenField.style.display = tokens.length <= 1 ? 'none' : '';
		}
	}

	networkEl.addEventListener( 'change', updateTokenOptions );
	updateTokenOptions();

	// Re-initialize after WooCommerce AJAX checkout fragment updates.
	jQuery( document.body ).on( 'updated_checkout', function () {
		var newNetworkEl = document.getElementById( 'payzcore_network' );
		var newTokenEl = document.getElementById( 'payzcore_token' );
		if ( newNetworkEl && newTokenEl ) {
			networkEl.removeEventListener( 'change', updateTokenOptions );
			networkEl = newNetworkEl;
			tokenEl = newTokenEl;
			tokenField = document.getElementById( 'payzcore_token_field' );
			newNetworkEl.addEventListener( 'change', updateTokenOptions );
			updateTokenOptions();
		}
	} );
})();
