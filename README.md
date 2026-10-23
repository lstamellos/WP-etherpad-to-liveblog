# WP Etherpad to Liveblog

WordPress plugin that publishes live field reporting written collaboratively in **Etherpad** as entries in **Automattic Liveblog**.

**Version 0.2.1 — first functional MVP with WordPress connection fix.** The implementation and automated mock integration tests are available. It has **not yet been validated against the actual pad.omniatv.com / omniatv.com installations**. Use staging before production.

## Editor workflow

1. Enable Automattic Liveblog on a WordPress post.
2. Configure server-side Etherpad API credentials in `wp-config.php` (see below).
3. Open the post editor and find **Etherpad → Liveblog**. The Connect/Pause/Resume/Disconnect controls submit independently via WordPress AJAX and show errors directly in the panel. Paste `https://pad.omniatv.com/p/<padID>`, acknowledge that the pad is writable only by authorized reporters, and click **Connect pad**.
4. Run the dedicated WP-CLI worker. The WordPress post editor shows connected pad, status, last revision and errors. Pause/resume/disconnect from the same box.

**Initial connection baselines existing POSTOK lines without publishing them retrospectively.** One Etherpad pad can be linked to only one Liveblog at a time and vice versa.

## Reporter workflow

Only three standalone commands are needed:

```
Το δικαστήριο διέκοψε τη συνεδρίαση.
POSTOK

NOPUB
Εσωτερική σημείωση για επιβεβαίωση ονόματος.
ENDNOPUB

Η συνεδρίαση επαναλήφθηκε.
POSTOK
```

`POSTOK` publishes the immediately preceding completed section. `NOPUB` / `ENDNOPUB` hide internal text. Edits in an *already published* section update **the same original Liveblog entry** after **5 seconds without further changes in that section**; typing elsewhere does not reset the timer.

Do not move or delete old POSTOK markers; the worker checks Etherpad changesets and safely pauses on structural ambiguity instead of risking a wrong-entry edit.

## Configuration

Set the following in WordPress `wp-config.php` (outside web-visible files and Git):

```php
define( 'WPELB_ETHERPAD_BASE_URL', 'https://pad.omniatv.com' );
// Prefer OAuth client credentials for recent Etherpad deployments:
define( 'WPELB_ETHERPAD_CLIENT_ID', 'service-client-id' );
define( 'WPELB_ETHERPAD_CLIENT_SECRET', 'secret-from-server-configuration' );
// OR a preissued bearer token:
// define( 'WPELB_ETHERPAD_BEARER_TOKEN', '...' );
// OR a legacy Etherpad API key:
// define( 'WPELB_ETHERPAD_API_KEY', '...' );
```

Credentials are exchanged **server-side** over HTTPS. OAuth credentials are exchanged at `/oidc/token`, with an in-memory cached bearer token. Legacy API keys go in POST bodies, never browser scripts or URL query strings.

### Worker

Run from the WordPress installation directory as the appropriate OS user:

```bash
wp etherpad-liveblog run --once
# or use a persistent loop managed by systemd:
wp etherpad-liveblog run --interval=2
```

For automatic upgrades, prefer the **short-lived `--once` mode launched repeatedly** by a supervisor; each invocation reloads PHP/plugin code (see [operations guide](docs/OPERATIONS.el.md)). No worker is automatically launched on plugin activation; WP-Cron alone cannot guarantee 2-second polling.

## Implemented behavior

- Pull via Etherpad `getRevisionsCount`, `getText(rev)` and `getRevisionChangeset(rev)`; track POSTOK marker anchors through revision changesets (including Unicode UTF-16 offsets).
- Publish only completed public blocks with `WPCOM_Liveblog_Entry::insert()`; update originals with `WPCOM_Liveblog_Entry::update()`.
- Per-block 5s debounce; new POSTOK publishes on the next successful worker pass; unclosed NOPUB tails never leak.
- Manual WordPress edit detection and safe block-level conflicts, sticky pending operations on uncertain writes, single-flight worker locks, error reporting and pause/resume.
- Prevent automatic historical import, delete/unpublish and structural remapping.
- Native WordPress updater recognizes published stable GitHub Releases with a correctly packaged ZIP; optional auto-updates are controlled by the WordPress administrator.

## Important MVP limits

- **Plain text only**, safely escaped with paragraph/line-break formatting. Etherpad rich-text styles and embedded images are intentionally not copied yet.
- The parser accepts full-line marker commands only. Editing, splitting, deleting or moving an existing published marker can stop the affected connection for safety.
- WordPress editors must confirm the pad's **write access is restricted**: a writer can trigger POSTOK publication. The plugin cannot independently enforce Etherpad pad ACL.
- Workers stop on revision jumps larger than 500, missing revision history, ambiguity or uncertain Liveblog writes. Such cases require manual reconciliation. A pending entry is never automatically retried, preventing accidental duplicate publishing after a crash.
- For WordPress-originated manual edits, the affected block stops synchronizing instead of silently overwriting an editor.
- Admin meta box currently targets standard WordPress posts; supported custom post types are future work.
- Only simple site-level per-pad mapping and service-user authorship are available in the first MVP.

## Development and testing

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/integration-smoke.php
php tests/updater-smoke.php
php tests/admin-ajax-smoke.php
node --check assets/admin.js
```

[Architecture](docs/ARCHITECTURE.md) · [Pad protocol](docs/PAD-PROTOCOL.md) · [Greek development plan](docs/DEVELOPMENT-PLAN.el.md) · [Testing](docs/TEST-PLAN.md) · [Operations](docs/OPERATIONS.el.md) · [Releases](docs/RELEASING.md)

**License:** GPL-2.0-or-later. [Etherpad HTTP API](https://docs.etherpad.org/api/http_api.html) · [Automattic Liveblog entry implementation](https://github.com/Automattic/liveblog/blob/develop/classes/class-wpcom-liveblog-entry.php).
