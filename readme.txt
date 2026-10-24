=== WP Etherpad to Liveblog ===
Contributors: lstamellos
Tags: etherpad, liveblog, journalism, wordpress
Requires at least: 6.4
Requires PHP: 8.0
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish live reporting from Etherpad into Automattic Liveblog.

== Description ==

Functional MVP with multilingual POSTOK publishing, NOPOST exclusions, per-Liveblog author search/selection and per-block edit sync. Legacy NOPUB exclusions remain supported. Native Liveblog reader polling triggers synchronizations with a minute-level WP-Cron fallback; no systemd service is required. Rich text formatting is not transferred.

== Installation ==

1. Install Automattic Liveblog and enable it on a post.
2. Install this plugin. No Etherpad API credentials are required for publicly readable pads.
3. In the Liveblog post editor, connect a public HTTPS Etherpad pad from any instance in the Etherpad → Liveblog box and choose an eligible WordPress author. Author can later be changed for future updates.
4. Publishing is driven automatically by Liveblog polling when readers have the page open, with WP-Cron fallback. For debugging, run wp etherpad-liveblog run --once.
5. Test on staging before production. See README.md and docs/OPERATIONS.el.md.

== Updates ==

The plugin checks published stable GitHub Releases using the native WordPress plugin-update mechanism. Administrators may enable automatic updates from the WordPress Plugins page.

== Changelog ==

= 0.5.0 =
* POSTOK and NOPOST control strings support upper/lower-case Greek, Latin and mixed keyboards, on complete lines.
* NOPOST closes the preceding section without publishing; old NOPUB/ENDNOPUB remain compatible.
* Search/select WordPress author per connected Liveblog; keep authorized publisher separate from visible author and preserve old-entry attribution.
* Regression tests for Unicode changeset anchors, NOPOST redaction, search and author permissions.

= 0.4.0 =
* Signed Etherpad padUpdate webhook POST receiver at /wp-json/wpelb/v1/notify.
* HMAC-SHA256 verification with timestamp and event ID, strict payload validation and existing-pad-only authorization.
* Webhook requests safely trigger the existing single-flight synchronizer, without requiring a separate service.
* Independent system cron (one-minute WP-CLI pass) recommended as reliable fallback; native Liveblog polling and WP-Cron remain supplementary.

= 0.3.1 =
* Service-free automatic sync: use the native Liveblog REST polling as a throttled trigger, without modifying Liveblog.
* WP-Cron fallback for periods without Liveblog readers when WordPress traffic reaches PHP.
* Keep WP-CLI worker as manual fallback; protect concurrent visitors with atomic per-post throttling and existing publication locks.

= 0.3.0 =
* Credential-free public Etherpad connection from any publicly accessible HTTPS instance.
* Public TXT and native Etherpad revision exports support POSTOK and safe correction tracking.
* Authenticated API mode remains available for existing linked pads; security checks and size bounds on remote hosts.
* Test coverage for external origins, public exports and Cloudflare-style denial.

= 0.2.1 =
* Fix: pad connection controls use WordPress AJAX, avoiding nested HTML forms inside the post editor.
* Show server-validated success or error messages without submitting the post editor.
* Automated admin connection regression tests.

= 0.2.0 =
* First functional Etherpad-to-Liveblog MVP with authorized pad connection.
* POSTOK and NOPUB/ENDNOPUB parsing, revision-aware marker tracking.
* Publish and edit original Liveblog entries with per-block debounce.
* WP-CLI worker, manual conflict checks, pause/resume and read-only status.
* WordPress native GitHub release updater.
