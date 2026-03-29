<?php
/**
 * Plugin Name:  WooCommerce Advanced Product Manager
 * Plugin URI:   https://github.com/Shaisolaris/woocommerce-product-manager
 * Description:  Advanced WooCommerce toolkit — bulk price/stock operations, custom product fields, enhanced checkout fields, inventory management, REST API extensions, and sales reporting.
 * Version:      3.0.0
 * Author:       Shai Solaris
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 8.x
 * License:      GPL-2.0+
 * Text Domain:  wcapm
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WCAPM_VERSION',     '3.0.0' );
define( 'WCAPM_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WCAPM_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'WCAPM_PLUGIN_FILE', __FILE__ );

add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCAPM_PLUGIN_FILE, true );
    }
} );

final class WC_Advanced_Product_Manager {

    private static ?WC_Advanced_Product_Manager $instance = null;

    public static function instance(): WC_Advanced_Product_Manager {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init' ], 20 );
        register_activation_hook( WCAPM_PLUGIN_FILE, [ $this, 'activate' ] );
    }

    public function init(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>WooCommerce Advanced Product Manager</strong> requires WooCommerce to be active.</p></div>';
            } );
            return;
        }

        load_plugin_textdomain( 'wcapm', false, dirname( plugin_basename( WCAPM_PLUGIN_FILE ) ) . '/languages' );

        require_once WCAPM_PLUGIN_DIR . 'includes/class-bulk-operations.php';
        require_once WCAPM_PLUGIN_DIR . 'includes/class-custom-fields.php';
        require_once WCAPM_PLUGIN_DIR . 'includes/class-inventory-manager.php';
        require_once WCAPM_PLUGIN_DIR . 'includes/class-checkout-fields.php';
        require_once WCAPM_PLUGIN_DIR . 'includes/class-rest-extensions.php';
        require_once WCAPM_PLUGIN_DIR . 'includes/class-reports.php';
        require_once WCAPM_PLUGIN_DIR . 'includes/class-import-export.php';
        require_once WCAPM_PLUGIN_DIR . 'admin/class-admin.php';

        WCAPM_Bulk_Operations::instance()->init();
        WCAPM_Custom_Fields::instance()->init();
        WCAPM_Inventory_Manager::instance()->init();
        WCAPM_Checkout_Fields::instance()->init();
        WCAPM_REST_Extensions::instance()->init();
        WCAPM_Import_Export::instance()->init();

        if ( is_admin() ) {
            WCAPM_Admin::instance()->init();
        }
    }

    public function activate(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wcapm_custom_fields (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            field_key   VARCHAR(100)    NOT NULL,
            field_label VARCHAR(200)    NOT NULL,
            field_type  VARCHAR(50)     NOT NULL DEFAULT 'text',
            field_opts  TEXT,
            position    INT             NOT NULL DEFAULT 0,
            is_required TINYINT(1)      NOT NULL DEFAULT 0,
            created_at  DATETIME        DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY field_key (field_key)
        ) $charset;" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wcapm_inventory_log (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id  BIGINT UNSIGNED NOT NULL,
            user_id     BIGINT UNSIGNED NOT NULL,
            action      VARCHAR(50)     NOT NULL,
            qty_before  INT             NOT NULL DEFAULT 0,
            qty_after   INT             NOT NULL DEFAULT 0,
            note        TEXT,
            created_at  DATETIME        DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id)
        ) $charset;" );

        add_option( 'wcapm_version', WCAPM_VERSION );
        flush_rewrite_rules();
    }
}

WC_Advanced_Product_Manager::instance();
