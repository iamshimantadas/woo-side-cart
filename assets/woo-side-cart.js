(function ($) {
	'use strict';

	const drawer = document.getElementById('woo-side-cart');
	if (!drawer) {
		return;
	}

	const isCartPage = !!WooSideCart.isCartPage;
	const isCheckout = !!WooSideCart.isCheckout;
	let requestInFlight = false;

	/* ==================================================================
	 * Drawer open / close
	 * ================================================================== */
	function openDrawer() {
		document.body.classList.add('wsc-open');
		drawer.setAttribute('aria-hidden', 'false');
		const overlay = document.querySelector('.wsc-overlay');
		if (overlay) overlay.setAttribute('aria-hidden', 'false');
	}

	function closeDrawer() {
		document.body.classList.remove('wsc-open');
		drawer.setAttribute('aria-hidden', 'true');
		const overlay = document.querySelector('.wsc-overlay');
		if (overlay) overlay.setAttribute('aria-hidden', 'true');
	}

	function showNotice(message) {
		const notice = drawer.querySelector('.wsc-notice');
		if (!notice) return;
		notice.textContent = message || WooSideCart.strings.error;
		notice.classList.add('is-visible');
		window.setTimeout(() => notice.classList.remove('is-visible'), 3500);
	}

	function replaceFragments(fragments) {
		Object.keys(fragments).forEach(function (selector) {
			const nodes = document.querySelectorAll(selector);
			if (!nodes.length) return;
			nodes.forEach(function (current) {
				const wrapper = document.createElement('div');
				wrapper.innerHTML = fragments[selector];
				const replacement = wrapper.firstElementChild;
				if (replacement) current.replaceWith(replacement);
			});
		});
	}

	/* ==================================================================
	 * Smooth Page Refresh Helper
	 * ================================================================== */
	function refreshPage() {
		const url = new URL(window.location.href);
		url.searchParams.delete('wsc_reload_cart');
		url.searchParams.set('wsc_refreshed', '1');
		window.location.replace(url.toString());
	}

	/* ==================================================================
	 * Refresh progress + drawer items (server-driven AJAX)
	 * ================================================================== */
	async function refreshProgress() {
		if (!WooSideCart.progress || !WooSideCart.progress.enabled) return;
		try {
			const body = new URLSearchParams();
			body.append('action', 'woo_side_cart_refresh');
			body.append('nonce', WooSideCart.nonce);

			const response = await fetch(WooSideCart.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			});
			const result = await response.json();
			if (result.success && result.data && result.data.fragments) {
				replaceFragments(result.data.fragments);
			}
		} catch (e) { /* silent */ }
	}

	/* ==================================================================
	 * Update cart item (drawer +/-/remove)
	 * ================================================================== */
	async function updateCartItem(cartKey, quantity) {
		if (requestInFlight) return;
		requestInFlight = true;
		drawer.classList.add('wsc-loading');

		try {
			const body = new URLSearchParams();
			body.append('action', 'woo_side_cart_update_item');
			body.append('nonce', WooSideCart.nonce);
			body.append('cart_key', cartKey);
			body.append('quantity', quantity);

			const response = await fetch(WooSideCart.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			});

			const result = await response.json();
			if (!result.success) {
				throw new Error(result.data && result.data.message ? result.data.message : '');
			}
			if (result.data && result.data.fragments) {
				replaceFragments(result.data.fragments);
			}

			// If on cart page OR checkout page, refresh the page completely
			if (isCartPage || isCheckout) {
				refreshPage();
				return;
			}

			if (result.data && result.data.empty) {
				window.setTimeout(closeDrawer, 500);
			}
		} catch (error) {
			showNotice(error.message || WooSideCart.strings.error);
		} finally {
			requestInFlight = false;
			drawer.classList.remove('wsc-loading');
		}
	}

	/* ==================================================================
	 * Click handler
	 * ================================================================== */
	document.addEventListener('click', function (event) {

		if (event.target.closest('[data-wsc-open]')) {
			event.preventDefault();
			openDrawer();
			return;
		}

		if (event.target.closest('[data-wsc-close]')) {
			event.preventDefault();
			closeDrawer();
			return;
		}

		const checkoutBtn = event.target.closest('[data-wsc-checkout], .wsc-checkout');
		if (checkoutBtn) {
			const p = WooSideCart.progress || {};
			if (p.minEnabled && p.minAmount > 0) {
				const progressEl = document.querySelector('.wsc-progress');
				const subtotal = progressEl
					? parseFloat(progressEl.getAttribute('data-subtotal') || '0')
					: 0;
				if (subtotal < p.minAmount) {
					event.preventDefault();
					showNotice((p.minMessage || WooSideCart.strings.minBlock).replace('%s', ''));
					return;
				}
			}
		}

		const removeButton = event.target.closest('[data-wsc-remove]');
		if (removeButton) {
			event.preventDefault();
			event.stopImmediatePropagation();
			updateCartItem(removeButton.getAttribute('data-wsc-remove'), 0);
			return;
		}

		const quantityButton = event.target.closest('[data-wsc-qty]');
		if (quantityButton) {
			event.preventDefault();
			event.stopImmediatePropagation();
			const item = quantityButton.closest('.wsc-item');
			if (!item) return;
			const key = item.getAttribute('data-cart-key');
			const quantitySpan = item.querySelector('.wsc-quantity span');
			const currentQty = parseInt(quantitySpan ? quantitySpan.textContent : '1', 10) || 1;
			const delta = parseInt(quantityButton.getAttribute('data-wsc-qty'), 10) || 0;
			updateCartItem(key, Math.max(0, currentQty + delta));
		}
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') closeDrawer();
	});

	/* ==================================================================
	 * Sync side cart on any add-to-cart (Global / Shop Page / Product Page)
	 * ================================================================== */
	$(document.body).on('added_to_cart', function (event, fragments, cart_hash, $button) {
		if (!isCartPage && !isCheckout) {
			openDrawer();
		}
		
		if (fragments) {
			const ours = {};
			Object.keys(fragments).forEach(function (sel) {
				if (sel.indexOf('.wsc-') !== -1) ours[sel] = fragments[sel];
			});
			if (Object.keys(ours).length) replaceFragments(ours);
		}
		
		if (!isCartPage && !isCheckout) {
			window.setTimeout(refreshProgress, 350);
		}
	});

	$(document.body).on('wc-blocks_added_to_cart', function () {
		if (!isCartPage && !isCheckout) {
			openDrawer();
			window.setTimeout(refreshProgress, 350);
		}
	});

	/* ==================================================================
	 * Global WooCommerce Sync
	 * ================================================================== */
	$(document.body).on('updated_wc_div wc_cart_updated updated_cart_totals', function () {
		if (isCartPage || isCheckout) {
			refreshPage();
		} else {
			refreshProgress();
		}
	});

	/* ==================================================================
	 * Cleanup URL params after page refresh
	 * ================================================================== */
	if (isCartPage || isCheckout) {
		const currentUrl = new URL(window.location.href);
		if (currentUrl.searchParams.get('wsc_refreshed') === '1') {
			currentUrl.searchParams.delete('wsc_refreshed');
			window.history.replaceState({}, document.title, currentUrl.pathname + currentUrl.search + currentUrl.hash);
		}
	}

	/* Open after normal form submission */
	const url = new URL(window.location.href);
	if (
		WooSideCart.openOnLoad ||
		url.searchParams.get('woo_side_cart_open') === '1' ||
		document.cookie.indexOf('woo_side_cart_open=1') !== -1
	) {
		openDrawer();
		url.searchParams.delete('woo_side_cart_open');
		window.history.replaceState({}, document.title, url.pathname + url.search + url.hash);
		document.cookie = 'woo_side_cart_open=; Max-Age=0; path=/';
	}

})(jQuery);