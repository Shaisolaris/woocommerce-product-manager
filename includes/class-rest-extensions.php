<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCAPM_REST_Extensions {

    private static ?WCAPM_REST_Extensions $instance = null;
    public static function instance(): self { if ( null === self::$instance ) self::$instance = new self(); return self::$instance; }

    public function init(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        add_filter( 'woocommerce_rest_prepare_product_object', [ $this, 'add_custom_fields_to_response' ], 10, 3 );
    }

    public function register_routes(): void {
        $ns = 'wcapm/v1';

        register_rest_route( $ns, '/bulk-price', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'bulk_price_update' ],
            'permission_callback' => fn() => current_user_can( 'edit_products' ),
            'args'                => [
                'product_ids' => [ 'required' => true, 'type' => 'array' ],
                'type'        => [ 'required' => true, 'enum' => [ 'fixed', 'percent' ] ],
                'value'       => [ 'required' => true, 'type' => 'number' ],
                'price_type'  => [ 'default' => 'regular', 'enum' => [ 'regular', 'sale' ] ],
            ],
        ] );

        register_rest_route( $ns, '/inventory', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_inventory_summary' ],
            'permission_callback' => fn() => current_user_can( 'edit_products' ),
        ] );

        register_rest_route( $ns, '/inventory/(?P<id>[\d]+)/log', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_inventory_log' ],
            'permission_callback' => fn() => current_user_can( 'edit_products' ),
        ] );

        register_rest_route( $ns, '/inventory/(?P<id>[\d]+)/adjust', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'adjust_inventory' ],
            'permission_callback' => fn() => current_user_can( 'edit_products' ),
            'args'                => [
                'qty'  => [ 'required' => true, 'type' => 'integer' ],
                'note' => [ 'default' => '', 'type' => 'string' ],
            ],
        ] );

        register_rest_route( $ns, '/reports/sales', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_sales_report' ],
            'permission_callback' => fn() => current_user_can( 'view_woocommerce_reports' ),
            'args'                => [
                'period' => [ 'default' => '30days', 'enum' => [ '7days', '30days', '90days', 'year' ] ],
            ],
        ] );

        register_rest_route( $ns, '/custom-fields', [
            [ 'methods' => WP_REST_Server::READABLE,  'callback' => [ $this, 'get_custom_fields' ],  'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ) ],
            [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'create_custom_field' ], 'permission_callback' => fn() => current_user_can( 'manage_woocommerce' ) ],
        ] );
    }

    public function bulk_price_update( WP_REST_Request $request ): WP_REST_Response {
        $product_ids = array_map( 'intval', $request->get_param( 'product_ids' ) );
        $type        = $request->get_param( 'type' );
        $value       = (float) $request->get_param( 'value' );
        $price_type  = $request->get_param( 'price_type' );
        $updated     = [];

        foreach ( $product_ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product ) continue;

            $current   = (float) ( $price_type === 'sale' ? $product->get_sale_price() : $product->get_regular_price() );
            $new_price = $type === 'percent' ? round( $current * ( 1 + $value / 100 ), 2 ) : round( $current + $value, 2 );
            if ( $new_price < 0 ) continue;

            $price_type === 'sale' ? $product->set_sale_price( $new_price ) : $product->set_regular_price( $new_price );
            $product->save();
            $updated[] = [ 'id' => $id, 'old_price' => $current, 'new_price' => $new_price ];
        }

        return new WP_REST_Response( [ 'updated' => count( $updated ), 'products' => $updated ], 200 );
    }

    public function get_inventory_summary(): WP_REST_Response {
        $args  = [ 'post_type' => 'product', 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids' ];
        $ids   = get_posts( $args );
        $low   = [];
        $out   = [];
        $total = 0;

        foreach ( $ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product || ! $product->managing_stock() ) continue;

            $qty   = (int) $product->get_stock_quantity();
            $total += $qty;

            if ( $qty <= 0 ) {
                $out[] = [ 'id' => $id, 'name' => $product->get_name(), 'qty' => $qty ];
            } elseif ( $qty <= (int) get_post_meta( $id, '_wcapm_reorder_point', true ) ) {
                $low[] = [ 'id' => $id, 'name' => $product->get_name(), 'qty' => $qty, 'reorder_point' => get_post_meta( $id, '_wcapm_reorder_point', true ) ];
            }
        }

        return new WP_REST_Response( [
            'total_stock_units' => $total,
            'out_of_stock'      => $out,
            'low_stock'         => $low,
        ], 200 );
    }

    public function get_inventory_log( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.*, u.display_name FROM {$wpdb->prefix}wcapm_inventory_log l LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID WHERE l.product_id = %d ORDER BY l.created_at DESC LIMIT 100",
                (int) $request['id']
            )
        );
        return new WP_REST_Response( $logs, 200 );
    }

    public function adjust_inventory( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $product = wc_get_product( (int) $request['id'] );
        if ( ! $product ) return new WP_Error( 'not_found', 'Product not found', [ 'status' => 404 ] );

        $old_qty = (int) $product->get_stock_quantity();
        $new_qty = $old_qty + (int) $request->get_param( 'qty' );
        $product->set_stock_quantity( $new_qty );
        $product->save();

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'wcapm_inventory_log', [
            'product_id' => $product->get_id(),
            'user_id'    => get_current_user_id(),
            'action'     => 'api_adjust',
            'qty_before' => $old_qty,
            'qty_after'  => $new_qty,
            'note'       => sanitize_text_field( $request->get_param( 'note' ) ),
            'created_at' => current_time( 'mysql' ),
        ] );

        return new WP_REST_Response( [ 'old_qty' => $old_qty, 'new_qty' => $new_qty ], 200 );
    }

    public function get_sales_report( WP_REST_Request $request ): WP_REST_Response {
        $period = $request->get_param( 'period' );
        $days   = match ( $period ) { '7days' => 7, '90days' => 90, 'year' => 365, default => 30 };
        $after  = date( 'Y-m-d', strtotime( "-{$days} days" ) );

        $orders = wc_get_orders( [
            'status'       => [ 'wc-completed', 'wc-processing' ],
            'date_created' => ">={$after}",
            'limit'        => -1,
        ] );

        $revenue       = 0.0;
        $order_count   = count( $orders );
        $product_sales = [];

        foreach ( $orders as $order ) {
            $revenue += (float) $order->get_total();
            foreach ( $order->get_items() as $item ) {
                $pid = $item->get_product_id();
                $product_sales[ $pid ] = ( $product_sales[ $pid ] ?? 0 ) + $item->get_quantity();
            }
        }

        arsort( $product_sales );
        $top_products = array_slice(
            array_map( fn( $id, $qty ) => [ 'id' => $id, 'name' => get_the_title( $id ), 'qty_sold' => $qty ], array_keys( $product_sales ), $product_sales ),
            0, 10, true
        );

        return new WP_REST_Response( [
            'period'       => $period,
            'orders'       => $order_count,
            'revenue'      => round( $revenue, 2 ),
            'avg_order'    => $order_count ? round( $revenue / $order_count, 2 ) : 0,
            'top_products' => array_values( $top_products ),
        ], 200 );
    }

    public function get_custom_fields(): WP_REST_Response {
        global $wpdb;
        return new WP_REST_Response( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wcapm_custom_fields ORDER BY position" ), 200 );
    }

    public function create_custom_field( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;
        $key = sanitize_key( $request->get_param( 'field_key' ) );
        if ( ! $key ) return new WP_Error( 'invalid', 'field_key required', [ 'status' => 400 ] );

        $wpdb->insert( $wpdb->prefix . 'wcapm_custom_fields', [
            'field_key'   => $key,
            'field_label' => sanitize_text_field( $request->get_param( 'field_label' ) ),
            'field_type'  => sanitize_text_field( $request->get_param( 'field_type' ) ?: 'text' ),
            'field_opts'  => sanitize_text_field( $request->get_param( 'field_opts' ) ),
            'position'    => (int) $request->get_param( 'position' ),
            'is_required' => (int) (bool) $request->get_param( 'is_required' ),
        ] );

        return new WP_REST_Response( [ 'id' => $wpdb->insert_id, 'field_key' => $key ], 201 );
    }

    public function add_custom_fields_to_response( WP_REST_Response $response, WC_Product $product ): WP_REST_Response {
        global $wpdb;
        $fields = $wpdb->get_results( "SELECT field_key FROM {$wpdb->prefix}wcapm_custom_fields" );
        $custom = [];
        foreach ( $fields as $field ) {
            $custom[ $field->field_key ] = get_post_meta( $product->get_id(), "_wcapm_{$field->field_key}", true );
        }
        $data            = $response->get_data();
        $data['wcapm']   = $custom;
        $response->set_data( $data );
        return $response;
    }
}
