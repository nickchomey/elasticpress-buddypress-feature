<?php
/**
 * Integrate with WP_User_Query
 *
 * @since  1.0
 * @package elasticpress
 */

namespace HardG\ElasticPressBuddyPress\Indexable\Group;

use ElasticPress\Indexables as Indexables;
use ElasticPress\Utils as Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Query integration class
 */
class QueryIntegration {
	/**
	 * Checks to see if we should be integrating and if so, sets up the appropriate actions and filters.
	 */
	public function __construct() {
		// Ensure that we are currently allowing ElasticPress to override the normal WP_Query
		if ( Utils\is_indexing() ) {
			return;
		}

		add_filter( 'bp_groups_pre_group_ids_query', [ $this, 'maybe_filter_query' ], 10, 2 );
		add_filter( 'bp_groups_get_total_groups_sql', [ $this, 'maybe_filter_total_groups_sql' ], 10, 3 );
	}

	public function cached_results( $formatted_args, $set = null ) {
		static $results;

		$args_key = md5( json_encode( $formatted_args ) );

		if ( null !== $set ) {
			$results[ $args_key ] = $set;
		}

		return isset( $results[ $args_key ] ) ? $results[ $args_key ] : null;
	}

	public function maybe_filter_query( $results, $r ) {
		$group_indexable = Indexables::factory()->get( 'bp-group' );

		if ( ! $group_indexable->elasticpress_enabled( $r ) || apply_filters( 'epbp_skip_group_query_integration', false, $r ) ) {
			return $results;
		}

		$formatted_args = $group_indexable->format_args( $r );

		$cached = $this->cached_results( $formatted_args );
		if ( null === $cached ) {
			$ep_query = $group_indexable->query_es( $formatted_args, $r );
		} else{
			$ep_query = $cached;
		}

//		var_dump( $ep_query );
		if ( false === $ep_query ) {
			$r['elasticsearch_success'] = false;
			return $results;
		}

		$this->cached_results( $formatted_args, $ep_query );

		$results = array_map(
			function( $document ) {
				return $document['ID'];
			},
			$ep_query['documents']
		);

		return $results;
	}

	public function maybe_filter_total_groups_sql( $total_groups_sql, $sql, $r ) {
		$group_indexable = Indexables::factory()->get( 'bp-group' );

		if ( ! $group_indexable->elasticpress_enabled( $r ) || apply_filters( 'epbp_skip_group_query_integration', false, $r ) ) {
			return $results;
		}

		$formatted_args = $group_indexable->format_args( $r );

		// Should always be cached?
		$cached = $this->cached_results( $formatted_args );

		$count = 0;
		if ( isset( $cached['found_documents']['value'] ) ) {
			$count = $cached['found_documents']['value'];
		}

		return sprintf( "SELECT %d", intval( $count ) );
	}
}
