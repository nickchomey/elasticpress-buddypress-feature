<?php
/**
 * Elasticsearch mapping for groups.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

return array(
	'settings' => array(
		'index.mapping.total_fields.limit' => apply_filters( 'epbp_group_total_field_limit', 5000 ),
		'index.max_result_window'          => apply_filters( 'epbp_group_max_result_window', 1000000 ),
		'analysis'                         => array(
			'analyzer'   => array(
				'default'          => array(
					'tokenizer' => 'standard',
					'filter'    => array( 'ewp_word_delimiter', 'lowercase', 'stop', 'ewp_snowball' ),
					'language'  => apply_filters( 'ep_analyzer_language', 'english', 'analyzer_default' ),
				),
				'shingle_analyzer' => array(
					'type'      => 'custom',
					'tokenizer' => 'standard',
					'filter'    => array( 'lowercase', 'shingle_filter' ),
				),
				'ewp_lowercase'    => array(
					'type'      => 'custom',
					'tokenizer' => 'keyword',
					'filter'    => array( 'lowercase' ),
				),
			),
			'filter'     => array(
				'shingle_filter'     => array(
					'type'             => 'shingle',
					'min_shingle_size' => 3,
					'max_shingle_size' => 5,
				),
				'ewp_word_delimiter' => array(
					'type'              => 'word_delimiter',
					'preserve_original' => true,
				),
				'ewp_snowball'       => array(
					'type'     => 'snowball',
					'language' => apply_filters( 'ep_analyzer_language', 'english', 'filter_ewp_snowball' ),
				),
				'edge_ngram'         => array(
					'side'     => 'front',
					'max_gram' => 10,
					'min_gram' => 3,
					'type'     => 'edgeNGram',
				),
			),
			'normalizer' => array(
				'lowerasciinormalizer' => array(
					'type'   => 'custom',
					'filter' => array( 'lowercase', 'asciifolding' ),
				),
			),
		),
	),
	'mappings' => array(
		'date_detection'    => true,
		'properties' => array(
			'ID'              => array(
				'type' => 'long',
			),
			'name' => array(
				'type'   => 'text',
				'fields' => array(
					'name' => array(
						'type' => 'text',
						'analyzer' => 'standard',
					),
					'raw'           => array(
						'type'         => 'keyword',
						'ignore_above' => 10922,
					),
					'sortable'   => array(
						'type'         => 'keyword',
						'ignore_above' => 10922,
						'normalizer'   => 'lowerasciinormalizer',
					),
				),
			),
			'description' => array(
				'type'   => 'text',
			),
			'slug' => array(
				'type'   => 'text',
				'fields' => array(
					'slug' => array(
						'type' => 'text',
					),
					'raw'           => array(
						'type'         => 'keyword',
						'ignore_above' => 10922,
					),
				),
			),
			'url'        => array(
				'type'   => 'text',
				'fields' => array(
					'url' => array(
						'type' => 'text',
					),
					'raw'      => array(
						'type'         => 'keyword',
						'ignore_above' => 10922,
					),
				),
			),
			'status'          => array(
				'type'   => 'text',
				'fields' => array(
					'status' => array(
						'type' => 'text',
					),
					'raw'      => array(
						'type'         => 'keyword',
						'ignore_above' => 10922,
					),
				),
			),
			'group_type'            => array(
				'type'   => 'keyword',
				'fields' => array(
					'group_type' => array(
						'type' => 'keyword',
					),
					'raw'      => array(
						'type'         => 'keyword',
						'ignore_above' => 10922,
					),
				),
			),
			'meta'            => array(
				'type' => 'object',
			),
			'creator_id'      => array(
				'type' => 'long',
			),
			'parent_id'      => array(
				'type' => 'long',
			),
			'date_created'             => array(
				'type'   => 'date',
				'format' => 'YYYY-MM-dd HH:mm:ss',
			),
			'last_activity'             => array(
				'type'   => 'date',
				'format' => 'YYYY-MM-dd HH:mm:ss',
			),
			'total_member_count' => array(
				'type' => 'long',
			),
		),
		'dynamic_templates' => array(
			array(
				'template_meta_types' => array(
					'path_match' => 'meta.*',
					'mapping'    => array(
						'type'       => 'nested',
						'path'       => 'full',
						'properties' => array(
							'value'    => array(
								'type'   => 'keyword',
								'fields' => array(
									'sortable' => array(
										'type'         => 'keyword',
										'ignore_above' => 10922,
										'normalizer'   => 'lowerasciinormalizer',
									),
									'raw'      => array(
										'type'         => 'keyword',
										'ignore_above' => 10922,
									),
								),
							),
							'raw'      => array( /* Left for backwards compat */
								'type'         => 'keyword',
								'ignore_above' => 10922,
							),
							'long'     => array(
								'type' => 'long',
							),
							'double'   => array(
								'type' => 'double',
							),
							'boolean'  => array(
								'type' => 'boolean',
							),
							'date'     => array(
								'type'   => 'date',
								'format' => 'yyyy-MM-dd',
							),
							'datetime' => array(
								'type'   => 'date',
								'format' => 'yyyy-MM-dd HH:mm:ss',
							),
							'time'     => array(
								'type'   => 'date',
								'format' => 'HH:mm:ss',
							),
						),
					),
				),
			),
		),
	),
);
