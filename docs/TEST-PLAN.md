# Test strategy and release gates

## Automated unit tests (to be implemented)

| ID | Scenario | Acceptance |
|---|---|---|
| P01 | One POSTOK and trailing draft | Exactly one block published; trailing draft ignored |
| P02 | Multiple POSTOK markers | One entry per nonempty completed publishable block |
| P03 | NOPUB region inside block | Entire region omitted, no private note leakage |
| P04 | POSTOK inside NOPUB | Not treated as publication |
| P05 | Unclosed/nested/malformed NOPUB | Fail closed; warning and no leak |
| P06 | Inline “POSTOK”, partial POSTO | No publication |
| P07 | Existing markers at first link | No retrospective publication |
| P08 | HTML/link/formatting with matching text revision | Correct sanitized formatting, no control lines |
| I01 | Edit a published block | Updates same original logical entry ID |
| I02 | Keep typing next block after editing previous | Previous block syncs after only its own debounce |
| I03 | Two published blocks edited concurrently | Independent debounce; correct mapped entries |
| I04 | Add/remove text before existing block | No positional identity corruption |
| I05 | Split/merge/reorder/delete POSTOK | Ambiguous cases pause; never update wrong entry |
| I06 | Same content in two distinct blocks | Separate UUIDs; no accidental cross-update |
| I07 | Manual WordPress edit conflicts with pad edit | Conflict detected; no silent overwrite |
| I08 | Deletion of source text | No automatic Liveblog deletion |
| R01 | Worker crashes after insert but before saving mapping | Reconcile; avoid duplicate visible entry |
| R02 | Two concurrent worker instances | Single-flight lease prevents duplicate dispatch |
| R03 | Etherpad timeout / 5xx / API code error | Backoff, recover, do not lose mapping |
| R04 | Liveblog write failure / timeout | Durable retry/reconciliation |
| S01 | Unauthorized mapping edit | 403/no mutation |
| S02 | Disallowed URL, redirect, SSRF/internal address | Rejected before HTTP fetch |
| S03 | Malicious HTML/script content | Sanitized by WordPress/Liveblog |
| S04 | Secret accidentally in error or NOPUB | Not logged or published |

## Integration testing

Use ephemeral WordPress + supported PHP, matching deployed Automattic Liveblog and Etherpad versions. Test real `getText/getHTML` with explicit revision and real `WPCOM_Liveblog_Entry::insert()/update()` behavior. Assert original public entry identity, replacement-event notifications, preservation of original timestamps, rendered HTML and cache visibility.

Do not infer success solely from a successful API status; inspect WordPress comment storage and the frontend Liveblog stream.

## Performance and operational testing

- Active pads with several collaborating reporters and long transcripts.
- Revision polling without unnecessary content fetch on unchanged pads.
- Sustained new typing while earlier blocks are corrected.
- Worker memory growth, request rate, CPU consumption and DB contention.
- Network interruptions, workers restarted, stale leases, liveblog archived mid-write.
- Test pause/resume, reconnect, credential rotation and staging→production migration.

## Release gates

1. PHP lint/CI passes; PHP/WordPress compatibility confirmed.
2. Automated parser, matcher, worker and adapter tests pass.
3. Security review complete, fail-closed error paths verified.
4. At least one supervised staging rehearsal with simultaneous correspondents.
5. Production enablement requires human editor approval and a documented rollback/pause procedure.

v0.1.0 only has a PHP syntax workflow. **None of the integration tests above have been run yet.**
