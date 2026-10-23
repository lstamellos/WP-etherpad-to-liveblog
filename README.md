# WP Etherpad to Liveblog

WordPress plugin that publishes live field reporting written collaboratively in **Etherpad** as entries in **Automattic Liveblog**.

**Version 0.3.1 — credential-free Etherpad and service-free polling-based synchronization.** Initial pad connection and manual POSTOK publication have been verified on the OmniaTV test Liveblog. Liveblog-triggered automation and published-entry edits still require live verification.

## Editor workflow

1. Enable Automattic Liveblog on a WordPress post.
2. Choose a publicly readable pad from any HTTPS Etherpad instance. No API keys or OAuth credentials are needed.
3. In **Etherpad → Liveblog**, paste the full URL, e.g. `https://pad.omniatv.com/p/<padID>` or `https://another-pad.example.org/p/<padID>`, and click **Connect pad**. Controls use WordPress AJAX.
4. Synchronization begins automatically when readers poll the Liveblog REST endpoint; WP-Cron is a minute-level fallback when the WordPress site receives uncached visits. The editor shows pad, status, revision and errors. Manual WP-CLI sync remains available.

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

## Public Etherpad connections (default)

New pad connections use Etherpad's standard public export endpoints, without an Etherpad account or API authentication:

- `/p/<padID>/export/txt`: lightweight text snapshot, checked during a triggered synchronization (at most once per 5 seconds per post from Liveblog polling).
- `/p/<padID>/export/etherpad`: full native pad export, containing revision IDs and changesets. Fetched initially and only when the text changes to retain block identity safely.

Use a full HTTPS pad URL from any publicly resolvable Etherpad instance. Pad mappings are unique by **origin + pad ID**. WordPress checks the destination hostname, blocks private/internal hosts and unexpected ports, disables redirects and limits download size (TXT: 800 KB, archive: 8 MiB). No cookies or credentials are sent.

Both export routes must be publicly readable to the WordPress server. If Cloudflare applies an automated challenge, narrowly allow the WordPress egress IP to access these two export paths; the prior `/api` exception alone does not cover them. No changes to privileged Etherpad API access are needed.

**Compatibility:** pre-existing links without a stored origin continue using the old authenticated API client. For those connections only, optional `WPELB_ETHERPAD_BASE_URL` and `WPELB_ETHERPAD_API_KEY` or OAuth/bearer credentials may still be needed.

**Limits:** the public archive contains full revision history and can grow beyond 8 MiB. On inaccessible/inconsistent or oversized exports, sync stops safely rather than guessing marker identity. Rich text and Etherpad lists are not yet transferred reliably; this MVP is for ordinary plain-text reporting.

### Automatic synchronization without a separate worker service

Native Automattic Liveblog reader polling requests to `/wp-json/liveblog/v1/{post_id}/entries/{start}/{end}/` trigger a **throttled, per-post** synchronization when they reach WordPress. The original Liveblog React code and REST responses are not modified. On PHP-FPM, the polling response is finished before Etherpad export fetching begins; on other SAPIs, the work is deferred through WP-Cron so a reader does not wait on remote exports.

A second trigger is provided by WordPress **WP-Cron** at one-minute intervals, checking all active linked pads. Both paths use the same revision-aware `WPELB_Sync_Worker::sync()` method and its single-flight publication lock. These triggers do **not** require systemd, a daemon or a persistent WP-CLI process.

**Timing is traffic-dependent:** when no readers have the Liveblog open, the periodic fallback executes on the next uncached PHP request that starts due WordPress cron. If `DISABLE_WP_CRON` is true, WordPress cron is blocked or requests are served entirely from CDN caches, a live polling trigger may be the only automatic mechanism. This design cannot guarantee 2-second unattended delivery in complete inactivity.

For diagnostics or a one-time immediate synchronization, run:

```bash
wp etherpad-liveblog run --once
```

For heavy production Liveblogs requiring guaranteed latency without readers, use an independent server-side scheduler or event-driven Etherpad webhook instead; live frontend polling alone does not provide an always-on clock.

## Implemented behavior

- Anonymous public TXT and native revision exports; existing authenticated links retain `getRevisionsCount` / `getText` / `getRevisionChangeset`. POSTOK anchors track changesets (including Unicode UTF-16 offsets).
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
php tests/public-export-smoke.php
php tests/liveblog-poll-trigger-smoke.php
node --check assets/admin.js
```

[Architecture](docs/ARCHITECTURE.md) · [Pad protocol](docs/PAD-PROTOCOL.md) · [Greek development plan](docs/DEVELOPMENT-PLAN.el.md) · [Testing](docs/TEST-PLAN.md) · [Operations](docs/OPERATIONS.el.md) · [Releases](docs/RELEASING.md)

**License:** GPL-2.0-or-later. [Etherpad HTTP API](https://docs.etherpad.org/api/http_api.html) · [Automattic Liveblog entry implementation](https://github.com/Automattic/liveblog/blob/develop/classes/class-wpcom-liveblog-entry.php).
