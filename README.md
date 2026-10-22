# WP Etherpad to Liveblog

A WordPress integration that will turn reporting written collaboratively in an [Etherpad](https://etherpad.org/) pad into entries in [Automattic Liveblog](https://github.com/Automattic/liveblog).

**Status: development scaffold (v0.1.1). Nothing is published or synchronized yet. Do not deploy this as a working publishing integration.**

## Goals

- One Etherpad pad connects to one WordPress Liveblog (a liveblog can have at most one linked pad).
- Reporters stay inside Etherpad: type updates and add a standalone `POSTOK` line to publish.
- `NOPUB` / `ENDNOPUB` delimit private or editorial notes that must never be published.
- Later edits to an already published block update its **original logical Liveblog entry** after a block-local quiet period (initial target: 5 seconds). Typing elsewhere in the pad does not delay those updates.
- Idempotent, recoverable synchronization; conservative behavior on ambiguous delimiters, failed writes and permissions.
- WordPress pulls Etherpad revisions using an independent worker; no Etherpad plugin is required for the initial version.

## Planned workflow

```text
Etherpad pad --read versioned snapshot--> WP sync worker
       |                                  |
       | POSTOK / NOPUB / ENDNOPUB         v
       +--------------------------------> parser + stable block mapping
                                              |
                                         publish or update
                                              |
                                  Automattic Liveblog entry API
```

**Important:** The bridge will use the official Liveblog entry class rather than inserting arbitrary posts or comments. An update in Liveblog creates an internal replacement event while retaining the original entry's reader-facing identity.

## Repository layout

- `wp-etherpad-to-liveblog.php` – WordPress plugin bootstrap.
- `includes/` – reserved integration modules and admin status page.
- `docs/ARCHITECTURE.md` – architecture and operational design.
- `docs/PAD-PROTOCOL.md` – control strings and editorial rules.
- `docs/DEVELOPMENT-PLAN.el.md` – Greek implementation roadmap and acceptance criteria.
- `docs/TEST-PLAN.md` – test scenarios and release gates.
- `docs/USER-GUIDE.el.md` – intended reporter/editor workflow.
- `docs/RELEASING.md` – published GitHub Releases and WordPress updater instructions.
- `.github/workflows/` – continuous PHP checks and tagged release package creation.

## Requirements (target, subject to installation audit)

- WordPress 6.4+ and PHP 8.0+.
- Automattic Liveblog, with the installed version/API checked before implementation.
- Etherpad with its HTTP API accessible **server-to-server** over HTTPS.
- A dedicated WordPress service user with the required Liveblog permissions.
- An independent WP-CLI worker (or equivalent scheduler) for short-interval polling; WP-Cron alone is not sufficient for a reliable 2-second cadence.

## Safety decisions

- No retrospective publication on first connection unless an editor explicitly requests it.
- No secret or token embedded in a pad, browser-side JavaScript, Git history, public logs or URLs.
- A pad's address is not publication authorization. Only WordPress editors authorized for the liveblog can configure the mapping.
- Never publish an incomplete/ambiguous `NOPUB` section.
- Never silently overwrite an entry that was subsequently edited manually in WordPress.
- A changed or removed `POSTOK` boundary must not silently remap or delete a live update.

## GitHub Releases and WordPress updates

This plugin uses WordPress's native third-party `Update URI` mechanism (WordPress 5.8+). Beginning with version **0.1.1**, plugin update checks read the latest **published, non-prerelease** GitHub Release using the public GitHub REST API. WordPress will offer an update only when the release has a higher semantic version **and** the correctly packaged `wp-etherpad-to-liveblog.zip` asset is present. GitHub's auto-generated source archives are not used.

On the **Plugins** screen in WordPress you can update manually or enable **automatic updates** for this plugin. The plugin does not force automatic updating. Release metadata is cached briefly and WordPress applies its normal update-check cadence.

A GitHub Actions workflow builds and uploads the ZIP on publishing each stable `vX.Y.Z` release; the ZIP contains the directory `wp-etherpad-to-liveblog/`, preserving the installed plugin path. Read [release and updater instructions](docs/RELEASING.md). For an already installed older version without this updater, install v0.1.1 once manually before later GitHub updates can be detected.

**Publishing is still not implemented** in the scaffold; this addition only handles plugin software updates.

## Current development phase

The plugin currently registers a **Tools → Etherpad to Liveblog** status page and GitHub release update integration and checks whether the Liveblog entry API is present. It does **not** connect to Etherpad, add controls to a liveblog, register polling jobs or publish content.

Read the [development plan](docs/DEVELOPMENT-PLAN.el.md) before implementing the next phase.

## Upstream references

- [Etherpad HTTP API](https://docs.etherpad.org/api/http_api.html)
- [Etherpad server hooks](https://docs.etherpad.org/api/hooks_server-side.html) (possible later event-driven alternative)
- [Automattic Liveblog](https://github.com/Automattic/liveblog)
- [Liveblog entry implementation](https://github.com/Automattic/liveblog/blob/develop/classes/class-wpcom-liveblog-entry.php)

## License

GPL-2.0-or-later.