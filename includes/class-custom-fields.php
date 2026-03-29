<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* -----------------------------------------------------------------------
 * Custom Product Fields
 * --------------------------------------------------------------------- */
class WCAPM_Custom_Fields {

    private static ?WCAPM_Custom_Fields $instance = null;
    public static function instance(): self { if ( null === self::$instance ) self::$instance = new self(); return self::$instance; }

    public function init(): void {
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'render_custom_fields' ] );
        add_action( 'woocommerce_process_product_meta',                 [ $this, 'save_custom_fields' ] );
        add_action( 'woocommerce_single_product_summary',               [ $this, 'display_on_product_page' ], 25 );
        add_filter( 'woocommerce_available_variation',                  [ $this, 'add_fields_to_variation_data' ], 10, 3 );
    }

    private function get_fields(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wcapm_custom_fields ORDER BY position ASC" ) ?: [];
    }

    public function render_custom_fields(): void {
        global $post;
        $fields = $this->get_fields();
        if ( ! $fields ) return;

        echo '<div class="options_group wcapm-custom-fields">';
        echo '<h4 style="padding:10px 12px;margin:0;background:#f8f8f8;border-top:1px solid #eee;">' . esc_html__( 'Custom Product Fields', 'wcapm' ) . '</h4>';

        foreach ( $fields as $field ) {
            $value = get_post_meta( $post->ID, "_wcapm_{$field->field_key}", true );
            switch ( $field->field_type ) {
                case 'textarea':
                    woocommerce_wp_textarea_input( [
                        'id'    => "_wcapm_{$field->field_key}",
                        'label' => $field->field_label,
                        'value' => $value,
                    ] );
                    break;
                case 'select':
                    $opts = array_map( 'trim', explode( ',', $field->field_opts ?? '' ) );
                    woocommerce_wp_select( [
                        'id'      => "_wcapm_{$field->field_key}",
                        'label'   => $field->field_label,
                        'value'   => $value,
                        'options' => array_combine( $opts, $opts ),
                    ] );
                    break;
                case 'checkbox':
                    woocommerce_wp_checkbox( [
                        'id'    => "_wcapm_{$field->field_key}",
                        'label' => $field->field_label,
                        'value' => $value,
                    ] );
                    break;
                default:
                    woocommerce_wp_text_input( [
                        'id'    => "_wcapm_{$field->field_key}",
                        'label' => $field->field_label,
                        'value' => $value,
                        'type'  => $field->field_type,
                    ] );
            }
        }
        echo '</div>';
    }

    public function save_custom_fields( int $product_id ): void {
        $fields = $this->get_fields();
        foreach ( $fields as $field ) {
            $key = "_wcapm_{$field->field_key}";
            if ( isset( $_POST[ $key ] ) ) {
                update_post_meta( $product_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
            } elseif ( $field->field_type === 'checkbox' ) {
                delete_post_meta( $product_id, $key );
            }
        }
    }

    public function display_on_product_page(): void {
        global $post;
        $fields = $this->get_fields();
        $output = '';
        foreach ( $fields as $field ) {
            $value = get_post_meta( $post->ID, "_wcapm_{$field->field_key}", true );
            if ( $value ) {
                $output .= '<tr><th>' . esc_html( $field->field_label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
            }
        }
        if ( $output ) {
            echo '<table class="wcapm-product-fields woocommerce-product-attributes"><tbody>' . wp_kses_post( $output ) . '</tbody></table>';
        }
    }

    public function add_fields_to_variation_data( array $data, \WC_Product $product, \WC_Product_Variation $variation ): array {
        $fields = $this->get_fields();
        foreach ( $fields as $field ) {
            $key          = "_wcapm_{$field->field_key}";
            $data[ $key ] = get_post_meta( $variation->get_id(), $key, true ) ?: get_post_meta( $product->get_id(), $key, true );
        }
        return $data;
    }
}

/* -----------------------------------------------------------------------
 * Inventory Manager
 * --------------------------------------------------------------------- */
class WCAPM_Inventory_Manager {

    private static ?WCAPM_Inventory_Manager $instance = null;
    public static function instance(): self { if ( null === self::$instance ) self::$instance = new self(); return self::$instance; }

    public function init(): void {
        add_action( 'woocommerce_product_options_stock_fields', [ $this, 'add_reorder_point_field' ] );
        add_action( 'woocommerce_process_product_meta',         [ $this, 'save_reorder_point' ] );
        add_action( 'woocommerce_reduce_order_stock',           [ $this, 'check_reorder_point' ] );
        add_filter( 'woocommerce_product_stock_status_options', [ $this, 'add_custom_stock_statuses' ] );
        add_action( 'wp_ajax_wcapm_get_inventory_log',          [ $this, 'ajax_get_inventory_log' ] );
        add_action( 'wp_ajax_wcapm_adjust_stock',               [ $this, 'ajax_adjust_stock' ] );
    }

    public function add_reorder_point_field(): void {
        global $post;
        woocommerce_wp_text_input( [
            'id'                => '_wcapm_reorder_point',
            'label'             => __( 'Reorder Point', 'wcapm' ),
            'description'       => __( 'Get notified when stock falls below this level.', 'wcapm' ),
            'desc_tip'          => true,
            'type'              => 'number',
            'value'             => get_post_meta( $post->ID, '_wcapm_reorder_point', true ),
            'custom_attributes' => [ 'min' => '0', 'step' => '1' ],
        ] );
    }

    public function save_reorder_point( int $product_id ): void {
        if ( isset( $_POST['_wcapm_reorder_point'] ) ) {
            update_post_meta( $product_id, '_wcapm_reorder_point', absint( $_POST['_wcapm_reorder_point'] ) );
        }
    }

    public function check_reorder_point( \WC_Order $order ): void {
        foreach ( $order->get_items() as $item ) {
            $product_id    = $item->get_product_id();
            $reorder_point = (int) get_post_meta( $product_id, '_wcapm_reorder_point', true );
            if ( ! $reorder_point ) continue;

            $product = wc_get_product( $product_id );
            if ( $product && $product->managing_stock() && (int) $product->get_stock_quantity() <= $reorder_point ) {
                $this->send_reorder_notification( $product, $reorder_point );
            }
        }
    }

    private function send_reorder_notification( \WC_Product $product, int $reorder_point ): void {
        $to      = get_option( 'admin_email' );
        $subject = sprintf( __( 'Reorder Alert: %s', 'wcapm' ), $product->get_name() );
        $message = sprintf(
            "Stock for \"%s\" has fallen to %d units (reorder point: %d).\n\nManage product: %s",
            $product->get_name(),
            $product->get_stock_quantity(),
            $reorder_point,
            admin_url( "post.php?post={$product->get_id()}&action=edit" )
        );
        wp_mail( $to, $subject, $message );
    }

    public function add_custom_stock_statuses( array $statuses ): array {
        $statuses['on_backorder_custom'] = __( 'Pre-order', 'wcapm' );
        $statuses['discontinued']        = __( 'Discontinued', 'wcapm' );
        return $statuses;
    }

    public function ajax_get_inventory_log(): void {
        check_ajax_referer( 'wcapm_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_products' ) ) wp_send_json_error();

        global $wpdb;
        $product_id = (int) ( $_POST['product_id'] ?? 0 );
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.*, u.display_name FROM {$wpdb->prefix}wcapm_inventory_log l LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID WHERE l.product_id = %d ORDER BY l.created_at DESC LIMIT 50",
                $product_id
            )
        );
        wp_send_json_success( $logs );
    }

    public function ajax_adjust_stock(): void {
        check_ajax_referer( 'wcapm_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_products' ) ) wp_send_json_error();

        $product_id = (int) ( $_POST['product_id'] ?? 0 );
        $qty        = (int) ( $_POST['qty'] ?? 0 );
        $note       = sanitize_text_field( $_POST['note'] ?? '' );
        $product    = wc_get_product( $product_id );

        if ( ! $product ) wp_send_json_error( 'Product not found' );

        $old_qty = (int) $product->get_stock_quantity();
        $new_qty = $old_qty + $qty;
        $product->set_stock_quantity( $new_qty );
        $product->save();

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'wcapm_inventory_log', [
            'product_id' => $product_id,
            'user_id'    => get_current_user_id(),
            'action'     => $qty >= 0 ? 'manual_add' : 'manual_remove',
            'qty_before' => $old_qty,
            'qty_after'  => $new_qty,
            'note'       => $note,
            'created_at' => current_time( 'mysql' ),
        ] );

        wp_send_json_success( [ 'old_qty' => $old_qty, 'new_qty' => $new_qty ] );
    }
}

/* -----------------------------------------------------------------------
 * Checkout Fields
 * --------------------------------------------------------------------- */
class WCAPM_Checkout_Fields {

    private static ?WCAPM_Checkout_Fields $instance = null;
    public static function instance(): self { if ( null === self::$instance ) self::$instance = new self(); return self::$instance; }

    public function init(): void {
        add_filter( 'woocommerce_checkout_fields',              [ $this, 'add_checkout_fields' ] );
        add_action( 'woocommerce_checkout_process',             [ $this, 'validate_checkout_fields' ] );
        add_action( 'woocommerce_checkout_update_order_meta',   [ $this, 'save_checkout_fields' ] );
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_in_admin' ] );
        add_filter( 'woocommerce_email_order_meta_fields',      [ $this, 'add_to_emails' ], 10, 3 );
    }

    private function custom_fields(): array {
        $saved = get_option( 'wcapm_checkout_fields', [] );
        if ( ! is_array( $saved ) || empty( $saved ) ) {
            return [
                [
                    'key'      => 'wcapm_company_vat',
                    'label'    => __( 'VAT Number', 'wcapm' ),
                    'type'     => 'text',
                    'required' => false,
                    'section'  => 'billing',
                    'priority' => 120,
                ],
                [
                    'key'      => 'wcapm_purchase_order',
                    'label'    => __( 'Purchase Order Number', 'wcapm' ),
                    'type'     => 'text',
                    'required' => false,
                    'section'  => 'order',
                    'priority' => 10,
                ],
                [
                    'key'      => 'wcapm_delivery_instructions',
                    'label'    => __( 'Delivery Instructions', 'wcapm' ),
                    'type'     => 'textarea',
                    'required' => false,
                    'section'  => 'order',
                    'priority' => 20,
                ],
            ];
        }
        return $saved;
    }

    public function add_checkout_fields( array $fields ): array {
        foreach ( $this->custom_fields() as $field ) {
            $section = $field['section'] ?? 'billing';
            $fields[ $section ][ $field['key'] ] = [
                'label'    => $field['label'],
                'type'     => $field['type'],
                'required' => (bool) $field['required'],
                'priority' => (int) ( $field['priority'] ?? 100 ),
                'class'    => [ 'form-row-wide' ],
            ];
        }
        return $fields;
    }

    public function validate_checkout_fields(): void {
        foreach ( $this->custom_fields() as $field ) {
            if ( ! empty( $field['required'] ) && empty( $_POST[ $field['key'] ] ) ) {
                wc_add_notice(
                    sprintf( __( '%s is required.', 'wcapm' ), $field['label'] ),
                    'error'
                );
            }
        }
    }

    public function save_checkout_fields( int $order_id ): void {
        foreach ( $this->custom_fields() as $field ) {
            if ( isset( $_POST[ $field['key'] ] ) ) {
                update_post_meta( $order_id, $field['key'], sanitize_text_field( wp_unslash( $_POST[ $field['key'] ] ) ) );
            }
        }
    }

    public function display_in_admin( \WC_Order $order ): void {
        foreach ( $this->custom_fields() as $field ) {
            $value = get_post_meta( $order->get_id(), $field['key'], true );
            if ( $value ) {
                printf( '<p><strong>%s:</strong> %s</p>', esc_html( $field['label'] ), esc_html( $value ) );
            }
        }
    }

    public function add_to_emails( array $fields, bool $sent_to_admin, \WC_Order $order ): array {
        foreach ( $this->custom_fields() as $field ) {
            $value = get_post_meta( $order->get_id(), $field['key'], true );
            if ( $value ) {
                $fields[ $field['key'] ] = [ 'label' => $field['label'], 'value' => $value ];
            }
        }
        return $fields;
    }
}
