# Releasing an update

Sites get updates from this repository's GitHub Releases. The plugin header has `Update URI: https://github.com/martingfisher/rycm-consent`, so WordPress never asks wordpress.org about it; instead `Rycm_Consent_Updater` reads `https://api.github.com/repos/martingfisher/rycm-consent/releases/latest` (cached for six hours) and offers the release's attached `rycm-consent.zip` as a normal update. The repository is public, so no token is stored anywhere.

## Steps

1. Make the change on a branch, open a PR, let CI pass (unit tests on PHP 7.4, 8.1, 8.4; integration on a throwaway WordPress; zip build). Run the browser check in [TESTING.md](TESTING.md) for front-end changes.
2. In the same PR, raise the version in all three places (the release workflow refuses a mismatch):
   - `rycm-consent.php`: the `Version:` header and `RYCM_CONSENT_VERSION`
   - `readme.txt`: `Stable tag:`
   - `CHANGELOG.md`: a `## x.y.z` section (it becomes the release notes)
3. Merge to `main`.
4. Tag the merge commit and push the tag:

   ```bash
   git checkout main && git pull && git tag v0.2.0 && git push origin v0.2.0
   ```

5. The Release workflow re-runs the tests, builds `dist/rycm-consent.zip` (top-level folder `rycm-consent/`) and publishes the release with the zip attached.
6. Sites show the update within six hours. To see it at once on a site: Dashboard > Updates > Check again (this clears WordPress's own cache; the plugin's six-hour cache is cleared after any plugin update, or with `wp transient delete rycm_consent_release --network`).

## Rules

- Never delete or re-point a published tag: sites may already have installed it.
- Use plain `x.y.z` tags with a `v` prefix. Drafts and pre-releases are ignored by the updater.
- If a release is bad, ship the fix as the next version rather than editing the release asset.
- Raising the plugin version does not re-ask visitors for consent. Only the policy version in Settings > Cookie consent does that; raise it when a site's data use changes, not for plugin updates.
- Upgrading CookieConsent: `bin/update-vendor.sh <exact version>`, then the full test run and browser check.
