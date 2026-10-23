# Changelog

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
