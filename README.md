# ePappous — Pappou Club (WordPress)

Αποθετήριο για το custom plugin **ePappous Club** (`wp-content/plugins/epappous-club`): σύστημα loyalty / μέλους για WooCommerce, με **πόντους**, **εξαργύρωση στο checkout**, **προϊόντα-δώρα** (κατηγορία WooCommerce), **referral** με καταγραφή clicks και ανταμοιβές, **ενσωμάτωση B2B King**, **κασσετίνα-δώρο** (admin + email από παραγγελία), και **σελίδες διαχείρισης** στο WordPress admin.

**Έκδοση plugin:** δείτε `EPC_VERSION` στο [`wp-content/plugins/epappous-club/epappous-club.php`](wp-content/plugins/epappous-club/epappous-club.php).

---

## Απαιτήσεις

| Απαίτηση | Σημείωση |
|----------|-----------|
| **WordPress** | 5.8+ |
| **PHP** | 7.4+ |
| **WooCommerce** | Πόντοι από παραγγελίες, HPOS συμβατό (`custom_order_tables` declared) |
| **B2B King** | Για έλεγχο ομάδας «Pappou Club» και σχετικές ροές (referral rewards, δώρα με πόντους όπου ο κώδικας το απαιτεί) |
| **WPML (προαιρετικό)** | Γλωσσικά referral links (αρχική ανά γλώσσα) μέσω `wpml_permalink` όπου υλοποιείται |

---

## Εγκατάσταση

1. Αντιγράψτε τον φάκελο `epappous-club` κάτω από `wp-content/plugins/`.
2. Από το **Plugins → Ενεργοποίηση** ενεργοποιήστε το **ePappous Club**.
3. Στο πρώτο activation δημιουργούνται/αναβαθμίζονται πίνακες στη βάση (βλ. παρακάτω).
4. Ανοίξτε **Pappou Club → Ρυθμίσεις** και συμπληρώστε WooCommerce tab, B2B group ID, referral, ειδοποιήσεις.

---

## Δομή αποθετηρίου

```
wp-content/plugins/epappous-club/
├── epappous-club.php          # Bootstrap, hooks activation, έκδοση
├── includes/                  # PHP κλάσεις (WooCommerce, Referral, Settings, κ.λπ.)
├── templates/                 # Admin templates (ρυθμίσεις, μέλη, referrals, points log)
├── admin/css/                 # Admin styles
├── admin/js/                  # Admin scripts (AJAX κασσετίνας, σημειώσεις χρήστη, κ.λπ.)
├── tests/                     # PHPUnit (ελαφρά stubs στο bootstrap)
├── wpml-config.xml            # WPML admin strings (όπου εφαρμόζεται)
└── languages/                 # Μεταφράσεις (.pot / .json αν υπάρχουν)
```

Ρίζα αποθετηρίου:

- **`README.md`** — αυτό το αρχείο (τεχνική τεκμηρίωση).
- **`docs/Pappou-Club-Admin-Manual-el.docx`** — εγχειρίδιο για διαχειριστή (ελληνικά, με θέσεις για screenshots).

---

## Βάση δεδομένων (κύριοι πίνακες)

Πρόθεμα πινάκων: `{$wpdb->prefix}` (π.χ. `wp_`).

| Πίνακας | Ρόλος |
|---------|--------|
| `epc_members` | Μέλη club: πόντοι, email, referral code, `user_id`, DOB, κ.λπ. |
| `epc_referrals` | Ιστορικό/γραμμές referral (membership / purchase) |
| `epc_referral_clicks` | Ένα row ανά cookie token από `?ref=CODE`: clicks, conversion, αγορά, rewarded_at |
| `epc_points_log` | Όλες οι κινήσεις πόντων (λόγος, reference παραγγελίας, κ.λπ.) |
| `epc_member_notes` | Σημειώσεις διαχειριστή ανά WordPress user (ξεχωριστά από «μέλος» club) |

Έκδοση σχήματος: `EPC_Database::DB_VERSION` στο [`includes/class-epc-database.php`](wp-content/plugins/epappous-club/includes/class-epc-database.php).

**Σημείωση:** Παλιοί πίνακες δώρων (`epc_gift_products` / rules) δεν χρησιμοποιούνται πλέον· τα δώρα είναι κανονικά προϊόντα Woo σε **ρυθμιζόμενη κατηγορία**.

---

## Διαχείριση στο WordPress (admin menu)

Μετά την ενεργοποίηση εμφανίζεται το μενού **Pappou Club** (ικονίδιο ομάδας):

| Υποσελίδα | Περιεχόμενο |
|-----------|-------------|
| **Dashboard** | Επισκόπηση |
| **Μέλη** | Λίστα μελών, πόντοι, ενέργειες |
| **Ρυθμίσεις** | Καρτέλες: Γενικά, Πόντοι, Referral, Ειδοποιήσεις, WooCommerce |
| **Referrals** | Clicks, debug ανά click, εργαλεία συμφωνίας (reconcile) όπου υπάρχουν |
| **Ιστορικό Πόντων** | Πλήρες log με εξηγήσεις τύπων κινήσεων |

Δικαίωμα πρόσβασης: `manage_options` (τυπικά **Διαχειριστής**).

---

## Ρυθμίσεις (σύνοψη καρτελών)

### Γενικά

- Όνομα club, ενεργοποίηση, ετικέτα/σύμβολο «νομίσματος» πόντων, ελάχιστη ηλικία, σελίδες όρων/απορρήτου.

### Πόντοι

- **Πόντοι ανά €**, **αξία πόντου σε €**, όρια εξαργύρωσης (ελάχιστοι πόντοι, μέγιστο % έκπτωσης), **λήξη πόντων**, **μπόνους γενεθλίων**, **μπόνους εγγραφής** (`epc_signup_bonus_points`).

### Referral

- Ενεργοποίηση, ανταμοιβές (referrer / referred), **ελάχιστο ποσό παραγγελίας** για να «μετρήσει» η αγορά για rewards, μέγιστος αριθμός referrals (0 = χωρίς όριο), διάρκεια cookie, πρόθεμα κωδικού, toggles **track membership** / **track purchase**, ημέρες διατήρησης/εκκαθάρισης clicks.

### Ειδοποιήσεις

- Email διαχειριστή, toggles ειδοποιήσεων (νέο μέλος, ολοκλήρωση referral).
- **Κασσετίνα — δώρο:** toggle εξαίρεσης B2B (όταν **ON**, ισχύει η λίστα IDs από την καρτέλα **WooCommerce**) και **κείμενο email** για το κουμπί «Ενημέρωση πελάτη για κασσετίνα».

### WooCommerce

- Σε ποια **κατάσταση παραγγελίας** κερδίζονται πόντοι (συνήθως processing/completed ανά ρύθμιση).
- Εξαιρέσεις (κατηγορίες, εκπτωτικά προϊόντα, αποστολικά).
- **Κατηγορία δώρων** (αγορά μόνο με πόντους) και σχετικές επιλογές.
- **B2B King — Group ID** του Pappou Club και **λίστα B2B King group IDs χωρίς κασσετίνα** (π.χ. `34`) όταν είναι ενεργός ο σχετικός διακόπτης στις Ειδοποιήσεις.

---

## Πόντοι από παραγγελίες & αντιστροφές

Η λογική βρίσκεται κυρίως στο [`includes/class-epc-woocommerce.php`](wp-content/plugins/epappous-club/includes/class-epc-woocommerce.php).

- **Κέρδος:** hooks `woocommerce_order_status_processing` και `woocommerce_order_status_completed` (με βάση τις ρυθμίσεις «πότε μετράει» το shop).
- **Αντιστροφή (revoke):** `cancelled`, `refunded`, **επίσης** `pending`, `on-hold`, `failed` — αν είχαν ήδη αποδοθεί πόντοι/εγγραφή settlement, αφαιρούνται και καθαρίζονται σχετικά meta ώστε σε νέα επιλέξιμη κατάσταση να μπορεί να γίνει ξανά κέρδος.

Για λεπτομέρειες κατάστασης-προς-κατάσταση, δείτε το εγχειρίδιο διαχειριστή ή το Points log στο admin.

---

## Εξαργύρωση πόντων στο checkout

Οι πόντοι μετατρέπονται σε έκπτωση με βάση `epc_points_value_euro`, `epc_min_redeem_points`, `epc_max_redeem_percent`. Οι κινήσεις καταγράφονται στο `epc_points_log` (π.χ. `checkout_redemption`).

---

## Δώρα WooCommerce (κατηγορία)

- Προϊόντα στη **ρυθμιζόμενη κατηγορία** αγοράζονται αποκλειστικά με πόντους.
- Κόστος σε πόντους: product meta (διαχείριση από WooCommerce).
- Σε processing/completed αφαιρούνται πόντοι· σε ακύρωση/refund επιστρέφουν (όπως ορίζει ο κώδικας).

---

## Referral — πώς δουλεύει (υψηλού επιπέδου)

1. Ο επισκέπτης ανοίγει σύνδεσμο με **`?ref=ΚΩΔΙΚΟΣ`** (και cookie για Χ ημέρες).
2. Δημιουργείται/ενημερώνεται γραμμή στο **`epc_referral_clicks`**.
3. Όταν ο νέος χρήστης **εγγραφεί** ως μέλος και ταιριάξει το click, ενημερώνεται το click (`converted_member_id`, κ.λπ.).
4. Όταν γίνεται **πληρωμένη/επιλέξιμη παραγγελία** (συνήθως `processing` / `completed`), το σύστημα συνδέει την αγορά με το click, **καταγράφει** το order ακόμη κι αν το ποσό είναι **κάτω από το ελάχιστο** για reward — τότε **δεν** δίνονται πόντοι αλλά φαίνεται η αγορά.
5. Αν μια αγορά ήταν κάτω από minimum και αργότερα γίνεται νέα **επιλέξιμη** αγορά, το click μπορεί να **αναβαθμιστεί** στη νέα παραγγελία (rebind).
6. Οι ανταμοιβές εκτελούνται μόνο όταν ικανοποιούνται οι έλεγχοι B2B club, τα toggles referral, τα όρια, και το ελάχιστο ποσό (όπου ισχύει).

Σελίδα **Referrals** στο admin: λίστα clicks, debug, κουμπιά πλήρους ελέγχου (π.χ. 90 ημέρες), χειροκίνητο Order ID όπου υπάρχει.

**WPML:** τα share links μπορούν να βασίζονται στη γλώσσα της τρέχουσας σελίδας / αρχικής ανά γλώσσα (υλοποίηση σε registration / cart box).

---

## Κασσετίνα — δώρο (παραγγελία & προφίλ)

- Στην οθόνη **παραγγελίας** WooCommerce εμφανίζεται metabox **«Κασσετίνα - Δώρο»** (εκτός αν ο πελάτης ανήκει σε **εξαιρούμενη** B2B ομάδα και το toggle εξαίρεσης είναι ενεργό).
- Κουμπί **«Ενημέρωση πελάτη για κασσετίνα»** στέλνει email (`wp_mail`) με περιεχόμενο από τις ρυθμίσεις ή προεπιλογή.
- Ενημερώνονται user meta: λήψη δώρου, ημερομηνία, audit (`epc_cassette_gift_*`).
- Μετά την αποστολή, το κουμπί **δεν** ξαναεμφανίζεται (και server-side έλεγχος για διπλοαποστολή).
- Στο **προφίλ χρήστη** (admin) υπάρχει αντίστοιχο block στις σημειώσεις όπου εφαρμόζεται η ίδια λογική εξαίρεσης.

Scripts φορτώνονται και σε οθόνες HPOS (`wc-orders`).

---

## Cron / συντήρηση

- **Γενέθλια / λήξη πόντων / εκκαθάριση referral clicks:** προγραμματίζονται στο activation (`EPC_Birthday`, `EPC_Expiry`, `EPC_Referral_Clicks_Cleanup`).

---

## Tests

Από τον φάκελο του plugin:

```bash
cd wp-content/plugins/epappous-club
phpunit -c phpunit.xml.dist
```

Απαιτείται PHPUnit. Το `tests/bootstrap.php` παρέχει ελαφρά stubs αν δεν τρέχει πλήρες WordPress.

---

## Ασφάλεια & απορρήτου

- Μην κοινοποιείτε διαπιστευτήρια admin ή production database dumps σε δημόσια issues.
- Το plugin αποθηκεύει email μελών και δεδομένα παραγγελιών μέσω WooCommerce — συμμορφωθείτε με GDPR/πολιτική απορρήτου του site.

---

## Εγχειρίδιο διαχειριστή (μη τεχνικό)

Για βήμα-βήμα οδηγίες στα ελληνικά (persona ~50 ετών, βασική εξοικείωση με WordPress):

→ **[`docs/Pappou-Club-Admin-Manual-el.docx`](docs/Pappou-Club-Admin-Manual-el.docx)**

Για να ξαναπαραχθεί το `.docx` μετά από αλλαγές κειμένου, τρέξτε:

```bash
python3 docs/build_admin_manual.py
```

(Απαιτείται `python-docx`.)

---

## Άδεια

GPL v2 or later (όπως στο header του plugin).

---

## Σύνδεσμοι

- Plugin URI / Author: όπως στο header του `epappous-club.php`.
