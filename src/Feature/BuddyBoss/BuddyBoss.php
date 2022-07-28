<?php

namespace ElasticPressBuddyBoss\Feature\BuddyBoss;

use ElasticPressBuddyBoss\Indexable\Activity\Activity as Activity;
use ElasticPressBuddyBoss\Indexable\Group\Group as Group;
use ElasticPressBuddyBoss\Feature\BuddyBoss\QueryIntegration as QueryIntegration;
use ElasticPress\Elasticsearch as Elasticsearch;
use ElasticPress\Feature as Feature;
use ElasticPress\Utils as Utils;
use ElasticPress\Indexables as Indexables;
use BP_Search;
use stdClass;

class BuddyBoss extends Feature {
	/**
	 * Initialize feature settings.
	 *
	 * @since  3.0
	 */
	public function __construct() {
		$this->slug = 'bp-groups';

		$this->title = esc_html__('BuddyPress Groups', 'elasticpress-buddypress');

		$this->requires_install_reindex = false;

		parent::__construct();
	}

	/**
	 * Setup all feature filters
	 *
	 * @since  2.1
	 */
	public function setup() {
		Indexables::factory()->register(new Group());
		Indexables::factory()->register(new Activity());
		add_action('init', [$this, 'search_setup'],11);
		
		//	add_action( 'widgets_init', [ $this, 'register_widget' ] );
		//	add_filter( 'ep_formatted_args', [ $this, 'formatted_args' ], 10, 2 );
	}
	
	/**
	 * Output feature box summary
	 *
	 * @since 2.1
	 */
	public function output_feature_box_summary() {
		echo esc_html_e('Index BuddyPress groups.', 'elasticpress-buddypress');
	}

	/**
	 * Output feature box long
	 *
	 * @since 2.1
	 */
	public function output_feature_box_long() {
		echo esc_html_e('Index BuddyPress groups.', 'elasticpress-buddypress');
	}

	public function formatted_args($formatted_args, $args) {
		return $formatted_args;
	}
	
	/**
	 * Setup feature on each page load
	 */
	public function search_setup() {
		//add_filter('ep_elasticpress_enabled', [$this, 'integrate_search_queries'], 10, 2); // probably don't even need this as won't be using standard ep search
		//remove_filter( 'the_content', 'bp_search_search_page_content', 9 );
		//add_filter( 'the_content', [ 'ElasticPressBuddyBoss\Feature\BuddyBoss\QueryIntegration', 'epbp_search_search_page_content'], 9 );
		/* add_filter('bp_search_bypass', function ($bypass) {
			$bypass = true;
			return $bypass;
		}); */
		
		//this hook is for using the entire BB Search component mechanism, except for bypassing the search part and using EP instead to get the results
		add_filter('bp_search_bypass', [$this,'epbp_search'], 10, 4);
	}

	/**
	 * ****Note: Not using this right now. Perhaps better to just add a filter to the BP_Search class that can either use or bypass the BB Search in favour of EP Search.****
	 * 
	 * Modified version of the BuddyBoss Search component's bp_search_search_page_content() function that kicks off the search process. 
	 * Starts the process to generate ES queries for the BP indices and then returns the content. Feeds the content into the BP Search component to generate the search results page.
	 * Only runs if EPBP is enabled, due to the remove/add filter in search_setup() above. If EPBO is enabled, those hooks wont be fired and this function will not run.
	 */
	public function epbp_search_search_page_content($content){
		global $bpgs_main_content_filter_has_run;
		if ( bp_search_is_search() && 'yes' != $bpgs_main_content_filter_has_run ) {
			remove_filter( 'the_content', [$this, 'epbp_search_search_page_content'], 9 );
			remove_filter( 'the_content', 'wpautop' );
			$bpgs_main_content_filter_has_run = 'yes';
			// setup search resutls and all..
			$this->prepare_search_page();
			ob_start();
			bp_get_template_part( 'search/results-page' );
			$content .= ob_get_clean();
		}
		return $content;
	}
	
	/**
	* ****Note: Not using this right now. Perhaps better to just add a filter to the BP_Search class that can either use or bypass the BB Search in favour of EP Search.**** 
	* Copy of the BuddyBoss Search Component's prepare_search_page() function. Modified to use epbp_search_search_page_content() instead of bp_search_search_page_content()
	 */
	public function prepare_search_page() {
		$args = array();
		if ( isset( $_GET['subset'] ) && ! empty( $_GET['subset'] ) ) {
			$args['search_subset'] = $_GET['subset'];
		}

		if ( isset( $_GET['s'] ) && ! empty( $_GET['s'] ) ) {
			$args['search_term'] = $_GET['s'];
		}

		if ( isset( $_GET['list'] ) && ! empty( $_GET['list'] ) ) {
			$current_page = (int) $_GET['list'];
			if ( $current_page > 0 ) {
				$args['current_page'] = $current_page;
			}
		}

		$args = apply_filters( 'bp_search_search_page_args', $args );
		$this->do_search( $args );
	}
	
	public function epbp_search($results, $args, $searchable_items, $search_helpers){
		$es_query = "";
		$query_args=array();
		
				
		foreach ( $searchable_items as $search_type ) {
			if ( ! isset( $search_helpers[ $search_type ] ) ) {
				continue;
			}
			//$obj = $this->search_helpers[ $search_type ];
			//if($search_type == 'groups'){
			//$es_query = Bp_Search_Groups::es_query_args($args['search_term'],$args['number'] );
			
			//$es_query .= apply_filters("epbp_".$search_type."_query_args",$args['search_term'],$args['number'] );
			
			//$funcname = "epbp_{$search_type}_query_args";
			//if(is_callable(EPBPQuery::$funcname($args['search_term'],$args['number']))){
				//$es_query .= QueryIntegration::$funcname($args['search_term'],$args['number']) ;
				//$query_args[] = EPBPQuery::$funcname($args['search_term'],$args['number']) ;
			//}
			//EPBPQuery::epbp_groups_query_args($args['search_term'],$args['number']);
			$es_query .= $this->epbp_query_args($search_type, $args['search_term'],$args['number']) ;
			
		}
		$total = array();
		$results = array();
		/* $total = new stdClass();
		$results = new stdClass(); */
		/* $time2 = microtime(true);
		error_log("BP Search: Search took " . ($time2 - $time1) . " seconds"); */
		list ($total, $results) = $this->epbp_msearch_query('seeingtheforestnet-bp-group','group', $es_query,'', $searchable_items);
		/* $time3 = microtime(true);
		error_log("BP Search: ep query took " . ($time3 - $time2) . " seconds" .PHP_EOL);		 */
		/* foreach ($es_results as $key=>$value){
			$results->$key = $value;
		} */
				
		return [$total, $results];

	}
	
	//public static function epbp_groups_query_args($search_term, $limit) {
	public static function epbp_query_args($search_type, $search_term, $limit) {
		$user_id = bp_loggedin_user_id();
		$search_type == 'activity_comment' ? $index = 'activity' : $index = $search_type;
		$header = json_encode(array("index" => "seeingtheforestnet-bp-{$index}"));
		/* $body = json_encode(			
			array(
				"size" => $limit,
				"_source" => ["ID", "name", "description", "last_activity"],
				"track_scores" => true,
				"sort" => array (
					array (
						"last_activity" => array (
							"order" => "desc"
						)
					)
				),
				"query" => array(
					"bool" => array(
						"must" => array(
							array(
								"multi_match" => array(
									"query" => $search_term,
									"fields" => array(
										"name",
										"description",
										"slug"
									),
									"type" => "phrase"
								)
							)
						),
						"filter" => array(
							array(
								"bool" => array(
									"should" => array(
										array(
											"bool" => array(
												"must_not" => array(
													array(
														"term" => array(
															"status" => "private"
														)
													)
												)
											)
										),
										array(
											"bool" => array(
												"must" => array(
													array(
														"term" => array(
															"members" => $user_id
														)
													)
												)
											)
										)
									)
								)
							)
						)
					)
				)
			)
		); */
		$body['size'] = $limit;
		$body["_source"] =  match($search_type) {
			'groups' 									=> array( "ID", "name", "description", "last_activity" ),
			'members'									=> array( "ID", "content", "type", "date_recorded" ),
			'activity', 'activity_comment' 				=> array( "ID", "content", "type", "date_recorded" ),
			'forum', 'topic', 'reply' 					=> array( "ID", "content", "type", "date_recorded" ),
			'photos', 'albums', 'videos', 'folders' 	=> array( "ID", "content", "type", "date_recorded" ),				
			'documents', 'message' 						=> array( "ID", "content", "type", "date_recorded" ),
			default				 						=> array( "ID", "content", "type", "date_recorded" ),
		};
		
		
		
		/* $body2["sort"] = array (
			array (
				"last_activity" => array (
					"order" => "desc"
				)
			)
		);
		$body2["track_scores"] = true; */
		
		//Multimatch query - does the text search		
		$body["query"]['bool']['must'][0]['multi_match']['query'] = $search_term;
		
		$body["query"]['bool']['must'][0]['multi_match']['fields'] = match($search_type) {
			'groups' 									=> array( "name", "description" ),
			'members'									=> array( "display_name", "user_email", "user_login" ),
			'activity', 'activity_comment' 				=> array( "content" ),
			'forum', 'topic', 'reply' 					=> array( "post_title", "post_content" ),
			'photos', 'albums', 'videos', 'folders' 	=> array( "title" ),				
			'documents', 'message' 						=> array( "content" ),
			default				 						=> array( "content" ),
		};
		
		/* 
		switch ($search_type) {
			case 'group':
				$multi_match_fields = array( "name", "description" );
				break;
			case 'member':
				$multi_match_fields = array(
					"display_name",
					"user_email",
					"user_login",					
				);
				break;
			case 'activity':
			case 'activity-comment':
				$multi_match_fields = array( "content" );
				break;
			case ['forum', 'topic', 'reply']:
				$multi_match_fields = array( "post_title",	"post_content" );
				break;
			case ['photos', 'albums', 'videos', 'folders']:
				$multi_match_fields = array( "title" );
				break;	
			case ['documents', 'message']:
				$multi_match_fields = array(
					"content"
				);
				break;
			default:
				$multi_match_fields = array(
					"name",
					"description",
					"slug"
				);
				break;
		}
		$body["query"]['bool']['must'][0]['multi_match']['fields'] = $multi_match_fields; */
		
		
		
		
		//type of multimatch query
		$body["query"]['bool']['must'][0]['multi_match']['type'] = "phrase";
		
		//Filter query - does the filtering
		
		//Status filter - excludes private groups
		if ($search_type == 'groups') {
			$body["query"]['bool']['filter'][0]['bool']['should'][0]['bool']['must_not'][0]['term']['status'] = "hidden";
			$body["query"]['bool']['filter'][0]['bool']['should'][1]['bool']['must'][0]['term']['members'] = $user_id;
		}
		//works for both activity and activity_comment
		if ($index == 'activity') {
			
			$body["query"]['bool']['filter'][0]['bool']['must'][0]['bool']['must'][0]['term']['type'] = $search_type;			
			$body["query"]['bool']['filter'][0]['bool']['must'][1]['bool']['should'][0]['bool']['must'][0]['bool']['must'][0]['terms']['privacy'] = array('public', 'loggedin');
			$body["query"]['bool']['filter'][0]['bool']['must'][1]['bool']['should'][0]['bool']['must'][1]['bool']['must_not'][0]['term']['component'] = "groups";
			
			
			// generate the filter query for activity posts in the user's groups
			$user_groups = array();
			if ( bp_is_active( 'groups' ) ) {

				// Fetch public groups.
				$public_groups = groups_get_groups(
					array(
						'fields'   => 'ids',
						'status'   => 'public',
						'per_page' => - 1,
					)
				);
				if ( ! empty( $public_groups['groups'] ) ) {
					$public_groups = $public_groups['groups'];
				} else {
					$public_groups = array();
				}

				$groups = groups_get_user_groups( bp_loggedin_user_id() );
				if ( ! empty( $groups['groups'] ) ) {
					$user_groups = $groups['groups'];
				} else {
					$user_groups = array();
				}

				$user_groups = array_values(array_map('intval',array_unique( array_merge( $user_groups, $public_groups ) )));
				$user_groups2 = [2,3,4,6];
				$user_groups3 = implode(',',array_map('intval',array_unique( array_merge( $user_groups, $public_groups ) )));
				$user_groups4 = implode(',',array_unique( array_merge( $user_groups, $public_groups ) ));
				$j1 = json_encode($user_groups);
				$j2 = json_encode($user_groups2);
				$j3 = json_encode($user_groups3);
				$j4 = json_encode($user_groups4);
				
			}
			$body["query"]['bool']['filter'][0]['bool']['must'][1]['bool']['should'][1]['bool']['must'][0]['bool']['must'][0]['terms']['item_id'] = $user_groups;
			$body["query"]['bool']['filter'][0]['bool']['must'][1]['bool']['should'][1]['bool']['must'][1]['bool']['must'][0]['term']['component'] = "groups";			
			
			
			//generate the filter query for user's friends' friends only posts
			$friends_ids = array();
			if ( bp_is_active( 'friends' ) ) {

				// Determine friends of user.
				$friends_ids = friends_get_friend_user_ids( bp_loggedin_user_id() );
				if ( empty( $friends_ids ) ) {
					$friends = array( 0 );
				}
				array_push( $friends_ids, bp_loggedin_user_id() );
				//$friends_ids = implode(',', $friends_ids );
				//$friends_ids = implode(',', $friends_ids );
			}
			$body["query"]['bool']['filter'][0]['bool']['must'][1]['bool']['should'][2]['bool']['must'][0]['bool']['must'][0]['terms']['user_id'] = $friends_ids;
			$body["query"]['bool']['filter'][0]['bool']['must'][1]['bool']['should'][2]['bool']['must'][1]['bool']['must'][0]['term']['component'] = "friends";
			
			
			//generate the filter query for user's own private posts
			$body["query"]['bool']['filter'][0]['bool']['must'][1]['bool']['should'][3]['bool']['must'][0]['bool']['must'][0]['term']['user_id'] = $user_id;
			$body["query"]['bool']['filter'][0]['bool']['must'][1]['bool']['should'][3]['bool']['must'][1]['bool']['must'][0]['term']['component'] = "onlyme";
		}
		
		
		//Member filter - include groups the user is a member of (to account for their hidden groups)
		
		
		
				
		
		$body = json_encode($body, JSON_FORCE_OBJECT);		
		return $header . PHP_EOL . $body . PHP_EOL;		
	}
		
	public function epbp_query_request_path($path, $index, $type, $query, $query_args, $query_object) {
		if ($type = 'group') {
			$path = "_msearch";
		}
		return $path;
	}

	public static function epbp_msearch_query($index, $type, $query, $query_args, $searchable_types, $query_object = null) {
		$path = "_msearch";
		/* if ( version_compare( Elasticsearch::factory()->get_elasticsearch_version(), '7.0', '<' ) ) {
			$path = $index . '/' . $type . '/_msearch';
		} else {
			$path = $index . '/_search';
		} */

		// For backwards compat
		/**
		 * Filter Elasticsearch query request path
		 *
		 * @hook ep_search_request_path
		 * @param {string} $path Request path
		 * @param  {string} $index Index name
		 * @param  {string} $type Index type
		 * @param  {array} $query Prepared Elasticsearch query
		 * @param  {array} $query_args Query arguments
		 * @param  {mixed} $query_object Could be WP_Query, WP_User_Query, etc.
		 * @return  {string} New path
		 */
		$path = apply_filters('ep_search_request_path', $path, $index, $type, $query, $query_args, $query_object);

		/**
		 * Filter Elasticsearch query request path
		 *
		 * @hook ep_query_request_path
		 * @param {string} $path Request path
		 * @param  {string} $index Index name
		 * @param  {string} $type Index type
		 * @param  {array} $query Prepared Elasticsearch query
		 * @param  {array} $query_args Query arguments
		 * @param  {mixed} $query_object Could be WP_Query, WP_User_Query, etc.
		 * @return  {string} New path
		 */
		$path = apply_filters('ep_query_request_path', $path, $index, $type, $query, $query_args, $query_object);

		$request_args = array(
			'body'    =>  $query,
			'method'  => 'POST',
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept' => 'text/plain'
				//'Content-Type' => 'application/x-ndjson',				
			),
		);

		/**
		 * Filter whether to send the EP-Search-Term header or not.
		 *
		 * @todo Evaluate if we should remove tests for is_admin() and empty post types.
		 *
		 * @since  3.5.2
		 * @hook ep_query_send_ep_search_term_header
		 * @param  {bool}  $send_header True means send the EP-Search-Term header
		 * @param  {array} $query_args  WP query args
		 * @return {bool}  New $send_header value
		 */
		$send_ep_search_term_header = apply_filters(
			'ep_query_send_ep_search_term_header',
			(Utils\is_epio() &&
				!empty($query_args['s']) &&
				Utils\is_integrated_request('search') &&
				!isset($_GET['post_type']) // phpcs:ignore WordPress.Security.NonceVerification
			),
			$query_args
		);

		// If needed, send the search term as a header to ES so the backend understands what a normal query looks like
		if ($send_ep_search_term_header) {
			$request_args['headers']['EP-Search-Term'] = rawurlencode($query_args['s']);
		}

		/**
		 * Filter Elasticsearch query request arguments
		 *
		 * @hook ep_query_request_args
		 * @since 3.6.4
		 * @param {array}  $request_args Request arguments
		 * @param {string} $path         Request path
		 * @param {string} $index        Index name
		 * @param {string} $type         Index type
		 * @param {array}  $query        Prepared Elasticsearch query
		 * @param {array}  $query_args   Query arguments
		 * @param {mixed}  $query_object Could be WP_Query, WP_User_Query, etc.
		 * @return {array} New request arguments
		 */
		$request_args = apply_filters('ep_query_request_args', $request_args, $path, $index, $type, $query, $query_args, $query_object);

		$request = Elasticsearch::factory()->remote_request($path, $request_args, $query_args, 'query');

		$remote_req_res_code = absint(wp_remote_retrieve_response_code($request));

		$is_valid_res = ($remote_req_res_code >= 200 && $remote_req_res_code <= 299);

		/**
		 * Filter whether Elasticsearch remote request response code is valid
		 *
		 * @hook ep_remote_request_is_valid_res
		 * @param {boolean} $is_valid_res Whether response code is valid or not
		 * @param  {array} $request Remote request response
		 * @return  {string} New value
		 */
		if (!is_wp_error($request) && apply_filters('ep_remote_request_is_valid_res', $is_valid_res, $request)) {

			$response_body = wp_remote_retrieve_body($request);

			//$response = json_decode( $response_body, false ); //creates object instead of array
			$response = json_decode($response_body, true); // creates an array instead of an object

			$results = array();

			foreach ($response['responses'] as $key1 => $bucket) {

				$searchable = $searchable_types[$key1];
				foreach ($bucket['hits']['hits'] as $key2 => $hit) {
					/* 
					//use this later -for now just put the results into BB Search's format
					$hit_id = $hit['_source']['ID'];
					$hits[$searchable][$hit_id]['relevance'] = $hit['_score'];
					$hits[$searchable][$hit_id]['id'] = $hit_id;
					$hits[$searchable][$hit_id]['type'] = $searchable;
					$hits[$searchable][$hit_id]['entry_date'] = $hit['_source']['last_activity']; */
					/* $hits[$key2]['relevance'] = $hit['_score'];
					$hits[$key2]['id'] = $hit['_source']['ID'];
					$hits[$key2]['type'] = $searchable;
					$hits[$key2]['entry_date'] = $hit['_source']['last_activity']; */
					$result = new stdClass;
					$result->relevance = $hit['_score'];
					$result->id = $hit['_source']['ID'];
					$result->type = $searchable;
					$result->entry_date = $hit['_source']['last_activity'];
					$results[] = $result;
					/* $hits[$key2]->relevance = $hit['_score'];
					$hits[$key2]->id = $hit['_source']['ID'];
					$hits[$key2]->type = $searchable;
					$hits[$key2]->entry_date = $hit['_source']['last_activity']; */

					//TODO: should even return the highlighted excerpt etc..., rather than do yet another query to populate the search page
				}
				$total_hits[$searchable] = $bucket['hits']['total']['value'];
				/* foreach ($bucket['hits'] as $hit){
					$hits[] = $hit['_source'];
				}
				$hits[$searchable] = $bucket['hits'];
				$searchable = $hits[0]['_index'];
				
				 */
				//$searchable_counter++;
			}
			/* $hits[]
			$hits       = Elasticsearch::factory()->get_hits_from_query( $response );
			$total_hits = Elasticsearch::factory()->get_total_hits_from_query( $response ); */

			if (!empty($response['aggregations'])) {
				/**
				 * Deprecated way to retrieve aggregations.
				 *
				 * @hook ep_retrieve_aggregations
				 * @param {array} $aggregations Elasticsearch aggregations
				 * @param  {array} $query Prepared Elasticsearch query
				 * @param {string} $scope Backwards compat for scope parameter.
				 * @param  {array} $query_args Current WP Query arguments
				 */
				do_action('ep_retrieve_aggregations', $response['aggregations'], $query, '', $query_args);
			}

			/**
			 * Fires after valid Elasticsearch query
			 *
			 * @hook ep_valid_response
			 * @param {array} $response Elasticsearch decoded response
			 * @param  {array} $query Prepared Elasticsearch query
			 * @param  {array} $query_args Current WP Query arguments
			 * @param  {mixed} $query_object Could be WP_Query, WP_User_Query, etc.
			 */
			do_action('ep_valid_response', $response, $query, $query_args, $query_object);

			// Backwards compat
			/**
			 * Fires after valid Elasticsearch query
			 *
			 * @hook ep_retrieve_raw_response
			 * @param {array} $response Elasticsearch request
			 * @param  {array} $query Prepared Elasticsearch query
			 * @param  {array} $query_args Current WP Query arguments
			 * @param  {mixed} $query_object Could be WP_Query, WP_User_Query, etc.
			 */
			do_action('ep_retrieve_raw_response', $request, $query, $query_args, $query_object);

			$documents = [];

			/* foreach ( $hits as $hit ) {
				$document            = $hit['_source'];
				//$document['site_id'] = Elasticsearch::factory()->parse_site_id( $hit['_index'] );

				if ( ! empty( $hit['highlight'] ) ) {
					$document['highlight'] = $hit['highlight'];
				}
 */
			/**
			 * Filter Elasticsearch retrieved document
			 *
			 * @hook ep_retrieve_the_{index_type}
			 * @param  {array} $document Document retrieved from Elasticsearch
			 * @param  {array} $hit Raw Elasticsearch hit
			 * @param  {string} $index Index name
			 * @return  {array} New document
			 */
			/* 		$documents[] = apply_filters( 'ep_retrieve_the_' . $type, $document, $hit, $index );
			}
 */
			/**
			 * Filter Elasticsearch query results
			 *
			 * @hook ep_es_query_results
			 * @param {array} $results Results from Elasticsearch
			 * @param  {response} $response Raw response from Elasticsearch
			 * @param  {array} $query Raw Elasticsearch query
			 * @param  {array} $query_args Query arguments
			 * @param  {mixed} $query_object Could be WP_Query, WP_User_Query, etc.
			 * @return  {array} New results
			 */
			/* return apply_filters(
				'epbp_es_query_results',
				[
					'found_documents' => $total_hits,
					'documents'       => $hits,
				],
				$response,
				$query,
				$query_args,
				$query_object
			); */
			return [$total_hits, $results];
			//return $es_query;

		}
		/**
		 * Fires after invalid Elasticsearch query
		 *
		 * @hook ep_invalid_response
		 * @param  {array} $request Remote request response
		 * @param  {array} $query Prepared Elasticsearch query
		 * @param  {array} $query_args Current WP Query arguments
		 * @param  {mixed} $query_object Could be WP_Query, WP_User_Query, etc.
		 */
		do_action('ep_invalid_response', $request, $query, $query_args, $query_object);

		return false;
	}


	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	/**
	 * Enable integration on search queries
	 *
	 * @param  bool          $enabled Whether EP is enabled
	 * @since  3.0
	 * @return bool
	 */
	public function integrate_search_queries($enabled, $query) {
		// This is a hack, since BP isn't passing an object.
		if (!is_array($query)) {
			return $enabled;
		}

		if (!array_key_exists('group_type', $query)) {
			return $enabled;
		}

		// @todo
		return true;

		if (isset($query['ep_integrate']) && false === $query['ep_integrate']) {
			$enabled = false;
		} elseif (!empty($query['search_terms'])) {
			$enabled = true;
		}

		return $enabled;
	}

	
}

