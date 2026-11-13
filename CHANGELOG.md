# Changelog

## 0.1.2

- Cloudflare Web Analytics as a built-in service (setting: Cloudflare Web Analytics token). Cookieless aggregate statistics need no consent under PECR as amended by the Data (Use and Access) Act 2025, provided visitors can object, so the beacon loads before any choice and after Accept, and stays off once a visitor chooses Reject all or switches analytics off. Allowing analytics again starts it; refusing on a page where it is running reloads the page without it. Listed under Analytics in the preferences panel and `[rycm_cookie_policy]`.
- "None" in a service's cookie list is no longer treated as a cookie name.

## 0.1.1

- Fix: expanded categories in the preferences panel were clipped into an oval, because CookieConsent uses `--cc-btn-border-radius` for the category panels as well as the buttons. The variable is now 12px and the buttons are made round directly.

## 0.1.0

First release (Phase 1).

- Banner and preferences panel on CookieConsent 3.3.0, styled from settings, equal-weight Accept, Reject and Customise.
- Google Consent Mode v2 defaults (all denied, `wait_for_update`) as the first script in `<head>`, updates on every choice and on return visits, optional UK/EEA region mode.
- Script blocking by category: built-in GA4 tag, `rycm_consent_block_handle()`, the `rycm_consent_blocked_handles` filter and `rycm_consent_wrap()`.
- WP Consent API integration (opt-in consent type, `wp_set_consent` per category).
- Consent records table, REST route with signed token, same-origin check and rate limit, daily retention purge (default 12 months), CSV export.
- Settings > Cookie consent: texts, colours with contrast warnings, categories, services and cookies, policy version bump.
- `[rycm_cookie_settings]` and `[rycm_cookie_policy]` shortcodes, floating re-open button.
- Updates from GitHub Releases via `Update URI`.
