# WP Etherpad to Liveblog

WordPress plugin that publishes live field reporting written collaboratively in **Etherpad** as entries in **Automattic Liveblog**.

**Version 0.5.0 — multilingual publication markers and per-Liveblog author selection.** OmniaTV has verified signed webhooks, new entry publishing, edits to published entries, WordPress-edit precedence and independent cron recovery on a test Liveblog.

## Editor workflow

1. Enable Automattic Liveblog on a WordPress post.
2. Choose a publicly readable pad from any HTTPS Etherpad instance. No API keys or OAuth credentials are needed.
3. In **Etherpad → Liveblog**, paste the full URL, e.g. `https://pad.omniatv.com/p/<padID>` or `https://another-pad.example.org/p/<padID>`. **Search for and select the WordPress author** to attribute new updates to, then click **Connect pad**. Controls use WordPress AJAX. Eligible authors are WordPress users with `edit_posts`; the authenticated connecting editor remains the authorized publisher.
4. On existing connections, **Save author** changes the attribution for *future* updates only. Edits to old entries keep their original author. Native Liveblog polling still triggers sync; on supported instances, the optional Etherpad padUpdate webhook triggers sync immediately. Configure independent system cron once per minute for guaranteed fallback, including pads hosted elsewhere. The editor shows pad, status, revision and errors.

**Initial connection baselines existing POSTOK lines without publishing them retrospectively.** One Etherpad pad can be linked to only one Liveblog at a time and vice versa.

## Reporter workflow

Type a control word **alone on its own line** (optional surrounding spaces).
Both commands are case-insensitive and accept Latin, Greek or mixed Greek/Latin
keyboard layouts, including `POSTOK`, `postok`, `ΠΟΣΤΟΚ`, `ποστοκ`,
`NOPOST`, `nopost`, `ΝΟΠΟΣΤ`, `νοποστ`.

```text
Το δικαστήριο διέκοψε τη συνεδρίαση.
ποστοκ

Εσωτερική σημείωση που δεν πρέπει να δημοσιευτεί.
νοποστ

Η συνεδρίαση επαναλήφθηκε.
POSTOK
```

- **POSTOK** closes the preceding section and publishes it as one Liveblog update.
- **NOPOST** closes and **discards** the preceding section; this content must not
  be published and is not stored as a publishable WordPress block.
- An unfinished section without a command remains a draft.
- Historic `NOPUB` / `ENDNOPUB` spans are still parsed so old pads do not break.
  New reporting should use `NOPOST` to discard an entire section instead.
- Do not rewrite existing markers in a linked pad. Published anchors are
  revision-tracked; modifying historical markers can pause synchronization for
  safety (including changing `POSTOK` to `ποστοκ`).

Edits in an *already published* section update **the same original Liveblog
entry** after **5 seconds without further changes in that section**, unless an
editor has manually changed the Liveblog entry. Manual WordPress edits always
take precedence and cause a per-block conflict rather than an overwrite.

### Per-Liveblog authors

The author selector is in the **Etherpad → Liveblog** meta box on each Liveblog.
Start typing a WordPress display name or username, choose a result and use
**Connect pad** for a new connection or **Save author** for an existing one.
Autocomplete is restricted to authorized post editors and only returns users
with `edit_posts`, consistent with Liveblog's own author assignment checks.

Attribution is independent from publishing permission: the editor who connects
the pad remains the service publisher, while Liveblog entries are attributed to
the selected author. Existing entries are **not** retroactively reassigned when
the selected author changes. Subsequent edits of existing entries retain their
original author. Legacy links default to the previously stored author and
publisher unless explicitly changed.

## Public Etherpad connections (default)

New pad connections use Etherpad's standard public export endpoints, without an Etherpad account or API authentication:

- `/p/<padID>/export/txt`: lightweight text snapshot, checked during a triggered synchronization (at most once per 5 seconds per post from Liveblog polling).
- `/p/<padID>/export/etherpad`: full native pad export, containing revision IDs and changesets. Fetched initially and only when the text changes to retain block identity safely.

Use a full HTTPS pad URL from any publicly resolvable Etherpad instance. Pad mappings are unique by **origin + pad ID**. WordPress checks the destination hostname, blocks private/internal hosts and unexpected ports, disables redirects and limits download size (TXT: 800 KB, archive: 8 MiB). No cookies or credentials are sent.

Both export routes must be publicly readable to the WordPress server. If Cloudflare applies an automated challenge, narrowly allow the WordPress egress IP to access these two export paths; the prior `/api` exception alone does not cover them. No changes to privileged Etherpad API access are needed.

**Compatibility:** pre-existing links without a stored origin continue using the old authenticated API client. For those connections only, optional `WPELB_ETHERPAD_BASE_URL` and `WPELB_ETHERPAD_API_KEY` or OAuth/bearer credentials may still be needed.

**Limits:** the public archive contains full revision history and can grow beyond 8 MiB. On inaccessible/inconsistent or oversized exports, sync stops safely rather than guessing marker identity. Rich text and Etherpad lists are not yet transferred reliably; this MVP is for ordinary plain-text reporting.

### Signed Etherpad webhooks (optional, faster)

The WordPress bridge accepts signed `padUpdate` notifications at:

```text
POST https://omniatv.com/wp-json/wpelb/v1/notify
```

Set a random **server-side** secret in `wp-config.php` (at least 32 characters, before `wp-settings.php` is loaded):

```php
define( 'WPELB_WEBHOOK_SECRET', 'REPLACE_WITH_OPENSSL_RAND_HEX_32_VALUE' );
```

Use the same secret for the optional Etherpad plugin **`ep_liveblog_webhook`** (a separately versioned npm package, not part of this WordPress plugin). A notification carries only the public Etherpad origin, pad ID and revision, plus a unique event ID, timestamp and `HMAC-SHA256` signature. WordPress verifies the signature and timestamp, then resolves **only an existing active origin + pad mapping**. No public request is allowed to choose a WordPress post to modify. The existing single-flight synchronizer applies the same POSTOK/revision/conflict rules.

The Etherpad plugin sends one notification shortly after changes stop and another after the existing **5-second published-edit debounce**. This ensures edited entries can settle without Liveblog readers. All webhook delivery failures are recoverable via the system cron described below. If Cloudflare blocks signed POSTs, add a narrow source-IP/path/method exception for `/wp-json/wpelb/v1/notify`, not a global WAF bypass.

### Independent system cron (recommended reliable fallback)

WP-Cron is traffic-driven and Liveblog polling requires readers. To guarantee synchronization even when no one visits the site, use the **host operating system's cron**, e.g. via Virtualmin/Webmin. Run this command as the WordPress OS user **once per minute**:

```crontab
* * * * * flock -n /HOME/ACCOUNT/.wpelb-cron.lock /ABSOLUTE/PATH/TO/wp --path=/ABSOLUTE/PATH/TO/WORDPRESS etherpad-liveblog run --once
```

Replace the placeholders with actual server paths and verify `command -v wp`. The lock file should belong to the correct user and reside in a directory that user controls. This is a short-lived **WP-CLI invocation, not a systemd service**. It recovers from missing/failed Etherpad webhook deliveries, handles third-party pads without installed plugins, and is not dependent on the WordPress site being visited.

### Automatic synchronization without a separate worker service

As a supplementary trigger, native Automattic Liveblog reader polling requests to `/wp-json/liveblog/v1/{post_id}/entries/{start}/{end}/` trigger a **throttled, per-post** synchronization when they reach WordPress. The original Liveblog React code and REST responses are not modified. On PHP-FPM, the polling response is finished before Etherpad export fetching begins; on other SAPIs, the work is deferred through WP-Cron so a reader does not wait on remote exports.

A second trigger is provided by WordPress **WP-Cron** at one-minute intervals, checking all active linked pads. Both paths use the same revision-aware `WPELB_Sync_Worker::sync()` method and its single-flight publication lock. These triggers do **not** require systemd, a daemon or a persistent WP-CLI process. The independent one-minute system cron above supplies the always-on fallback.

**Timing is traffic-dependent:** when no readers have the Liveblog open, the periodic fallback executes on the next uncached PHP request that starts due WordPress cron. If `DISABLE_WP_CRON` is true, WordPress cron is blocked or requests are served entirely from CDN caches, a live polling trigger may be the only automatic mechanism. This design cannot guarantee 2-second unattended delivery in complete inactivity.

For diagnostics or a one-time immediate synchronization, run:

```bash
wp etherpad-liveblog run --once
```

For faster unattended delivery, install the optional signed Etherpad webhook sender on instances you control. Only the system cron gives a dependable periodic recovery check for other instances.

## Implemented behavior

- Anonymous public TXT and native revision exports; existing authenticated links retain `getRevisionsCount` / `getText` / `getRevisionChangeset`. POSTOK anchors track changesets (including Unicode UTF-16 offsets).
- Publish only completed public blocks with `WPCOM_Liveblog_Entry::insert()`; update originals with `WPCOM_Liveblog_Entry::update()`.
- Per-block 5s debounce; POSTOK publishes, NOPOST skips and discards, and unclosed legacy NOPUB tails never leak.
- Manual WordPress edit detection and safe block-level conflicts, sticky pending operations on uncertain writes, single-flight worker locks, error reporting and pause/resume.
- Prevent automatic historical import, delete/unpublish and structural remapping.
- Native WordPress updater recognizes published stable GitHub Releases with a correctly packaged ZIP; optional auto-updates are controlled by the WordPress administrator.

## Important MVP limits

- **Plain text only**, safely escaped with paragraph/line-break formatting. Etherpad rich-text styles and embedded images are intentionally not copied yet.
- The parser accepts full-line POSTOK/NOPOST decisions only (Latin/Greek/mixed keyboard layout). Editing, splitting, deleting or moving an existing published marker can stop the affected connection for safety.
- WordPress editors must confirm the pad's **write access is restricted**: a writer can trigger POSTOK publication. The plugin cannot independently enforce Etherpad pad ACL.
- Workers stop on revision jumps larger than 500, missing revision history, ambiguity or uncertain Liveblog writes. Such cases require manual reconciliation. A pending entry is never automatically retried, preventing accidental duplicate publishing after a crash.
- For WordPress-originated manual edits, the affected block stops synchronizing instead of silently overwriting an editor.
- Admin meta box currently targets standard WordPress posts; supported custom post types are future work.
- Each Liveblog has its own selected author; the publishing user is stored separately.

## Development and testing

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/integration-smoke.php
php tests/marker-keyboards-smoke.php
php tests/updater-smoke.php
php tests/admin-ajax-smoke.php
php tests/public-export-smoke.php
php tests/liveblog-poll-trigger-smoke.php
php tests/webhook-receiver-smoke.php
node --check assets/admin.js
```

[Architecture](docs/ARCHITECTURE.md) · [Pad protocol](docs/PAD-PROTOCOL.md) · [Greek development plan](docs/DEVELOPMENT-PLAN.el.md) · [Testing](docs/TEST-PLAN.md) · [Operations](docs/OPERATIONS.el.md) · [Releases](docs/RELEASING.md)

**License:** GPL-2.0-or-later. [Etherpad HTTP API](https://docs.etherpad.org/api/http_api.html) · [Automattic Liveblog entry implementation](https://github.com/Automattic/liveblog/blob/develop/classes/class-wpcom-liveblog-entry.php).
