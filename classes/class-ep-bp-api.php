<?php

use ElasticPress\Indexables as Indexables;

/**
 * Functions to add ElasticPress support for BuddyPress non-post content like group and members.
 *
 * @package         Elasticpress_Buddypress
 */

/**
 * Inspired by EP_API.
 */
class EP_BP_API {

	/**
	 * Enable detailed CLI output while indexing.
	 */
	const DEBUG_CLI_OUTPUT = true;

	/**
	 * Maximum number of members to include per elasticpress POST request when bulk syncing.
	 */
	const MAX_BULK_MEMBERS_PER_PAGE = 350;

	/**
	 * Maximum number of groups to include per elasticpress POST request when bulk syncing.
	 */
	const MAX_BULK_GROUPS_PER_PAGE = 350;

	/**
	 * Object type name used to fetch taxonomies and index/query elasticsearch
	 */
	const MEMBER_TYPE_NAME = 'user';

	/**
	 * Object type name used to fetch taxonomies and index/query elasticsearch
	 */
	const GROUP_TYPE_NAME = 'bp_group';

	/**
	 * Type of object currently being processed
	 *
	 * @var string
	 */
	private $type;
	
	/**
	 * //this __construct function is from the  'feature-optimize-cache' branch of MESH
	 * List of all xprofile field ids for cache clearing purposes.
	 * See _delete_member_object_cache().
	 */
	private $xprofile_field_ids;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->xprofile_field_ids = $wpdb->get_col( 'SELECT id FROM wp_bp_xprofile_fields' );
	}
	
	

	//TOP: From MESH - maybe not needed
	public function prepare_meta_types( $post_meta ) {
		$indexables = ElasticPress\Indexables::factory()->get_all();
		if ( empty( $indexables ) ) {
			return [];
		}
		return $indexables[0]->prepare_meta_types( $post_meta );
	}

	/**
	 * Prepare a group for syncing
	 * Must be inside the groups loop.
	 *
	 * @param BP_Groups_Group $group Group to prepare.
	 * @return array
	 */
	public function prepare_group( $group ) {
		$groupmeta = groups_get_groupmeta( $group->id );

		$args = [
			'post_id'           => $group->id,
			'ID'                => $group->id,
			'post_author'       => $this->get_user_data( get_userdata( $group->creator_id ) ),
			'post_date'         => $group->date_created,
			'post_date_gmt'     => $group->date_created,
			'post_title'        => $this->prepare_text_content( $group->name ),
			'post_excerpt'      => '',
			'post_content'      => $this->prepare_text_content( $group->description ),
			'post_status'       => 'publish',
			'post_name'         => $this->prepare_text_content( $group->name ),
			'post_modified'     => $group->date_created,
			'post_modified_gmt' => $group->date_created,
			'post_parent'       => 0,
			'post_type'         => self::GROUP_TYPE_NAME,
			'post_mime_type'    => '',
			'permalink'         => bp_get_group_permalink(),
			'terms'             => $this->prepare_terms( $group ),
			//'post_meta'         => $this->prepare_meta( $group ),
			'post_meta'         => [],
			'date_terms'        => [],
			'comment_count'     => 0,
			'comment_status'    => 0,
			'ping_status'       => 0,
			'menu_order'        => 0,
			'guid'              => bp_get_group_permalink(),
		];

		//		$args['meta'] = Indexables::factory()->prepare_meta_types( $args['post_meta'] );  //TOP: Boone turned this off

		return $args;
	}

	/**
	 * Reduce memory usageq by clearing relevant object cache keys for a user.
	 *
	 * This doesn't completely eliminate leaks but it helps.
	 *
	 * @param stdClass $user User for which to clear object cache keys.
	 */
	public function _delete_member_object_cache( stdClass $user ) {
		foreach ( $this->xprofile_field_ids as $field_id ) {
			wp_cache_delete( $user->ID . ':' . $field_id, 'bp_xprofile_data' );
		}

		wp_cache_delete( $user->ID, 'user_meta' );
		wp_cache_delete( 'bp_core_userdata_' . $user->ID, 'bp' );
		wp_cache_delete( 'bp_user_username_' . $user->ID, 'bp' );
		wp_cache_delete( $user->ID, 'bp_member_member_type' );
	}
	
	/**
	 * Prepare a member for syncing
	 * Must be inside the members loop.
	 *
	 * @param WP_User $user User to prepare.
	 * @return array
	 */
	public function prepare_member( stdClass $user ) {
		global $members_template;

		// Fake global member for BP loop-dependent logic.
		if ( ! isset( $members_template ) ) {
			$members_template = new stdClass;
		}
		$members_template->member = $user;

		$post_excerpt = make_clickable( bp_get_member_permalink() );

		$xprofile_terms = ( function() use ( &$user, &$post_excerpt ) {
			$fields = [];

			// feature-optimize-cache added args for caching
			$args = [
				'hide_empty_fields' => true,
				'hide_empty_groups' => true,
				'update_meta_cache' => false,
				'user_id' => $user->ID,
			];

			if ( bp_has_profile( $args ) ) {
				while ( bp_profile_groups() ) {
					bp_the_profile_group();

					if (
						bp_profile_group_has_fields() &&
						apply_filters( 'ep_bp_index_xprofile_group_' . bp_get_the_profile_group_slug(), true )
					) {
						while ( bp_profile_fields() ) {
							bp_the_profile_field();

							if ( apply_filters( 'ep_bp_index_xprofile_field_' . bp_get_the_profile_field_id(), true ) ) {
								$fields[] = [
									'term_id' => bp_get_the_profile_field_id(),
									'slug'    => bp_get_the_profile_field_name(),
									'name'    => bp_get_the_profile_field_value(),
									'parent'  => bp_get_the_profile_group_name(),
									//'parent' => bp_get_the_profile_group_id(), //TOP: MESH uses this
									//'parent' => 0, //TOP: another fork uses this - reasoning being that a group doesn't have a parent?
								];

								// TODO make filterable/optional.
								if (
									'about' === strtolower( bp_get_the_profile_field_name() ) &&
									! empty( bp_get_the_profile_field_value() )
								) {
									$post_excerpt = $this->prepare_text_content( bp_get_the_profile_field_value() );
								}
							}
						}
					}
				}
			}

			return [ 'xprofile' => $fields ];
		} )();

		$args = [
			'post_id'           => $user->ID,
			'ID'                => $user->ID,
			'post_author'       => $this->get_user_data( $user ),
			'post_date'         => $user->user_registered,
			'post_date_gmt'     => $user->user_registered,
			'post_title'        => bp_core_get_user_displayname( $user->ID ),
			'post_excerpt'      => $post_excerpt,
			'post_content'      => '',
			'post_status'       => 'publish',
			'post_name'         => '',
			'post_modified'     => $user->user_registered,
			'post_modified_gmt' => $user->user_registered,
			'post_parent'       => 0,
			'post_type'         => self::MEMBER_TYPE_NAME,
			'post_mime_type'    => '',
			'permalink'         => bp_get_member_permalink(),
			'terms'             => array_merge( $this->prepare_terms( $user ), $xprofile_terms ),
			//'post_meta'         => $this->prepare_meta( $user ), //TODO: why is this commented out?			
			'post_meta'         => [],
			'date_terms'        => [],
			'comment_count'     => 0,
			'comment_status'    => 0,
			'ping_status'       => 0,
			'menu_order'        => 0,
			'guid'              => bp_get_member_permalink(),
		];

//		$args['meta'] = Indexables::factory()->prepare_meta_types( $args['post_meta'] ); //TOP: MESH uses this

		return $args;
	}

	/**
	 * Normalized author data for any object type.
	 *
	 * @param WP_User $user User.
	 * @return array user data
	 */
	private function get_user_data( $user ) {
		if ( is_object( $user ) ) {
			$user_data = [
				'raw'          => $user->user_login,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'id'           => $user->ID,
			];
		} else {
			$user_data = [
				'raw'          => '',
				'login'        => '',
				'display_name' => '',
				'id'           => '',
			];
		}

		return $user_data;
	}

	/**
	 * Send a request to EP_API.
	 * Allows bulk_index_* functions to loop through objects and fire off successive requests of a reasonable size.
	 *
	 * @param array $objects See prepare_member() and prepare_group() for expected array format.
	 * @return stdClass
	 */
	private function send_request( $objects ) {
		$flatten = [];

		foreach ( $objects as $object ) {
			$flatten[] = $object[0];
			$flatten[] = $object[1];
		}

		$path = trailingslashit( ep_get_index_name( bp_get_root_blog_id() ) ) . "{$this->type}/_bulk";
		//$path = trailingslashit( ep_get_index_name( bp_get_root_blog_id() ) ) . "post/_bulk"; //TOP: MESH uses this

		// Make sure to add a new line at the end or the request will fail
		$body = rtrim( implode( "\n", $flatten ) ) . "\n";

		$request_args = array(
			'method'  => 'POST',
			'body'    => $body,
			'timeout' => 30,
		);

		$request = ElasticPress\Elasticsearch::factory()->remote_request( $path, apply_filters( 'ep_bulk_index_posts_request_args', $request_args, $body ) );

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$response = wp_remote_retrieve_response_code( $request );

		if ( 200 !== $response ) {
			return new WP_Error( $response, wp_remote_retrieve_response_message( $request ), $request );
		}

		return json_decode( wp_remote_retrieve_body( $request ), true );
	}

	/**
	 * Bulk index all groups.
	 * The equivalent functionality for posts in EP_API is spread out over several functions.
	 * This is a "condensed" adapted version that handles all required preparation as well as indexing.
	 *
	 * @param array $args Passed to bp_has_groups()
	 * @return bool success
	 */
	public function bulk_index_groups( $args = [] ) {
		global $groups_template;

		$this->type = self::GROUP_TYPE_NAME;

		$groups = [];

		$args = array_merge(
			[
				'per_page' => self::MAX_BULK_GROUPS_PER_PAGE,
				'page'     => 1,
			], $args
		);

		$querystring = bp_ajax_querystring( 'groups' ) . '&' . http_build_query( $args );

		if ( bp_has_groups( $querystring ) ) {
			while ( bp_groups() ) {
				bp_the_group();
				$group_args                              = $this->prepare_group( $groups_template->group );
				$groups[ $groups_template->group->id ][] = '{ "index": { "_id": "' . $groups_template->group->id . '" } }';
				$groups[ $groups_template->group->id ][] = addcslashes( wp_json_encode( $group_args ), "\n" );
			}

			$this->send_request( $groups );

			if ( self::DEBUG_CLI_OUTPUT ) {
				WP_CLI::log(
					sprintf(
						'Processed %d/%d entries. . .',
						$groups_template->group_count + self::MAX_BULK_GROUPS_PER_PAGE * ( $args['page'] - 1 ),
						$groups_template->total_group_count
					)
				);
			}

			$this->bulk_index_groups(
				[
					'page' => $args['page'] + 1,
				]
			);
		}

		return true;
	}

	/**
	 * Bulk index all members.
	 * See also bulk_index_groups()
	 *
	 * @param array $args Passed to bp_has_members()
	 * @return bool success
	 */
	public function bulk_index_members( $args = [] ) {
		global $members_template;
		global $wp_object_cache;

		$this->type = self::MEMBER_TYPE_NAME;

		$members = [];

		$args = array_merge(
			[
				'per_page' => self::MAX_BULK_MEMBERS_PER_PAGE,
				'page'     => 1,
			], $args
		);

		$querystring = bp_ajax_querystring( 'members' ) . '&' . http_build_query( $args );

		if ( bp_has_members( $querystring ) ) {
			while ( bp_members() ) {
				bp_the_member();
				$member_args                                = $this->prepare_member( $members_template->member );
				$members[ $members_template->member->id ][] = '{ "index": { "_id": "' . $members_template->member->id . '" } }';
				$members[ $members_template->member->id ][] = addcslashes( wp_json_encode( $member_args ), "\n" );
				/* //from feature-optimize-cache branch
				$user_id = bp_get_member_user_id();
				$members[ $user_id ][] = '{ "index": { "_id": "' . $user_id . '" } }';
				$members[ $user_id ][] = addcslashes( wp_json_encode( $member_args ), "\n" );		
				$this->_delete_member_object_cache( $members_template->member ); */
			}

			$this->send_request( $members );

			if ( self::DEBUG_CLI_OUTPUT ) {
				WP_CLI::log(
					sprintf(
						'Processed %d/%d entries. . .',
						$members_template->member_count + self::MAX_BULK_MEMBERS_PER_PAGE * ( $args['page'] - 1 ),
						$members_template->total_member_count
					)
				);
			}

			$this->bulk_index_members(
				[
					'page' => $args['page'] + 1,
				]
			);
		}

		return true;
	}

	/**
	 * Ripped straight from EP_API.
	 *
	 * @param object $object group or member. must match $this->type
	 * @return array
	 */
	public function prepare_meta( $object ) {
		switch ( $this->type ) {
			case self::MEMBER_TYPE_NAME:
				$meta = get_user_meta( $object->ID );
				break;
			case self::GROUP_TYPE_NAME:
				$meta = groups_get_groupmeta( $object->id );
				break;
		}

		/* TODO: Compare the switch and if statements from different forks
		if ( property_exists( $object, 'user_login' ) ) {
			$object->post_type = self::MEMBER_TYPE_NAME;
			$meta              = apply_filters( 'ep_bp_user_meta', get_user_meta( $object->ID ), $object );
		} else if ( property_exists( $object, 'creator_id' ) ) {
			$object->post_type = self::GROUP_TYPE_NAME;
			$meta              = groups_get_groupmeta( $object->id );
		} else {
			return [];
		} */
		
		$post = $object;

		if ( empty( $meta ) ) {
			return array();
		}

		$prepared_meta = array();

		/**
		 * Filter index-able private meta
		 *
		 * Allows for specifying private meta keys that may be indexed in the same manor as public meta keys.
		 *
		 * @since 1.7
		 *
		 * @param         array Array of index-able private meta keys.
		 * @param WP_Post $post The current post to be indexed.
		 */
		$allowed_protected_keys = apply_filters( 'ep_prepare_meta_allowed_protected_keys', array(), $post );

		/**
		 * Filter non-indexed public meta
		 *
		 * Allows for specifying public meta keys that should be excluded from the ElasticPress index.
		 *
		 * @since 1.7
		 *
		 * @param         array Array of public meta keys to exclude from index.
		 * @param WP_Post $post The current post to be indexed.
		 */
		$excluded_public_keys = apply_filters( 'ep_prepare_meta_excluded_public_keys', array(), $post );

		foreach ( $meta as $key => $value ) {

			$allow_index = false;

			if ( is_protected_meta( $key ) ) {

				if ( true === $allowed_protected_keys || in_array( $key, $allowed_protected_keys ) ) {
					$allow_index = true;
				}
			} else {

				if ( true !== $excluded_public_keys && ! in_array( $key, $excluded_public_keys )  ) {
					$allow_index = true;
				}
			}

			if ( true === $allow_index || apply_filters( 'ep_prepare_meta_whitelist_key', false, $key, $post ) ) {
				$prepared_meta[ $key ] = maybe_unserialize( $value );
			}
		}

		return $prepared_meta;

	}

	/**
	 * Ripped straight from EP_API.
	 *
	 * @param  string $content
	 * @return string
	 */
	private function prepare_text_content( $content ) {
		//$content = strip_tags( $content ); // preserve links in results.
		$content = preg_replace( '#[\n\r]+#s', ' ', $content );

		return $content;
	}

	/**
	 * Prepare terms to send to ES.
	 * Modified from EP_API.
	 *
	 * @param WP_User|BP_Groups_Group $object user or group
	 *
	 * @since 0.1.0
	 * @return array
	 */
	private function prepare_terms( $object ) {
		$taxonomy_names = get_object_taxonomies( $this->type );

		$selected_taxonomies = array();

		foreach ( $taxonomy_names as $taxonomy_name ) {
			$taxonomy = get_taxonomy( $taxonomy_name );
			if ( $taxonomy->public ) {
				$selected_taxonomies[] = $taxonomy;
			}
		}

		$selected_taxonomies = apply_filters( 'ep_sync_taxonomies', $selected_taxonomies, $object );

		if ( empty( $selected_taxonomies ) ) {
			return array();
		}

		$terms = array();

		$allow_hierarchy = apply_filters( 'ep_sync_terms_allow_hierarchy', false );

		foreach ( $selected_taxonomies as $taxonomy ) {

			/* TOP: MESH removed all of this. Boone kept it. Looks like something to do with some plugin, multisite, blogs etc... Probably not needed
			
			if ( function_exists( 'wpmn_get_object_terms' ) ) {
				$object_terms = wpmn_get_object_terms(
					( isset( $object->ID ) ) ? $object->ID : $object->id, // groups have lowercase id property, members upper
					$taxonomy->name
				);
			} else {
				$switched = false;
				if ( ! bp_is_root_blog() ) {
					switch_to_blog( bp_get_root_blog_id() );
					$switched = true;
				}

				$object_terms = wp_get_object_terms(
					( isset( $object->ID ) ) ? $object->ID : $object->id, // groups have lowercase id property, members upper
					$taxonomy->name
				);

				if ( $switched ) {
					restore_current_blog();
				}
			} */

			if ( ! $object_terms || is_wp_error( $object_terms ) ) {
				continue;
			}

			$terms_dic = array();

			foreach ( $object_terms as $term ) {
				if ( ! isset( $terms_dic[ $term->term_id ] ) ) {
					$terms_dic[ $term->term_id ] = array(
						'term_id' => $term->term_id,
						'slug'    => $term->slug,
						'name'    => $term->name,
						'parent'  => $term->parent,
					);
					if ( $allow_hierarchy ) {
						$terms_dic = $this->get_parent_terms( $terms_dic, $term, $taxonomy->name );
					}
				}
			}
			$terms[ $taxonomy->name ] = array_values( $terms_dic );
		}

		return $terms;
	}

	/**
	 * Ripped straight from EP_API.
	 *
	 * @return EP_BP_API
	 */
	public static function factory() {
		static $instance = false;

		if ( ! $instance ) {
			$instance = new self();
		}

		return $instance;
	}

}

/**
 * Accessor functions for methods in above class. See doc blocks above for function details.
 *
 * @param array $args See bulk_index_groups().
 */

function ep_bp_bulk_index_groups( $args ) {
	return EP_BP_API::factory()->bulk_index_groups( $args );
}

/**
 * Accessor function.
 *
 * @param array $args See bulk_index_members().
 */
function ep_bp_bulk_index_members( $args ) {
	return EP_BP_API::factory()->bulk_index_members( $args );
}
