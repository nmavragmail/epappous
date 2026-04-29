<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore
$tabs       = [
    'general'       => [ 'label' => __( 'Γενικά', 'epappous-club' ),        'icon' => 'dashicons-admin-settings' ],
    'points'        => [ 'label' => __( 'Πόντοι', 'epappous-club' ),        'icon' => 'dashicons-star-filled' ],
    // 'tiers'         => [ 'label' => __( 'Βαθμίδες', 'epappous-club' ),      'icon' => 'dashicons-awards' ],
    'referral'      => [ 'label' => __( 'Referral', 'epappous-club' ),      'icon' => 'dashicons-share' ],
    'notifications' => [ 'label' => __( 'Ειδοποιήσεις', 'epappous-club' ),  'icon' => 'dashicons-email-alt' ],
    'woocommerce'   => [ 'label' => __( 'WooCommerce', 'epappous-club' ),   'icon' => 'dashicons-store' ],
];

$tiers_json = EPC_Settings::get( 'epc_tiers' );
$tiers      = json_decode( $tiers_json, true );
if ( ! is_array( $tiers ) ) {
    $tiers = [];
}
?>
<div class="wrap epc-wrap">
    <div class="epc-header">
        <h1>
            <span class="dashicons dashicons-admin-settings"></span>
            <?php esc_html_e( 'Ρυθμίσεις Pappou Club', 'epappous-club' ); ?>
        </h1>
        <span class="epc-version">v<?php echo esc_html( EPC_VERSION ); ?></span>
    </div>

    <p class="description" style="margin: -4px 0 12px;">
        <?php esc_html_e( 'Όλα τα tabs φορτώνονται μαζί· η εναλλαγή είναι άμεση (χωρίς επαναφόρτωση σελίδας).', 'epappous-club' ); ?>
    </p>

    <div class="epc-tabs-wrapper epc-settings-tabs-shell">
        <nav class="epc-tabs-nav epc-settings-tabs" data-epc-prefetch="1">
            <?php foreach ( $tabs as $slug => $tab ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-settings&tab=' . $slug ) ); ?>"
                   class="epc-tab-link <?php echo $active_tab === $slug ? 'active' : ''; ?>"
                   data-tab="<?php echo esc_attr( $slug ); ?>">
                    <span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
                    <?php echo esc_html( $tab['label'] ); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="epc-tab-content">
            <form method="post" action="options.php" id="epc-settings-form">
                <?php settings_fields( 'epc_settings_group' ); ?>

                <!-- ════════ GENERAL ════════ -->
                <div class="epc-tab-panel <?php echo $active_tab === 'general' ? 'active' : ''; ?>" data-tab="general">
                    <div class="epc-card">
                        <div class="epc-card-header">
                            <h2><?php esc_html_e( 'Γενικές Ρυθμίσεις', 'epappous-club' ); ?></h2>
                            <p class="epc-card-desc"><?php esc_html_e( 'Βασικές ρυθμίσεις για το Pappou Club.', 'epappous-club' ); ?></p>
                        </div>
                        <div class="epc-card-body">
                            <div class="epc-field-row">
                                <label for="epc_club_name"><?php esc_html_e( 'Όνομα Club', 'epappous-club' ); ?></label>
                                <input type="text" id="epc_club_name" name="epc_club_name"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_club_name' ) ); ?>"
                                       class="regular-text" />
                                <p class="description"><?php esc_html_e( 'Το όνομα που εμφανίζεται στους πελάτες.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_club_enabled"><?php esc_html_e( 'Ενεργοποίηση Club', 'epappous-club' ); ?></label>
                                <label class="epc-toggle">
                                    <input type="hidden" name="epc_club_enabled" value="0" />
                                    <input type="checkbox" id="epc_club_enabled" name="epc_club_enabled" value="1"
                                           <?php checked( EPC_Settings::get( 'epc_club_enabled' ), '1' ); ?> />
                                    <span class="epc-toggle-slider"></span>
                                </label>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_currency_label"><?php esc_html_e( 'Ετικέτα Νομίσματος', 'epappous-club' ); ?></label>
                                <input type="text" id="epc_currency_label" name="epc_currency_label"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_currency_label' ) ); ?>"
                                       class="regular-text" />
                                <p class="description"><?php esc_html_e( 'π.χ. πόντοι, coins, stars', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_currency_symbol"><?php esc_html_e( 'Σύμβολο', 'epappous-club' ); ?></label>
                                <input type="text" id="epc_currency_symbol" name="epc_currency_symbol"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_currency_symbol' ) ); ?>"
                                       class="small-text" />
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_min_age"><?php esc_html_e( 'Ελάχιστη Ηλικία', 'epappous-club' ); ?></label>
                                <input type="number" id="epc_min_age" name="epc_min_age"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_min_age' ) ); ?>"
                                       class="small-text" min="0" max="120" />
                                <p class="description"><?php esc_html_e( 'Βάλτε 0 για κανέναν περιορισμό.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_terms_page_id"><?php esc_html_e( 'Σελίδα Όρων Χρήσης', 'epappous-club' ); ?></label>
                                <?php
                                wp_dropdown_pages( [
                                    'name'              => 'epc_terms_page_id',
                                    'id'                => 'epc_terms_page_id',
                                    'selected'          => EPC_Settings::get( 'epc_terms_page_id' ),
                                    'show_option_none'  => __( '— Επιλέξτε σελίδα —', 'epappous-club' ),
                                    'option_none_value' => '',
                                ] );
                                ?>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_privacy_page_id"><?php esc_html_e( 'Πολιτική Απορρήτου', 'epappous-club' ); ?></label>
                                <?php
                                wp_dropdown_pages( [
                                    'name'              => 'epc_privacy_page_id',
                                    'id'                => 'epc_privacy_page_id',
                                    'selected'          => EPC_Settings::get( 'epc_privacy_page_id' ),
                                    'show_option_none'  => __( '— Επιλέξτε σελίδα —', 'epappous-club' ),
                                    'option_none_value' => '',
                                ] );
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ════════ POINTS ════════ -->
                <div class="epc-tab-panel <?php echo $active_tab === 'points' ? 'active' : ''; ?>" data-tab="points">
                    <div class="epc-card">
                        <div class="epc-card-header">
                            <h2><?php esc_html_e( 'Ρυθμίσεις Πόντων', 'epappous-club' ); ?></h2>
                            <p class="epc-card-desc"><?php esc_html_e( 'Καθορίστε πώς κερδίζονται & εξαργυρώνονται οι πόντοι.', 'epappous-club' ); ?></p>
                        </div>
                        <div class="epc-card-body">
                            <div class="epc-field-row">
                                <label for="epc_points_per_euro"><?php esc_html_e( 'Πόντοι ανά €', 'epappous-club' ); ?></label>
                                <input type="number" id="epc_points_per_euro" name="epc_points_per_euro"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_points_per_euro' ) ); ?>"
                                       class="small-text" min="0" step="0.1" />
                                <p class="description"><?php esc_html_e( 'Πόσοι πόντοι κερδίζονται για κάθε 1€ αγοράς.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_points_value_euro"><?php esc_html_e( 'Αξία Πόντου σε €', 'epappous-club' ); ?></label>
                                <input type="number" id="epc_points_value_euro" name="epc_points_value_euro"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_points_value_euro' ) ); ?>"
                                       class="small-text" min="0" step="0.001" />
                                <p class="description"><?php esc_html_e( 'Πόσα € αξίζει 1 πόντος κατά την εξαργύρωση.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_min_redeem_points"><?php esc_html_e( 'Ελάχιστοι Πόντοι Εξαργύρωσης', 'epappous-club' ); ?></label>
                                <input type="number" id="epc_min_redeem_points" name="epc_min_redeem_points"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_min_redeem_points' ) ); ?>"
                                       class="small-text" min="0" step="10" />
                                <p class="description"><?php esc_html_e( 'Ελάχιστοι πόντοι που μπορεί να εξαργυρώσει ο πελάτης σε μία παραγγελία (ξεκίνημα του slider στο καλάθι).', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_max_redeem_percent"><?php esc_html_e( 'Μέγιστο % Έκπτωσης', 'epappous-club' ); ?></label>
                                <div class="epc-input-group">
                                    <input type="number" id="epc_max_redeem_percent" name="epc_max_redeem_percent"
                                           value="<?php echo esc_attr( EPC_Settings::get( 'epc_max_redeem_percent' ) ); ?>"
                                           class="small-text" min="0" max="100" />
                                    <span class="epc-input-addon">%</span>
                                </div>
                                <p class="description"><?php esc_html_e( 'Μέγιστο ποσοστό παραγγελίας που μπορεί να καλυφθεί με πόντους.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_points_expiry_days"><?php esc_html_e( 'Λήξη Πόντων (ημέρες)', 'epappous-club' ); ?></label>
                                <input type="number" id="epc_points_expiry_days" name="epc_points_expiry_days"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_points_expiry_days' ) ); ?>"
                                       class="small-text" min="0" />
                                <p class="description"><?php esc_html_e( 'Βάλτε 0 αν δεν λήγουν ποτέ.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_signup_bonus_points"><?php esc_html_e( 'Πόντοι μπόνους εγγραφής', 'epappous-club' ); ?></label>
                                <input type="number" id="epc_signup_bonus_points" name="epc_signup_bonus_points"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_signup_bonus_points' ) ); ?>"
                                       class="small-text" min="0" step="1" />
                                <p class="description"><?php esc_html_e( 'Πόντοι που αποδίδονται μία φορά όταν δημιουργείται νέο μέλος (φόρμα εγγραφής, checkout, διαχείριση). Βάλτε 0 για κανένα μπόνους.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_birthday_bonus"><?php esc_html_e( 'Μπόνους Γενεθλίων', 'epappous-club' ); ?></label>
                                <input type="number" id="epc_birthday_bonus" name="epc_birthday_bonus"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_birthday_bonus' ) ); ?>"
                                       class="small-text" min="0" />
                                <p class="description"><?php esc_html_e( 'Πόντοι που δίνονται αυτόματα στα γενέθλια του μέλους. Βάλτε 0 για απενεργοποίηση.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-info-box" style="margin-top: 8px;">
                                <span class="dashicons dashicons-info-outline"></span>
                                <div>
                                    <strong><?php esc_html_e( 'Πώς λειτουργεί:', 'epappous-club' ); ?></strong>
                                    <?php esc_html_e( 'Ένα ημερήσιο cron job ελέγχει ποια μέλη έχουν γενέθλια σήμερα (βάσει του πεδίου ημερομηνίας γέννησης) και τους αποδίδει αυτόματα τους πόντους. Κάθε μέλος λαμβάνει μπόνους γενεθλίων μία φορά ανά ημερολογιακό έτος.', 'epappous-club' ); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ( false ) : ?>
                <!-- ════════ TIERS (hidden — re-enable tab + EPC_Tiers loader) ════════ -->
                <div class="epc-tab-panel <?php echo $active_tab === 'tiers' ? 'active' : ''; ?>" data-tab="tiers">
                    <div class="epc-card">
                        <div class="epc-card-header">
                            <h2><?php esc_html_e( 'Βαθμίδες Μελών', 'epappous-club' ); ?></h2>
                            <p class="epc-card-desc"><?php esc_html_e( 'Ορίστε τα tier levels και τα πλεονεκτήματά τους.', 'epappous-club' ); ?></p>
                        </div>
                        <div class="epc-card-body">
                            <div id="epc-tiers-container">
                                <?php foreach ( $tiers as $i => $tier ) : ?>
                                    <div class="epc-tier-row" data-index="<?php echo (int) $i; ?>">
                                        <div class="epc-tier-color-preview" style="background:<?php echo esc_attr( $tier['color'] ?? '#6b7280' ); ?>"></div>
                                        <div class="epc-tier-fields">
                                            <input type="text" class="epc-tier-slug" placeholder="<?php esc_attr_e( 'Slug', 'epappous-club' ); ?>"
                                                   value="<?php echo esc_attr( $tier['slug'] ); ?>" />
                                            <input type="text" class="epc-tier-label" placeholder="<?php esc_attr_e( 'Ετικέτα', 'epappous-club' ); ?>"
                                                   value="<?php echo esc_attr( $tier['label'] ); ?>" />
                                            <input type="number" class="epc-tier-min" placeholder="<?php esc_attr_e( 'Ελ. πόντοι', 'epappous-club' ); ?>"
                                                   value="<?php echo (int) $tier['min_points']; ?>" min="0" />
                                            <input type="number" class="epc-tier-mult" placeholder="<?php esc_attr_e( 'Πολ/στής', 'epappous-club' ); ?>" step="0.1"
                                                   value="<?php echo esc_attr( $tier['multiplier'] ); ?>" min="1" />
                                            <input type="text" class="epc-tier-color epc-color-picker" placeholder="<?php esc_attr_e( 'Χρώμα', 'epappous-club' ); ?>"
                                                   value="<?php echo esc_attr( $tier['color'] ); ?>" />
                                        </div>
                                        <button type="button" class="button epc-remove-tier" title="<?php esc_attr_e( 'Αφαίρεση', 'epappous-club' ); ?>">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="epc_tiers" id="epc_tiers_json"
                                   value="<?php echo esc_attr( $tiers_json ); ?>" />
                            <button type="button" class="button button-secondary" id="epc-add-tier">
                                <span class="dashicons dashicons-plus-alt"></span>
                                <?php esc_html_e( 'Προσθήκη Βαθμίδας', 'epappous-club' ); ?>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ════════ REFERRAL ════════ -->
                <div class="epc-tab-panel <?php echo $active_tab === 'referral' ? 'active' : ''; ?>" data-tab="referral">
                    <div class="epc-card">
                        <div class="epc-card-header">
                            <h2><?php esc_html_e( 'Ρυθμίσεις Referral', 'epappous-club' ); ?></h2>
                            <p class="epc-card-desc">
                                <?php esc_html_e( 'Κάθε μέλος έχει μοναδικό κωδικό και link (?ref=). Οι πόντοι ανταμοιβής χορηγούνται όταν ολοκληρωθούν και η εγγραφή του νέου μέλους και επιλέξιμη παραγγελία (με τα δύο σχετικά toggles ενεργά), εφόσον και οι δύο χρήστες ανήκουν στο B2B group «Pappou Club» και τηρούνται όρια παραγγελιών/αριθμού referrals.', 'epappous-club' ); ?>
                            </p>
                        </div>
                        <div class="epc-card-body">
                            <div class="epc-field-row">
                                <label for="epc_referral_enabled"><?php esc_html_e( 'Ενεργοποίηση Referral', 'epappous-club' ); ?></label>
                                <label class="epc-toggle">
                                    <input type="hidden" name="epc_referral_enabled" value="0" />
                                    <input type="checkbox" id="epc_referral_enabled" name="epc_referral_enabled" value="1"
                                           <?php checked( EPC_Settings::get( 'epc_referral_enabled' ), '1' ); ?> />
                                    <span class="epc-toggle-slider"></span>
                                </label>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_referral_code_prefix"><?php esc_html_e( 'Πρόθεμα Κωδικού', 'epappous-club' ); ?></label>
                                <input type="text" id="epc_referral_code_prefix" name="epc_referral_code_prefix"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_referral_code_prefix' ) ); ?>"
                                       class="regular-text" />
                                <p class="description"><?php esc_html_e( 'π.χ. PAPPOU → κωδικοί PAPPOU-XXXX', 'epappous-club' ); ?></p>
                            </div>

                            <hr class="epc-divider" />
                            <h3><?php esc_html_e( 'Ανταμοιβές (πόντοι club)', 'epappous-club' ); ?></h3>
                            <p class="description"><?php esc_html_e( 'Η απονομή γίνεται πάντα ως πόντοι στον πίνακα μελών (όχι κουπόνι έκπτωσης).', 'epappous-club' ); ?></p>

                            <div class="epc-field-row">
                                <label for="epc_referral_reward_referrer"><?php esc_html_e( 'Ανταμοιβή Αυτού που Προσκαλεί', 'epappous-club' ); ?></label>
                                <input type="number" id="epc_referral_reward_referrer" name="epc_referral_reward_referrer"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_referral_reward_referrer' ) ); ?>"
                                       class="small-text" min="0" />
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_referral_reward_referred"><?php esc_html_e( 'Ανταμοιβή Νέου Μέλους', 'epappous-club' ); ?></label>
                                <input type="number" id="epc_referral_reward_referred" name="epc_referral_reward_referred"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_referral_reward_referred' ) ); ?>"
                                       class="small-text" min="0" />
                            </div>

                            <hr class="epc-divider" />
                            <h3><?php esc_html_e( 'Συνθήκες & Κανόνες', 'epappous-club' ); ?></h3>

                            <div class="epc-field-row">
                                <label for="epc_referral_track_membership"><?php esc_html_e( 'Καταγραφή κατά την Εγγραφή', 'epappous-club' ); ?></label>
                                <label class="epc-toggle">
                                    <input type="hidden" name="epc_referral_track_membership" value="0" />
                                    <input type="checkbox" id="epc_referral_track_membership" name="epc_referral_track_membership" value="1"
                                           <?php checked( EPC_Settings::get( 'epc_referral_track_membership' ), '1' ); ?> />
                                    <span class="epc-toggle-slider"></span>
                                </label>
                                <p class="description"><?php esc_html_e( 'Καταγράφεται referral όταν κάποιος γίνει μέλος μέσω link.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_referral_track_purchase"><?php esc_html_e( 'Καταγραφή κατά την Αγορά', 'epappous-club' ); ?></label>
                                <label class="epc-toggle">
                                    <input type="hidden" name="epc_referral_track_purchase" value="0" />
                                    <input type="checkbox" id="epc_referral_track_purchase" name="epc_referral_track_purchase" value="1"
                                           <?php checked( EPC_Settings::get( 'epc_referral_track_purchase' ), '1' ); ?> />
                                    <span class="epc-toggle-slider"></span>
                                </label>
                                <p class="description"><?php esc_html_e( 'Καταγράφεται referral όταν ο referred ολοκληρώσει αγορά.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_referral_min_order"><?php esc_html_e( 'Ελάχιστο Ποσό Παραγγελίας (€)', 'epappous-club' ); ?></label>
                                <input type="number" id="epc_referral_min_order" name="epc_referral_min_order"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_referral_min_order' ) ); ?>"
                                       class="small-text" min="0" step="0.01" />
                                <p class="description"><?php esc_html_e( 'Ελάχιστο ποσό παραγγελίας για να μετρήσει ως referral. 0 = χωρίς όριο.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_referral_max_referrals"><?php esc_html_e( 'Μέγιστος Αριθμός Referrals', 'epappous-club' ); ?></label>
                                <input type="number" id="epc_referral_max_referrals" name="epc_referral_max_referrals"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_referral_max_referrals' ) ); ?>"
                                       class="small-text" min="0" />
                                <p class="description"><?php esc_html_e( 'Μέγιστος αριθμός επιτυχημένων referrals ανά μέλος. 0 = απεριόριστα.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_referral_cookie_days"><?php esc_html_e( 'Διάρκεια Cookie (ημέρες)', 'epappous-club' ); ?></label>
                                <input type="number" id="epc_referral_cookie_days" name="epc_referral_cookie_days"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_referral_cookie_days' ) ); ?>"
                                       class="small-text" min="1" />
                                <p class="description"><?php esc_html_e( 'Πόσες ημέρες διατηρείται το referral cookie στον browser.', 'epappous-club' ); ?></p>
                            </div>

                            <hr class="epc-divider" />
                            <h3><?php esc_html_e( 'Εκκαθάριση καταγραφών clicks (referral)', 'epappous-club' ); ?></h3>
                            <p class="description"><?php esc_html_e( 'Ημερήσιο cron διαγράφει παλιές γραμμές από τον πίνακα referral clicks ώστε να μην μεγαλώνει απεριόριστα. 0 = απενεργοποίηση για το αντίστοιμο κομμάτι.', 'epappous-club' ); ?></p>

                            <div class="epc-field-row">
                                <label for="epc_referral_clicks_prune_unrewarded_days"><?php esc_html_e( 'Ημέρες διατήρησης μη ανταμειφθέντων leads', 'epappous-club' ); ?></label>
                                <input type="number" id="epc_referral_clicks_prune_unrewarded_days" name="epc_referral_clicks_prune_unrewarded_days"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_referral_clicks_prune_unrewarded_days' ) ); ?>"
                                       class="small-text" min="0" />
                                <p class="description"><?php esc_html_e( 'Διαγραφή γραμμών χωρίς rewarded_at παλαιότερων από Ν ημέρες.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_referral_clicks_prune_rewarded_days"><?php esc_html_e( 'Ημέρες διατήρησης ολοκληρωμένων (rewarded)', 'epappous-club' ); ?></label>
                                <input type="number" id="epc_referral_clicks_prune_rewarded_days" name="epc_referral_clicks_prune_rewarded_days"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_referral_clicks_prune_rewarded_days' ) ); ?>"
                                       class="small-text" min="0" />
                                <p class="description"><?php esc_html_e( 'Διαγραφή γραμμών με rewarded_at παλαιότερο από Ν ημέρες. 0 = να μη διαγράφονται.', 'epappous-club' ); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ════════ NOTIFICATIONS ════════ -->
                <div class="epc-tab-panel <?php echo $active_tab === 'notifications' ? 'active' : ''; ?>" data-tab="notifications">
                    <div class="epc-card">
                        <div class="epc-card-header">
                            <h2><?php esc_html_e( 'Ειδοποιήσεις', 'epappous-club' ); ?></h2>
                            <p class="epc-card-desc"><?php esc_html_e( 'Ποιες ειδοποιήσεις email αποστέλλονται.', 'epappous-club' ); ?></p>
                        </div>
                        <div class="epc-card-body">
                            <div class="epc-field-row">
                                <label for="epc_admin_email"><?php esc_html_e( 'Email Διαχειριστή', 'epappous-club' ); ?></label>
                                <input type="email" id="epc_admin_email" name="epc_admin_email"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_admin_email' ) ); ?>"
                                       class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
                                <p class="description"><?php esc_html_e( 'Αφήστε κενό για χρήση του email του site.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_cassette_gift_email_body"><?php esc_html_e( 'Email Κασσετίνα Δώρο', 'epappous-club' ); ?></label>
                                <textarea id="epc_cassette_gift_email_body"
                                          name="epc_cassette_gift_email_body"
                                          class="large-text"
                                          rows="6"
                                          placeholder="<?php echo esc_attr__( 'Γράψε εδώ το περιεχόμενο email που θα σταλεί με το κουμπί «Ενημέρωση πελάτη για κασσετίνα».', 'epappous-club' ); ?>"><?php echo esc_textarea( EPC_Settings::get( 'epc_cassette_gift_email_body' ) ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Το περιεχόμενο αυτό αποστέλλεται στον πελάτη όταν πατηθεί το κουμπί «Ενημέρωση πελάτη για κασσετίνα» μέσα στην παραγγελία. Αν μείνει κενό, στέλνεται προεπιλεγμένο μήνυμα με σύνδεσμο στην αρχική σελίδα. Αν στο WooCommerce είναι ενεργή η εξαίρεση κασσετίνας για B2B και ο πελάτης ανήκει σε ομάδα της λίστας, το email δεν αποστέλλεται.', 'epappous-club' ); ?></p>
                            </div>

                            <?php
                            $notifications = [
                                'epc_notify_new_member'        => __( 'Νέο Μέλος', 'epappous-club' ),
                                'epc_notify_referral_complete' => __( 'Ολοκλήρωση Referral', 'epappous-club' ),
                                // 'epc_notify_tier_upgrade'      => __( 'Αναβάθμιση Βαθμίδας', 'epappous-club' ),
                            ];
                            foreach ( $notifications as $key => $label ) : ?>
                                <div class="epc-field-row">
                                    <label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
                                    <label class="epc-toggle">
                                        <input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="0" />
                                        <input type="checkbox" id="<?php echo esc_attr( $key ); ?>"
                                               name="<?php echo esc_attr( $key ); ?>" value="1"
                                               <?php checked( EPC_Settings::get( $key ), '1' ); ?> />
                                        <span class="epc-toggle-slider"></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- ════════ WOOCOMMERCE ════════ -->
                <div class="epc-tab-panel <?php echo $active_tab === 'woocommerce' ? 'active' : ''; ?>" data-tab="woocommerce">
                    <div class="epc-card">
                        <div class="epc-card-header">
                            <h2><?php esc_html_e( 'WooCommerce Ενσωμάτωση', 'epappous-club' ); ?></h2>
                            <p class="epc-card-desc"><?php esc_html_e( 'Ρυθμίσεις σύνδεσης με το WooCommerce.', 'epappous-club' ); ?></p>
                        </div>
                        <div class="epc-card-body">
                            <div class="epc-field-row">
                                <label for="epc_b2bking_club_group_id"><?php esc_html_e( 'B2B King — Group ID «Pappou Club»', 'epappous-club' ); ?></label>
                                <input type="number"
                                       id="epc_b2bking_club_group_id"
                                       name="epc_b2bking_club_group_id"
                                       value="<?php echo esc_attr( (string) max( 0, (int) EPC_Settings::get( 'epc_b2bking_club_group_id' ) ) ); ?>"
                                       class="small-text"
                                       min="0"
                                       step="1" />
                                <p class="description">
                                    <?php esc_html_e( 'Post ID της ομάδας στο B2B King (B2BKing → Groups). Μετά migration ενημερώστε το εδώ. Μόνο χρήστες αυτής της ομάδας κερδίζουν και εξαργυρώνουν πόντους· το υπόλοιπο παραμένει αν αλλάξει ομάδα.', 'epappous-club' ); ?>
                                </p>
                                <?php if ( ! function_exists( 'b2bking' ) ) : ?>
                                    <p class="description" style="color:#b45309;">
                                        <span class="dashicons dashicons-warning"></span>
                                        <?php esc_html_e( 'Το B2B King δεν φαίνεται ενεργό — οι έλεγχοι ομάδας θα απορρίπτουν όλους μέχρι να φορτωθεί το plugin.', 'epappous-club' ); ?>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_cassette_gift_enabled"><?php esc_html_e( 'Εξαίρεση κασσετίνας δώρου για B2B ομάδες (λίστα παρακάτω)', 'epappous-club' ); ?></label>
                                <label class="epc-toggle">
                                    <input type="hidden" name="epc_cassette_gift_enabled" value="0" />
                                    <input type="checkbox" id="epc_cassette_gift_enabled" name="epc_cassette_gift_enabled" value="1"
                                           <?php checked( EPC_Settings::get( 'epc_cassette_gift_enabled' ), '1' ); ?> />
                                    <span class="epc-toggle-slider"></span>
                                </label>
                                <p class="description"><?php esc_html_e( 'Όταν είναι ενεργό, για τους πελάτες στις B2B ομάδες της λίστας παρακάτω κρύβεται το «Κασσετίνα - Δώρο» (παραγγελία & προφίλ) και δεν στέλνεται το email. Όταν είναι απενεργοποιημένο, ισχύει για όλους — η λίστα αγνοείται (και για ομάδα 34).', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_cassette_gift_exclude_b2b_group_ids"><?php esc_html_e( 'B2B King group IDs χωρίς κασσετίνα (όταν το παραπάνω είναι ενεργό)', 'epappous-club' ); ?></label>
                                <input type="text" id="epc_cassette_gift_exclude_b2b_group_ids" name="epc_cassette_gift_exclude_b2b_group_ids"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_cassette_gift_exclude_b2b_group_ids' ) ); ?>"
                                       class="regular-text" placeholder="34" />
                                <p class="description"><?php esc_html_e( 'Post ID ομάδων B2B King (χωρισμένα με κόμμα). Χρησιμοποιείται μόνο αν είναι ενεργός ο παραπάνω διακόπτης. Προεπιλογή: 34.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_cassette_gift_min_order"><?php esc_html_e( 'Ελάχιστη αξία παραγγελίας για κασσετίνα δώρο (€)', 'epappous-club' ); ?></label>
                                <input type="number"
                                       id="epc_cassette_gift_min_order"
                                       name="epc_cassette_gift_min_order"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_cassette_gift_min_order' ) ); ?>"
                                       class="small-text"
                                       min="0"
                                       step="0.01" />
                                <p class="description"><?php esc_html_e( 'Αν η παραγγελία είναι κάτω από αυτό το ποσό, στο order metabox εμφανίζεται μήνυμα «ΔΕΝ δικαιούται κασσετίνα δώρο» και δεν εμφανίζεται το κουμπί αποστολής email. Προεπιλογή: 39.', 'epappous-club' ); ?></p>
                            </div>

                            <hr class="epc-divider" />

                            <div class="epc-field-row">
                                <label for="epc_woo_earn_on_complete"><?php esc_html_e( 'Ενεργοποίηση απονομής πόντων παραγγελίας', 'epappous-club' ); ?></label>
                                <label class="epc-toggle">
                                    <input type="hidden" name="epc_woo_earn_on_complete" value="0" />
                                    <input type="checkbox" id="epc_woo_earn_on_complete" name="epc_woo_earn_on_complete" value="1"
                                           <?php checked( EPC_Settings::get( 'epc_woo_earn_on_complete' ), '1' ); ?> />
                                    <span class="epc-toggle-slider"></span>
                                </label>
                                <p class="description"><?php esc_html_e( 'Ενεργοποιεί την απονομή πόντων σύμφωνα με τα επιλεγμένα status παρακάτω.', 'epappous-club' ); ?></p>
                            </div>

                            <?php
                            $earn_statuses = json_decode( (string) EPC_Settings::get( 'epc_woo_earn_statuses' ), true );
                            if ( ! is_array( $earn_statuses ) ) {
                                $earn_statuses = [ 'completed' ];
                            }
                            $status_options = [
                                'processing' => __( 'Processing', 'epappous-club' ),
                                'completed'  => __( 'Completed', 'epappous-club' ),
                            ];
                            ?>
                            <div class="epc-field-row">
                                <label><?php esc_html_e( 'Status απονομής πόντων', 'epappous-club' ); ?></label>
                                <div style="display:flex;gap:16px;flex-wrap:wrap;">
                                    <?php foreach ( $status_options as $status_key => $status_label ) : ?>
                                        <label style="display:inline-flex;align-items:center;gap:6px;">
                                            <input type="checkbox"
                                                   class="epc-woo-earn-status"
                                                   value="<?php echo esc_attr( $status_key ); ?>"
                                                   <?php checked( in_array( $status_key, $earn_statuses, true ) ); ?> />
                                            <span><?php echo esc_html( $status_label ); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" id="epc_woo_earn_statuses_json" name="epc_woo_earn_statuses"
                                       value="<?php echo esc_attr( wp_json_encode( array_values( $earn_statuses ) ) ); ?>" />
                                <p class="description"><?php esc_html_e( 'Επίλεξε σε ποια status παραγγελίας αποδίδονται πόντοι. Σε cancelled/refunded οι αποδοθέντες πόντοι ακυρώνονται αυτόματα.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_woo_exclude_sale_items"><?php esc_html_e( 'Εξαίρεση Εκπτωτικών', 'epappous-club' ); ?></label>
                                <label class="epc-toggle">
                                    <input type="hidden" name="epc_woo_exclude_sale_items" value="0" />
                                    <input type="checkbox" id="epc_woo_exclude_sale_items" name="epc_woo_exclude_sale_items" value="1"
                                           <?php checked( EPC_Settings::get( 'epc_woo_exclude_sale_items' ), '1' ); ?> />
                                    <span class="epc-toggle-slider"></span>
                                </label>
                                <p class="description"><?php esc_html_e( 'Δεν κερδίζονται πόντοι σε προϊόντα που είναι ήδη σε έκπτωση.', 'epappous-club' ); ?></p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_woo_earn_include_shipping"><?php esc_html_e( 'Πόντοι στα μεταφορικά', 'epappous-club' ); ?></label>
                                <label class="epc-toggle">
                                    <input type="hidden" name="epc_woo_earn_include_shipping" value="0" />
                                    <input type="checkbox" id="epc_woo_earn_include_shipping" name="epc_woo_earn_include_shipping" value="1"
                                           <?php checked( EPC_Settings::get( 'epc_woo_earn_include_shipping' ), '1' ); ?> />
                                    <span class="epc-toggle-slider"></span>
                                </label>
                                <p class="description"><?php esc_html_e( 'Όταν είναι ενεργό, οι πόντοι υπολογίζονται και πάνω στο κόστος μεταφορικών. Διαφορετικά μόνο πάνω στην αξία προϊόντων (μετά την έκπτωση από εξαργύρωση πόντων).', 'epappous-club' ); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="epc-card">
                        <div class="epc-card-header">
                            <h2><?php esc_html_e( 'Δώρα με Πόντους (αγορά μέσω WooCommerce)', 'epappous-club' ); ?></h2>
                            <p class="epc-card-desc"><?php esc_html_e( 'Προϊόντα που ανήκουν στην κατηγορία Δώρου δεν αγοράζονται με χρήματα — μόνο με εξαργύρωση πόντων μέσα από το κανονικό checkout.', 'epappous-club' ); ?></p>
                        </div>
                        <div class="epc-card-body">
                            <div class="epc-field-row">
                                <label for="epc_woo_gift_category"><?php esc_html_e( 'Κατηγορία προϊόντων δώρου', 'epappous-club' ); ?></label>
                                <?php
                                $current_gift_cat_id = (int) EPC_Settings::get( 'epc_woo_gift_category' );
                                $product_cats        = function_exists( 'get_terms' )
                                    ? get_terms( [
                                        'taxonomy'   => 'product_cat',
                                        'hide_empty' => false,
                                        'orderby'    => 'name',
                                    ] )
                                    : [];
                                if ( is_wp_error( $product_cats ) ) {
                                    $product_cats = [];
                                }
                                ?>
                                <select id="epc_woo_gift_category" name="epc_woo_gift_category" class="regular-text">
                                    <option value="0"><?php esc_html_e( '— Χωρίς κατηγορία δώρου —', 'epappous-club' ); ?></option>
                                    <?php foreach ( $product_cats as $cat ) : ?>
                                        <option value="<?php echo esc_attr( (string) $cat->term_id ); ?>" <?php selected( $current_gift_cat_id, (int) $cat->term_id ); ?>>
                                            <?php echo esc_html( $cat->name ); ?> (#<?php echo (int) $cat->term_id; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e( 'Όποιο WooCommerce προϊόν ανήκει σε αυτή την κατηγορία θα διαθέτει στο edit screen πεδίο «Πόντοι για εξαργύρωση», δεν θα αγοράζεται με χρήματα και δεν θα κερδίζει πόντους. Οι πόντοι αφαιρούνται από το ledger όταν η παραγγελία γίνει processing/completed.', 'epappous-club' ); ?>
                                </p>
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_woo_gift_allow_redeem_stack"><?php esc_html_e( 'Επιπλέον εξαργύρωση πόντων μαζί με δώρο', 'epappous-club' ); ?></label>
                                <label class="epc-toggle">
                                    <input type="hidden" name="epc_woo_gift_allow_redeem_stack" value="0" />
                                    <input type="checkbox" id="epc_woo_gift_allow_redeem_stack" name="epc_woo_gift_allow_redeem_stack" value="1"
                                           <?php checked( EPC_Settings::get( 'epc_woo_gift_allow_redeem_stack' ), '1' ); ?> />
                                    <span class="epc-toggle-slider"></span>
                                </label>
                                <p class="description"><?php esc_html_e( 'Όταν είναι ενεργό (default), ο πελάτης μπορεί να χρησιμοποιήσει και τον slider εξαργύρωσης πόντων για έκπτωση στα κανονικά προϊόντα, παράλληλα με δώρα στο καλάθι. Αν απενεργοποιηθεί, ο slider κρύβεται όταν υπάρχει δώρο στο καλάθι.', 'epappous-club' ); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="epc_tiers" value="<?php echo esc_attr( $tiers_json ); ?>" />

                <div class="epc-form-actions">
                    <?php submit_button( __( 'Αποθήκευση Ρυθμίσεων', 'epappous-club' ), 'primary epc-save-btn', 'submit', false ); ?>
                </div>
            </form>
        </div>
    </div>
</div>
