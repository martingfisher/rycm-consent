=== RYCM Cookie Consent ===
Contributors: rycm
Tags: cookie consent, gdpr, pecr, consent mode, cookie banner
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

UK GDPR and PECR cookie consent for RYCM client sites, built on CookieConsent v3.

== Description ==

* Accept all, Reject all and Customise as equal-weight buttons. Nothing optional is on until the visitor switches it on.
* Google Consent Mode v2: all signals denied first in the page head, updated on every choice and straight away on return visits.
* Scripts held by category until consent: the built-in GA4 tag, any enqueued script handle, or pasted snippets.
* WP Consent API support, so Site Kit, WooCommerce and others follow the visitor's choice.
* A consent record for every choice (consent ID, choice, categories, policy version, time, salted IP hash, browser family) with a retention setting and CSV export.
* [rycm_cookie_settings] and [rycm_cookie_policy] shortcodes.
* Updates from GitHub Releases, through the normal WordPress Updates screen.

Bundles CookieConsent 3.3.0 by Orest Bida (MIT licence), unmodified, in assets/vendor/cookieconsent.

== Changelog ==

= 0.1.2 =
* Cloudflare Web Analytics as a built-in service: runs by default, stops on Reject all or with analytics switched off.

= 0.1.1 =
* Fix: expanded categories in the preferences panel were clipped into an oval.

= 0.1.0 =
* First release.
