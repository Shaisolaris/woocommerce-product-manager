# WooCommerce Advanced Product Manager



WooCommerce product management plugin with bulk edit actions, inventory tracking, CSV import/export, custom product fields, and admin dashboard.

## Quick Start

1. Copy to `wp-content/plugins/woocommerce-product-manager/`
2. Activate in WordPress admin
3. Configure in Settings

Advanced WooCommerce plugin providing bulk operations, custom product fields, inventory management, enhanced checkout fields, REST API extensions, and sales reporting.

## Features

### Bulk Operations
- Increase or decrease regular/sale prices by percentage or fixed amount across selected products
- Set or clear sale prices across multiple products in one action
- Set, add, or subtract stock quantities across selected products
- Enable/disable reviews in bulk
- All operations available via bulk actions dropdown on the Products list page

### Custom Product Fields
- Define unlimited custom fields per product (text, number, email, url, select, checkbox, textarea)
- Fields appear in the WooCommerce product editor
- Fields display on single product pages
- Fields included in variation data for variable products
- Manage fields via REST API or admin panel

### Inventory Manager
- Per-product reorder points — get email alerts when stock drops below threshold
- Full inventory adjustment log stored in custom DB table
- Manual stock adjustments with notes from product edit screen
- Custom stock statuses: Pre-order, Discontinued
- Export full inventory log to CSV

### Checkout Fields
- VAT number field on billing form
- Purchase order number on order form
- Delivery instructions textarea
- All fields saved to order meta, visible in admin, included in order emails
- Configurable via `wcapm_checkout_fields` option

### REST API Extensions (namespace: `wcapm/v1`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/bulk-price` | Bulk price update with product_ids, type, value, price_type |
| GET | `/inventory` | Inventory summary — out of stock, low stock, total units |
| GET | `/inventory/{id}/log` | Inventory adjustment history |
| POST | `/inventory/{id}/adjust` | Manual stock adjustment with note |
| GET | `/reports/sales?period=30days` | Sales report — orders, revenue, avg order, top products |
| GET | `/custom-fields` | List all custom field definitions |
| POST | `/custom-fields` | Create a new custom field |

All endpoints require `edit_products` capability. Custom fields are automatically appended to WooCommerce product REST responses under `wcapm` key.

### Sales Reports
- Daily revenue chart (30-day view)
- Top 10 products by revenue
- CSV export for products and inventory log

### Import / Export
- Export all products to CSV (SKU, name, prices, stock, categories, tags)
- Import/update products from CSV (matches on SKU, creates if new)
- Export full inventory log with product names and user names

## Database Tables

`{prefix}wcapm_custom_fields` — custom field definitions

`{prefix}wcapm_inventory_log` — full inventory adjustment history with timestamps and user attribution

## File Structure

```
woocommerce-product-manager/
├── woocommerce-product-manager.php   # Bootstrap, activation, DB setup
├── includes/
│   ├── class-bulk-operations.php     # Bulk price, stock, review actions
│   ├── class-custom-fields.php       # Custom product fields + inventory manager + checkout fields
│   ├── class-rest-extensions.php     # REST API endpoints and WC response filters
│   ├── class-reports.php             # Sales reports + import/export
│   └── [stub files for autoloading]
├── admin/
│   └── class-admin.php               # Admin menus, dashboard, reports page, columns
└── assets/
    ├── css/admin.css
    └── js/admin.js
```

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+
