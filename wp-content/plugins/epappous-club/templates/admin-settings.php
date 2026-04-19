<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore
$tabs = [
    'general'       => [ 'label' => __( 'Γενικά', 'epappous-club' ),        'icon' => 'dashicons-admin-settings' ],
    'points'        => [ 'label' => __( 'Πόντοι', 'epappous-club' ),        'icon' => 'dashicons-star-filled' ],
    // 'tiers'         => [ 'label' => __( 'Βαθμίδες', 'epappous-club' ),      'icon' => 'dashicons-awards' ],
    'referral'      => [ 'label' => __( 'Referral', 'epappous-club' ),      'icon' => 'dashicons-share' ],
    'gifts'         => [ 'label' => __( 'Δώρα', 'epappous-club' ),          'icon' => 'dashicons-cart' ],
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

    <div class="epc-tabs-wrapper">
        <nav class="epc-tabs-nav">
            <?php foreach ( $tabs as $slug => $tab ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-settings&tab=' . $slug ) ); ?>"
                   class="epc-tab-link <?php echo $active_tab === $slug ? 'active' : ''; ?>">
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
                                <?php esc_html_e( 'Το σύστημα referral επιτρέπει στα μέλη να προσκαλούν φίλους. Κάθε μέλος παίρνει ένα μοναδικό κωδικό (π.χ. PAPPOU-A3X9) που μοιράζεται μέσω link. Όταν κάποιος γίνει μέλος ή αγοράσει, και οι δύο κερδίζουν πόντους.', 'epappous-club' ); ?>
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
                            <h3><?php esc_html_e( 'Ανταμοιβές', 'epappous-club' ); ?></h3>

                            <div class="epc-field-row">
                                <label for="epc_referral_reward_type"><?php esc_html_e( 'Τύπος Ανταμοιβής', 'epappous-club' ); ?></label>
                                <select id="epc_referral_reward_type" name="epc_referral_reward_type">
                                    <option value="points" <?php selected( EPC_Settings::get( 'epc_referral_reward_type' ), 'points' ); ?>>
                                        <?php esc_html_e( 'Πόντοι', 'epappous-club' ); ?>
                                    </option>
                                    <option value="discount_fixed" <?php selected( EPC_Settings::get( 'epc_referral_reward_type' ), 'discount_fixed' ); ?>>
                                        <?php esc_html_e( 'Σταθερή Έκπτωση (€)', 'epappous-club' ); ?>
                                    </option>
                                    <option value="discount_percent" <?php selected( EPC_Settings::get( 'epc_referral_reward_type' ), 'discount_percent' ); ?>>
                                        <?php esc_html_e( 'Ποσοστιαία Έκπτωση (%)', 'epappous-club' ); ?>
                                    </option>
                                </select>
                            </div>

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
                                <label for="epc_referral_require_purchase"><?php esc_html_e( 'Απαιτείται Αγορά για Ανταμοιβή', 'epappous-club' ); ?></label>
                                <label class="epc-toggle">
                                    <input type="hidden" name="epc_referral_require_purchase" value="0" />
                                    <input type="checkbox" id="epc_referral_require_purchase" name="epc_referral_require_purchase" value="1"
                                           <?php checked( EPC_Settings::get( 'epc_referral_require_purchase' ), '1' ); ?> />
                                    <span class="epc-toggle-slider"></span>
                                </label>
                                <p class="description"><?php esc_html_e( 'Αν ενεργό, η ανταμοιβή δίνεται μόνο μετά την πρώτη αγορά.', 'epappous-club' ); ?></p>
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
                        </div>
                    </div>
                </div>

                <!-- ════════ GIFTS ════════ -->
                <div class="epc-tab-panel <?php echo $active_tab === 'gifts' ? 'active' : ''; ?>" data-tab="gifts">
                    <div class="epc-card">
                        <div class="epc-card-header">
                            <h2><?php esc_html_e( 'Ρυθμίσεις Δώρων', 'epappous-club' ); ?></h2>
                            <p class="epc-card-desc"><?php esc_html_e( 'Γενικές ρυθμίσεις για τα προϊόντα-δώρα. Για διαχείριση δώρων, πηγαίνετε στο μενού Δώρα.', 'epappous-club' ); ?></p>
                        </div>
                        <div class="epc-card-body">
                            <div class="epc-field-row">
                                <label for="epc_gifts_enabled"><?php esc_html_e( 'Ενεργοποίηση Δώρων', 'epappous-club' ); ?></label>
                                <label class="epc-toggle">
                                    <input type="hidden" name="epc_gifts_enabled" value="0" />
                                    <input type="checkbox" id="epc_gifts_enabled" name="epc_gifts_enabled" value="1"
                                           <?php checked( EPC_Settings::get( 'epc_gifts_enabled' ), '1' ); ?> />
                                    <span class="epc-toggle-slider"></span>
                                </label>
                            </div>

                            <?php if ( false ) : ?>
                            <div class="epc-field-row">
                                <label for="epc_gifts_min_tier"><?php esc_html_e( 'Ελάχιστη Βαθμίδα', 'epappous-club' ); ?></label>
                                <select id="epc_gifts_min_tier" name="epc_gifts_min_tier">
                                    <?php foreach ( $tiers as $tier ) : ?>
                                        <option value="<?php echo esc_attr( $tier['slug'] ); ?>"
                                                <?php selected( EPC_Settings::get( 'epc_gifts_min_tier' ), $tier['slug'] ); ?>>
                                            <?php echo esc_html( $tier['label'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Ελάχιστη βαθμίδα μέλους για πρόσβαση στα δώρα.', 'epappous-club' ); ?></p>
                            </div>
                            <?php endif; ?>
                            <input type="hidden" name="epc_gifts_min_tier" value="<?php echo esc_attr( EPC_Settings::get( 'epc_gifts_min_tier' ) ); ?>" />

                            <div class="epc-field-row">
                                <label for="epc_gifts_per_page"><?php esc_html_e( 'Δώρα ανά Σελίδα', 'epappous-club' ); ?></label>
                                <input type="number" id="epc_gifts_per_page" name="epc_gifts_per_page"
                                       value="<?php echo esc_attr( EPC_Settings::get( 'epc_gifts_per_page' ) ); ?>"
                                       class="small-text" min="1" max="100" />
                            </div>

                            <div class="epc-field-row">
                                <label for="epc_gifts_show_stock"><?php esc_html_e( 'Εμφάνιση Αποθέματος', 'epappous-club' ); ?></label>
                                <label class="epc-toggle">
                                    <input type="hidden" name="epc_gifts_show_stock" value="0" />
                                    <input type="checkbox" id="epc_gifts_show_stock" name="epc_gifts_show_stock" value="1"
                                           <?php checked( EPC_Settings::get( 'epc_gifts_show_stock' ), '1' ); ?> />
                                    <span class="epc-toggle-slider"></span>
                                </label>
                                <p class="description"><?php esc_html_e( 'Εμφάνιση διαθέσιμου αποθέματος στα δώρα.', 'epappous-club' ); ?></p>
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

                            <?php
                            $notifications = [
                                'epc_notify_new_member'        => __( 'Νέο Μέλος', 'epappous-club' ),
                                'epc_notify_referral_complete' => __( 'Ολοκλήρωση Referral', 'epappous-club' ),
                                'epc_notify_gift_redeemed'     => __( 'Εξαργύρωση Δώρου', 'epappous-club' ),
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
