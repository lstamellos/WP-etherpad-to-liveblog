# Σχέδιο ανάπτυξης — WP Etherpad to Liveblog

**Κατάσταση:** προδιαγραφή και αρχικός σκελετός, όχι λειτουργικό σύστημα δημοσίευσης (0.1.0).

## Δεσμευτικές απαιτήσεις

- Ένα pad ↔ ένα Liveblog. Σύνδεση από το WordPress, χωρίς ρυθμίσεις του ανταποκριτή.
- **Pull** από WordPress προς Etherpad HTTP API, κατά προτίμηση με αυτόνομο WP-CLI worker.
- `POSTOK`: άμεση δημοσίευση ολοκληρωμένου νέου τμήματος.
- `NOPUB` / `ENDNOPUB`: απόκρυψη μη δημοσιεύσιμων σημειώσεων.
- Διορθώσεις στο pad σε ήδη δημοσιευμένο τμήμα → ενημέρωση του ίδιου **λογικού** Liveblog entry.
- **Debounce ανά δημοσιευμένο block: 5 δευτερόλεπτα από την τελευταία παρατηρημένη αλλαγή του συγκεκριμένου block**, όχι από την τελευταία πληκτρολόγηση οπουδήποτε στο pad.
- Αποφυγή διπλών αναρτήσεων, ασάφειας αντιστοίχισης και επιγραφής χειροκίνητων διορθώσεων στο WordPress.

## Φάση 0 — Έλεγχος πραγματικών εγκαταστάσεων

1. Καταγραφή εκδόσεων Etherpad, PHP, WordPress, Automattic Liveblog και διαμόρφωσης reverse proxy.
2. Επιβεβαίωση λειτουργικού API, negotiation έκδοσης, αυθεντικοποίησης (OAuth bearer στη νεότερη τεκμηρίωση / πιθανό API key σε παλαιότερες εγκαταστάσεις) και πρόσβασης μόνο από τον WordPress server. Έλεγχος **δικαιωμάτων εγγραφής των χρηστών του pad**: αν οποιοσδήποτε με URL μπορεί να γράψει POSTOK, δεν επιτρέπεται αυτόματη δημοσίευση χωρίς πρόσθετη έγκριση.
3. Έλεγχος `WPCOM_Liveblog_Entry::insert/update`, author handling, hooks, cache, Liveblog REST behavior και υφιστάμενων τοπικών προσαρμογών.
4. Επιλογή δικαιωμάτων dedicated WP publishing user και επιβεβαίωση υποδομής για υπηρεσία worker.
**Κριτήριο ολοκλήρωσης:** επαληθευμένο compatibility matrix και δοκιμαστική ανάγνωση συγκεκριμένου pad revision χωρίς καταγραφή μυστικών.

## Φάση 1 — Settings, σύνδεση και δεδομένα

- Ρυθμίσεις εξυπηρετητή Etherpad και προστατευμένων credentials, allowlist HTTPS host.
- Meta box «Σύνδεση Etherpad» στο WordPress Liveblog (URL pad, test, connect, pause, resume, disconnect).
- Έλεγχος capability, nonce, ενεργού liveblog, μοναδικότητα αντιστοίχισης.
- Σχεδιασμός/υλοποίηση migrations και εγγραφών state/mapping/operation log.
- Initial baseline: αν το pad περιέχει ήδη POSTOK, **δεν γίνεται αναδρομική δημοσίευση**.
**Κριτήριο:** δύο liveblogs δεν μπορούν να συνδεθούν στο ίδιο pad και αντίστροφα· καμία ενεργοποίηση από μη εξουσιοδοτημένο χρήστη.

## Φάση 2 — Parser, HTML και ταυτότητα blocks

- Strict line parser για POSTOK/NOPUB/ENDNOPUB, Unicode, πολλαπλές παραγράφους, UTF-8, blank lines.
- Ανάγνωση text και HTML στο ίδιο revision· αντιστοίχιση markers προς formatted fragments και WordPress HTML sanitization.
- Δημιουργία εσωτερικών σταθερών UUID για blocks· tracking anchors με revision changesets, βοηθητικό content matching.
- Fail-closed χειρισμός μετακινήσεων/διαγραφών delimiters και ασαφούς αντιστοίχισης.
**Κριτήριο:** unit tests επιβεβαιώνουν ότι NOPUB δεν διαρρέει ποτέ και ότι edits/insertions πριν από blocks δεν ενημερώνουν λάθος entry.

## Φάση 3 — Νέες δημοσιεύσεις και worker

- Dedicated WP-CLI service, ρυθμιζόμενο polling (στόχος 2″), μόνο για ενεργά linked pads.
- `getRevisionsCount` πριν από πλήρες snapshot· canonical hashes, ασφαλή locks, timeout/retry, outbox.
- Δημιουργία εγγραφών μόνο μέσω `WPCOM_Liveblog_Entry::insert()`.
- Καταγραφή αρχικού entry ID και post binding, reconciliation μετά από crash.
**Κριτήριο:** το POSTOK δημιουργεί μία και μόνο σωστή ορατή ανταπόκριση, ακόμη μετά από αποσύνδεση ή restart στις δοκιμασμένες περιπτώσεις.

## Φάση 4 — Συγχρονισμός διορθώσεων (ανά block)

- Επανασύγκριση των ήδη δημοσιευμένων blocks στις νέες revisions.
- Ανεξάρτητα last_observed_hash / last_observed_change_at / last_published_hash ανά block.
- Ανανέωση του timer **μόνο** όταν αλλάζει το συγκεκριμένο block.
- Μετά ≥5″ χωρίς νέα αλλαγή του block, `WPCOM_Liveblog_Entry::update()` με το **αρχικό** entry ID.
- Σύγκρουση με χειροκίνητη αλλαγή του Liveblog → παύση μόνο του επηρεαζόμενου block και ειδοποίηση editor.
**Κριτήριο:** συνεχής συγγραφή στο επόμενο block δεν εμποδίζει τη διόρθωση του προηγούμενου· η αρχική ώρα και το δημόσιο entry ID μένουν σταθερά.

## Φάση 5 — Ασφάλεια, παρατηρησιμότητα και ανθεκτικότητα

- Dashboard τελευταίου sync, lag, σφαλμάτων, pending edits και conflict resolution.
- Καλό error logging χωρίς μυστικά/ιδιωτικές σημειώσεις· retention/cleanup πολιτική.
- Tests για XSS, SSRF, capability bypass, retry storms, duplicate workers, marker changes, manual editor conflicts.
- Συμβατότητα με Cloudflare/cache και frontend Liveblog refresh, χωρίς επέμβαση στον Liveblog πυρήνα.
**Κριτήριο:** ολοκληρωμένα integration και failure/recovery tests, monitoring και ασφαλές pause.

## Φάση 6 — Staging, πιλοτική λειτουργία, παραγωγή

1. End-to-end staging με δοκιμαστικό pad και liveblog, ταυτόχρονη συγγραφή δύο+ χρηστών.
2. Δοκιμή POSTOK, NOPUB, edit debounce, crash/retry, μεγάλου pad και μεταβολών delimiters.
3. Controlled pilot, δυνατότητα instant pause/rollback (χωρίς διαγραφή ήδη δημοσιευμένων).
4. Ενεργοποίηση στην παραγωγή μόνο μετά από επιβεβαίωση από editor.
**Κριτήριο:** επιτυχής πιλοτική χρήση και ρητή έγκριση μετά τα tests.

## Εκτός MVP

- Υποχρεωτική εγκατάσταση Etherpad plugin / push hooks.
- Αυτόματο delete/unpublish δημοσιευμένου entry με διαγραφή του στο pad.
- Πολλαπλά liveblogs ανά pad ή πολλαπλά pads ανά liveblog.
- Αυτόματη επίλυση ambiguous split/merge/reordering.
- Νέα editorial commands (KEYPOSTOK κ.λπ.) πριν σταθεροποιηθεί το βασικό πρωτόκολλο.

## Αναγκαίες τεχνικές επισημάνσεις

Το να παρακολουθούμε απλώς τη σειρά των POSTOK **δεν αρκεί** για σταθερή ταυτότητα. Χρειάζεται anchor tracking/ambiguity detection. Επίσης, το Liveblog `update()` δημιουργεί εσωτερικό replacement comment: πρέπει να αποθηκεύουμε το **original entry ID** για τις επόμενες διορθώσεις.

Το WP-Cron δεν επιτυγχάνει αξιόπιστα polling 2″: απαιτείται πραγματική υπηρεσία worker. Ο ακριβής ρυθμός θα οριστικοποιηθεί μετά από μέτρηση φορτίου και latency.

**Βασική αρχή:** καμία πρόσθετη διαδικασία για τον ανταποκριτή πέρα από σύνταξη και POSTOK· τα NOPUB χρησιμοποιούνται μόνο όταν χρειάζονται.
