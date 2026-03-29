<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCAPM_Admin {

    private static ?WCAPM_Admin $instance = null;
    public static function instance(): self { if ( null === self::$instance ) self::$instance = new self(); return self::$instance; }

    public function init(): void {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_filter( 'manage_edit-product_columns',       [ $this, 'add_product_columns' ] );
        add_action( 'manage_product_posts_custom_column', [ $this, 'render_product_columns' ], 10, 2 );
    }

    public function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Product Manager', 'wcapm' ),
            __( 'Product Manager', 'wcapm' ),
            'edit_products',
            'wcapm-dashboard',
            [ $this, 'render_dashboard' ]
        );
        add_submenu_page( 'woocommerce', __( 'Bulk Operations', 'wcapm' ), __( 'Bulk Operations', 'wcapm' ), 'edit_products', 'wcapm-bulk', [ $this, 'render_bulk' ] );
        add_submenu_page( 'woocommerce', __( 'Sales Reports', 'wcapm' ),   __( 'Sales Reports', 'wcapm' ),   'view_woocommerce_reports', 'wcapm-reports', [ $this, 'render_reports' ] );
        add_submenu_page( 'woocommerce', __( 'Import / Export', 'wcapm' ), __( 'Import / Export', 'wcapm' ), 'edit_products', 'wcapm-import-export', [ $this, 'render_import_export' ] );
        add_submenu_page( 'woocommerce', __( 'Custom Fields', 'wcapm' ),   __( 'Custom Fields', 'wcapm' ),   'manage_woocommerce', 'wcapm-fields', [ $this, 'render_custom_fields_manager' ] );
    }

    public function render_dashboard(): void {
        $reports     = WCAPM_Reports::instance();
        $top         = $reports->get_top_products( 5 );
        $daily       = $reports->get_sales_by_period( 7 );
        $total_rev   = array_sum( $daily );
        $order_count = wc_get_orders( [ 'status' => [ 'wc-completed', 'wc-processing' ], 'date_created' => '>=' . date( 'Y-m-d', strtotime( '-30 days' ) ), 'return' => 'ids', 'limit' => -1 ] );
        ?>
        <div class="wrap wcapm-wrap">
            <h1><?php esc_html_e( 'WooCommerce Product Manager', 'wcapm' ); ?></h1>
            <div class="wcapm-stats-grid">
                <div class="wcapm-stat"><span class="num"><?php echo esc_html( wc_price( $total_rev ) ); ?></span><span class="lbl"><?php esc_html_e( 'Revenue (7 days)', 'wcapm' ); ?></span></div>
                <div class="wcapm-stat"><span class="num"><?php echo esc_html( count( $order_count ) ); ?></span><span class="lbl"><?php esc_html_e( 'Orders (30 days)', 'wcapm' ); ?></span></div>
                <div class="wcapm-stat"><span class="num"><?php echo esc_html( wp_count_posts( 'product' )->publish ); ?></span><span class="lbl"><?php esc_html_e( 'Active Products', 'wcapm' ); ?></span></div>
            </div>
            <h2><?php esc_html_e( 'Top Products (30 days)', 'wcapm' ); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th><?php esc_html_e( 'Product', 'wcapm' ); ?></th><th><?php esc_html_e( 'Qty Sold', 'wcapm' ); ?></th><th><?php esc_html_e( 'Revenue', 'wcapm' ); ?></th></tr></thead>
                <tbody>
                <?php foreach ( $top as $row ) : ?>
                    <tr><td><a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a></td><td><?php echo esc_html( $row['qty'] ); ?></td><td><?php echo wp_kses_post( wc_price( $row['revenue'] ) ); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_bulk(): void {
        ?>
        <div class="wrap wcapm-wrap">
            <h1><?php esc_html_e( 'Bulk Operations', 'wcapm' ); ?></h1>
            <div class="wcapm-card">
                <h2><?php esc_html_e( 'Bulk Price Update', 'wcapm' ); ?></h2>
                <p><?php esc_html_e( 'Select products on the Products list page and use the bulk actions dropdown, or use the controls here for all products.', 'wcapm' ); ?></p>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Update Type', 'wcapm' ); ?></th><td>
                        <select id="wcapm-update-type"><option value="percent"><?php esc_html_e( 'Percentage', 'wcapm' ); ?></option><option value="fixed"><?php esc_html_e( 'Fixed Amount', 'wcapm' ); ?></option></select>
                    </td></tr>
                    <tr><th><?php esc_html_e( 'Value', 'wcapm' ); ?></th><td><input type="number" id="wcapm-update-value" step="0.01" placeholder="10" /></td></tr>
                    <tr><th><?php esc_html_e( 'Apply To', 'wcapm' ); ?></th><td>
                        <select id="wcapm-price-type"><option value="regular"><?php esc_html_e( 'Regular Price', 'wcapm' ); ?></option><option value="sale"><?php esc_html_e( 'Sale Price', 'wcapm' ); ?></option></select>
                    </td></tr>
                </table>
                <p class="wcapm-warning"><?php esc_html_e( 'This will update ALL published products. Use the Products list for selective updates.', 'wcapm' ); ?></p>
                <button class="button button-primary" id="wcapm-run-bulk-price"><?php esc_html_e( 'Apply to All Products', 'wcapm' ); ?></button>
                <div id="wcapm-bulk-result"></div>
            </div>
        </div>
        <?php
    }

    public function render_reports(): void {
        $reports = WCAPM_Reports::instance();
        $top30   = $reports->get_top_products( 10, 30 );
        $daily   = $reports->get_sales_by_period( 30 );
        ?>
        <div class="wrap wcapm-wrap">
            <h1><?php esc_html_e( 'Sales Reports', 'wcapm' ); ?></h1>
            <div class="wcapm-report-grid">
                <div class="wcapm-card">
                    <h2><?php esc_html_e( 'Daily Revenue (30 days)', 'wcapm' ); ?></h2>
                    <canvas id="wcapm-revenue-chart" height="120"></canvas>
                    <script>window.wcapmChartData = <?php echo wp_json_encode( [ 'labels' => array_keys( $daily ), 'values' => array_values( $daily ) ] ); ?>;</script>
                </div>
                <div class="wcapm-card">
                    <h2><?php esc_html_e( 'Top Products (30 days)', 'wcapm' ); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr><th><?php esc_html_e( 'Product', 'wcapm' ); ?></th><th><?php esc_html_e( 'Units', 'wcapm' ); ?></th><th><?php esc_html_e( 'Revenue', 'wcapm' ); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ( $top30 as $row ) : ?>
                            <tr><td><?php echo esc_html( $row['name'] ); ?></td><td><?php echo esc_html( $row['qty'] ); ?></td><td><?php echo wp_kses_post( wc_price( $row['revenue'] ) ); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <p><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcapm_export_products' ), 'wcapm_export' ) ); ?>" class="button"><?php esc_html_e( 'Export Products CSV', 'wcapm' ); ?></a>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcapm_export_inventory' ), 'wcapm_export' ) ); ?>" class="button"><?php esc_html_e( 'Export Inventory Log CSV', 'wcapm' ); ?></a></p>
        </div>
        <?php
    }

    public function render_import_export(): void {
        ?>
        <div class="wrap wcapm-wrap">
            <h1><?php esc_html_e( 'Import / Export', 'wcapm' ); ?></h1>
            <div class="wcapm-card">
                <h2><?php esc_html_e( 'Import Products from CSV', 'wcapm' ); ?></h2>
                <p><?php esc_html_e( 'CSV must have headers: SKU, Name, Regular Price, Sale Price, Stock Qty', 'wcapm' ); ?></p>
                <input type="file" id="wcapm-import-file" accept=".csv" />
                <button class="button button-primary" id="wcapm-run-import"><?php esc_html_e( 'Import', 'wcapm' ); ?></button>
                <div id="wcapm-import-result"></div>
            </div>
        </div>
        <?php
    }

    public function render_custom_fields_manager(): void {
        global $wpdb;
        $fields = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wcapm_custom_fields ORDER BY position" );
        ?>
        <div class="wrap wcapm-wrap">
            <h1><?php esc_html_e( 'Custom Product Fields', 'wcapm' ); ?></h1>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th><?php esc_html_e( 'Key', 'wcapm' ); ?></th><th><?php esc_html_e( 'Label', 'wcapm' ); ?></th><th><?php esc_html_e( 'Type', 'wcapm' ); ?></th><th><?php esc_html_e( 'Required', 'wcapm' ); ?></th></tr></thead>
                <tbody>
                <?php foreach ( $fields as $f ) : ?>
                    <tr><td><?php echo esc_html( $f->field_key ); ?></td><td><?php echo esc_html( $f->field_label ); ?></td><td><?php echo esc_html( $f->field_type ); ?></td><td><?php echo $f->is_required ? '✓' : '—'; ?></td></tr>
                <?php endforeach; ?>
                <?php if ( ! $fields ) : ?><tr><td colspan="4"><?php esc_html_e( 'No custom fields yet. Use the REST API to create them.', 'wcapm' ); ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function add_product_columns( array $columns ): array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'price' ) {
                $new['wcapm_reorder'] = __( 'Reorder Point', 'wcapm' );
            }
        }
        return $new;
    }

    public function render_product_columns( string $column, int $post_id ): void {
        if ( $column === 'wcapm_reorder' ) {
            $val = get_post_meta( $post_id, '_wcapm_reorder_point', true );
            echo $val ? esc_html( $val ) : '—';
        }
    }

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, 'wcapm' ) ) return;
        wp_enqueue_style( 'wcapm-admin', WCAPM_PLUGIN_URL . 'assets/css/admin.css', [], WCAPM_VERSION );
        wp_enqueue_script( 'wcapm-admin', WCAPM_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], WCAPM_VERSION, true );
        wp_localize_script( 'wcapm-admin', 'wcapm', [
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'rest_url'  => rest_url( 'wcapm/v1/' ),
            'nonce'     => wp_create_nonce( 'wcapm_nonce' ),
            'rest_nonce'=> wp_create_nonce( 'wp_rest' ),
        ] );
    }
}
