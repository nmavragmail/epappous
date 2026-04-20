# ePappous Club

WordPress plugin (`wp-content/plugins/epappous-club`): loyalty / membership για το **Παππού Club**, με πόντους, WooCommerce, B2B King (group «Pappou Club»), referral, δώρα Woo κατά κατηγορία, και σελίδα ρυθμίσεων στο admin.

## Απαιτήσεις

- WordPress 5.8+
- PHP 7.4+
- **WooCommerce** (πόντοι από παραγγελίες, checkout εξαργύρωση, δώρα-κατηγορία)
- **B2B King** με το group που ορίζεται στη ρύθμιση «B2B King — Group ID Pappou Club» (χρησιμοποιείται για referral rewards και έλεγχο πρόσβασης σε flows πόντων όπου ο κώδικας το απαιτεί)

## Τι κάνει το plugin (σύνοψη)

- **Μέλη** πίνακας `wp_epc_members` (πόντοι, referral code, DOB για γενέθλια, σύνδεση με WP user όταν υπάρχει).
- **Πόντοι**: κέρδη από παραγγελίες (στάδια/status όπως ρυθμίζονται), εξαργύρωση στο checkout (αρνητικό fee), δώρα αποκλειστικά με πόντους για προϊόντα σε **ρυθμιζόμενη κατηγορία WooCommerce** (όχι ξεχωριστός κατάλογος δώρων στη βάση).
- **Referral**: `?ref=` + cookie, γραμμές σε `wp_epc_referral_clicks`. Η **ανταμοιβή σε πόντους** εκτελείται όταν έχουν ολοκληρωθεί **και** η εγγραφή του νέου μέλους **και** επιλέξιμη αγορά, **και** τα δύο αντίστοιχα toggles είναι ενεργά στο admin, **και** οι δύο εμπλεκόμενοι είναι στο B2B group του club (δες κώδικα `EPC_Referral::grant_rewards_if_complete`). Ελάχιστο παραγγελίας για να «μετρήσει» η αγορά: ρύθμιση «Ελάχιστο Ποσό Παραγγελίας».
- **Εκκαθάριση referral clicks**: ημερήσιο cron `epc_referral_clicks_cleanup_daily` — διαγραφή παλιών γραμμών με βάση τις ημέρες διατήρησης στις ρυθμίσεις (μη ανταμειφθέντα / ολοκληρωμένα).
- **Εγγραφή στο club** από φόρμες/προφίλ και από checkout (classic + blocks) με ενιαίο έλεγχο ημερομηνίας γέννησης (`EPC_DOB_Validator`).
- **Βαθμίδες (tiers)**: αποθηκεύονται στο μέλος και στις defaults, αλλά το module tiers είναι **απενεργοποιημένο** στο `epappous-club.php` (χωρίς UI/emails tiers).

## Εγκατάσταση

1. Αντιγράψτε τον φάκελο `epappous-club` κάτω από `wp-content/plugins/`.
2. Ενεργοποιήστε το plugin από το WordPress admin.
3. **Pappou Club → Ρυθμίσεις** για ρύθμιση (και WooCommerce tab για δώρα/B2B).

## Tests

Από τον φάκελο του plugin:

```bash
phpunit -c phpunit.xml.dist
```

Χρειάζεστε PHPUnit στο PATH. Χωρίς πλήρες WordPress δίπλα, τα tests χρησιμοποιούν ελαφρά stubs στο `tests/bootstrap.php` για βασικούς ελέγχους.

## Έκδοση

Η τρέχουσα έκδοση ορίζεται στο `epappous-club.php` (`EPC_VERSION`).
