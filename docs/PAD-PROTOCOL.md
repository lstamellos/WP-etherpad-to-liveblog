# Pad control-string protocol — v2

## Publication decisions

Each command must occupy its **own line**. Leading/trailing ASCII spaces
and tabs are allowed. Words embedded in normal sentences are never controls.

The two commands are **case-insensitive** and **Greek/Latin keyboard agnostic**.
Mixed Greek and Latin letters also work, including (not exhaustive):

| Meaning | Accepted examples |
|---|---|
| Publish preceding complete section | `POSTOK`, `postok`, `ΠΟΣΤΟΚ`, `ποστοκ`, `ΠoΣtOκ` |
| Discard preceding section entirely | `NOPOST`, `nopost`, `ΝΟΠΟΣΤ`, `νοποστ`, `νOpoΣt` |

### Example

```text
Το δικαστήριο διέκοψε στις 12:14.
postok

Εσωτερική σημείωση για διασταύρωση ονόματος.
νοποστ

Η συνεδρίαση επαναλήφθηκε.
ΠΟΣΤΟΚ

Νεότερες σημειώσεις υπό επεξεργασία...
```

Expected result: exactly two Liveblog entries. Text preceding `νοποστ`
is excluded from publication and is not retained as a public block.
The unfinished tail is not published.

## Migration from v1

Existing `NOPUB` and `ENDNOPUB` regions continue to work **unchanged** for
pads that already contain them. In v2, `NOPOST` is the preferred way to end
an entire unpublished reporting section. This is **not** a replacement with
identical semantics: `NOPUB`/ `ENDNOPUB` exclude an *embedded* region,
whereas `NOPOST` closes and discards the entire preceding section.

Do not rewrite previously committed control lines from `POSTOK` to Greek
(or vice versa) or from `POSTOK` to `NOPOST` after connecting a pad:
the synchronizer tracks six-character UTF-16 marker anchors across
Etherpad changesets and halts on ambiguous structural edits. Retaining legacy
spellings is necessary for safe migration.

## Safety and attribution

1. `POSTOK` marks the end of the immediately preceding public section.
2. `NOPOST` marks a **nonpublishable** section and consumes it without emitting
   an entry. It is still tracked as a six-unit changeset anchor.
3. `POSTOK` and `NOPOST` encountered inside a legacy `NOPUB` span are ignored.
   An unterminated private span withholds the tail.
4. Content after the last decision is always a draft.
5. Any existing completed markers are baselined when a pad is first linked,
   preventing retrospective publication.
6. Edits to a published section update the same Liveblog entry after a 5-second
   quiet period. Manual WordPress edits always take precedence and result in a
   block-level conflict rather than being overwritten.
7. Each connected Liveblog stores a selected **WordPress author** for new entries,
   chosen via a search field in the WordPress post editor. Only WordPress users
   with the `edit_posts` capability may be assigned, per Liveblog's author
   validation rules.
8. The connection's publisher (the authorized editor who connected the pad)
   is stored separately. Changing the selected author **never grants publishing
   permission** and does **not** retroactively change existing entries.
9. Moving/removing old boundaries, uncertain writes or missing changeset history
   requires manual reconciliation. Never infer the identity of a published
   entry from its position alone.
10. All public text is plain-text escaped in the initial implementation.
