<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCAPM_Bulk_Operations {

    private static ?WCAPM_Bulk_Operations $instance = null;
    public static function instance(): self { if ( null === self::$instance ) self::$instance = new self(); return self::$instance; }

    public function init(): void {
        add_filter( 'bulk_actions-edit-product',        [ $this, 'register_bulk_actions' ] );
        add_filter( 'handle_bulk_actions-edit-product', [ $this, 'handle_bulk_actions' ], 10, 3 );
        add_action( 'admin_notices',                    [ $this, 'bulk_action_notices' ] );
        add_action( 'wp_ajax_wcapm_bulk_price_update',  [ $this, 'ajax_bulk_price_update' ] );
        add_action( 'wp_ajax_wcapm_bulk_stock_update',  [ $this, 'ajax_bulk_stock_update' ] );
    }

    public function register_bulk_actions( array $actions ): array {
        $actions['wcapm_set_sale']        = __( 'WCAPM: Set Sale Price (%)', 'wcapm' );
        $actions['wcapm_increase_price']  = __( 'WCAPM: Increase Price (%)', 'wcapm' );
        $actions['wcapm_decrease_price']  = __( 'WCAPM: Decrease Price (%)', 'wcapm' );
        $actions['wcapm_set_stock']       = __( 'WCAPM: Set Stock Quantity', 'wcapm' );
        $actions['wcapm_clear_sale']      = __( 'WCAPM: Clear Sale Prices', 'wcapm' );
        $actions['wcapm_enable_reviews']  = __( 'WCAPM: Enable Reviews', 'wcapm' );
        $actions['wcapm_disable_reviews'] = __( 'WCAPM: Disable Reviews', 'wcapm' );
        return $actions;
    }

    public function handle_bulk_actions( string $redirect_to, string $action, array $post_ids ): string {
        if ( ! str_starts_with( $action, 'wcapm_' ) ) return $redirect_to;
        if ( ! current_user_can( 'edit_products' ) ) return $redirect_to;

        $value    = (float) sanitize_text_field( $_REQUEST['wcapm_bulk_value'] ?? 0 );
        $count    = 0;

        foreach ( $post_ids as $post_id ) {
            $product = wc_get_product( $post_id );
            if ( ! $product ) continue;

            switch ( $action ) {
                case 'wcapm_increase_price':
                    $current = (float) $product->get_regular_price();
                    if ( $current > 0 ) {
                        $product->set_regular_price( round( $current * ( 1 + $value / 100 ), 2 ) );
                        $product->save();
                        $count++;
                    }
                    break;

                case 'wcapm_decrease_price':
                    $current = (float) $product->get_regular_price();
                    if ( $current > 0 ) {
                        $product->set_regular_price( round( $current * ( 1 - $value / 100 ), 2 ) );
                        $product->save();
                        $count++;
                    }
                    break;

                case 'wcapm_set_sale':
                    $regular = (float) $product->get_regular_price();
                    if ( $regular > 0 && $value > 0 && $value < 100 ) {
                        $product->set_sale_price( round( $regular * ( 1 - $value / 100 ), 2 ) );
                        $product->save();
                        $count++;
                    }
                    break;

                case 'wcapm_clear_sale':
                    $product->set_sale_price( '' );
                    $product->save();
                    $count++;
                    break;

                case 'wcapm_set_stock':
                    if ( $product->managing_stock() ) {
                        $old_qty = $product->get_stock_quantity();
                        $product->set_stock_quantity( (int) $value );
                        $product->save();
                        $this->log_inventory( $post_id, 'bulk_set', (int) $old_qty, (int) $value );
                        $count++;
                    }
                    break;

                case 'wcapm_enable_reviews':
                    update_post_meta( $post_id, 'comment_status', 'open' );
                    $count++;
                    break;

                case 'wcapm_disable_reviews':
                    update_post_meta( $post_id, 'comment_status', 'closed' );
                    $count++;
                    break;
            }
        }

        return add_query_arg( [ 'wcapm_action' => $action, 'wcapm_count' => $count ], $redirect_to );
    }

    public function bulk_action_notices(): void {
        if ( ! isset( $_GET['wcapm_count'] ) ) return;
        $count  = (int) $_GET['wcapm_count'];
        $action = sanitize_text_field( $_GET['wcapm_action'] ?? '' );
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html( sprintf( __( 'WCAPM: Updated %d product(s). Action: %s', 'wcapm' ), $count, $action ) )
        );
    }

    public function ajax_bulk_price_update(): void {
        check_ajax_referer( 'wcapm_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_products' ) ) wp_send_json_error( 'Unauthorized' );

        $product_ids = array_map( 'intval', (array) ( $_POST['product_ids'] ?? [] ) );
        $type        = sanitize_text_field( $_POST['update_type'] ?? 'fixed' );
        $value       = (float) ( $_POST['value'] ?? 0 );
        $price_type  = sanitize_text_field( $_POST['price_type'] ?? 'regular' );
        $updated     = 0;

        foreach ( $product_ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product ) continue;

            $current = (float) ( $price_type === 'sale' ? $product->get_sale_price() : $product->get_regular_price() );
            $new_price = $type === 'percent' ? round( $current * ( 1 + $value / 100 ), 2 ) : round( $current + $value, 2 );

            if ( $new_price < 0 ) continue;

            if ( $price_type === 'sale' ) {
                $product->set_sale_price( $new_price );
            } else {
                $product->set_regular_price( $new_price );
            }
            $product->save();
            $updated++;
        }

        wp_send_json_success( [ 'updated' => $updated ] );
    }

    public function ajax_bulk_stock_update(): void {
        check_ajax_referer( 'wcapm_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_products' ) ) wp_send_json_error( 'Unauthorized' );

        $product_ids = array_map( 'intval', (array) ( $_POST['product_ids'] ?? [] ) );
        $qty         = (int) ( $_POST['qty'] ?? 0 );
        $mode        = sanitize_text_field( $_POST['mode'] ?? 'set' );
        $updated     = 0;

        foreach ( $product_ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product || ! $product->managing_stock() ) continue;

            $old_qty   = (int) $product->get_stock_quantity();
            $new_qty   = match ( $mode ) {
                'add'      => $old_qty + $qty,
                'subtract' => max( 0, $old_qty - $qty ),
                default    => $qty,
            };

            $product->set_stock_quantity( $new_qty );
            $product->save();
            $this->log_inventory( $id, "bulk_{$mode}", $old_qty, $new_qty );
            $updated++;
        }

        wp_send_json_success( [ 'updated' => $updated ] );
    }

    private function log_inventory( int $product_id, string $action, int $qty_before, int $qty_after, string $note = '' ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'wcapm_inventory_log', [
            'product_id' => $product_id,
            'user_id'    => get_current_user_id(),
            'action'     => $action,
            'qty_before' => $qty_before,
            'qty_after'  => $qty_after,
            'note'       => $note,
            'created_at' => current_time( 'mysql' ),
        ] );
    }
}
