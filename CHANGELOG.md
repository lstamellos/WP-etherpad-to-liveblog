# Changelog

## 0.5.0 (2026-10-24)

- Full-line POSTOK and NOPOST are now case-insensitive and Greek/Latin/mixed keyboard tolerant, with six-code-unit marker compatibility.
- NOPOST closes an unpublished section; NOPUB/ENDNOPUB remain supported for legacy pads.
- Per-Liveblog selected WordPress author with authenticated AJAX search in the post editor and a separate authorized publisher. Future entries use the selected author; old entries stay unchanged.
- New Unicode parser, safety, permission and author regression tests.

## 0.4.0 — 2026-10-24

- Added signed HMAC-SHA256 Etherpad padUpdate webhook receiver at `POST /wp-json/wpelb/v1/notify`; shared secret lives only in server-side configuration.
- Bound notifications to an existing active mapping by exact public instance origin and pad ID; no body-selected WordPress post and no direct unauthenticated publishing.
- Uses the existing revision-aware single-flight synchronizer after the REST response (PHP-FPM) or deferred WP-Cron (non-FPM), preserving POSTOK, corrections and crash safety.
- Keeps native Liveblog polling and WP-Cron as secondary triggers. Recommend independent system cron invoking `wp etherpad-liveblog run --once` every minute as reliable fall-back.
- The optional Etherpad plugin `ep_liveblog_webhook` is versioned independently and requires its own repository/installation.
- Added webhook signature, invalid payload, clock-expiry and cross-pad authorization smoke tests.

## 0.3.1 — 2026-10-23

- Native Automattic Liveblog REST polling activates background post-specific Etherpad sync, throttled across concurrent readers without modifying Liveblog.
- On PHP-FPM the Liveblog response is delivered before the costly Etherpad synchronization; on non-FPM servers the work is deferred via WP-Cron.
- Added 60-second WP-Cron fallback whenever WordPress receives uncached PHP traffic; no systemd service needed.
- Existing WP-CLI interface remains available for manual verification and recovery. Automatic timing remains traffic dependent.
- Added smoke tests for route specificity, per-post throttling, background scheduling, and paused-status protections.

## 0.3.0 — 2026-10-23

- No Etherpad API credentials required for new pad connections; a publicly readable pad URL on any public HTTPS Etherpad instance is supported.
- Public TXT snapshots identify text changes; native .etherpad exports provide revision IDs and changesets to retain safe published-block identity for updates.
- Existing connected pads using authenticated HTTP API continue to work; new bindings store the pad's canonical instance origin and are unique per origin and pad ID.
- WordPress safe-URL/hostname validation, no redirects, no cookies/credentials, strict response-size limits; failure to access exports stops sync safely.
- GitHub Actions smoke tests added for anonymous exports and cross-instance URLs.

## 0.2.1 — 2026-10-23

- Fixed broken Etherpad pad connection controls in the WordPress post editor: replaced invalid nested HTML forms with authenticated, permission-checked AJAX actions.
- Added immediately visible AJAX validation/errors and refreshed connection status after successful actions.
- Added regression tests for connect, status persistence, nonce enforcement, pause and form markup.
- No changes to the existing Etherpad/Liveblog worker protocol.

## 0.2.0 — 2026-10-23

- First functional MVP: Etherpad read API, persistent liveblog connection, per-block POSTOK publication, NOPUB exclusions, changeset-based boundary verification and independent 5-second edit debounce.
- WordPress post editor connection/pause/resume/disconnect box, WP-CLI worker, safety locks, pending-operation safeguards, manual WordPress edit conflicts.
- OAuth client credentials, bearer token and legacy API-key configurations; HTTPS allowlist.
- Automated parser/matcher/synchronizer mock integration tests and documented operational limitations.
- Existing GitHub Releases WordPress updater retained; automated release packaging.
- Not yet validated on the production Etherpad and Liveblog deployments.

## 0.1.1 — 2026-10-23

- Implemented WordPress Update URI integration and packaging workflow.

## 0.1.0 — 2026-10-23

- Initialized WordPress plugin scaffold and architecture documentation.
