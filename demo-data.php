<?php
/**
 * Demo data — sample content created on plugin activation.
 * This file is loaded by the main plugin file to populate initial data.
 */

function get_demo_products() {
    return [
        ['name' => 'Sample Product 1', 'price' => 29.99, 'status' => 'publish'],
        ['name' => 'Sample Product 2', 'price' => 49.99, 'status' => 'publish'],
        ['name' => 'Sample Product 3', 'price' => 99.99, 'status' => 'draft'],
    ];
}

function get_demo_settings() {
    return [
        'enable_feature' => true,
        'display_mode' => 'grid',
        'items_per_page' => 12,
    ];
}
