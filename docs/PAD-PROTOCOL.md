# Pad control-string protocol (draft v1)

This is the **intended behavior**. Parsing/publishing are not available in v0.1.0.

## Markers

Markers are case-sensitive and recognized **only when they occupy their own line** (leading/trailing whitespace may be ignored):

| Marker | Meaning |
|---|---|
| `POSTOK` | Closes the current publishable section and authorizes creation of one Liveblog entry. |
| `NOPUB` | Starts an editorial/private region that is excluded from publication. |
| `ENDNOPUB` | Ends that region; resumes publishable text. |

Words such as “POSTOK” inside a sentence, or a partially typed `POSTO`, are ordinary text and never trigger an event. Escape/literal-marker syntax may be added after tests if necessary.

## Example

```text
Το δικαστήριο διέκοψε στις 12:14.
POSTOK

Η συνεδρίαση συνεχίζεται.

NOPUB
Να επιβεβαιωθεί το επώνυμο του μάρτυρα.
ENDNOPUB

Η εισαγγελέας υπέβαλε νέα ερώτηση.
POSTOK

Νεότερες σημειώσεις ακόμη σε επεξεργασία...
```

Expected: two Liveblog entries. The private note never appears in either. Trailing text after the final `POSTOK` remains unpublished.

## Safety rules

1. Default scope: all publishable text since the previous **effective** POSTOK; NOPUB spans are removed, not independently published.
2. A `POSTOK` encountered **inside NOPUB** is ignored. Nested `NOPUB`, unmatched `ENDNOPUB`, or ambiguous state produces a validation warning; publication of affected material is withheld.
3. An unclosed `NOPUB` must not leak even if later text includes POSTOK. Block and flag any affected publish until closing/repair is unambiguous.
4. An empty publishable section does not create an entry. Consecutive POSTOK markers are a no-op plus a diagnostic, not two updates.
5. All text after the most recent valid POSTOK is working copy and cannot appear in Liveblog until another POSTOK.
6. Historical POSTOK markers found at initial link time are baseline-only, not automatically published.
7. Later edits to a published block update the **same logical Liveblog entry** after a block-local 5-second quiet period. Work on other blocks must not delay that update.
8. Published blocks are not deleted merely because their text/marker is removed in Etherpad.
9. Moving/removing markers, splitting/merging old blocks, reordering entries or missing anchor identity may require editor intervention; never guess which existing entry to replace.
10. Preserve editorially meaningful HTML when safely mappable. No collaborators' highlight colors or control strings in output.

## Recommended editorial practice

Reporters write freely and type `POSTOK` alone on a line when a report is ready. `NOPUB` and `ENDNOPUB` are only necessary for private editorial notes inside the pad. They do **not** need to stop typing while edits to an older block are synchronized.

## Future extensions — out of initial scope

- `KEYPOSTOK` (key event), manual review/approval or named reporter metadata.
- Selective publication destinations and write-back status indicators.
- Explicit block IDs if operationally necessary for safe split/merge handling.

Extensions must preserve backward compatibility with the simple three-marker workflow.
