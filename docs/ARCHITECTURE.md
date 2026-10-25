# Architecture — WordPress Etherpad → Automattic Liveblog

This describes the **implemented architecture**, independent of any specific
newsroom, site hostname or hosting provider. See [README](../README.md)
for setup and [Pad protocol](PAD-PROTOCOL.md) for control strings.

## Components

| WordPress component | Responsibility |
|---|---|
| `WPELB_Admin` | Post-editor connection controls, authorized AJAX author selection, pause/resume/disconnect |
| `WPELB_Public_Export_Client` | Credential-free read-only Etherpad TXT/native revision exports from a validated HTTPS origin |
| `WPELB_Etherpad_Client` | Explicitly configured authenticated API fallback for **legacy** mappings lacking an origin |
| `WPELB_Block_Parser` | Full-line POSTOK/NOPOST and legacy NOPUB/ENDNOPUB parsing |
| `WPELB_Block_Matcher` | UTF-16 anchor tracking through native Etherpad changesets |
| `WPELB_Mapping_Store` | Per-WordPress-post persisted mapping and single-flight option locks |
| `WPELB_Sync_Worker` | Revision comparison, per-block debounce, publication/update, safety state |
| `WPELB_Liveblog_Adapter` | Liveblog entry creation/update and author validation |
| `WPELB_Webhook` | HMAC-SHA256-verified, existing-mapping-only change notifications |
| `WPELB_Liveblog_Poll_Trigger` | Native reader-poll trigger and traffic-driven WP-Cron fallback |

## Data flow

```text
Any authorized correspondent
      |
      v
Publicly readable HTTPS Etherpad pad (restricted writes)
      | public /export/txt and /export/etherpad
      v
WordPress export client -> revision-aware parser and matcher
                               |
                     persisted block mapping
                               |
            single-flight worker with safety checks
                               |
                Automattic Liveblog entries
                               |
                         Liveblog readers
```

The WordPress **site** and the Etherpad **instance** may have different
operators. Neither host nor post ID is compiled into the normal connection
flow: the editor explicitly selects the HTTPS pad URL and the target post is
the WordPress post being edited.

Only the **published, completed** sections become Liveblog entries. WordPress
does not copy unpublished sections into Liveblog, but publicly accessible
Etherpad exports and revision history still expose the pad's source text.
A publicly readable pad must therefore not contain confidential notes.

## Update triggers

All triggers call the **same** `WPELB_Sync_Worker::sync(post_id)` method:

1. The independently installed OS cron invokes `wp etherpad-liveblog run --once`
   every minute. It works without Liveblog readers, WordPress visits or a
   webhook-capable Etherpad.
2. Optional [`ep_liveblog_webhook`](https://github.com/lstamellos/ep_liveblog_webhook)
   sends short signed `padUpdate` notifications to
   `POST /wp-json/wpelb/v1/notify`. Signatures include timestamp, event ID
   and raw JSON; the shared secret is server-side only.
3. Liveblog native REST polling invokes throttled synchronization after the
   reader response on PHP-FPM, or schedules WP-Cron elsewhere.
4. WordPress WP-Cron schedules a one-minute pass, but does not guarantee it
   runs in the absence of requests that reach PHP.

Signed webhooks are **not** a public publishing API. They cannot select an
arbitrary WordPress post: their origin + pad ID must match an existing active
mapping. They send revision metadata, not pad content. HTTP 202 acknowledges
the trigger, not successful publishing.

## Identity and persistence

The post meta key `_wpelb_state` stores the linked pad's origin, ID,
latest revision, tracked marker anchors, block states, hashes, original Liveblog
entry IDs, manual-edit baselines, the selected author and the authorized
publishing user. The mapping is per WordPress post.

A pad is reserved with an option based on the **origin + pad ID**.
Publication locks use `wpelb_lock_<post_id>`. The worker never automatically
steals a possible stale lock, since that could permit two writes.

On initial connection existing completed sections become `skipped` baseline
blocks, not backdated posts. New POSTOK boundaries create ready blocks.
New NOPOST boundaries create skipped blocks and discard that section.
Existing published blocks retain their entry identity across non-structural
content edits.

## Publication and edit safety

- `WPCOM_Liveblog_Entry::insert()` creates a Liveblog entry. The selected
  author is editorial attribution, while the WordPress user who established
  the connection supplies the publishing capability.
- `WPCOM_Liveblog_Entry::update()` creates a replacement event while the
  bridge retains the **original** entry ID for future updates.
- Dirty published blocks are updated after at least five seconds since the
  last observed edit **of that block**; new sections can be published meanwhile.
- The current stored WordPress content is checked against the bridge's last
  baseline. If it has been manually changed or deleted, the affected block
  becomes `conflict` and is **not overwritten**.
- Writes are marked `pending` before calling Liveblog. If a write returns
  an error or stops during the uncertain window, the entry must be reconciled
  manually; it is not blindly retried.
- Moving, deleting or rewriting an existing completed marker, inconsistent
  revision history, or a revision jump greater than 500 can pause a mapping
  rather than risk editing the wrong entry.
- Disconnecting a mapping leaves published Liveblog entries intact.

## Network and security boundaries

New pad connections validate public HTTPS hostnames, reject nonstandard ports
and redirects, and apply download limits. Both TXT and native Etherpad
exports must be anonymously readable by the WordPress server; editing must
nevertheless be restricted at the Etherpad provider.

The optional legacy authenticated API client is only used for **old** mappings
without a saved origin and now requires explicit
`WPELB_ETHERPAD_BASE_URL` in `wp-config.php`. There is no default provider.
The repository-specific GitHub release URL in the updater is the plugin's
**software distribution source**, not an Etherpad destination or WordPress
publishing site.

This MVP transfers safely escaped plain text, not rich text or media.
The admin connection box currently targets ordinary WordPress posts; see the
README for runtime limitations.
