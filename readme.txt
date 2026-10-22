=== WP Etherpad to Liveblog ===
Contributors: lstamellos
Tags: etherpad, liveblog, journalism, wordpress
Requires at least: 6.4
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An upcoming server-side bridge to publish collaborative Etherpad reporting into Automattic Liveblog.

== Description ==

DEVELOPMENT SCAFFOLD ONLY. No synchronization or publishing functionality is enabled in version 0.1.0.

Planned features: one pad per liveblog, POSTOK publication delimiter, NOPUB private sections, and block-local debounce for editing already published entries.

The reporter works in Etherpad; the WordPress bridge will use the documented Etherpad HTTP API and Automattic Liveblog entry methods.

== Installation ==

1. Install and activate Automattic Liveblog.
2. Install this plugin into wp-content/plugins/.
3. Activate and inspect Tools > Etherpad to Liveblog.
4. Do not expect automatic synchronization in 0.1.0.

== Changelog ==

= 0.1.0 =
* Initial repository and non-publishing plugin scaffold.
* Architecture, protocol, development plan and test plan.
