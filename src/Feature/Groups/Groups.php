<?php

namespace HardG\ElasticPressBuddyPress\Feature\Groups;

use HardG\ElasticPressBuddyPress\Indexable\Group\Group as Group;
use ElasticPress\Feature as Feature;
use ElasticPress\Indexables as Indexables;

class Groups extends Feature {
	/**
	 * Initialize feature settings.
	 *
	 * @since  3.0
	 */
	public function __construct() {
		$this->slug = 'bp-groups';

		$this->title = esc_html__( 'BuddyPress Groups', 'elasticpress-buddypress' );

		$this->requires_install_reindex = false;

		parent::__construct();
	}

	/**
	 * Setup all feature filters
	 *
	 * @since  2.1
	 */
	public function setup() {
		Indexables::factory()->register( new Group() );
		add_action( 'init', [ $this, 'search_setup' ] );
	//	add_action( 'widgets_init', [ $this, 'register_widget' ] );
	//	add_filter( 'ep_formatted_args', [ $this, 'formatted_args' ], 10, 2 );
	}

	/**
	 * Setup feature on each page load
	 */
	public function search_setup() {
		add_filter( 'ep_elasticpress_enabled', [ $this, 'integrate_search_queries' ], 10, 2 );
	}

	/**
	 * Enable integration on search queries
	 *
	 * @param  bool          $enabled Whether EP is enabled
	 * @since  3.0
	 * @return bool
	 */
	public function integrate_search_queries( $enabled, $query ) {
		// This is a hack, since BP isn't passing an object.
		if ( ! is_array( $query ) ) {
			return $enabled;
		}

		if ( ! array_key_exists( 'group_type', $query ) ) {
			return $enabled;
		}

		// @todo
		return true;

		if ( isset( $query['ep_integrate'] ) && false === $query['ep_integrate'] ) {
			$enabled = false;
		} elseif ( ! empty( $query['search_terms'] ) ) {
			$enabled = true;
		}

		return $enabled;
	}

	/**
	 * Output feature box summary
	 *
	 * @since 2.1
	 */
	public function output_feature_box_summary() {
		echo esc_html_e( 'Index BuddyPress groups.', 'elasticpress-buddypress' );
	}

	/**
	 * Output feature box long
	 *
	 * @since 2.1
	 */
	public function output_feature_box_long() {
		echo esc_html_e( 'Index BuddyPress groups.', 'elasticpress-buddypress' );
	}

	public function formatted_args( $formatted_args, $args ) {
		return $formatted_args;
	}
}
