<?php
/**
 * Plugin Name: MCP Abilities - Content Demand
 * Plugin URI: https://devenia.com/
 * Description: Tracks zero-result site searches and exposes content demand candidates through MCP abilities.
 * Version: 0.1.0
 * Author: basicus
 * Author URI: https://profiles.wordpress.org/basicus/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Requires PHP: 8.0
 *
 * @package MCP_Abilities_Content_Demand
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MCP_CONTENT_DEMAND_DB_VERSION = '1';
const MCP_CONTENT_DEMAND_OPTION_DB_VERSION = 'mcp_content_demand_db_version';

/**
 * Return the content-demand table name.
 */
function mcp_content_demand_table_name(): string {
	global $wpdb;
	return esc_sql( $wpdb->prefix . 'mcp_content_demand_searches' );
}

/**
 * Install or update the plugin table.
 */
function mcp_content_demand_install(): void {
	global $wpdb;

	$table_name      = mcp_content_demand_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		term varchar(190) NOT NULL,
		normalized_term varchar(190) NOT NULL,
		term_hash char(64) NOT NULL,
		search_count bigint(20) unsigned NOT NULL DEFAULT 0,
		zero_result_count bigint(20) unsigned NOT NULL DEFAULT 0,
		last_result_count int unsigned NOT NULL DEFAULT 0,
		status varchar(32) NOT NULL DEFAULT 'new',
		first_seen datetime NOT NULL,
		last_seen datetime NOT NULL,
		last_url text NULL,
		last_referrer text NULL,
		notes text NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY term_hash (term_hash),
		KEY status_zero_result_count (status, zero_result_count),
		KEY last_seen (last_seen)
	) {$charset_collate};";

	dbDelta( $sql );
	update_option( MCP_CONTENT_DEMAND_OPTION_DB_VERSION, MCP_CONTENT_DEMAND_DB_VERSION, false );
}
register_activation_hook( __FILE__, 'mcp_content_demand_install' );

/**
 * Ensure schema exists after deploys where activation did not run.
 */
function mcp_content_demand_maybe_install(): void {
	if ( get_option( MCP_CONTENT_DEMAND_OPTION_DB_VERSION ) !== MCP_CONTENT_DEMAND_DB_VERSION ) {
		mcp_content_demand_install();
	}
}
add_action( 'init', 'mcp_content_demand_maybe_install', 5 );

/**
 * Check if Abilities API is available.
 */
function mcp_content_demand_check_dependencies(): bool {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				echo '<div class="notice notice-error"><p><strong>MCP Abilities - Content Demand</strong> requires the Abilities API plugin to be installed and activated.</p></div>';
			}
		);
		return false;
	}

	return true;
}

/**
 * Ability permission callback.
 */
function mcp_content_demand_permission_callback(): bool {
	return current_user_can( 'edit_pages' );
}

/**
 * Register an ability safely even when the registry initialized before this plugin loaded.
 *
 * @param string              $name Ability name.
 * @param array<string,mixed> $args Ability args.
 */
function mcp_content_demand_register_ability( string $name, array $args ): void {
	if ( doing_action( 'wp_abilities_api_init' ) ) {
		wp_register_ability( $name, $args );
		return;
	}

	$registry = class_exists( 'WP_Abilities_Registry' ) ? WP_Abilities_Registry::get_instance() : null;
	if ( $registry && ! $registry->is_registered( $name ) ) {
		$registry->register( $name, $args );
	}
}

/**
 * Normalize a search term for aggregation and privacy.
 */
function mcp_content_demand_normalize_term( string $raw_term ): string {
	$term = html_entity_decode( wp_strip_all_tags( $raw_term ), ENT_QUOTES, get_bloginfo( 'charset' ) );
	$term = strtolower( remove_accents( $term ) );
	$term = preg_replace( '/https?:\/\/\S+/i', ' ', $term ) ?? $term;
	$term = preg_replace( '/\b[A-Z0-9._%+\-]+@([A-Z0-9.\-]+\.[A-Z]{2,})\b/i', '$1', $term ) ?? $term;
	$term = preg_replace( '/[^\p{L}\p{N}\s.\-+]/u', ' ', $term ) ?? $term;
	$term = preg_replace( '/\s+/u', ' ', $term ) ?? $term;
	$term = trim( $term, " \t\n\r\0\x0B.-+" );

	if ( strlen( $term ) > 190 ) {
		$term = substr( $term, 0, 190 );
		$term = trim( $term );
	}

	return $term;
}

/**
 * Decide whether a normalized term is worth storing.
 */
function mcp_content_demand_should_log_term( string $normalized_term ): bool {
	if ( strlen( $normalized_term ) < 2 ) {
		return false;
	}

	if ( preg_match( '/^\d+$/', $normalized_term ) ) {
		return false;
	}

	return true;
}

/**
 * Detect obvious bots to avoid polluting content demand.
 */
function mcp_content_demand_is_bot_request(): bool {
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	if ( '' === $user_agent ) {
		return false;
	}

	return (bool) preg_match( '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|duckduckgo|semrush|ahrefs|mj12|siteaudit/i', $user_agent );
}

/**
 * Record a frontend search query.
 */
function mcp_content_demand_record_search( string $raw_term, int $result_count, string $url = '', string $referrer = '' ): bool {
	global $wpdb;

	$normalized_term = mcp_content_demand_normalize_term( $raw_term );
	if ( ! mcp_content_demand_should_log_term( $normalized_term ) ) {
		return false;
	}

	$table_name        = mcp_content_demand_table_name();
	$now               = current_time( 'mysql' );
	$term_hash         = hash( 'sha256', $normalized_term );
	$zero_result_delta = 0 === $result_count ? 1 : 0;
	$display_term      = substr( $normalized_term, 0, 190 );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom aggregate table; table name is built from the current site's escaped prefix.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
	$existing_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table_name} WHERE term_hash = %s LIMIT 1",
			$term_hash
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( $existing_id > 0 ) {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom aggregate table; table name is built from the current site's escaped prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table_name}
				SET search_count = search_count + 1,
					zero_result_count = zero_result_count + %d,
					last_result_count = %d,
					last_seen = %s,
					last_url = %s,
					last_referrer = %s
				WHERE id = %d",
				$zero_result_delta,
				max( 0, $result_count ),
				$now,
				$url,
				$referrer,
				$existing_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return false !== $updated;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom aggregate table insert.
	$inserted = $wpdb->insert(
		$table_name,
		array(
			'term'              => $display_term,
			'normalized_term'   => $normalized_term,
			'term_hash'         => $term_hash,
			'search_count'      => 1,
			'zero_result_count' => $zero_result_delta,
			'last_result_count' => max( 0, $result_count ),
			'status'            => 'new',
			'first_seen'        => $now,
			'last_seen'         => $now,
			'last_url'          => $url,
			'last_referrer'     => $referrer,
		),
		array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
	);

	return false !== $inserted;
}

/**
 * Log zero-result frontend searches after the main query is known.
 */
function mcp_content_demand_log_frontend_search(): void {
	if ( is_admin() || ! is_search() || mcp_content_demand_is_bot_request() ) {
		return;
	}

	global $wp_query;

	if ( ! $wp_query instanceof WP_Query || (int) $wp_query->found_posts > 0 ) {
		return;
	}

	$raw_term = get_search_query( false );
	$url      = home_url( add_query_arg( null, null ) );
	$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

	mcp_content_demand_record_search( $raw_term, 0, esc_url_raw( $url ), $referrer );
}
add_action( 'template_redirect', 'mcp_content_demand_log_frontend_search', 20 );

/**
 * Generate a conservative content candidate title.
 */
function mcp_content_demand_suggest_title( string $term ): string {
	$clean = trim( $term );
	if ( '' === $clean ) {
		return '';
	}

	if ( preg_match( '/(gmail|outlook|hotmail|yahoo|icloud|proton|aol|zoho|microsoft 365|office 365)/i', $clean ) ) {
		return sprintf( '%s attachment size limit: check if your file fits', ucwords( $clean ) );
	}

	return sprintf( '%s: check if your file fits email', ucwords( $clean ) );
}

/**
 * Convert a database row to MCP output.
 *
 * @param object $row Database row.
 * @return array<string,mixed>
 */
function mcp_content_demand_normalize_row( object $row ): array {
	$term = (string) $row->normalized_term;

	return array(
		'id'                => (int) $row->id,
		'term'              => (string) $row->term,
		'normalized_term'   => $term,
		'search_count'      => (int) $row->search_count,
		'zero_result_count' => (int) $row->zero_result_count,
		'last_result_count' => (int) $row->last_result_count,
		'status'            => (string) $row->status,
		'first_seen'        => (string) $row->first_seen,
		'last_seen'         => (string) $row->last_seen,
		'last_url'          => (string) $row->last_url,
		'suggested_title'   => mcp_content_demand_suggest_title( $term ),
		'suggested_slug'    => sanitize_title( mcp_content_demand_suggest_title( $term ) ),
		'quality_gate'      => array(
			'publish_directly'       => false,
			'requires_fact_checking' => true,
			'requires_human_review'  => true,
			'note'                   => 'Use this as demand evidence only. Draft high-quality Gutenberg content, verify facts from primary sources, then review before publishing.',
		),
	);
}

/**
 * Register content-demand abilities.
 */
function mcp_content_demand_register_abilities(): void {
	if ( ! mcp_content_demand_check_dependencies() ) {
		return;
	}

	mcp_content_demand_register_ability(
		'content-demand/list-candidates',
		array(
			'label'               => 'List Content Demand Candidates',
			'description'         => 'Lists aggregated zero-result site search terms that may justify high-quality new content.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'min_zero_results' => array(
						'type'        => 'integer',
						'default'     => 1,
						'minimum'     => 1,
						'description' => 'Minimum zero-result searches required.',
					),
					'status'           => array(
						'type'        => 'string',
						'default'     => 'new',
						'description' => 'Candidate status: new, planned, published, ignored, or all.',
					),
					'limit'            => array(
						'type'        => 'integer',
						'default'     => 25,
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => 'Maximum candidates to return.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'candidates' => array( 'type' => 'array' ),
					'count'      => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				global $wpdb;

				$table_name       = mcp_content_demand_table_name();
				$min_zero_results = max( 1, (int) ( $input['min_zero_results'] ?? 1 ) );
				$status           = sanitize_key( (string) ( $input['status'] ?? 'new' ) );
				$limit            = min( 100, max( 1, (int) ( $input['limit'] ?? 25 ) ) );
				$where            = 'zero_result_count >= %d';
				$args             = array( $min_zero_results );

				if ( 'all' !== $status ) {
					$where .= ' AND status = %s';
					$args[] = $status;
				}

				$args[] = $limit;
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom aggregate table; query is assembled from sanitized fixed clauses and prepared values.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
				$rows   = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$table_name}
						WHERE {$where}
						ORDER BY zero_result_count DESC, last_seen DESC
						LIMIT %d",
						$args
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				$candidates = array_map( 'mcp_content_demand_normalize_row', is_array( $rows ) ? $rows : array() );

				return array(
					'success'    => true,
					'candidates' => $candidates,
					'count'      => count( $candidates ),
				);
			},
			'permission_callback' => 'mcp_content_demand_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => false,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);

	mcp_content_demand_register_ability(
		'content-demand/update-candidate',
		array(
			'label'               => 'Update Content Demand Candidate',
			'description'         => 'Updates the workflow status or notes for a content-demand candidate.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => 'Candidate ID.',
					),
					'status' => array(
						'type'        => 'string',
						'description' => 'Status: new, planned, published, or ignored.',
					),
					'notes'  => array(
						'type'        => 'string',
						'description' => 'Internal notes for the agent workflow.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'candidate' => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => function ( array $input = array() ): array {
				global $wpdb;

				$table_name = mcp_content_demand_table_name();
				$id         = (int) ( $input['id'] ?? 0 );
				if ( $id <= 0 ) {
					return array( 'success' => false, 'message' => 'Candidate ID is required.' );
				}

				$updates = array();
				$formats = array();

				if ( isset( $input['status'] ) ) {
					$status = sanitize_key( (string) $input['status'] );
					if ( ! in_array( $status, array( 'new', 'planned', 'published', 'ignored' ), true ) ) {
						return array( 'success' => false, 'message' => 'Invalid status.' );
					}
					$updates['status'] = $status;
					$formats[]         = '%s';
				}

				if ( isset( $input['notes'] ) ) {
					$updates['notes'] = sanitize_textarea_field( (string) $input['notes'] );
					$formats[]        = '%s';
				}

				if ( empty( $updates ) ) {
					return array( 'success' => false, 'message' => 'No updates provided.' );
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom aggregate table update.
				$updated = $wpdb->update( $table_name, $updates, array( 'id' => $id ), $formats, array( '%d' ) );
				if ( false === $updated ) {
					return array( 'success' => false, 'message' => 'Failed to update candidate.' );
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom aggregate table; table name is built from the current site's escaped prefix.
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ) );

				return array(
					'success'   => true,
					'candidate' => $row ? mcp_content_demand_normalize_row( $row ) : null,
				);
			},
			'permission_callback' => 'mcp_content_demand_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => false,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);
}
add_action( 'wp_abilities_api_init', 'mcp_content_demand_register_abilities' );
