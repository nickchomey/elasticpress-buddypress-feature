<?php
/**
 * Plugin Name:     ElasticPress BuddyPress
 * Plugin URI:      https://github.com/mlaa/elasticpress-buddypress.git
 * Description:     ElasticPress custom feature to index BuddyPress group & members.
 * Author:          MLA
 * Author URI:      https://github.com/mlaa
 * Text Domain:     elasticpress-buddypress
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Elasticpress_Buddypress
 */

namespace HardG\ElasticPressBuddyPress;
use EPR_REST_Posts_Controller;
//require_once dirname( __FILE__ ) . '/classes/class-ep-bp-api.php';
//require_once dirname( __FILE__ ) . '/features/buddypress/buddypress.php';
//require_once dirname( __FILE__ ) . '/features/buddypress/filters.php';
//require_once dirname( __FILE__ ) . '/features/buddypress/facets.php';

//require_once dirname( __FILE__ ) . '/elasticpress-rest.php';

define( 'EPBP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once dirname( __FILE__ ) . '/bin/wp-cli.php';
}


//TODO: Boone Overhauled this to use autoload mechanism. 
add_action( 'bp_include', function() {
	if ( ! class_exists( '\ElasticPress\Features' ) ) {
		return;
	}

	require __DIR__ . '/autoload.php';

	App::init();
} );

//add_action( 'plugins_loaded', 'ep_bp_register_feature' );


//TOP: Boone removed the REST endpoint. Need to check this over
add_action( 'rest_api_init', function () {
	$controller = new EPR_REST_Posts_Controller;
	$controller->register_routes();
} );

