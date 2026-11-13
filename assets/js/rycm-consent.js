/*!
 * RYCM Cookie Consent bootstrap.
 * Runs CookieConsent v3 with the settings printed by the plugin, then keeps
 * Google Consent Mode, the WP Consent API and the server-side consent record
 * in step with every choice the visitor makes.
 */
(function () {
	'use strict';

	var D = window.rycmConsentData;
	if (!D || !window.CookieConsent) {
		return;
	}
	var CC = window.CookieConsent;
	var token = D.token;

	// Cookie patterns arrive as regex source strings; CookieConsent wants RegExp objects.
	var cats = D.cc.categories || {};
	Object.keys(cats).forEach(function (key) {
		var ac = cats[key].autoClear;
		if (ac && Array.isArray(ac.cookies)) {
			ac.cookies = ac.cookies.map(function (c) {
				return { name: new RegExp(c.pattern) };
			});
		}
	});

	function consentModeUpdate(accepted) {
		if (!D.consentMode || typeof window.gtag !== 'function') {
			return;
		}
		var update = {};
		Object.keys(D.cmMap).forEach(function (cat) {
			D.cmMap[cat].forEach(function (signal) {
				update[signal] = accepted.indexOf(cat) > -1 ? 'granted' : 'denied';
			});
		});
		window.gtag('consent', 'update', update);
	}

	function wpConsentUpdate(accepted) {
		if (typeof window.wp_set_consent !== 'function') {
			return;
		}
		Object.keys(D.wpMap).forEach(function (cat) {
			D.wpMap[cat].forEach(function (wpCat) {
				window.wp_set_consent(wpCat, accepted.indexOf(cat) > -1 ? 'allow' : 'deny');
			});
		});
	}

	// Cloudflare Web Analytics runs unless analytics is refused: start it when allowed,
	// reload without it when refused on a page where it is already running.
	function cfBeaconUpdate(accepted) {
		if (!D.cfBeacon) {
			return;
		}
		if (accepted.indexOf('analytics') > -1) {
			if (typeof window.rycmCfBeacon === 'function') {
				window.rycmCfBeacon();
			}
		} else if (window.rycmCfLoaded) {
			window.rycmCfLoaded = false;
			setTimeout(function () { window.location.reload(); }, 50);
		}
	}

	function showReopen(on) {
		var btns = document.querySelectorAll('.rycm-cc-reopen');
		for (var i = 0; i < btns.length; i++) {
			btns[i].hidden = !on;
		}
	}

	function apply(cookie) {
		var accepted = (cookie && cookie.categories) || [];
		consentModeUpdate(accepted);
		wpConsentUpdate(accepted);
		cfBeaconUpdate(accepted);
		showReopen(true);
		try {
			document.dispatchEvent(new CustomEvent('rycm-consent:change', { detail: { categories: accepted.slice() } }));
		} catch (e) { /* very old browsers */ }
	}

	function record(cookie, retried) {
		if (!cookie || !cookie.consentId || !window.fetch) {
			return;
		}
		var body = JSON.stringify({
			consent_id: cookie.consentId,
			categories: cookie.categories || [],
			revision: cookie.revision,
			token: token
		});
		window.fetch(D.recordUrl, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true, // a withdrawal reloads the page; the record must still arrive
			headers: { 'Content-Type': 'application/json' },
			body: body
		}).then(function (res) {
			// A page served from cache can carry a token older than a day: fetch a fresh one and try once more.
			if (res.status === 403 && !retried) {
				return window.fetch(D.tokenUrl, { cache: 'no-store', credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (d) { token = d.token; record(cookie, true); });
			}
		}).catch(function () { /* recording must never break the page */ });
	}

	window.wp_consent_type = 'optin';
	try {
		document.dispatchEvent(new CustomEvent('wp_consent_type_defined'));
	} catch (e) { /* ignore */ }

	var config = D.cc;
	config.onFirstConsent = function (p) { record(p.cookie); };
	config.onConsent = function (p) { apply(p.cookie); };
	config.onChange = function (p) { apply(p.cookie); record(p.cookie); };

	function openPrefs(ev) {
		var t = ev.target && ev.target.closest ? ev.target.closest('[data-rycm-cookie-settings], a[href$="#rycm-cookie-settings"]') : null;
		if (!t) {
			return;
		}
		ev.preventDefault();
		CC.showPreferences();
	}

	function start() {
		CC.run(config);
		document.addEventListener('click', openPrefs);
	}

	// CookieConsent collects held <script data-category> tags when it runs, so wait for the whole DOM.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}

	// Small public API for themes and other plugins.
	window.rycmConsent = {
		accepted: function (category) { return CC.acceptedCategory(category); },
		show: function () { CC.showPreferences(); }
	};
})();
