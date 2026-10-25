# WP Etherpad to Liveblog

Publish collaboratively written [Etherpad](https://etherpad.org/) reports as updates
in [Automattic Liveblog](https://github.com/Automattic/liveblog) on WordPress.

**Self-hosted WordPress integration, usable with any publicly readable HTTPS
Etherpad instance.** The WordPress site and Etherpad may be on different
domains, servers or providers. No organization-specific hostname or fixed
WordPress post ID is required.

## Requirements

- WordPress **6.4+**, PHP **8.0+**, and the **Automattic Liveblog** plugin,
  with Liveblog enabled on a normal WordPress **post**.
- A WordPress editor who can edit the post and publish posts. The plugin's
  **Etherpad → Liveblog** box appears in the post editor.
- An Etherpad pad with publicly accessible **read-only export endpoints**:
  `/p/<padID>/export/txt` and `/p/<padID>/export/etherpad`.
  The WordPress server must be able to reach both endpoints using HTTPS.
- **Restricted pad editing**: only trusted correspondents should be able to
  write to the pad. Anyone who can edit it can trigger a Liveblog publication.

New pad connections do **not** need an Etherpad API key, OAuth configuration,
WordPress-to-Etherpad credentials, or an Etherpad-side plugin. Publication uses
the WordPress site's existing Liveblog plugin.

## Install and connect a pad

1. Install and activate **Automattic Liveblog** and **WP Etherpad to Liveblog**
   on your WordPress site.
2. Enable Liveblog on the WordPress post that will receive updates.
3. Open the post editor and find **Etherpad → Liveblog** in the sidebar.
4. Enter the **full HTTPS pad URL**, for example
   `https://pad.example.org/p/court-reporting`.
5. Search for and select the WordPress **author** who should be credited for
   new updates. Eligible authors have the `edit_posts` capability.
6. Confirm that pad editing is restricted to trusted contributors; click
   **Connect pad**.

A newly connected pad's **already completed sections are baselined, not
republished**. Only new completed sections are eligible for publication.
Each connection is one pad ↔ one Liveblog; a pad cannot be linked twice.

An existing connection provides **Pause sync**, **Resume sync** and
**Disconnect** controls. Disconnection does not delete published entries.
**Save author** changes attribution for *future new entries only*. The author
of existing entries remains unchanged when the pad content is edited later.

The WordPress editor who establishes the connection remains the authorized
publishing user; the selected author is editorial attribution, not a grant
of publishing permission.

## Write updates in Etherpad

Use either control word **alone on its own line** (surrounding spaces are fine):

| Control | Effect | Accepted examples |
|---|---|---|
| `POSTOK` | Publish the preceding section as one update | `POSTOK`, `postok`, `ΠΟΣΤΟΚ`, `ποστοκ` |
| `NOPOST` | Close and discard the preceding section without publishing | `NOPOST`, `nopost`, `ΝΟΠΟΣΤ`, `νοποστ` |

The parser also accepts mixed Greek/Latin keyboard input and mixed case,
such as `ΠoΣtOκ`. Control words *inside sentences* do not trigger anything.

```text
The hearing resumed at 10:30.
postok

Internal note: verify the speaker's name.
νοποστ

The next witness entered the room.
POSTOK

Draft in progress...
```

This produces **two** Liveblog updates. The internal note is not published,
and the incomplete draft is withheld until a control marker closes it.
Legacy `NOPUB` / `ENDNOPUB` markers are also recognized for existing pads,
but have different semantics: they exclude a *region inside a section*, whereas
`NOPOST` discards the entire preceding section.

**Never delete, move or rewrite an existing completed marker.** Marker anchors
are tracked through Etherpad revisions; even changing an old `POSTOK` to
`ποστοκ` may force the connection to pause to protect update identity.

### Correct existing Liveblog updates

Edit the text of an already published section **inside Etherpad**, without
touching its marker. After at least five seconds of inactivity for that
section, the plugin updates its original Liveblog entry rather than posting a
duplicate. Editing a different section does not reset this debounce timer.

If someone edited that entry **directly in WordPress**, the manual edit takes
precedence: the bridge reports a conflict for that section and does not
overwrite it. Other sections can continue to synchronize. A conflict requires
editor review; it is not automatically discarded.

## Keep updates flowing: cron and optional webhooks

There are three mechanisms, sharing the same guarded synchronizer:

1. **Independent OS cron (recommended)** checks connected pads once a minute,
   including when no one visits WordPress or Liveblog and when the Etherpad
   server does not run a webhook plugin.
2. **Signed Etherpad webhook (optional)** triggers a faster sync after changes
   on an Etherpad installation you administer.
3. **Built-in fallbacks** use native Liveblog reader polling and WordPress
   WP-Cron. These depend on requests reaching WordPress/PHP, so they do not
   guarantee unattended execution.

### Configure independent OS cron

On the **WordPress server**, identify its OS account, WP-CLI path and site path.
Create a private directory owned by that user, e.g.
`/home/site-user/.wpelb-cron/`, and test a one-off invocation:

```bash
wp --path=/path/to/wordpress etherpad-liveblog run --once
```

Then add **one** crontab entry under the same account (substitute *all*
paths with your own; do not copy these placeholders literally):

```cron
* * * * * /usr/bin/flock -n /home/site-user/.wpelb-cron/sync.lock /usr/local/bin/wp --path=/path/to/wordpress etherpad-liveblog run --once >/dev/null 2>>/home/site-user/.wpelb-cron/sync-errors.log
```

The `flock` lock prevents overlapping cron runs. The plugin also guards
each individual WordPress Liveblog against concurrent publication.
This is a short-lived CLI process, **not** a new systemd service.
To inspect the configured job use `crontab -l`. The error log is normally
empty. Verify the actual `flock` and WP-CLI locations on your OS.

### Optional signed Etherpad webhook

If you administer the Etherpad server, you may install the separate
[`ep_liveblog_webhook`](https://github.com/lstamellos/ep_liveblog_webhook)
plugin there. **It is not required** for third-party Etherpad servers:
the independent cron performs periodic checks without it.

On the WordPress server, generate a random shared secret:

```bash
openssl rand -hex 32
```

In `wp-config.php`, **before WordPress is loaded**, configure:

```php
define( 'WPELB_WEBHOOK_SECRET', 'REPLACE_WITH_YOUR_RANDOM_SECRET' );
```

Give the same secret to Etherpad as a protected
`EP_LIVEBLOG_WEBHOOK_SECRET` environment variable. On the Etherpad side,
add this property to the **existing** Etherpad `settings.json` (do not
replace that configuration file):

```json
{
  "ep_liveblog_webhook": {
    "enabled": true,
    "origin": "https://pad.example.org",
    "endpoint": "https://news.example.com/wp-json/wpelb/v1/notify",
    "padIds": ["court-reporting"]
  }
}
```

- Replace the `origin` with your Etherpad's public HTTPS origin.
  It must match the pad connection saved in WordPress.
- Replace `endpoint` with **your WordPress site's** HTTPS origin followed
  by `/wp-json/wpelb/v1/notify`.
- Restrict `padIds` to the connected pad IDs, or configure the supported
  `padPrefix` when appropriate.
- Restart Etherpad using its existing service manager. The separate webhook
  repository contains its installation commands and supported settings.

Webhooks send **revision metadata, not pad text**, and authenticate with
HMAC-SHA256. HTTP **202 only means the notification was accepted**, not that
the corresponding entry is already published. Keep independent cron enabled,
as webhook notifications may be missed during errors or restarts.

If a reverse proxy or WAF challenges the signed notification, create a
*narrow* exception for your Etherpad server egress IP, WordPress hostname,
HTTP `POST`, and precisely the webhook REST route. Avoid disabling your
site's WAF globally.

## Security and operational limits

- **Publicly readable is not publicly writable.** The Etherpad export endpoints
  must be accessible to WordPress, but pad *editing* must be restricted.
  `NOPOST` prevents **Liveblog publication**; it does **not** make the source
  text secret or remove it from Etherpad's public exports and revision history.
  Do not place confidential information in a publicly readable pad.
- **Network controls:** only public HTTPS Etherpad URLs are accepted for new
  connections. The client validates hosts, rejects internal/private targets,
  nonstandard ports and redirects, and limits downloaded data (TXT: 800 KB,
  native archive: 8 MiB). If an external firewall blocks downloads, allow the
  WordPress server to reach *both* public export endpoints.
- **Plain text only:** the bridge escapes text and preserves paragraphs and
  line breaks, not Etherpad rich text, collaborator colors, lists or images.
- **Safe failure:** missing history, a revision jump over 500, ambiguous marker
  edits, or an uncertain write can pause a connection. Pending writes require
  manual reconciliation to avoid accidental duplicates.
- **Standard posts only:** the connection meta box currently targets WordPress
  `post`, not arbitrary custom post types.
- **Liveblog compatibility:** the code relies on the Automattic Liveblog
  `WPCOM_Liveblog_Entry` API; verify compatibility with your installed version
  before using it for important live coverage.

### Legacy authenticated connections

**Only old connections created with the original Etherpad API integration**,
which lack a stored `origin`, use the authenticated API client instead of public
exports. For these connections, explicitly configure the Etherpad server in
`wp-config.php`:

```php
define( 'WPELB_ETHERPAD_BASE_URL', 'https://pad.example.org' );
define( 'WPELB_ETHERPAD_API_KEY', 'REPLACE_WITH_LEGACY_API_KEY' );
```

Alternative supported authentication is via the existing Etherpad bearer/OAuth
settings documented in the code. **There is intentionally no default Etherpad
hostname:** legacy API connections without an explicit URL fail with an
actionable configuration error rather than connecting to somebody else's pad.
New public-export connections need neither constant.

## Diagnose problems

| Symptom | What to check |
|---|---|
| Cannot connect a pad | Liveblog is enabled; you have post-edit and publish rights; both public export paths are reachable and not challenged by a proxy |
| No new update | The full-line `POSTOK` follows the text; connection status is active; check WordPress cron/system cron and bridge errors |
| No webhook delivery | Same secret on both servers, matching HTTPS `origin`, synchronized clocks and reverse proxy/WAF logs |
| An edited entry stays unchanged | Check per-block `conflict`; a manual WordPress edit must not be overwritten |
| Connection pauses | Check pending writes, changed marker anchors and available Etherpad revision history; do not blindly clear locks or reset mappings |
| Author not offered | WordPress user must exist and have `edit_posts` |
| New install works, old API connection fails | Configure `WPELB_ETHERPAD_BASE_URL` explicitly for that legacy connection |

A one-off diagnostic pass runs with:

```bash
wp --path=/path/to/wordpress etherpad-liveblog run --once
```

More detail: [Operations (Greek)](docs/OPERATIONS.el.md) ·
[Pad protocol](docs/PAD-PROTOCOL.md) ·
[User guide (Greek)](docs/USER-GUIDE.el.md) ·
[Architecture](docs/ARCHITECTURE.md) ·
[Release instructions](docs/RELEASING.md).

## Updates, development and license

The plugin uses normal WordPress update notifications sourced from this
project's [published GitHub Releases](https://github.com/lstamellos/WP-etherpad-to-liveblog/releases).
Automatic updates remain **opt-in** through WordPress. For a production site,
verify update/rollback on staging before enabling unattended updates.

Run the repository's smoke tests on PHP 8.0+ (CI also checks PHP 8.3):

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/updater-smoke.php
php tests/integration-smoke.php
php tests/marker-keyboards-smoke.php
php tests/legacy-origin-smoke.php
php tests/admin-ajax-smoke.php
php tests/public-export-smoke.php
php tests/liveblog-poll-trigger-smoke.php
php tests/webhook-receiver-smoke.php
node --check assets/admin.js
```

WordPress plugin license: **GPL-2.0-or-later**. The optional Etherpad webhook
plugin is released separately under **GPL-3.0-only**.
