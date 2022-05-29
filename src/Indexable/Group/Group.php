<?php

namespace HardG\ElasticPressBuddyPress\Indexable\Group;

use ElasticPress\Indexable as Indexable;
use ElasticPress\Elasticsearch as Elasticsearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Group extends Indexable {
	/**
	 * We only need one group index.
	 *
	 * @var boolean
	 */
	public $global = true;

	/**
	 * Indexable slug.
	 *
	 * @var string
	 */
	public $slug = 'bp-group';
	/**
	 * Create indexable and setup dependencies
	 *
	 * @since  3.0
	 */
	public function __construct() {
		$this->labels = [
			'plural'   => esc_html__( 'Groups', 'elasticpress-buddypress' ),
			'singular' => esc_html__( 'Group', 'elasticpress-buddypress' ),
		];

		$this->sync_manager      = new SyncManager( $this->slug );
		$this->query_integration = new QueryIntegration( $this->slug );
	}

	/**
	 * Put mapping for groups.
	 *
	 * @since  3.0
	 * @return boolean
	 */
	public function put_mapping() {
		$mapping = require apply_filters( 'epbp_group_mapping_file', EPBP_PLUGIN_DIR . '/mappings/group/initial.php' );

		$mapping = apply_filters( 'epbp_group_mapping', $mapping );

		return Elasticsearch::factory()->put_mapping( $this->get_index_name(), $mapping );
	}

	/**
	 * Prepare a group document for indexing.
	 *
	 * @param  int $group_id Group ID.
	 * @return array
	 */
	public function prepare_document( $group_id ) {
		$group = groups_get_group( $group_id );

		if ( empty( $group ) ) {
			return false;
		}

		$last_activity = groups_get_groupmeta( $group->id, 'last_activity', true );
		if ( ! $last_activity ) {
			$last_activity = $group->date_created;
		}

		$group_args = [
			'ID'                 => $group->id,
			'name'               => $group->name,
			'description'        => $group->description,
			'slug'               => $group->slug,
			'url'                => bp_get_group_permalink( $group ),
			'status'             => $group->status,
			'creator_id'         => $group->creator_id,
			'parent_id'          => $group->parent_id,
			'date_created'       => $group->date_created,
			'group_type'         => bp_groups_get_group_type( $group->id, false ),
			'last_activity'      => $last_activity,
			'total_member_count' => (int) groups_get_groupmeta( $group->id, 'total_member_count', true ),
			'meta'               => $this->prepare_meta_types( $this->prepare_meta( $group->id ) ),
		];

		$group_args = apply_filters( 'epbp_group_sync_args', $group_args, $group_id );

		return $group_args;
	}

	/**
	 * Query DB for groups.
	 *
	 * @param  array $args Query arguments
	 * @since  3.0
	 * @return array
	 */
	public function query_db( $args ) {
		global $wpdb;

		$bp = buddypress();

		$defaults = [
			'number'  => 350,
			'offset'  => 0,
			'orderby' => 'date_created',
			'order'   => 'desc',
		];

		if ( isset( $args['per_page'] ) ) {
			$args['number'] = $args['per_page'];
		}

		$args = apply_filters( 'epbp_group_query_db_args', wp_parse_args( $args, $defaults ) );

		$args['order'] = trim( strtolower( $args['order'] ) );

		if ( ! in_array( $args['order'], [ 'asc', 'desc' ], true ) ) {
			$args['order'] = 'desc';
		}

		/**
		 * BP group query doesn't support offset.
		 */
		$objects = $wpdb->get_results( $wpdb->prepare( "SELECT SQL_CALC_FOUND_ROWS id as ID FROM {$bp->groups->table_name} ORDER BY %s %s LIMIT %d, %d", $args['orderby'], $args['orderby'], (int) $args['offset'], (int) $args['number'] ) );

		return [
			'objects'       => $objects,
			'total_objects' => ( 0 === count( $objects ) ) ? 0 : (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' ),
		];
	}

	/**
	 * Prepare meta to send to ES.
	 *
	 * @param int $group_id Group ID.
	 * @return array
	 */
	public function prepare_meta( $group_id ) {
		$meta = (array) groups_get_groupmeta( $group_id, '', false );

		if ( empty( $meta ) ) {
			return [];
		}

		$prepared_meta = [];

		/**
		 * Filter index-able private meta
		 *
		 * Allows for specifying private meta keys that may be indexed in the same manor as public meta keys.
		 *
		 * @param array $keys     Array of index-able private meta keys.
		 * @param int   $group_id The current post to be indexed.
		 */
		$allowed_protected_keys = apply_filters( 'epbp_prepare_group_meta_allowed_protected_keys', [], $group_id );

		$excluded_keys = [
			// Indexed as a top-level property.
			'last_activity',
			'total_member_count',

			// Serialized.
			'ass_subscribed_users',
			'bpdocs',
			'bp_docs_terms',
		];

		/**
		 * Filter non-indexed public meta
		 *
		 * Allows for specifying public meta keys that should be excluded from the ElasticPress index.
		 *
		 * @param array $keys     Array of public meta keys to exclude from index.
		 * @param int   $group_id The current post to be indexed.
		 */
		$excluded_public_keys = apply_filters( 'epbp_prepare_group_meta_excluded_public_keys', $excluded_keys, $group_id );

		foreach ( $meta as $key => $value ) {

			$allow_index = false;

			if ( is_protected_meta( $key ) ) {

				if ( true === $allowed_protected_keys || in_array( $key, $allowed_protected_keys, true ) ) {
					$allow_index = true;
				}
			} else {

				if ( true !== $excluded_public_keys && ! in_array( $key, $excluded_public_keys, true ) ) {
					$allow_index = true;
				}
			}

			// Exclude bbPress 'forum_enabled'.
			if ( 0 === strpos( $key, '_bbp_forum_enabled_' ) ) {
				$allow_index = false;
			}

			// Exclude legacy BPGES items.
			if ( 0 === strpos( $key, 'ass_user_topic_status_' ) ) {
				$allow_index = false;
			}

			/**
			 * We need this extra filter to allow for blacklisting of dynamic keys.
			 */
			$allow_index = apply_filters( 'epbp_allow_index_group_meta_key', $allow_index, $key );

			if ( true === $allow_index || apply_filters( 'epbp_prepare_group_meta_whitelist_key', false, $key, $group_id ) ) {
				$prepared_meta[ $key ] = maybe_unserialize( $value );
			}
		}

		return $prepared_meta;
	}

	protected function parse_orderby( $args ) {
		$sort = [];

		// 'type' takes precedence.
		$order   = isset( $args['order'] ) && 'DESC' === $args['order'] ? 'DESC' : 'ASC';
		if ( 'alphabetical' === $args['type'] ) {
			$sort[] = [
				'name.sortable' => [
					'order' => 'ASC',
				],
			];
		} else {
			$orderby = $args['orderby'];

			switch ( $args['orderby'] ) {
				case 'last_activity' :
				case 'date_created' :
					$sort[] = [
						$orderby => [
							'order' => $order,
						],
					];
				break;
			}
		}

		return $sort;
	}

	public function format_args( $args ) {
		$formatted_args = [
			'from' => $args['per_page'] * ( $args['page'] - 1 ),
			'size' => $args['per_page'],
			'sort' => $this->parse_orderby( $args ),
		];

		$query = [];
		$match = [];
		$filter = [];

		if ( empty( $args['show_hidden'] ) ) {
			$filter[] = [
				'terms' => [
					'status' => [ 'public', 'private' ],
				],
			];
		}

		if ( $args['slug'] ) {
			$filter[] = [
				'terms' => [
					'slug' => $args['slug'],
				]
			];
		}

		if ( $args['include'] ) {
			$filter[] = [
				'terms' => [
					'ID' => $args['include'],
				],
			];
		}

		if ( $args['search_terms'] ) {
			/**
			 * Filters the fields to be matched by group searches.
			 *
			 * @param array $search_fields
			 */
			$search_fields = apply_filters( 'epbp_group_query_search_fields', [ 'name', 'description' ] );
			$match = [
				'fields'   => $search_fields,
				'operator' => 'or',
				'query'    => $args['search_terms'],
			];
		}

		// Convert meta_query.
		if ( ! empty( $args['meta_query'] ) ) {
			$exclude_meta_keys = apply_filters( 'epbp_group_query_excluded_meta_keys', [] );
			foreach ( $args['meta_query'] as $mq ) {
				if ( in_array( $mq['key'], $exclude_meta_keys, true ) ) {
					continue;
				}

				if ( is_array( $mq['value'] ) ) {
					$filter[] = [
						'terms' => [
							'meta.' . $mq['key'] => $mq['value'],
						],
					];
				} else {
					$filter[] = [
						'term' => [
							'meta.' . $mq['key'] => $mq['value'],
						],
					];
				}
			}
		}

		if ( $match ) {
			$query['bool']['must']['multi_match'] = $match;
		}

		$filter = apply_filters( 'epbp_group_query_filter_args', $filter, $args );

		if ( $filter ) {
			$query['bool']['filter'] = $filter;
		}

		if ( $query ) {
			$formatted_args['query'] = $query;
		}

		$formatted_args = apply_filters( 'epbp_group_query_args', $formatted_args, $args );

		return $formatted_args;
	}
}
