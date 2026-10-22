# Διαχείριση λειτουργικής έκδοσης 0.2.0

**Πριν από παραγωγή:** απαιτείται δοκιμή staging με τις εγκατεστημένες εκδόσεις Etherpad/Liveblog. Ο μηχανισμός δοκιμάστηκε με mock APIs, όχι ακόμη σε πραγματική εγκατάσταση.

## Προϋποθέσεις

1. Ενεργό Automattic Liveblog στο WordPress και δικαιώματα editor/publisher.
2. Μη δημόσια επεξεργάσιμο Etherpad. Κάθε χρήστης με δικαίωμα εγγραφής μπορεί να ενεργοποιήσει POSTOK· **μη συνδέετε δημόσιο pad χωρίς έλεγχο πρόσβασης**.
3. Πρόσβαση εξυπηρετητή WordPress προς `https://pad.omniatv.com/api` και Etherpad API bearer/OAuth credentials ή legacy key μέσω `wp-config.php`.
4. Dedicated OS account για τον WP-CLI worker, με κανονικά δικαιώματα στο WordPress.

Παράδειγμα `wp-config.php`:

```php
define( 'WPELB_ETHERPAD_BASE_URL', 'https://pad.omniatv.com' );
define( 'WPELB_ETHERPAD_CLIENT_ID', 'YOUR_CLIENT_ID' );
define( 'WPELB_ETHERPAD_CLIENT_SECRET', 'YOUR_CLIENT_SECRET' );
```

Εναλλακτικά ορίζεται `WPELB_ETHERPAD_API_KEY` (παλαιότερο Etherpad) ή `WPELB_ETHERPAD_BEARER_TOKEN`. Ποτέ στο GitHub ή μέσα στο pad.

## Σύνδεση

Στο post με ενεργοποιημένο Liveblog εμφανίζεται το meta box **Etherpad → Liveblog**. Εισάγουμε URL `/p/<padID>`, βεβαιώνουμε περιορισμένη εγγραφή και επιλέγουμε **Connect pad**. Τα προϋπάρχοντα POSTOK **δεν αναδημοσιεύονται**. `Pause` σταματά νέο συγχρονισμό, `Resume` επαναφέρει. `Disconnect` διατηρεί όλες τις δημοσιευμένες ενημερώσεις αλλά διαγράφει την αντιστοίχιση και δεν επιτρέπεται όταν εκκρεμεί αβέβαιη εγγραφή.

## Worker

Για χειροκίνητη δοκιμή:

```bash
cd /PATH/TO/WORDPRESS
sudo -u WWW_USER wp etherpad-liveblog run --once --path=/PATH/TO/WORDPRESS
```

Για συνεχή εργασία προτιμήστε εποπτευόμενο loop **νέα PHP διεργασία ανά κύκλο**: `while :; do wp etherpad-liveblog run --once --path=/PATH/TO/WORDPRESS; sleep 2; done`. Η systemd service θα πρέπει να εκτελείται από τον κατάλληλο τοπικό χρήστη, με προστατευμένα logs, `Restart=always` και `RestartSec=5`. Έτσι τα plugin updates θα εφαρμόζονται στην επόμενη εκτέλεση. Υποστηρίζεται επίσης `wp etherpad-liveblog run --interval=2`, αλλά αυτή η μακρόβια διεργασία απαιτεί επανεκκίνηση μετά από αλλαγή έκδοσης.

Δεν εκκινείται worker αυτόματα με activation. Το WP-Cron δεν μπορεί να εγγυηθεί τον απαιτούμενο ρυθμό polling.

## Ασφάλεια/σφάλματα

- **Pending operation:** έγινε ή επιχειρήθηκε μια αβέβαιη εγγραφή. Ο συγχρονισμός παγώνει για αποφυγή διπλής δημοσίευσης. Απαιτείται χειροκίνητη επαλήθευση του αντίστοιχου original Liveblog ID πριν τη συνέχιση — δεν υπάρχει ακόμη UI αυτόματου reconciliation.
- **Conflict:** κάποιος άλλαξε το ίδιο entry απευθείας στο WordPress. Το συγκεκριμένο block παύει να ενημερώνεται αυτόματα, όχι τα υπόλοιπα.
- **Marker ambiguity:** μεταφέρθηκε/διαγράφηκε/τροποποιήθηκε ήδη δημοσιευμένο `POSTOK`· διακόπτεται ο συγχρονισμός της σύνδεσης, χωρίς επαναχαρτογράφηση κατά προσέγγιση.
- **Revision history reset/gap >500:** pause· απαιτείται έλεγχος. Δεν γίνεται αυτομάτως αρχικοποίηση που θα διέγραφε τα mappings.
- **Κλείσιμο/αρχειοθέτηση Liveblog:** παύση νέων εγγραφών.
- **Χειροκίνητο κλείδωμα:** αν πεθάνει η διεργασία την ώρα του συγχρονισμού, μπορεί να παραμείνει `wpelb_lock_<postid>` στο WP options. Πρώτα βεβαιωθείτε ότι δεν εκτελείται worker για το post, κατόπιν διαγράψτε το συγκεκριμένο option. Μην αφαιρείτε κλειδώματα στα τυφλά.

## Όρια MVP

Plain text (παράγραφοι, αλλαγές γραμμής), όχι rich text Etherpad. Meta box σε posts, όχι ακόμη σε custom post types. Οι νέες δημοσιεύσεις εμφανίζονται στον επόμενο κύκλο και οι διορθώσεις όταν παρέλθουν 5 δευτερόλεπτα από την τελευταία παρατηρημένη αλλαγή **στο συγκεκριμένο block**.

Καμία αυτόματη ενέργεια δεν πρέπει να πραγματοποιηθεί στα παραγωγικά pads πριν τη δοκιμή και τη ρητή σύνδεση από εξουσιοδοτημένο editor.
