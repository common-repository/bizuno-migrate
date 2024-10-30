<?php
/**
 * Plugin Name: Bizuno Migrate
 * Plugin URI: https://www.phreesoft.com/products/wordpress-bizuno-migrate
 * Description: Tools to assist in migrating your current accounting system to Bizuno Accounting
 * Version: 1.0.1
 * Author: PhreeSoft, Inc.
 * Author URI: http://www.PhreeSoft.com
 * Text Domain: bizuno
 * License: GPL3
 * License URI: https://www.gnu.org/licenses/gpl.html
 * Domain Path: /locale
 */

if (!defined( 'ABSPATH' )) { die( 'No script kiddies please!' ); }

/**
 * Things to do upon plugin activation
 */
register_activation_hook( __FILE__ , 'bizuno_migrate_activate' );
function bizuno_migrate_activate() {
    add_option('bizuno_migrate_active', true); //activate
    require_once ( __DIR__ . "/../bizuno-accounting/portal/controller.php");
    $ctl = new \bizuno\portalCtl();
    $_GET['bizRt'] = 'bizuno/portal/installPlugin';
    $_GET['plugin']= 'xfr';
    $ctl->compose();
    \bizuno\msgDebugWrite();
}

/**
 * Things to do upon plugin deactivation
 */
register_deactivation_hook(__FILE__ , 'bizuno_migrate_deactivate' );
function bizuno_migrate_deactivate() {
    delete_option('bizuno_migrate_active');
}

/**
 * This removes all information related to this plugin
 */
register_uninstall_hook(__FILE__, 'bizuno_migrate_remove');
function bizuno_migrate_remove() {
    require_once ( __DIR__ . "/../bizuno-accounting/portal/controller.php");
    $ctl = new \bizuno\portalCtl();
    $_GET['bizRt'] = 'bizuno/portal/deletePlugin';
    $_GET['plugin']= 'xfr';
    $ctl->compose();
    \bizuno\msgDebugWrite();
}
