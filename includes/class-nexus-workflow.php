<?php
/**
 * Nexus Workflow — regole automatizzate (v1.1.31). Endpoint REST associati
 * in class-nexus-rest.php (media/set, media/regenerate, workflow/rules).
 *
 * Persistenza: option 'nexus_workflow_rules' (array di {condition, action,
 * value}). Valutazione nel flusso pipeline (dopo la valutazione AI, prima
 * della promozione): se una regola combacia con fonte/categoria/punteggio/
 * stato, esegue l'azione (approve|schedule|publish|notify).
 *
 * @package Nexus_News_AI
 * @since   1.1.31
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Nexus_Workflow {

	const OPTION = 'nexus_workflow_rules';

	const CONDITIONS = array( 'source', 'category', 'score', 'status' );
	const ACTIONS    = array( 'approve', 'schedule', 'publish', 'notify' );

	/**
	 * Legge le regole salvate.
	 *
	 * @return array<int,array{condition:string,action:string,value:string}>
	 */
	public static function get_rules() {
		$rules = get_option( self::OPTION, array() );
		if ( ! is_array( $rules ) ) {
			return array();
		}
		return $rules;
	}

	/**
	 * Sanitizza un array di regole (input REST/UI).
	 *
	 * @param mixed $input Input grezzo.
	 * @return array<int,array{condition:string,action:string,value:string}>
	 */
	public static function sanitize_rules( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$out = array();
		foreach ( $input as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$condition = isset( $rule['condition'] ) ? sanitize_key( (string) $rule['condition'] ) : '';
			$action    = isset( $rule['action'] ) ? sanitize_key( (string) $rule['action'] ) : '';
			$value     = isset( $rule['value'] ) ? sanitize_text_field( (string) $rule['value'] ) : '';
			if ( ! in_array( $condition, self::CONDITIONS, true ) || ! in_array( $action, self::ACTIONS, true ) ) {
				continue;
			}
			if ( 'score' === $condition && ! is_numeric( $value ) ) {
				continue;
			}
			$out[] = array(
				'condition' => $condition,
				'action'    => $action,
				'value'     => $value,
			);
		}
		return $out;
	}

	/**
	 * Salva le regole (sanitizzate).
	 *
	 * @param mixed $rules Input.
	 * @return array Regole salvate.
	 */
	public static function save_rules( $rules ) {
		$clean = self::sanitize_rules( $rules );
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * Valuta le regole su un articolo ed esegue le azioni combacianti.
	 * Best-effort, mai fatale.
	 *
	 * @param object|int $article Riga articolo o ID.
	 * @return array{matched:int,actions:array}
	 */
	public static function evaluate_article( $article ) {
		$out = array( 'matched' => 0, 'actions' => array() );
		$rules = self::get_rules();
		if ( ! $rules ) {
			return $out;
		}

		if ( is_numeric( $article ) ) {
			$article = Nexus_DB::get_article( (int) $article );
		}
		if ( ! is_object( $article ) || empty( $article->id ) ) {
			return $out;
		}

		foreach ( $rules as $rule ) {
			if ( ! self::rule_matches( $rule, $article ) ) {
				continue;
			}
			$out['matched']++;
			$result = self::execute_action( $rule['action'], $rule['value'], $article );
			$out['actions'][] = array( 'action' => $rule['action'], 'ok' => ! empty( $result['ok'] ), 'note' => $result['note'] );
		}

		return $out;
	}

	/**
	 * La regola combacia con l'articolo?
	 *
	 * @param array  $rule    {condition, action, value}.
	 * @param object $article Riga articolo.
	 * @return bool
	 */
	private static function rule_matches( array $rule, $article ) {
		$condition = $rule['condition'];
		$value     = (string) $rule['value'];

		switch ( $condition ) {
			case 'source':
				$field = (string) ( $article->source_name ?? '' );
				if ( '' === $field ) {
					$field = (string) ( $article->source_type ?? '' );
				}
				return strcasecmp( $field, $value ) === 0;

			case 'category':
				return strcasecmp( (string) $article->category, $value ) === 0;

			case 'score':
				return (float) $article->quality_score >= (float) $value;

			case 'status':
				return strcasecmp( (string) $article->status, $value ) === 0;
		}

		return false;
	}

	/**
	 * Esegue l'azione della regola (con guardie di stato).
	 *
	 * @param string $action  Azione.
	 * @param string $value   Valore (per schedule = data futura).
	 * @param object $article Riga articolo.
	 * @return array{ok:bool,note:string}
	 */
	private static function execute_action( $action, $value, $article ) {
		$id = (int) $article->id;
		$status = (string) $article->status;

		switch ( $action ) {
			case 'approve':
				if ( in_array( $status, array( 'draft', 'pending_approval' ), true ) && class_exists( 'Nexus_Pipeline' ) && method_exists( 'Nexus_Pipeline', 'approve' ) ) {
					$res = Nexus_Pipeline::approve( $id, __( 'Approvazione automatica (regola workflow).', 'nexus-news-ai' ) );
					return array( 'ok' => ! empty( $res['ok'] ), 'note' => 'approve' );
				}
				return array( 'ok' => false, 'note' => 'approve: stato non ammesso' );

			case 'publish':
				if ( in_array( $status, array( 'pending_approval', 'approved', 'draft' ), true ) && class_exists( 'Nexus_Pipeline' ) && method_exists( 'Nexus_Pipeline', 'publish' ) ) {
					$res = Nexus_Pipeline::publish( $id );
					return array( 'ok' => ! empty( $res['ok'] ), 'note' => 'publish' );
				}
				return array( 'ok' => false, 'note' => 'publish: stato non ammesso' );

			case 'schedule':
				$ts = strtotime( (string) $value );
				if ( false === $ts || $ts <= (int) current_time( 'timestamp' ) ) {
					return array( 'ok' => false, 'note' => 'schedule: data non valida/futura' );
				}
				$mysql = date( 'Y-m-d H:i:s', $ts ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
				Nexus_DB::update_article( $id, array( 'status' => 'scheduled', 'scheduled_at' => $mysql ) );
				if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
					wp_clear_scheduled_hook( 'nexus_publish_scheduled', array( $id ) );
				}
				if ( function_exists( 'wp_schedule_single_event' ) ) {
					wp_schedule_single_event( $ts, 'nexus_publish_scheduled', array( $id ) );
				}
				return array( 'ok' => true, 'note' => 'schedule' );

			case 'notify':
			default:
				if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
					Nexus_DB::insert_activity( 0, 'workflow', sprintf( 'Regola workflow: notifica per articolo #%d (%s).', $id, (string) $article->title ), 'info' );
				}
				if ( class_exists( 'Nexus_Notify' ) && method_exists( 'Nexus_Notify', 'send_generic' ) ) {
					Nexus_Notify::send_generic( 'pending', sprintf( 'Regola workflow su articolo #%d: %s', $id, (string) $article->title ) );
				}
				return array( 'ok' => true, 'note' => 'notify' );
		}
	}
}
