<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$rules = EPC_Gift_Rules::get_all();
$tiers_json = EPC_Settings::get( 'epc_tiers' );
$tiers = json_decode( $tiers_json, true ) ?: [];

$type_labels = [
    'product'  => __( 'Προϊόν', 'epappous-club' ),
    'category' => __( 'Κατηγορία', 'epappous-club' ),
    'tag'      => __( 'Tag', 'epappous-club' ),
];

$type_icons = [
    'product'  => 'dashicons-archive',
    'category' => 'dashicons-category',
    'tag'      => 'dashicons-tag',
];

// Get WC categories and tags for dropdowns
$wc_categories = [];
$wc_tags = [];
if ( taxonomy_exists( 'product_cat' ) ) {
    $wc_categories = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
    if ( is_wp_error( $wc_categories ) ) {
        $wc_categories = [];
    }
}
if ( taxonomy_exists( 'product_tag' ) ) {
    $wc_tags = get_terms( [ 'taxonomy' => 'product_tag', 'hide_empty' => false ] );
    if ( is_wp_error( $wc_tags ) ) {
        $wc_tags = [];
    }
}
?>
<div class="wrap epc-wrap">
    <div class="epc-header">
        <h1>
            <span class="dashicons dashicons-cart"></span>
            <?php esc_html_e( 'Κανόνες Δώρων', 'epappous-club' ); ?>
        </h1>
        <button type="button" class="button" id="epc-add-rule-btn">
            <span class="dashicons dashicons-plus-alt"></span>
            <?php esc_html_e( 'Νέος Κανόνας', 'epappous-club' ); ?>
        </button>
    </div>

    <div class="epc-info-box">
        <span class="dashicons dashicons-info-outline"></span>
        <div>
            <?php esc_html_e( 'Πρόσθεσε κανόνες για να ορίσεις ποια WooCommerce προϊόντα είναι διαθέσιμα ως δώρα. Μπορείς να προσθέσεις μεμονωμένα προϊόντα, ολόκληρες κατηγορίες, ή tags.', 'epappous-club' ); ?>
        </div>
    </div>

    <?php if ( empty( $rules ) ) : ?>
        <div class="epc-empty-state">
            <span class="dashicons dashicons-cart"></span>
            <h3><?php esc_html_e( 'Δεν υπάρχουν κανόνες δώρων', 'epappous-club' ); ?></h3>
            <p><?php esc_html_e( 'Πρόσθεσε τον πρώτο κανόνα για να ορίσεις δώρα.', 'epappous-club' ); ?></p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped epc-table">
            <thead>
                <tr>
                    <th style="width:50px;"><?php esc_html_e( 'ID', 'epappous-club' ); ?></th>
                    <th style="width:100px;"><?php esc_html_e( 'Τύπος', 'epappous-club' ); ?></th>
                    <th><?php esc_html_e( 'Τιμή', 'epappous-club' ); ?></th>
                    <th style="width:100px;"><?php esc_html_e( 'Πόντοι', 'epappous-club' ); ?></th>
                    <th style="width:80px;"><?php esc_html_e( 'Ενεργό', 'epappous-club' ); ?></th>
                    <th style="width:100px;"><?php esc_html_e( 'Ενέργειες', 'epappous-club' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rules as $rule ) : ?>
                    <tr class="<?php echo $rule['is_active'] ? '' : 'epc-row-inactive'; ?>">
                        <td><?php echo (int) $rule['id']; ?></td>
                        <td>
                            <span class="epc-rule-type-badge epc-rule-type-<?php echo esc_attr( $rule['rule_type'] ); ?>">
                                <span class="dashicons <?php echo esc_attr( $type_icons[ $rule['rule_type'] ] ?? 'dashicons-info' ); ?>"></span>
                                <?php echo esc_html( $type_labels[ $rule['rule_type'] ] ?? $rule['rule_type'] ); ?>
                            </span>
                        </td>
                        <td>
                            <strong><?php echo esc_html( EPC_Gift_Rules::get_rule_label( $rule ) ); ?></strong>
                            <?php if ( $rule['rule_type'] !== 'product' ) : ?>
                                <br /><small style="color:#9ca3af;">
                                    <?php
                                    $count = count( $rule['rule_type'] === 'category'
                                        ? get_posts( [ 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'tax_query' => [ [ 'taxonomy' => 'product_cat', 'terms' => (int) $rule['rule_value'] ] ] ] )
                                        : get_posts( [ 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'tax_query' => [ [ 'taxonomy' => 'product_tag', 'terms' => (int) $rule['rule_value'] ] ] ] )
                                    );
                                    printf( esc_html__( '%d προϊόντα', 'epappous-club' ), $count );
                                    ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo esc_html( EPC_Settings::get( 'epc_currency_symbol' ) . ' ' . number_format( (int) $rule['points_required'] ) ); ?></strong>
                        </td>
                        <?php /* Tier column hidden — tier_required still in DB.
                        <td>
                            <span class="epc-gift-tier epc-tier-<?php echo esc_attr( $rule['tier_required'] ); ?>">
                                <?php echo esc_html( ucfirst( $rule['tier_required'] ) ); ?>
                            </span>
                        </td>
                        */ ?>
                        <td>
                            <?php if ( $rule['is_active'] ) : ?>
                                <span style="color:#10b981;font-weight:600;">&#10003;</span>
                            <?php else : ?>
                                <span style="color:#ef4444;">&#10007;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="button epc-toggle-rule-btn" data-id="<?php echo (int) $rule['id']; ?>" title="<?php esc_attr_e( 'On/Off', 'epappous-club' ); ?>">
                                <span class="dashicons dashicons-<?php echo $rule['is_active'] ? 'hidden' : 'visibility'; ?>"></span>
                            </button>
                            <button type="button" class="button epc-delete-rule-btn" data-id="<?php echo (int) $rule['id']; ?>" title="<?php esc_attr_e( 'Διαγραφή', 'epappous-club' ); ?>">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Rule Modal -->
<div id="epc-gift-rule-modal" class="epc-modal" style="display:none;">
    <div class="epc-modal-overlay"></div>
    <div class="epc-modal-content">
        <div class="epc-modal-header">
            <h2><?php esc_html_e( 'Νέος Κανόνας Δώρου', 'epappous-club' ); ?></h2>
            <button type="button" class="epc-modal-close">&times;</button>
        </div>
        <form id="epc-rule-form">
            <input type="hidden" name="id" id="epc-rule-id" value="" />
            <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'epc_admin_nonce' ) ); ?>" />

            <div class="epc-modal-body">
                <div class="epc-field-row">
                    <label for="epc-rule-type"><?php esc_html_e( 'Τύπος', 'epappous-club' ); ?></label>
                    <select id="epc-rule-type" name="rule_type">
                        <option value="product"><?php esc_html_e( 'Μεμονωμένο Προϊόν', 'epappous-club' ); ?></option>
                        <option value="category"><?php esc_html_e( 'Κατηγορία Προϊόντων', 'epappous-club' ); ?></option>
                        <option value="tag"><?php esc_html_e( 'Tag Προϊόντων', 'epappous-club' ); ?></option>
                    </select>
                </div>

                <!-- Product search (native, no Select2 dependency) -->
                <div class="epc-field-row epc-rule-value-group" id="epc-rule-value-product">
                    <label><?php esc_html_e( 'Προϊόν', 'epappous-club' ); ?></label>
                    <div style="position:relative;">
                        <input type="text" id="epc-product-search-input" autocomplete="off"
                               placeholder="<?php esc_attr_e( 'Πληκτρολόγησε όνομα προϊόντος...', 'epappous-club' ); ?>"
                               style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:8px 12px;font-size:14px;" />
                        <input type="hidden" id="epc-product-search-value" name="rule_value_product" value="" />
                        <div id="epc-product-search-results" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:100;background:#fff;border:1px solid #d1d5db;border-radius:0 0 6px 6px;max-height:200px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,0.1);"></div>
                    </div>
                </div>

                <!-- Category select -->
                <div class="epc-field-row epc-rule-value-group" id="epc-rule-value-category" style="display:none;">
                    <label><?php esc_html_e( 'Κατηγορία', 'epappous-club' ); ?></label>
                    <select name="rule_value_category">
                        <option value=""><?php esc_html_e( '— Επιλέξτε —', 'epappous-club' ); ?></option>
                        <?php foreach ( $wc_categories as $cat ) : ?>
                            <option value="<?php echo (int) $cat->term_id; ?>">
                                <?php echo esc_html( $cat->name ); ?> (<?php echo (int) $cat->count; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Tag select -->
                <div class="epc-field-row epc-rule-value-group" id="epc-rule-value-tag" style="display:none;">
                    <label><?php esc_html_e( 'Tag', 'epappous-club' ); ?></label>
                    <select name="rule_value_tag">
                        <option value=""><?php esc_html_e( '— Επιλέξτε —', 'epappous-club' ); ?></option>
                        <?php foreach ( $wc_tags as $tag ) : ?>
                            <option value="<?php echo (int) $tag->term_id; ?>">
                                <?php echo esc_html( $tag->name ); ?> (<?php echo (int) $tag->count; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Hidden field that gets set by JS before submit -->
                <input type="hidden" name="rule_value" id="epc-rule-value-hidden" value="" />

                <div class="epc-field-row">
                    <label for="epc-rule-points"><?php esc_html_e( 'Πόντοι Εξαργύρωσης', 'epappous-club' ); ?></label>
                    <input type="number" id="epc-rule-points" name="points_required" min="0" class="small-text" value="0" />
                </div>

                <?php if ( false ) : ?>
                <div class="epc-field-row">
                    <label for="epc-rule-tier"><?php esc_html_e( 'Ελάχιστη Βαθμίδα', 'epappous-club' ); ?></label>
                    <select id="epc-rule-tier" name="tier_required">
                        <?php foreach ( $tiers as $tier ) : ?>
                            <option value="<?php echo esc_attr( $tier['slug'] ); ?>">
                                <?php echo esc_html( $tier['label'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <input type="hidden" name="tier_required" value="basic" id="epc-rule-tier" />
            </div>

            <div class="epc-modal-footer">
                <button type="button" class="button epc-modal-close-btn"><?php esc_html_e( 'Ακύρωση', 'epappous-club' ); ?></button>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'epappous-club' ); ?></button>
            </div>
        </form>
    </div>
</div>

