<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCAPM_Reports {

    private static ?WCAPM_Reports $instance = null;
    public static function instance(): self { if ( null === self::$instance ) self::$instance = new self(); return self::$instance; }

    public function get_sales_by_period( int $days = 30 ): array {
        $after  = date( 'Y-m-d', strtotime( "-{$days} days" ) );
        $orders = wc_get_orders( [
            'status'       => [ 'wc-completed', 'wc-processing' ],
            'date_created' => ">={$after}",
            'limit'        => -1,
        ] );

        $daily = [];
        foreach ( $orders as $order ) {
            $date          = $order->get_date_created()->date( 'Y-m-d' );
            $daily[ $date ] = ( $daily[ $date ] ?? 0 ) + (float) $order->get_total();
        }
        ksort( $daily );
        return $daily;
    }

    public function get_top_products( int $limit = 10, int $days = 30 ): array {
        $after  = date( 'Y-m-d', strtotime( "-{$days} days" ) );
        $orders = wc_get_orders( [
            'status'       => [ 'wc-completed', 'wc-processing' ],
            'date_created' => ">={$after}",
            'limit'        => -1,
        ] );

        $product_data = [];
        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $pid = $item->get_product_id();
                if ( ! isset( $product_data[ $pid ] ) ) {
                    $product_data[ $pid ] = [ 'qty' => 0, 'revenue' => 0.0 ];
                }
                $product_data[ $pid ]['qty']     += $item->get_quantity();
                $product_data[ $pid ]['revenue'] += (float) $item->get_total();
            }
        }

        uasort( $product_data, fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] );
        $top = array_slice( $product_data, 0, $limit, true );

        return array_map( fn( $id, $data ) => [
            'id'      => $id,
            'name'    => get_the_title( $id ),
            'qty'     => $data['qty'],
            'revenue' => round( $data['revenue'], 2 ),
        ], array_keys( $top ), $top );
    }
}

class WCAPM_Import_Export {

    private static ?WCAPM_Import_Export $instance = null;
    public static function instance(): self { if ( null === self::$instance ) self::$instance = new self(); return self::$instance; }

    public function init(): void {
        add_action( 'admin_post_wcapm_export_products', [ $this, 'export_products' ] );
        add_action( 'admin_post_wcapm_export_inventory', [ $this, 'export_inventory_log' ] );
        add_action( 'wp_ajax_wcapm_import_products',    [ $this, 'ajax_import_products' ] );
    }

    public function export_products(): void {
        check_admin_referer( 'wcapm_export' );
        if ( ! current_user_can( 'edit_products' ) ) wp_die( 'Unauthorized' );

        $products = wc_get_products( [ 'limit' => -1, 'status' => 'publish' ] );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=products-' . date( 'Y-m-d' ) . '.csv' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'ID', 'SKU', 'Name', 'Type', 'Status', 'Regular Price', 'Sale Price', 'Stock Qty', 'Stock Status', 'Categories', 'Tags', 'Description' ] );

        foreach ( $products as $product ) {
            $cats = implode( '|', wp_list_pluck( wc_get_product_terms( $product->get_id(), 'product_cat' ), 'name' ) );
            $tags = implode( '|', wp_list_pluck( wc_get_product_terms( $product->get_id(), 'product_tag' ), 'name' ) );
            fputcsv( $out, [
                $product->get_id(),
                $product->get_sku(),
                $product->get_name(),
                $product->get_type(),
                $product->get_status(),
                $product->get_regular_price(),
                $product->get_sale_price(),
                $product->get_stock_quantity(),
                $product->get_stock_status(),
                $cats,
                $tags,
                wp_strip_all_tags( $product->get_description() ),
            ] );
        }
        fclose( $out );
        exit;
    }

    public function export_inventory_log(): void {
        check_admin_referer( 'wcapm_export' );
        if ( ! current_user_can( 'edit_products' ) ) wp_die( 'Unauthorized' );

        global $wpdb;
        $logs = $wpdb->get_results(
            "SELECT l.*, p.post_title AS product_name, u.display_name AS user_name 
             FROM {$wpdb->prefix}wcapm_inventory_log l 
             LEFT JOIN {$wpdb->posts} p ON l.product_id = p.ID 
             LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID 
             ORDER BY l.created_at DESC"
        );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=inventory-log-' . date( 'Y-m-d' ) . '.csv' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'ID', 'Product', 'User', 'Action', 'Qty Before', 'Qty After', 'Change', 'Note', 'Date' ] );

        foreach ( $logs as $log ) {
            fputcsv( $out, [
                $log->id,
                $log->product_name,
                $log->user_name,
                $log->action,
                $log->qty_before,
                $log->qty_after,
                $log->qty_after - $log->qty_before,
                $log->note,
                $log->created_at,
            ] );
        }
        fclose( $out );
        exit;
    }

    public function ajax_import_products(): void {
        check_ajax_referer( 'wcapm_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_products' ) ) wp_send_json_error( 'Unauthorized' );

        if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
            wp_send_json_error( 'No file uploaded' );
        }

        $file    = $_FILES['csv_file']['tmp_name'];
        $handle  = fopen( $file, 'r' );
        $headers = fgetcsv( $handle );
        $created = $updated = $errors = 0;

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( count( $row ) < count( $headers ) ) { $errors++; continue; }
            $data = array_combine( $headers, $row );

            $product_id = wc_get_product_id_by_sku( $data['SKU'] ?? '' );

            if ( $product_id ) {
                $product = wc_get_product( $product_id );
                $updated++;
            } else {
                $product = new WC_Product_Simple();
                $created++;
            }

            if ( ! empty( $data['Name'] ) ) $product->set_name( sanitize_text_field( $data['Name'] ) );
            if ( ! empty( $data['SKU'] ) )  $product->set_sku( sanitize_text_field( $data['SKU'] ) );
            if ( isset( $data['Regular Price'] ) ) $product->set_regular_price( wc_format_decimal( $data['Regular Price'] ) );
            if ( isset( $data['Sale Price'] ) && $data['Sale Price'] !== '' ) $product->set_sale_price( wc_format_decimal( $data['Sale Price'] ) );
            if ( isset( $data['Stock Qty'] ) && is_numeric( $data['Stock Qty'] ) ) {
                $product->set_manage_stock( true );
                $product->set_stock_quantity( (int) $data['Stock Qty'] );
            }

            $product->save();
        }

        fclose( $handle );
        wp_send_json_success( [ 'created' => $created, 'updated' => $updated, 'errors' => $errors ] );
    }
}
