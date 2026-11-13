# Testing

## Unit tests (pure logic, no WordPress)

```bash
php tests/run.php
```

Covers: accept/reject/custom derivation, category cleaning, IP hashing, browser family, Consent Mode defaults and updates (including the UK/EEA region list), script-tag rewriting, cookie patterns, colours and WCAG contrast, CSV formula escaping, the record token, settings sanitising, the CookieConsent config (opt-in, no pre-ticked categories, equal weight, local storage, escaping), GitHub release parsing, and the copy rules (no em dashes, no implied-consent wording).

## Integration tests (real WordPress on SQLite)

On this Mac, PHP is Homebrew's: put `/opt/homebrew/bin` on `PATH` first.

```bash
bin/setup-test-wp.sh /tmp/rycm-consent-test-wp
```

```bash
cd /tmp/rycm-consent-test-wp/core && php ../wp-cli.phar --allow-root eval-file ~/Developer/rycm-consent/tests/integration/run.php
```

Covers: table and cron install, the real `wp_kses` sanitiser, the head output order (Consent Mode first, GA4 held with `data-src`), holding an enqueued handle and its inline blocks, the REST route end to end (201 per choice, server-derived action, salted IP hash, browser family, bad token, cross-origin, bad UUID, future revision, rate limit 429), the token refresh route, retention purge, CSV export, both shortcodes, the policy-version bump, WP Consent API registration, and a full WordPress update check against a mocked GitHub release (Updates screen entry and View details).

## Browser check (before every release that touches the front end)

Serve the throwaway site:

```bash
cd /tmp/rycm-consent-test-wp/core && php -S localhost:8899 router.php
```

Set a test GA4 ID (Settings > Cookie consent, `G-TEST1234`; admin login `admin` / `testpass123` from the setup script), clear local storage, then check:

1. Fresh visit: banner shows; no request to `googletagmanager.com` or `google-analytics.com`; `dataLayer` has only `consent default` (denied) and the two `set` calls.
2. Accept all: `gtag/js?id=G-TEST1234` loads and a `g/collect` hit is sent; `consent update` granted; a `POST /wp-json/rycm-consent/v1/record` returns 201; a row with `accept` appears.
3. Floating cookie button, Reject all: page reloads; `_ga` and `_ga_TEST1234` are deleted; no Google request after the reload; a `reject` row appears with the same consent ID.
4. Accept again and open another page: `consent update` granted comes straight after the default, before `js`/`config`.
5. Raise the policy version: the banner returns, the stored grant is not applied, no Google request.
6. Keyboard: Tab moves into the preferences panel and stays inside it; Escape closes it.
7. Phone width (375px): banner fits with no horizontal scroll.

Read the rows with:

```bash
cd /tmp/rycm-consent-test-wp/core && php ../wp-cli.phar --allow-root eval 'global $wpdb; print_r($wpdb->get_results("SELECT * FROM {$wpdb->prefix}rycm_consent_records"));'
```

### Last run: 13 November 2026, v0.1.0, CookieConsent 3.3.0, WordPress 7.1, PHP 8.5

All seven passed in the Claude desktop browser pane (Chromium) on `localhost:8899`. Records written: accept (policy 2), reject (policy 2), accept (policy 2), each with the same consent ID, salted IP hash and `Chrome / macOS`.
