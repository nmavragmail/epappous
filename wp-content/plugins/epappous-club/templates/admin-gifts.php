<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$gifts = EPC_Gifts::get_all();
$tiers_json = EPC_Settings::get( 'epc_tiers' );
$tiers = json_decode( $tiers_json, true ) ?: [];
?>
<div class="wrap epc-wrap">
    <div class="epc-header">
        <h1>
            <span class="dashicons dashicons-cart"></span>
            <?php esc_html_e( 'Διαχείριση Δώρων', 'epappous-club' ); ?>
        </h1>
        <button type="button" class="button button-primary" id="epc-add-gift-btn">
            <span class="dashicons dashicons-plus-alt"></span>
            <?php esc_html_e( 'Νέο Δώρο', 'epappous-club' ); ?>
        </button>
    </div>

    <p class="epc-info-box">
        <span class="dashicons dashicons-info-outline"></span>
        <?php esc_html_e( 'Τα δώρα είναι προϊόντα που τα μέλη μπορούν να εξαργυρώσουν χρησιμοποιώντας τους πόντους τους. Μπορείτε να τα συνδέσετε με WooCommerce products ή να δημιουργήσετε αυτόνομα δώρα.', 'epappous-club' ); ?>
    </p>

    <?php if ( empty( $gifts ) ) : ?>
        <div class="epc-empty-state">
            <span class="dashicons dashicons-cart"></span>
            <h3><?php esc_html_e( 'Δεν υπάρχουν δώρα ακόμα', 'epappous-club' ); ?></h3>
            <p><?php esc_html_e( 'Προσθέστε το πρώτο δώρο για τα μέλη σας.', 'epappous-club' ); ?></p>
        </div>
    <?php else : ?>
        <div class="epc-gifts-grid">
            <?php foreach ( $gifts as $gift ) : ?>
                <div class="epc-gift-card <?php echo $gift['is_active'] ? '' : 'epc-gift-inactive'; ?>"
                     data-gift-id="<?php echo (int) $gift['id']; ?>">
                    <?php if ( ! empty( $gift['image_url'] ) ) : ?>
                        <div class="epc-gift-image">
                            <img src="<?php echo esc_url( $gift['image_url'] ); ?>"
                                 alt="<?php echo esc_attr( $gift['title'] ); ?>" />
                        </div>
                    <?php else : ?>
                        <div class="epc-gift-image epc-gift-no-image">
                            <span class="dashicons dashicons-format-image"></span>
                        </div>
                    <?php endif; ?>

                    <div class="epc-gift-body">
                        <h3><?php echo esc_html( $gift['title'] ); ?></h3>
                        <p class="epc-gift-desc"><?php echo esc_html( wp_trim_words( $gift['description'], 15 ) ); ?></p>

                        <div class="epc-gift-meta">
                            <span class="epc-gift-points">
                                <?php echo esc_html( EPC_Settings::get( 'epc_currency_symbol' ) ); ?>
                                <?php echo esc_html( number_format( $gift['points_required'] ) ); ?>
                            </span>
                            <span class="epc-gift-tier epc-tier-<?php echo esc_attr( $gift['tier_required'] ); ?>">
                                <?php echo esc_html( ucfirst( $gift['tier_required'] ) ); ?>
                            </span>
                            <?php if ( (int) $gift['stock'] >= 0 ) : ?>
                                <span class="epc-gift-stock <?php echo (int) $gift['stock'] === 0 ? 'out-of-stock' : ''; ?>">
                                    <?php
                                    if ( (int) $gift['stock'] === 0 ) {
                                        esc_html_e( 'Εξαντλήθηκε', 'epappous-club' );
                                    } else {
                                        printf( esc_html__( 'Απόθεμα: %d', 'epappous-club' ), (int) $gift['stock'] );
                                    }
                                    ?>
                                </span>
                            <?php else : ?>
                                <span class="epc-gift-stock unlimited"><?php esc_html_e( 'Απεριόριστο', 'epappous-club' ); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="epc-gift-actions">
                            <button type="button" class="button epc-edit-gift"
                                    data-gift='<?php echo esc_attr( wp_json_encode( $gift ) ); ?>'>
                                <span class="dashicons dashicons-edit"></span>
                            </button>
                            <button type="button" class="button epc-toggle-gift-btn"
                                    data-id="<?php echo (int) $gift['id']; ?>">
                                <span class="dashicons dashicons-<?php echo $gift['is_active'] ? 'hidden' : 'visibility'; ?>"></span>
                            </button>
                            <button type="button" class="button epc-delete-gift-btn"
                                    data-id="<?php echo (int) $gift['id']; ?>">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Gift Modal -->
<div id="epc-gift-modal" class="epc-modal" style="display:none;">
    <div class="epc-modal-overlay"></div>
    <div class="epc-modal-content">
        <div class="epc-modal-header">
            <h2 id="epc-gift-modal-title"><?php esc_html_e( 'Νέο Δώρο', 'epappous-club' ); ?></h2>
            <button type="button" class="epc-modal-close">&times;</button>
        </div>
        <form id="epc-gift-form">
            <input type="hidden" name="id" id="epc-gift-id" value="" />
            <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'epc_admin_nonce' ) ); ?>" />

            <div class="epc-modal-body">
                <div class="epc-field-row">
                    <label for="epc-gift-title"><?php esc_html_e( 'Τίτλος', 'epappous-club' ); ?></label>
                    <input type="text" id="epc-gift-title" name="title" class="regular-text" required />
                </div>

                <div class="epc-field-row">
                    <label for="epc-gift-description"><?php esc_html_e( 'Περιγραφή', 'epappous-club' ); ?></label>
                    <textarea id="epc-gift-description" name="description" rows="3" class="large-text"></textarea>
                </div>

                <div class="epc-field-row-inline">
                    <div>
                        <label for="epc-gift-points"><?php esc_html_e( 'Πόντοι', 'epappous-club' ); ?></label>
                        <input type="number" id="epc-gift-points" name="points_required" min="0" class="small-text" />
                    </div>
                    <div>
                        <label for="epc-gift-stock"><?php esc_html_e( 'Απόθεμα', 'epappous-club' ); ?></label>
                        <input type="number" id="epc-gift-stock" name="stock" min="-1" class="small-text" value="-1" />
                        <p class="description"><?php esc_html_e( '-1 = Απεριόριστο', 'epappous-club' ); ?></p>
                    </div>
                </div>

                <div class="epc-field-row-inline">
                    <div>
                        <label for="epc-gift-tier"><?php esc_html_e( 'Ελάχιστη Βαθμίδα', 'epappous-club' ); ?></label>
                        <select id="epc-gift-tier" name="tier_required">
                            <?php foreach ( $tiers as $tier ) : ?>
                                <option value="<?php echo esc_attr( $tier['slug'] ); ?>">
                                    <?php echo esc_html( $tier['label'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="epc-gift-product-id"><?php esc_html_e( 'WooCommerce Product ID', 'epappous-club' ); ?></label>
                        <input type="number" id="epc-gift-product-id" name="product_id" min="0" class="small-text" />
                        <p class="description"><?php esc_html_e( 'Προαιρετικό', 'epappous-club' ); ?></p>
                    </div>
                </div>

                <div class="epc-field-row">
                    <label for="epc-gift-image"><?php esc_html_e( 'URL Εικόνας', 'epappous-club' ); ?></label>
                    <div class="epc-image-field">
                        <input type="text" id="epc-gift-image" name="image_url" class="regular-text" />
                        <button type="button" class="button epc-upload-image"><?php esc_html_e( 'Επιλογή', 'epappous-club' ); ?></button>
                    </div>
                </div>

                <div class="epc-field-row">
                    <label for="epc-gift-active"><?php esc_html_e( 'Ενεργό', 'epappous-club' ); ?></label>
                    <label class="epc-toggle">
                        <input type="hidden" name="is_active" value="0" />
                        <input type="checkbox" id="epc-gift-active" name="is_active" value="1" checked />
                        <span class="epc-toggle-slider"></span>
                    </label>
                </div>
            </div>

            <div class="epc-modal-footer">
                <button type="button" class="button epc-modal-close-btn"><?php esc_html_e( 'Ακύρωση', 'epappous-club' ); ?></button>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'epappous-club' ); ?></button>
            </div>
        </form>
    </div>
</div>
