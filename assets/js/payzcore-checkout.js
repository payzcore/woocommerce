/**
 * PayzCore checkout JavaScript.
 *
 * Handles the payment instructions UI on the order-received page:
 * - Countdown timer based on expires_at
 * - Polling the payment status every 15 seconds via AJAX
 * - Copy-to-clipboard for address and amount
 * - Auto-redirect when payment is confirmed
 *
 * @package PayzCore
 */

/* global jQuery, payzcore_params */

(function ($) {
	'use strict';

	var paymentBox   = $('#payzcore-payment-box');
	var timerEl      = $('#payzcore-timer');
	var statusEl     = $('#payzcore-status');
	var statusIcon   = $('#payzcore-status-icon');
	var statusText   = $('#payzcore-status-text');

	if (!paymentBox.length) {
		return;
	}

	var orderId    = paymentBox.data('order-id');
	var orderKey   = paymentBox.data('order-key');
	var expiresAt  = paymentBox.data('expires-at');
	var expiresMs  = new Date(expiresAt).getTime();
	var pollTimer      = null;
	var countTimer     = null;
	var isFinished     = false;
	var pollFailCount  = 0;

	/**
	 * Initialize the module.
	 */
	function init() {
		startCountdown();
		startPolling();
		bindCopyButtons();
		bindTxidForm();
	}

	/**
	 * Start the countdown timer.
	 * Updates the timer display every second and triggers expiry state
	 * when the countdown reaches zero.
	 */
	function startCountdown() {
		updateCountdown();
		countTimer = setInterval(function () {
			updateCountdown();
		}, 1000);
	}

	/**
	 * Update the countdown display with the remaining time.
	 */
	function updateCountdown() {
		var now       = Date.now();
		var remaining = expiresMs - now;

		if (remaining <= 0) {
			timerEl.text('00:00').addClass('expired');
			clearInterval(countTimer);
			if (!isFinished) {
				showStatus('expired', payzcore_params.i18n.expired);
			}
			return;
		}

		var totalSeconds = Math.floor(remaining / 1000);
		var hours        = Math.floor(totalSeconds / 3600);
		var minutes      = Math.floor((totalSeconds % 3600) / 60);
		var seconds      = totalSeconds % 60;

		var display;
		if (hours > 0) {
			display = pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
		} else {
			display = pad(minutes) + ':' + pad(seconds);
		}

		timerEl.text(display);

		timerEl.removeClass('warning critical');
		if (totalSeconds <= 60) {
			timerEl.addClass('critical');
		} else if (totalSeconds <= 300) {
			timerEl.addClass('warning');
		}
	}

	/**
	 * Pad a number with a leading zero if less than 10.
	 *
	 * @param {number} num - Number to pad.
	 * @return {string}
	 */
	function pad(num) {
		return num < 10 ? '0' + num : '' + num;
	}

	/**
	 * Start polling the payment status via AJAX.
	 * Polls every 15 seconds (configurable via payzcore_params.poll_interval).
	 */
	function startPolling() {
		checkStatus();
		pollTimer = setInterval(function () {
			if (!isFinished) {
				checkStatus();
			}
		}, payzcore_params.poll_interval || 15000);
	}

	/**
	 * Send an AJAX request to check the current payment status.
	 */
	function checkStatus() {
		$.ajax({
			url: payzcore_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action:    'payzcore_check_status',
				nonce:     payzcore_params.nonce,
				order_id:  orderId,
				order_key: orderKey
			},
			success: function (response) {
				pollFailCount = 0;

				if (!response || !response.data) {
					return;
				}

				var status = response.data.status;

				switch (status) {
					case 'paid':
					case 'overpaid':
						handlePaid(response.data);
						break;
					case 'confirming':
						showStatus('confirming', payzcore_params.i18n.confirming);
						break;
					case 'partial':
						showStatus('partial', payzcore_params.i18n.partial_detail || payzcore_params.i18n.partial);
						break;
					case 'expired':
					case 'cancelled':
						handleExpired();
						break;
					default:
						break;
				}
			},
			error: function () {
				pollFailCount++;
				if (pollFailCount >= 3) {
					showStatus('partial', payzcore_params.i18n.connection_issue);
				}
			}
		});
	}

	/**
	 * Handle a successful payment.
	 * Shows the success state and triggers a redirect after 3 seconds.
	 *
	 * @param {Object} data - Response data with status, redirect, paid_amount, tx_hash.
	 */
	function handlePaid(data) {
		isFinished = true;
		clearInterval(pollTimer);
		clearInterval(countTimer);

		showStatus('paid', payzcore_params.i18n.confirmed);

		setTimeout(function () {
			showStatus('paid', payzcore_params.i18n.redirecting);
			if (data.redirect) {
				window.location.href = data.redirect;
			} else {
				window.location.reload();
			}
		}, 3000);
	}

	/**
	 * Handle an expired payment.
	 * Shows the expired state and stops all timers.
	 */
	function handleExpired() {
		isFinished = true;
		clearInterval(pollTimer);
		clearInterval(countTimer);

		timerEl.text('00:00').addClass('expired');
		showStatus('expired', payzcore_params.i18n.expired);

		$('#payzcore-txid-input').prop('disabled', true);
		$('#payzcore-txid-submit').prop('disabled', true);
	}

	/**
	 * Display a status message.
	 *
	 * @param {string} type    - Status type (pending|confirming|paid|expired|partial).
	 * @param {string} message - Message text to display.
	 */
	function showStatus(type, message) {
		statusEl
			.show()
			.removeClass('pending confirming paid expired partial')
			.addClass(type);

		statusText.text(message);
	}

	/**
	 * Bind click handlers to all copy buttons.
	 */
	function bindCopyButtons() {
		$('.payzcore-copy-btn').on('click', function () {
			var btn  = $(this);
			var text = btn.data('copy');

			if (!text) {
				return;
			}

			copyToClipboard(text).then(function () {
				btn.addClass('copied');
				var originalTitle = btn.attr('title');
				btn.attr('title', payzcore_params.i18n.copied);

				setTimeout(function () {
					btn.removeClass('copied');
					btn.attr('title', originalTitle);
				}, 3000);
			}).catch(function () {
				fallbackCopy(text);
			});
		});
	}

	/**
	 * Copy text to clipboard using the modern Clipboard API.
	 *
	 * @param {string} text - Text to copy.
	 * @return {Promise}
	 */
	function copyToClipboard(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		return Promise.reject();
	}

	/**
	 * Fallback copy method using a temporary textarea element.
	 *
	 * @param {string} text - Text to copy.
	 */
	function fallbackCopy(text) {
		var textarea = document.createElement('textarea');
		textarea.value = text;
		textarea.style.position = 'fixed';
		textarea.style.left = '-9999px';
		textarea.style.top = '-9999px';
		document.body.appendChild(textarea);
		textarea.focus();
		textarea.select();

		try {
			document.execCommand('copy');
		} catch (e) {
			/* Copy failed silently. */
		}

		document.body.removeChild(textarea);
	}

	/**
	 * Bind the transaction hash confirmation form (static wallet mode).
	 */
	function bindTxidForm() {
		var submitBtn  = $('#payzcore-txid-submit');
		var inputField = $('#payzcore-txid-input');
		var messageEl  = $('#payzcore-txid-message');

		if (!submitBtn.length) {
			return;
		}

		submitBtn.on('click', function () {
			var txHash = $.trim(inputField.val());

			if (!txHash) {
				showTxidMessage(payzcore_params.i18n.txid_empty, 'error');
				return;
			}

			var cleanHash = txHash.replace(/^0x/, '');
			if (!/^[a-fA-F0-9]{10,128}$/.test(cleanHash)) {
				showTxidMessage(payzcore_params.i18n.txid_invalid, 'error');
				return;
			}

			var originalText = submitBtn.text();
			submitBtn.prop('disabled', true).text(payzcore_params.i18n.txid_submitting);

			$.ajax({
				url: payzcore_params.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action:    'payzcore_confirm_txid',
					nonce:     payzcore_params.txid_nonce,
					order_id:  orderId,
					order_key: orderKey,
					tx_hash:   txHash
				},
				success: function (response) {
					if (response && response.success) {
						showTxidMessage(payzcore_params.i18n.txid_success, 'success');
						inputField.prop('disabled', true);
						submitBtn.hide();
					} else {
						var msg = (response && response.data && response.data.message)
							? response.data.message
							: payzcore_params.i18n.txid_error;
						showTxidMessage(msg, 'error');
						submitBtn.prop('disabled', false).text(originalText);
					}
				},
				error: function () {
					showTxidMessage(payzcore_params.i18n.txid_error, 'error');
					submitBtn.prop('disabled', false).text(originalText);
				}
			});
		});

		/* Allow Enter key to submit. */
		inputField.on('keypress', function (e) {
			if (e.which === 13) {
				e.preventDefault();
				submitBtn.trigger('click');
			}
		});
	}

	/**
	 * Show a message in the txid form section.
	 *
	 * @param {string} message - Message text.
	 * @param {string} type    - 'success' or 'error'.
	 */
	function showTxidMessage(message, type) {
		var messageEl = $('#payzcore-txid-message');
		messageEl
			.text(message)
			.removeClass('success error')
			.addClass(type)
			.show();
	}

	/* Initialize when DOM is ready. */
	$(document).ready(init);

})(jQuery);
