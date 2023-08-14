<?php
/**
 * InstaWP CLI Core
 *
 * @package      InstaWP\CLI\Core
 * @copyright    Copyright (C) 2023, InstaWP
 * @link         http://instawp.com
 * @since        1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:       InstaWP CLI Core
 * Version:           1.0.0
 * Plugin URI:        https://instawp.com
 * Description:       CLI Package for InstaWP Remote Features.
 * Author:            InstaWP
 * Author URI:        https://instawp.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 5.6
 * Tested up to:      6.3
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Autoloading.
 */
include dirname( __FILE__ ) . '/vendor/autoload.php';