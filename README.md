# ePappous Club

WordPress plugin για loyalty/membership πρόγραμμα με σύστημα referral, δώρα, και πλήρη σελίδα ρυθμίσεων.

## Χαρακτηριστικά

- **Settings Page** — Πλήρης σελίδα ρυθμίσεων με tabs (Γενικά, Πόντοι, Βαθμίδες, Referral, Δώρα, Ειδοποιήσεις, WooCommerce)
- **Referral System** — Σύστημα παραπομπών με μοναδικούς κωδικούς, cookie tracking, και ανταμοιβές
- **Gift Products** — Διαχείριση προϊόντων-δώρων που εξαργυρώνονται με πόντους
- **Tier System** — Πολλαπλές βαθμίδες μελών (Basic, Silver, Gold, Platinum)
- **Points System** — Πλήρες σύστημα πόντων με κέρδη, εξαργύρωση, και λήξη
- **WooCommerce Integration** — Σύνδεση με WooCommerce για αυτόματη απόδοση πόντων

## Εγκατάσταση

1. Αντιγράψτε τον φάκελο `wp-content/plugins/epappous-club/` στο WordPress installation σας
2. Ενεργοποιήστε το plugin από το WordPress admin
3. Πλοηγηθείτε στο **Pappou Club → Ρυθμίσεις** για διαμόρφωση

## Πώς λειτουργεί το Referral

1. Κάθε μέλος λαμβάνει μοναδικό κωδικό (π.χ. `PAPPOU-A3X9`)
2. Μοιράζεται link: `example.com/?ref=PAPPOU-A3X9`
3. Το cookie αποθηκεύεται στον browser του φίλου
4. Καταγραφή γίνεται σε δύο περιπτώσεις:
   - **Εγγραφή μέλους** — ο φίλος γίνεται μέλος του club
   - **Αγορά** — ο φίλος ολοκληρώνει παραγγελία
5. Ανταμοιβή (πόντοι/έκπτωση) δίνεται και στους δύο

## Απαιτήσεις

- WordPress 5.8+
- PHP 7.4+
- WooCommerce (προαιρετικό, για πλήρη λειτουργικότητα)
