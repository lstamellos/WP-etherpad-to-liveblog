# Architecture and integration decisions

Status: **design specification**, not implemented in v0.1.0. Decisions require verification against the versions running on `pad.omniatv.com` and `omniatv.com`.

## Components and ownership

| Component | Responsibility |
|---|---|
| WordPress admin | Configure/enable/pause one-to-one pad↔liveblog mapping; inspect errors/conflicts |
| Etherpad client | Read revision counter, then a consistent text + HTML snapshot at a selected revision |
| Control parser | Build publishable blocks and exclude NOPUB regions; emit warnings |
| Block identity tracker | Preserve identities across edits/insertions; detect ambiguous marker changes |
| Persistent mapping store | Link pad/block ↔ original Liveblog entry; hashes, debounce, locks and audit |
| Sync worker | Revision polling, per-block change detection, debounce, retries and reconciliation |
| Liveblog adapter | Validate post/status/permissions; call Liveblog insert()/update() |

**Data flow:** Etherpad → read-only WordPress polling → parser + matcher → persistent state machine → Liveblog adapter → readers.

No client-side secret, browser-side pad scraper, or plugin installed in Etherpad is required for the initial implementation.

## Upstream contracts

### Etherpad

Use the [documented HTTP API](https://docs.etherpad.org/api/http_api.html):

1. Query `/api` to negotiate the installed API version.
2. Call `getRevisionsCount(padID)`; skip fetching unchanged snapshots only when no pending debounce/retry needs processing.
3. If changed, fetch `getText(padID, rev)` and `getHTML(padID, rev)` with **the same explicit revision**.
4. Verify matching segmentation and fail safely on mismatched/invalid data.
5. Handle API error codes, timeouts, body-size limits and auth failures.

Current Etherpad documentation describes OAuth bearer tokens/service-client credentials; older releases can use legacy API keys. **Determine the actual deployment's auth contract first, and support only the configured, tested method.** Never pass a credential to browser JavaScript or disclose it in logs. Prefer a dedicated read-only/restricted service identity and network policy where possible; assess the broad privileges of native API credentials before use.

The optional server-side [`padUpdate` hook](https://docs.etherpad.org/api/hooks_server-side.html) can later trigger a pull, but direct pushing is not required.

### Automattic Liveblog

Use `WPCOM_Liveblog_Entry::insert( array( 'post_id' => ..., 'content' => ..., 'user' => WP_User ) )` and `WPCOM_Liveblog_Entry::update( array( 'entry_id' => original_id, 'post_id' => ..., 'content' => ... ) )`, after verifying the deployed version and enabled liveblog status. **Do not insert liveblog entries as posts or bypass Liveblog's methods.**

In the upstream implementation, entries are a custom comment type. `update()` emits a *replacement event* with its own comment ID, links it to the original via `liveblog_replaces`, and also updates the original comment content. Persist both the stable **original entry ID** and the latest replacement/event ID separately; the former is used when updating again. Verify hooks/cache interactions in the actual installation.

A dedicated WordPress user needs the appropriate role/capabilities. Check capability to publish/edit the target liveblog and check the liveblog is active before every write. The automated worker must not inherit arbitrary visiting-user permissions.

Upstream code: https://github.com/Automattic/liveblog/blob/develop/classes/class-wpcom-liveblog-entry.php

## Publish/update state machine

```
unseen block --POSTOK observed and unambiguous--> ready
ready --validated/authorized--> publishing --> published(original_entry_id)
published --hash differs--> dirty(last_change_seen_at)
dirty --new edit same block--> dirty(reset block timer)
dirty --unchanged >= 5s--> updating --> published(original ID retained)
any --ambiguous boundary/conflict--> paused_for_review
network/write failure --> retry/reconcile, never assume success
```

- New complete blocks are dispatched promptly after a valid snapshot. An incomplete marker cannot trigger publication.
- Edits to **published** blocks are debounced independently (default 5 seconds), based on observation time and content hash; typing in another block does not reset their timers.
- Poll target: ~2 seconds for active pads; actual load/latency and scheduling must be benchmarked before deployment.
- Persist observation time, observed hash and last successfully published hash across worker restarts.
- On next loop, pending dirty blocks must be evaluated even if the pad revision has not changed.
- Normalize/compare **publishable** content (not NOPUB notes, author colors, irrelevant formatting).
- Compare snapshot hash against liveblog baseline to avoid needless update events.

## Identity and ambiguous edits

A positional index (first POSTOK, second POSTOK, …) is **not** a stable identifier. Assign an immutable internal block UUID at first observation; maintain tracked delimiter anchors across Etherpad revisions (changesets where feasible), using bounded content/neighbor matching only as an auxiliary signal.

Deleting/moving/replacing boundaries, splitting one published block, merging two published blocks, or inserting delimiters between earlier entries creates potential ambiguity. Without unambiguous identity, **pause affected updates and alert an editor**. Never silently update the wrong liveblog entry. No extra reporter command is needed in ordinary usage. If anchor tracking is unworkable in the installed Etherpad release, design an explicit fallback before enabling editing synchronization.

## Initial linking and lifecycle

- Editors attach a pad URL in the liveblog's WordPress editor; parse the pad ID server-side and verify its existence.
- Maintain unique database constraints for both normalized (Etherpad origin, pad ID) and WordPress liveblog post ID; reject duplicate bindings.
- On first connection, record current revision and marker baseline. **Do not back-publish existing POSTOK blocks** by default; allow an explicitly reviewed import mode later.
- Provide pause/resume; pausing stops writes without deleting entries or state. Disconnect does not delete published content.
- If a liveblog closes/archives, stop publication rather than reopening it.
- Changes to the pad URL or owner require authorized revalidation; do not silently switch sources.

## Worker scheduling and recovery

Use a dedicated WP-CLI long-running process managed by systemd/Supervisor (or an equivalent reliable service) for the ~2-second polling target. Single-flight locks with TTL/lease renewal prevent two workers acting on the same pad concurrently. Normal WP-Cron cannot guarantee sub-minute execution and must not be presented as equivalent.

Store an operation key (binding ID, block UUID, desired normalized content hash, action) in a durable outbox before writing. On restart, **reconcile** any incomplete operation against Liveblog comments/replacement metadata and the operation ledger before retrying. Atomically associate successful writes with their mapping as far as WordPress DB transactions allow; validate failure paths and hooks. Do not promise absolute exactly-once semantics until crash-window tests demonstrate it.

Use bounded retries with backoff, eventual catch-up, health checks and status counters. API timeouts should never cause automatic deletion or duplicate posting.

## Proposed persistence model (to finalize in migration)

- `wp_wpelb_bindings`: id, normalized Etherpad origin, pad ID, liveblog post ID, status, baseline revision, last seen revision, last_success, creator, timestamps; UNIQUE(origin,pad_id), UNIQUE(liveblog_post_id).
- `wp_wpelb_blocks`: UUID, binding ID, tracked anchor references, original Liveblog entry ID, latest replacement event ID, last published hash/content baseline, observed hash, last_observed_change_at, sync state, version, timestamps.
- `wp_wpelb_operations`: unique operation key, state, action, payload hash, original entry ID, retry data, audit timestamps.
- `wp_wpelb_events` (or bounded audit table): operational errors, conflict decisions, administrator actions, correlation IDs; restrict access and retention.

Never store tokens in these tables. Manage secrets with restricted server-side configuration (wp-config/environment or protected secret store).

## HTML, conflict and security policy

- Parse markers from normalized plain text, map precisely to corresponding HTML segments from the **same revision**; fail closed on segment mismatch. Sanitize resulting HTML using WordPress content policy (e.g. `wp_kses_post` and Liveblog's own filters). Preserve normal paragraphs, links, bold/italic; strip Etherpad collaborator highlights and marker lines.
- Do not treat an arbitrary user-supplied URL as a fetch target. Enforce exact allowed Etherpad scheme/host/port, HTTPS, redirects policy, internal-network SSRF protection, bounded response size and timeouts.
- Resolve saved WordPress editor modifications by comparing the current content with the last bridge-written baseline before every edit. On mismatch, stop overwriting the entry and flag a conflict for explicit editor resolution.
- Removing text or a published marker does NOT delete an existing Liveblog update. Delete/unpublish, if ever needed, must be a separate authorized action with confirmation.
- Restrict mapping settings to editors with appropriate `edit_post` and Liveblog publishing capabilities, nonce + server validation; restrict global credentials to administrators.
- Secrets never appear in GitHub, logs, HTML or pad content. Log block/entry IDs, revision and status only; redact sensitive text including NOPUB content.
