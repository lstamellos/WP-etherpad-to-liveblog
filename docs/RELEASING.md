# Releasing the WordPress plugin from GitHub

## Normal release procedure

1. Merge and test changes on `main`. Verify both plugin version sources:
   - `Version: X.Y.Z` in `wp-etherpad-to-liveblog.php`
   - `Stable tag: X.Y.Z` in `readme.txt`
2. Create the GitHub tag `vX.Y.Z` **on the tested commit**, e.g. `v0.1.1`.
3. Publish a **stable (not prerelease)** GitHub Release for that tag.
4. GitHub Actions (`.github/workflows/github-release.yml`) runs on `release.published`, validates the tag/version and attaches the asset **`wp-etherpad-to-liveblog.zip`**.
5. Confirm the Actions job **succeeded** and the release page shows the ZIP asset. Do not announce the update as ready before that. If release asset uploads are blocked by immutable-release settings, upload the correctly packaged ZIP *while the release is draft*, then publish. The workflow must be adapted to that repository policy.
6. On staging WordPress with an **older installed version**, go to Dashboard → Updates / Plugins and check for updates. Test **Update now**, verify the installed plugin folder still has the same slug and the reported version is updated. Confirm the normal WordPress per-plugin automatic-updates toggle is available. A second staged release is necessary to verify an actual unattended automatic update.

## ZIP contract

The downloaded release asset must be named `wp-etherpad-to-liveblog.zip`, containing exactly one **top-level plugin directory**:

```text
wp-etherpad-to-liveblog/
  wp-etherpad-to-liveblog.php
  readme.txt
  uninstall.php
  includes/
    ...
```

This archive must be packaged **from the tag**, not from a moving `main`. Never use the auto-generated `Source code (zip)` or `zipball_url` in WordPress updates: their root directory is not guaranteed to match the installed plugin path.

## How WordPress detects updates

- The plugin has `Update URI: https://github.com/lstamellos/WP-etherpad-to-liveblog`, preventing accidental WordPress.org updates for a colliding slug.
- On a normal WP plugin update check, WordPress invokes `update_plugins_github.com`. The updater ignores *other* plugins and queries `https://api.github.com/repos/lstamellos/WP-etherpad-to-liveblog/releases/latest`.
- Only public, published, non-draft/non-prerelease stable `vX.Y.Z` releases with an **uploaded**, matching ZIP asset URL can be offered.
- If the package is absent or the network/API fails, no update is offered. Metadata is cached for ~15 minutes on success, ~5 minutes otherwise; WordPress itself also caches plugin update checks at its normal intervals.
- No GitHub personal token is required for the public repository. The GitHub API may rate-limit high-volume requests; don't bypass caching.
- Site administrators choose manual updates or enable automatic updates using WordPress's native plugin setting. The bridge does **not** enable unattended updates by default.

## Update safety and deployment

- Keep backups and use staging before production.
- The WP-CLI synchronization worker is implemented. Use a supervisor invoking separate `--once` runs so new plugin files load on the next run; a long-running `run --interval=2` process must be restarted after updates. Test restart/reload before enabling unattended updates on production.
- If rollback is needed, reinstall the previous tagged ZIP explicitly; never rely on a failed GitHub API request to reverse an installed version.
- GitHub release ZIPs are code-distribution artifacts. Restrict repository write/release permissions and ensure CI completes before trusting a release.

## References

- [WordPress Update URI header](https://make.wordpress.org/core/2021/06/29/introducing-update-uri-plugin-header-in-wordpress-5-8/)
- [WordPress update_plugins_{$hostname} hook](https://developer.wordpress.org/reference/hooks/update_plugins_hostname/)
- [GitHub latest release REST API](https://docs.github.com/en/rest/releases/releases#get-the-latest-release)
