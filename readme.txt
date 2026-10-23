=== WP Etherpad to Liveblog ===
Contributors: lstamellos
Tags: etherpad, liveblog, journalism, wordpress
Requires at least: 6.4
Requires PHP: 8.0
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish live reporting from Etherpad into Automattic Liveblog.

== Description ==

Functional MVP for connecting one Etherpad pad to one Automattic Liveblog, with POSTOK publication, NOPUB note exclusion and per-block 5-second edit debounce. Rich text formatting is not transferred in this version. An external WP-CLI worker must be configured.

== Installation ==

1. Install Automattic Liveblog and enable it on a post.
2. Install this plugin. No Etherpad API credentials are required for publicly readable pads.
3. In the Liveblog post editor, connect a public HTTPS Etherpad pad from any instance in the Etherpad → Liveblog box.
4. Run wp etherpad-liveblog run --once periodically or as a managed service.
5. Test on staging before production. See README.md and docs/OPERATIONS.el.md.

== Updates ==

The plugin checks published stable GitHub Releases using the native WordPress plugin-update mechanism. Administrators may enable automatic updates from the WordPress Plugins page.

== Changelog ==

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
