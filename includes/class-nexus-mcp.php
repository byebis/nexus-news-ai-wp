<?php
/**
 * Nexus MCP — superficie REST compatibile con Model Context Protocol (MCP)
 * per agenti esterni (Claude, ChatGPT, altri). v1.1.31 (gap #3).
 *
 * Espone:
 *  - GET  /nexus/v1/mcp/tools  → tool discovery (stile MCP listTools);
 *  - POST /nexus/v1/mcp/call   → invocazione tool (stile MCP callTool).
 *
 * Feature premium: gli endpoint richiedono perm_admin (nonce + manage_options)
 * e licenza Pro (Nexus_License::is_pro()) → 403 'nexus_pro_required'.
 *
 * @package Nexus_News_AI
 * @since   1.1.31
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Nexus_MCP {

	const NAMESPACE = 'nexus/v1';
	const BASE = '/mcp';

	/** @var array Catalogo strumenti {name, description, inputSchema}. */
	private static $catalog = null;

	/**
	 * Self-init (su nexus_modules_init).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registra le route MCP.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/tools',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_tools' ),
					'permission_callback' => array( __CLASS__, 'perm' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/call',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_call' ),
					'permission_callback' => array( __CLASS__, 'perm' ),
					'args'                => array(
						'name' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'arguments' => array(
							'type'              => 'object',
							'required'          => false,
							'default'           => array(),
						),
					),
				),
			)
		);
	}

	/**
	 * Permesso: nonce REST + manage_options + licenza Pro.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return true|WP_Error
	 */
	public static function perm( $request ) {
		$nonce = ( $request instanceof \WP_REST_Request ) ? (string) $request->get_header( 'X-WP-Nonce' ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'nexus_mcp_forbidden_nonce', __( 'Nonce REST mancante o non valido.', 'nexus-news-ai' ), array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'nexus_mcp_forbidden', __( 'Non hai i permessi per questa risorsa.', 'nexus-news-ai' ), array( 'status' => 403 ) );
		}
		if ( class_exists( 'Nexus_License' ) && method_exists( 'Nexus_License', 'is_pro' ) && ! Nexus_License::is_pro() ) {
			return new WP_Error( 'nexus_pro_required', __( 'L\'integrazione MCP richiede il piano Pro.', 'nexus-news-ai' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Catalogo strumenti MCP.
	 *
	 * @return array
	 */
	public static function catalog() {
		if ( null !== self::$catalog ) {
			return self::$catalog;
		}

		self::$catalog = array(
			array(
				'name'        => 'nexus_get_status',
				'description' => 'Riepilogo stato della redazione (conteggi, agenti attivi, health).',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass(), 'required' => array() ),
			),
			array(
				'name'        => 'nexus_list_articles',
				'description' => 'Elenca articoli (filtrabili per stato).',
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'status' => array( 'type' => 'string', 'description' => 'draft|pending_approval|approved|published|rejected' ),
						'limit'  => array( 'type' => 'integer', 'description' => 'Max risultati (default 10)' ),
					),
					'required' => array(),
				),
			),
			array(
				'name'        => 'nexus_get_article',
				'description' => 'Dettaglio di un articolo per id.',
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer', 'description' => 'ID articolo' ),
					),
					'required' => array( 'id' ),
				),
			),
			array(
				'name'        => 'nexus_schedule_publish',
				'description' => 'Programma la pubblicazione di un articolo (data/ora futura).',
				'inputSchema' => array(
					'type' => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer', 'description' => 'ID articolo' ),
						'scheduled_at' => array( 'type' => 'string', 'description' => 'Data/ora futura (es. 2026-12-01 09:00:00)' ),
					),
					'required' => array( 'id', 'scheduled_at' ),
				),
			),
			array(
				'name'        => 'nexus_get_settings_status',
				'description' => 'Stato connessioni/impostazioni (flag senza segreti).',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass(), 'required' => array() ),
			),
		);

		return self::$catalog;
	}

	/**
	 * GET /mcp/tools — discovery.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_tools() {
		return rest_ensure_response(
			array(
				'tools'          => self::catalog(),
				'protocol'       => 'mcp-json-rpc',
				'mcpApiVersion'  => '2025-06-18',
			)
		);
	}

	/**
	 * POST /mcp/call — invocazione tool.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_call( $request ) {
		$name = sanitize_key( (string) $request->get_param( 'name' ) );
		$args = $request->get_param( 'arguments' );
		$args = is_array( $args ) ? $args : array();

		$result = self::call( $name, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE ) ) ),
			)
		);
	}

	/**
	 * Esegue un tool MCP.
	 *
	 * @param string $name Nome tool.
	 * @param array  $args Argomenti.
	 * @return mixed|WP_Error
	 */
	public static function call( $name, array $args ) {
		switch ( $name ) {
			case 'nexus_get_status':
				$stats = array();
				if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'stats' ) ) {
					$stats = Nexus_DB::stats();
				}
				return array( 'ok' => true, 'stats' => $stats );

			case 'nexus_list_articles':
				if ( ! class_exists( 'Nexus_DB' ) ) {
					return new WP_Error( 'nexus_mcp_unavailable', __( 'DB non disponibile.', 'nexus-news-ai' ), array( 'status' => 501 ) );
				}
				$status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
				$limit  = isset( $args['limit'] ) ? min( 50, max( 1, (int) $args['limit'] ) ) : 10;
				$qargs  = array( 'per_page' => $limit );
				if ( '' !== $status ) {
					$qargs['status'] = $status;
				}
				$rows = Nexus_DB::articles( $qargs );
				$items = array();
				foreach ( (array) $rows as $a ) {
					$items[] = array(
						'id'         => (int) $a->id,
						'title'      => (string) $a->title,
						'status'     => (string) $a->status,
						'category'   => (string) $a->category,
						'agent_id'   => (int) $a->agent_id,
						'published_at' => (string) ( $a->published_at ?? '' ),
						'scheduled_at' => (string) ( $a->scheduled_at ?? '' ),
					);
				}
				return array( 'ok' => true, 'items' => $items, 'total' => count( $items ) );

			case 'nexus_get_article':
				if ( ! class_exists( 'Nexus_DB' ) ) {
					return new WP_Error( 'nexus_mcp_unavailable', __( 'DB non disponibile.', 'nexus-news-ai' ), array( 'status' => 501 ) );
				}
				$id = isset( $args['id'] ) ? absint( $args['id'] ) : 0;
				$a = Nexus_DB::get_article( $id );
				if ( ! $a ) {
					return new WP_Error( 'nexus_mcp_article_not_found', __( 'Articolo non trovato.', 'nexus-news-ai' ), array( 'status' => 404 ) );
				}
				return array(
					'ok'    => true,
					'article' => array(
						'id'       => (int) $a->id,
						'title'    => (string) $a->title,
						'summary'  => (string) ( $a->summary ?? '' ),
						'category' => (string) $a->category,
						'status'   => (string) $a->status,
						'published_at' => (string) ( $a->published_at ?? '' ),
					),
				);

			case 'nexus_schedule_publish':
				return self::schedule_publish( $args );

			case 'nexus_get_settings_status':
				if ( class_exists( 'Nexus_Rest' ) && method_exists( 'Nexus_Rest', 'settings_status_flags' ) ) {
					return array( 'ok' => true, 'status' => Nexus_Rest::settings_status_flags() );
				}
				return array( 'ok' => true, 'status' => array() );

			default:
				return new WP_Error( 'nexus_mcp_unknown_tool', sprintf( __( 'Strumento MCP sconosciuto: %s', 'nexus-news-ai' ), $name ), array( 'status' => 400 ) );
		}
	}

	/**
	 * Programma la pubblicazione di un articolo (logica allineata a
	 * Nexus_Rest::rest_schedule_post).
	 *
	 * @param array $args Argomenti {id, scheduled_at}.
	 * @return array|WP_Error
	 */
	private static function schedule_publish( array $args ) {
		$id = isset( $args['id'] ) ? absint( $args['id'] ) : 0;
		$scheduled_at = isset( $args['scheduled_at'] ) ? (string) $args['scheduled_at'] : '';
		$ts = strtotime( $scheduled_at );
		if ( false === $ts ) {
			return new WP_Error( 'nexus_mcp_invalid_date', __( 'Data/ora non valida.', 'nexus-news-ai' ), array( 'status' => 400 ) );
		}
		if ( $ts <= (int) current_time( 'timestamp' ) ) {
			return new WP_Error( 'nexus_mcp_past_date', __( 'La data di pubblicazione deve essere futura.', 'nexus-news-ai' ), array( 'status' => 400 ) );
		}
		$article = Nexus_DB::get_article( $id );
		if ( ! $article ) {
			return new WP_Error( 'nexus_mcp_article_not_found', __( 'Articolo non trovato.', 'nexus-news-ai' ), array( 'status' => 404 ) );
		}
		$allowed_from = array( 'draft', 'pending_approval', 'approved' );
		if ( ! in_array( (string) $article->status, $allowed_from, true ) ) {
			return new WP_Error( 'nexus_mcp_cannot_schedule', __( 'L\'articolo non può essere programmato nello stato attuale.', 'nexus-news-ai' ), array( 'status' => 409 ) );
		}
		$mysql = date( 'Y-m-d H:i:s', $ts ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		Nexus_DB::update_article( $id, array( 'status' => 'scheduled', 'scheduled_at' => $mysql ) );
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'nexus_publish_scheduled', array( $id ) );
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( $ts, 'nexus_publish_scheduled', array( $id ) );
		}
		return array( 'ok' => true, 'article_id' => $id, 'scheduled_at' => $mysql );
	}
}

// Self-init (bootstrap non toccato).
add_action( 'nexus_modules_init', array( 'Nexus_MCP', 'init' ) );
