(function ($) {
	'use strict';

	const drawer = document.getElementById('woo-side-cart');

	if (!drawer) {
		return;
	}

	const isCartPage = !!WooSideCart.isCartPage;
	let requestInFlight = false;
	let reloadScheduled = false;

	/* ==================================================================
	 * Reload helper (only used on /cart/)
	 * ================================================================== */
	function scheduleReload(delay) {
		if (!isCartPage || reloadScheduled) {
			return;
		}
		reloadScheduled = true;

		window.setTimeout(function () {
			const url = new URL(window.location.href);
			url.searchParams.set('wsc_reload_cart', '1');
			window.location.replace(url.toString());
		}, typeof delay === 'number' ? delay : 60);
	}

	/* ==================================================================
	 * Drawer open / close
	 * ================================================================== */
	function openDrawer() {
		document.body.classList.add('wsc-open');
		drawer.setAttribute('aria-hidden', 'false');

		const overlay = document.querySelector('.wsc-overlay');
		if (overlay) {
			overlay.setAttribute('aria-hidden', 'false');
		}
	}

	function closeDrawer() {
		document.body.classList.remove('wsc-open');
		drawer.setAttribute('aria-hidden', 'true');

		const overlay = document.querySelector('.wsc-overlay');
		if (overlay) {
			overlay.setAttribute('aria-hidden', 'true');
		}
	}

	/* ==================================================================
	 * Notice
	 * ================================================================== */
	function showNotice(message) {
		const notice = drawer.querySelector('.wsc-notice');
		if (!notice) {
			return;
		}
		notice.textContent = message || WooSideCart.strings.error;
		notice.classList.add('is-visible');
		window.setTimeout(function () {
			notice.classList.remove('is-visible');
		}, 3500);
	}

	/* ==================================================================
	 * Fragment replacement
	 * ================================================================== */
	function replaceFragments(fragments) {
		Object.keys(fragments).forEach(function (selector) {
			const current = document.querySelector(selector);
			if (!current) {
				return;
			}
			const wrapper = document.createElement('div');
			wrapper.innerHTML = fragments[selector];
			const replacement = wrapper.firstElementChild;
			if (replacement) {
				current.replaceWith(replacement);
			}
		});
	}

	/* ==================================================================
	 * Side cart update — works on EVERY page, including /cart/
	 * ==================================================================
	 * On /cart/, after the AJAX succeeds, we reload the page so the
	 * native WooCommerce cart table reflects the new quantity.
	 * ================================================================== */
	async function updateCartItem(cartKey, quantity) {
		if (requestInFlight) {
			return;
		}
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
				headers: {
					'Content-Type':
						'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: body.toString()
			});

			const result = await response.json();

			if (!result.success) {
				throw new Error(
					result.data && result.data.message
						? result.data.message
						: ''
				);
			}

			if (result.data && result.data.fragments) {
				replaceFragments(result.data.fragments);
			}

			/* On /cart/, reload so the native cart table updates. */
			if (isCartPage) {
				scheduleReload(200);
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

		/* Floating button — open drawer */
		if (event.target.closest('[data-wsc-open]')) {
			event.preventDefault();
			openDrawer();
			return;
		}

		/* Close button / overlay */
		if (event.target.closest('[data-wsc-close]')) {
			event.preventDefault();
			closeDrawer();
			return;
		}

		/* Remove product */
		const removeButton = event.target.closest('[data-wsc-remove]');
		if (removeButton) {
			event.preventDefault();
			event.stopImmediatePropagation();
			updateCartItem(
				removeButton.getAttribute('data-wsc-remove'),
				0
			);
			return;
		}

		/* Quantity +/- inside side cart */
		const quantityButton = event.target.closest('[data-wsc-qty]');
		if (quantityButton) {
			event.preventDefault();
			event.stopImmediatePropagation();

			const item = quantityButton.closest('.wsc-item');
			if (!item) {
				return;
			}

			const key = item.getAttribute('data-cart-key');
			const quantitySpan = item.querySelector('.wsc-quantity span');

			const currentQty = parseInt(
				quantitySpan ? quantitySpan.textContent : '1',
				10
			) || 1;

			const delta = parseInt(
				quantityButton.getAttribute('data-wsc-qty'),
				10
			) || 0;

			const newQuantity = Math.max(0, currentQty + delta);

			updateCartItem(key, newQuantity);
		}
	});

	/* Escape closes the drawer */
	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') {
			closeDrawer();
		}
	});

	/* ==================================================================
	 * Auto-open on add-to-cart
	 * ================================================================== */
	$(document.body).on('added_to_cart', function () {
		openDrawer();
	});

	const url = new URL(window.location.href);

	if (
		WooSideCart.openOnLoad ||
		url.searchParams.get('woo_side_cart_open') === '1' ||
		document.cookie.indexOf('woo_side_cart_open=1') !== -1
	) {
		openDrawer();

		url.searchParams.delete('woo_side_cart_open');
		window.history.replaceState(
			{},
			document.title,
			url.pathname + url.search + url.hash
		);

		document.cookie = 'woo_side_cart_open=; Max-Age=0; path=/';
	}

	/* ==================================================================
	 * Cart page: reload on native WooCommerce cart changes
	 * ==================================================================
	 * This only handles changes coming from the NATIVE cart UI
	 * (the cart table / Blocks cart). Side-cart buttons are already
	 * handled above via updateCartItem() + scheduleReload().
	 * ================================================================== */
	if (isCartPage) {

		/* Classic cart: form submit */
		document.addEventListener(
			'submit',
			function (event) {
				if (event.target.matches('.woocommerce-cart-form')) {
					event.preventDefault();
					event.stopImmediatePropagation();
					scheduleReload(0);
				}
			},
			true
		);

		/* Classic cart: qty input change */
		document.addEventListener(
			'change',
			function (event) {
				if (
					event.target.matches(
						'.woocommerce-cart-form input.qty'
					) &&
					!event.target.closest('#woo-side-cart')
				) {
					event.stopImmediatePropagation();
					scheduleReload(120);
				}
			},
			true
		);

		/* Classic cart: +/- buttons and Update button */
		document.addEventListener(
			'click',
			function (event) {
				if (
					event.target.closest(
						'.woocommerce-cart-form .plus, ' +
						'.woocommerce-cart-form .minus, ' +
						'.woocommerce-cart-form button[name="update_cart"], ' +
						'.woocommerce-cart-form input[name="update_cart"]'
					)
				) {
					event.preventDefault();
					event.stopImmediatePropagation();
					scheduleReload(0);
				}
			},
			true
		);

		/* Blocks cart: intercept fetch() to /wc/store/v1/cart/update-item */
		if (typeof window.fetch === 'function') {
			const originalFetch = window.fetch.bind(window);

			window.fetch = function (input, init) {
				let requestUrl = '';

				if (typeof input === 'string') {
					requestUrl = input;
				} else if (input && typeof input.url === 'string') {
					requestUrl = input.url;
				}

				/*
				 * Skip requests made by our own plugin — they go to
				 * admin-ajax.php and must not trigger a reload here.
				 */
				if (requestUrl.indexOf('admin-ajax.php') !== -1) {
					return originalFetch(input, init);
				}

				const isCartUpdate =
					requestUrl.indexOf('/wc/store/v1/cart/update-item') !== -1 ||
					requestUrl.indexOf('/wc/store/v1/cart/remove-item') !== -1;

				const promise = originalFetch(input, init);

				if (isCartUpdate) {
					promise
						.then(function (res) {
							if (res && res.ok) {
								scheduleReload(120);
							}
							return res;
						})
						.catch(function () {
							/* silent */
						});
				}

				return promise;
			};
		}

		/* jQuery backstop for classic AJAX cart updates */
		$(document).ajaxComplete(function (event, xhr, settings) {
			if (
				settings &&
				typeof settings.url === 'string' &&
				settings.url.indexOf('update-item') !== -1 &&
				settings.url.indexOf('admin-ajax.php') === -1
			) {
				scheduleReload(120);
			}
		});

		/* Strip the reload marker after reload */
		const currentUrl = new URL(window.location.href);
		if (currentUrl.searchParams.get('wsc_reload_cart') === '1') {
			currentUrl.searchParams.delete('wsc_reload_cart');
			window.history.replaceState(
				{},
				document.title,
				currentUrl.pathname + currentUrl.search + currentUrl.hash
			);
		}
	}

})(jQuery);