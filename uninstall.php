<?php
/**
 * Uninstall Abilities Audit.
 *
 * @package Abilities_Audit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'abilities_audit_disabled' );
