# RYCM Cookie Consent

RYCM's own WordPress cookie consent plugin. It replaces Cookiebot, Termly and Complianz on RYCM client sites at no per-site or per-visit cost, and it behaves the same way as the `CookieConsent.astro` banner on RYCM's Astro sites.

Built on [CookieConsent v3](https://github.com/orestbida/cookieconsent) by Orest Bida (MIT). Version 3.3.0 is vendored unmodified in `assets/vendor/cookieconsent/`.

The legal rules it implements are in `RYCM_forms_system/COOKIE_PRACTICE.md` (UK PECR + UK GDPR, ICO 2023 guidance). In short:

| Rule | How the plugin meets it |
|---|---|
| Nothing non-essential before consent | Held scripts are `type="text/plain"`; the GA4 tag uses `data-src`, so the browser does not even fetch it. Consent Mode defaults are all denied. |
| Accept, Reject and Customise carry equal weight | All three are first-layer buttons with identical size and colour (`equalWeightButtons` plus matching CSS variables). |
| No pre-ticked boxes, no implied consent | CookieConsent runs in `opt-in` mode; every optional category starts off. Default copy has no "by continuing" wording (unit-tested). |
| Choice stored without a consent cookie | Browser local storage (`rycm_consent` by default), 12 months. |
| Easy to withdraw | Floating cookie button after a choice, `[rycm_cookie_settings]` for footers, or any link to `#rycm-cookie-settings`. Withdrawing deletes the category's declared cookies and reloads the page without its scripts. |
| Re-ask when the policy changes | Settings > Cookie consent > "Ask everyone again" raises the policy version. |
| Proof of consent | One row per choice in `{prefix}rycm_consent_records`, exportable as CSV. |

## Install on a site

1. Download `rycm-consent.zip` from the [latest release](https://github.com/martingfisher/rycm-consent/releases/latest).
2. Plugins > Add New > Upload Plugin, then activate.
3. Settings > Cookie consent: set the colours, the GA4 ID if GA4 should load through this plugin, the cookie policy page, and list every third-party service that sets cookies.
4. Put `[rycm_cookie_policy]` on the cookie policy page and `[rycm_cookie_settings]` in the footer (or a menu link to `#rycm-cookie-settings`).
5. Recommended: install the free [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) plugin so Site Kit, WooCommerce and others follow the choice.
6. Remove the old banner (Blocksy's, Complianz, Cookiebot) so there is only one.

Future updates arrive through Dashboard > Updates like any other plugin, and auto-updates can be switched on per site.

## What gets recorded

| Column | Content |
|---|---|
| `consent_id` | UUID CookieConsent stores in the visitor's browser. Lets you find one person's history if they ask. |
| `action` | `accept`, `reject` or `custom`, worked out on the server from the categories. |
| `categories` | What was accepted, for example `necessary,analytics`. |
| `policy_version` | The policy version the visitor saw. |
| `created_at` | UTC. |
| `ip_hash` | SHA-256 of the IP address with a per-site secret salt. The address itself is never stored. |
| `ua_family` | Browser and system family only, such as `Chrome / macOS`. |

Rows older than the retention setting (default 12 months) are deleted by a daily cron. The REST route that writes them (`POST /wp-json/rycm-consent/v1/record`) needs a signed token printed into the page, refuses cross-origin requests and allows 30 writes per visitor per 10 minutes.

The token is not a WordPress nonce on purpose. Nonces are tied to the logged-in user and expire, so on a cached page (Wordify, Cloudflare) every visitor would get someone else's stale nonce and the record would fail. The token is an HMAC of the site secret and a 12-hour window, valid for 12 to 24 hours; if a cached page carries an older one, the banner fetches a fresh one from `GET /wp-json/rycm-consent/v1/token` and retries once.

## For developers: holding scripts until consent

Categories: `functional`, `analytics`, `marketing` (`necessary` is never held).

```php
// An enqueued script, including its inline before/after blocks.
wp_enqueue_script( 'hotjar', 'https://static.hotjar.com/c/hotjar-123.js', array(), null, true );
rycm_consent_block_handle( 'hotjar', 'analytics' );

// Or for handles another plugin enqueues.
add_filter( 'rycm_consent_blocked_handles', function ( $map ) {
	$map['some-plugin-pixel'] = 'marketing';
	return $map;
} );

// A pasted vendor snippet.
echo rycm_consent_wrap( '<script>/* Meta Pixel */</script>', 'marketing' );
```

By hand in a template: `<script type="text/plain" data-category="analytics" data-src="https://…"></script>`.

In the browser: `window.rycmConsent.accepted('analytics')`, `window.rycmConsent.show()`, and a `rycm-consent:change` event on `document` with `detail.categories`.

Other filters: `rycm_consent_enabled` (switch the front end off for a request), `rycm_consent_client_ip` (if the host puts the visitor IP somewhere other than `CF-Connecting-IP` or `REMOTE_ADDR`), `rycm_consent_release_url`. Action: `rycm_consent_recorded`.

## Consent Mode v2

First script in `<head>` (priority -1000, marked `data-cfasync="false"` so Rocket Loader leaves it alone):

```js
gtag('consent', 'default', { functionality_storage, personalization_storage, analytics_storage,
  ad_storage, ad_user_data, ad_personalization: 'denied', security_storage: 'granted', wait_for_update: 500 });
gtag('set', 'ads_data_redaction', true);
```

then, for a returning visitor, an immediate `gtag('consent', 'update', …)` from their stored choice, before any tag reads the defaults. Mapping: analytics → `analytics_storage`; marketing → `ad_storage`, `ad_user_data`, `ad_personalization`; preferences → `functionality_storage`, `personalization_storage`. "Consent Mode region" can instead deny only for the UK, EEA and Switzerland and grant elsewhere; that only matters for tags not held by the plugin (GTM in advanced mode), because held scripts still wait for the banner everywhere.

## Development

See [TESTING.md](TESTING.md) for the test suites and the browser check, and [RELEASING.md](RELEASING.md) for shipping an update.

## Roadmap

**Phase 2: automatic blocking of known services.** An output buffer on the front end that finds known third-party tags and embeds and holds them without any per-site code: GA4 and Universal gtag, Google Tag Manager, YouTube, Vimeo, Google Maps, Meta Pixel, Hotjar, Microsoft Clarity, LinkedIn Insight. Needs: a pattern library (script `src` hosts, inline signatures such as `fbq(`, iframe hosts) mapped to category and service, each with its declared cookies so the policy table fills itself; iframe and embed handling (`data-src` swap plus a click-to-load placeholder with the service name, a "Load this video" button and a "Always allow" link that accepts the category); exclusions for page builders and caching plugins that already rewrite tags (FlyingPress delay, WP Rocket, Rocket Loader); and tests against real markup from each service. Main risks: output buffering conflicts with caching plugins, and themes that load embeds with JavaScript after the buffer has run.

**Phase 3: cookie scanning.** A scheduled scan that loads the site's key pages in a headless browser with everything accepted, lists every cookie and storage key that appears, matches them against the pattern library and flags anything undeclared in wp-admin (and optionally in the RYCM ops digest). WordPress cannot run a browser itself, so the scanner belongs in a Cloudflare Worker using Browser Rendering on the RYCM account, called with a per-site key, with results posted back to a REST route. Needs: the Worker, a per-site shared secret, a results table, the admin review screen ("add to services" in one click) and a monthly schedule.
