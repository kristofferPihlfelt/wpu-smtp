<?php
/**
 * Removes the plugin's options. Constants in wp-config.php are left for you to delete.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wpu_smtp' );
delete_option( 'wpu_smtp_last_error' );
