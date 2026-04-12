<?php
/**
 * My Account — Points tab template.
 *
 * Available variables:
 *   $balance, $referral_code, $referral_url,
 *   $redeem_amount, $discount_value, $coupon_cost, $coupon_value,
 *   $history, $total, $per_page, $paged, $gifts
 */
defined( 'ABSPATH' ) || exit;
?>

<div class="twonet-loyalty-account">

    <!-- Balance -->
    <div class="twonet-loyalty-balance-card">
        <h2><?php esc_html_e( 'Το υπόλοιπό σας', '2net-loyalty' ); ?></h2>
        <div class="twonet-balance-number"><?php echo esc_html( number_format_i18n( $balance ) ); ?></div>
        <span class="twonet-balance-label"><?php esc_html_e( 'πόντοι', '2net-loyalty' ); ?></span>
    </div>

    <!-- Quick actions -->
    <div class="twonet-loyalty-actions">

        <?php if ( $balance >= $redeem_amount ) : ?>
        <div class="twonet-action-card">
            <h3><?php esc_html_e( 'Εξαργύρωση για έκπτωση', '2net-loyalty' ); ?></h3>
            <p><?php printf(
                esc_html__( '%d πόντοι = %s€ έκπτωση στο καλάθι', '2net-loyalty' ),
                $redeem_amount,
                number_format( $discount_value, 2 )
            ); ?></p>
            <a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="button">
                <?php esc_html_e( 'Πήγαινε στο καλάθι', '2net-loyalty' ); ?>
            </a>
        </div>
        <?php endif; ?>

        <?php if ( $balance >= $coupon_cost ) : ?>
        <div class="twonet-action-card">
            <h3><?php esc_html_e( 'Αγορά κουπονιού', '2net-loyalty' ); ?></h3>
            <p><?php printf(
                esc_html__( '%d πόντοι = κουπόνι %s€', '2net-loyalty' ),
                $coupon_cost,
                number_format( $coupon_value, 2 )
            ); ?></p>
            <form class="twonet-buy-coupon-form" data-action="2net_buy_coupon">
                <label>
                    <?php esc_html_e( 'Ποσότητα:', '2net-loyalty' ); ?>
                    <input type="number" name="multiples" value="1" min="1"
                           max="<?php echo esc_attr( floor( $balance / $coupon_cost ) ); ?>">
                </label>
                <button type="submit" class="button"><?php esc_html_e( 'Αγορά', '2net-loyalty' ); ?></button>
            </form>
        </div>
        <?php endif; ?>

    </div>

    <!-- Available gifts -->
    <?php if ( ! empty( $gifts ) ) : ?>
    <div class="twonet-loyalty-gifts">
        <h3><?php esc_html_e( 'Διαθέσιμα δώρα', '2net-loyalty' ); ?></h3>
        <div class="twonet-gifts-grid">
            <?php foreach ( $gifts as $gift ) :
                $gift_points = absint( get_post_meta( $gift->get_id(), '_loyalty_points', true ) );
                if ( $gift_points <= 0 ) continue;
                $can_afford = $balance >= $gift_points;
            ?>
            <div class="twonet-gift-item <?php echo $can_afford ? 'affordable' : 'too-expensive'; ?>">
                <?php echo $gift->get_image( 'thumbnail' ); ?>
                <h4><?php echo esc_html( $gift->get_name() ); ?></h4>
                <span class="twonet-gift-cost"><?php echo esc_html( number_format_i18n( $gift_points ) ); ?> <?php esc_html_e( 'πόντοι', '2net-loyalty' ); ?></span>
                <?php if ( $can_afford ) : ?>
                <button class="button twonet-redeem-gift-btn" data-product-id="<?php echo esc_attr( $gift->get_id() ); ?>">
                    <?php esc_html_e( 'Εξαργύρωση', '2net-loyalty' ); ?>
                </button>
                <?php else : ?>
                <span class="twonet-not-enough"><?php esc_html_e( 'Δεν επαρκούν οι πόντοι', '2net-loyalty' ); ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Referral -->
    <?php if ( $referral_code ) : ?>
    <div class="twonet-loyalty-referral">
        <h3><?php esc_html_e( 'Σύσταση φίλου', '2net-loyalty' ); ?></h3>
        <p><?php printf(
            esc_html__( 'Μοιραστείτε τον σύνδεσμό σας και κερδίστε %d πόντους για κάθε φίλο που κάνει την πρώτη του παραγγελία!', '2net-loyalty' ),
            (int) TwoNet_Loyalty_Core::get_setting( 'referral_bonus', 400 )
        ); ?></p>
        <div class="twonet-referral-box">
            <input type="text" readonly value="<?php echo esc_url( $referral_url ); ?>" id="twonet-referral-url"
                   onclick="this.select();" />
            <button class="button twonet-copy-referral" data-target="#twonet-referral-url">
                <?php esc_html_e( 'Αντιγραφή', '2net-loyalty' ); ?>
            </button>
        </div>
        <p class="twonet-referral-code">
            <?php esc_html_e( 'Κωδικός:', '2net-loyalty' ); ?> <code><?php echo esc_html( $referral_code ); ?></code>
        </p>
    </div>
    <?php endif; ?>

    <!-- Transaction history -->
    <div class="twonet-loyalty-history">
        <h3><?php esc_html_e( 'Ιστορικό πόντων', '2net-loyalty' ); ?></h3>

        <?php if ( empty( $history ) ) : ?>
            <p><?php esc_html_e( 'Δεν υπάρχουν ακόμη κινήσεις.', '2net-loyalty' ); ?></p>
        <?php else : ?>
        <table class="woocommerce-table twonet-history-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Ημερομηνία', '2net-loyalty' ); ?></th>
                    <th><?php esc_html_e( 'Τύπος', '2net-loyalty' ); ?></th>
                    <th><?php esc_html_e( 'Περιγραφή', '2net-loyalty' ); ?></th>
                    <th><?php esc_html_e( 'Πόντοι', '2net-loyalty' ); ?></th>
                    <th><?php esc_html_e( 'Υπόλοιπο', '2net-loyalty' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $history as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $row->created_at ) ) ); ?></td>
                    <td><?php echo esc_html( TwoNet_Points_Manager::type_label( $row->type ) ); ?></td>
                    <td><?php echo esc_html( $row->description ); ?></td>
                    <td class="<?php echo $row->points >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo $row->points >= 0 ? '+' : ''; ?><?php echo esc_html( number_format_i18n( $row->points ) ); ?>
                    </td>
                    <td><?php echo esc_html( number_format_i18n( $row->balance ) ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        $total_pages = ceil( $total / $per_page );
        if ( $total_pages > 1 ) :
        ?>
        <nav class="twonet-pagination">
            <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                <?php if ( $i === $paged ) : ?>
                    <span class="current"><?php echo esc_html( $i ); ?></span>
                <?php else : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'points_page', $i ) ); ?>"><?php echo esc_html( $i ); ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
        <?php endif; ?>

        <?php endif; ?>
    </div>

</div>
