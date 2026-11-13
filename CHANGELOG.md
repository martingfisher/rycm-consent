# Changelog

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
