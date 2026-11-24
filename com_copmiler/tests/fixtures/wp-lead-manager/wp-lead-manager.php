<?php
/**
 * Plugin Name: Lead Manager Pro
 * Description: Full CRM lead capture with admin screens, settings, custom DB table, and AJAX.
 * Version: 3.1.0
 * Author: Test Author
 * Text Domain: wp-lead-manager
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

register_activation_hook(__FILE__, 'wplm_install_schema');

function wplm_install_schema()
{
    global $wpdb;
    $table = $wpdb->prefix . 'lead_entries';
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$wpdb->prefix}lead_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        full_name VARCHAR(191) NOT NULL,
        email VARCHAR(191) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id)
    ) {$charset_collate};";
    dbDelta($sql);
}

add_action('admin_menu', function () {
    add_menu_page('Lead Manager', 'Leads', 'manage_options', 'wplm-dashboard', 'wplm_render_dashboard');
    add_submenu_page('wplm-dashboard', 'Lead Settings', 'Settings', 'manage_options', 'wplm-settings', 'wplm_render_settings');
});

add_action('admin_init', function () {
    register_setting('wplm_group', 'wplm_notify_email');
});

function wplm_render_dashboard()
{
    echo '<div class="wrap"><h1>' . esc_html__('Lead Manager Dashboard', 'wp-lead-manager') . '</h1></div>';
}

function wplm_render_settings()
{
    $email = get_option('wplm_notify_email', 'admin@example.com');
    echo '<form method="post" action="options.php">';
    settings_fields('wplm_group');
    echo '<input type="email" name="wplm_notify_email" value="' . esc_attr((string) $email) . '" />';
    submit_button('Save Lead Settings');
    echo '</form>';
}

add_action('wp_ajax_wplm_save_lead', function () {
    check_ajax_referer('wplm_nonce');
    wp_send_json_success(['saved' => true]);
});
