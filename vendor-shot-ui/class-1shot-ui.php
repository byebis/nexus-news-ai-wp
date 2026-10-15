<?php
/**
 * 1shot-UI-Kit v2.0.0 — Helper PHP condiviso
 *
 * Un unico file da includere in TUTTI i 4 plugin byebis:
 *   1shotwp · keeper-ai · 1shot-Visibility · nexus-news-ai
 *
 * v2.0.0 — LEGGERO/VELOCE/NATIVO: il kit è SOLO CSS (zero JS caricati,
 * zero nonce/REST/localize). Nessun tema scuro (solo light). Gli helper
 * di markup sono puro PHP escapato. Per toast/conferme/modal usare i
 * componenti nativi di WP o <details>/<summary> per gli accordion.
 *
 * Uso nel file principale del plugin:
 *
 *   require_once __DIR__ . '/vendor-shot-ui/class-1shot-ui.php';
 *   \Shot_UI::init( array( 'plugin_slug' => '1shot-visibility' ) );
 *
 *   (il parametro 'dark_setting' delle versioni ≤ 1.1.1 è ancora accettato
 *   ma IGNORED: nessun tema scuro in v2.0. Nessuna modifica richiesta alle
 *   chiamate esistenti.)
 *
 * In ogni pagina admin:
 *
 *   \Shot_UI::enqueue();           // enqueue CSS (idempotente)
 *
 * Helper di markup opzionali (tutti escapati):
 *
 *   \Shot_UI::page_head( 'Titolo', 'sottotitolo', array( ...azioni ) ); // default minimalista
 *   \Shot_UI::hero( 'Titolo', 'tagline', '🛡️', array( ...azioni ) );   // variante banner navy
 *   \Shot_UI::btn( 'Esegui scan', array( 'primary' => true, 'attrs' => array( 'data-run' => '1' ) ) );
 *   \Shot_UI::badge( 'ok', 'Pass' );            // ok|warn|err|info|neutral
 *   \Shot_UI::pill( true, 'Attivo' );           // pill on/off
 *   \Shot_UI::toggle( 'opt_enabled', true, 'Abilita modulo' );
 *   \Shot_UI::empty_state( '🔍', 'Nessun risultato', 'Prova a modificare i filtri.', 'Reset filtri' );
 *
 * NOTA per i plugin namespaced: chiamare SEMPRE \Shot_UI:: con backslash
 * iniziale (o aggiungere `use Shot_UI;` in testa al file).
 *
 * @package Shot_UI
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Il kit è vendored in TUTTI i plugin byebis: se un altro plugin l'ha già
 * caricato, questa copia esce subito (evita "Cannot declare class Shot_UI"). */
if ( class_exists( 'Shot_UI' ) ) {
	return;
}

class Shot_UI {

	const VERSION = '2.0.0';

	/** @var array Config corrente. */
	private static $cfg = array(
		'plugin_slug' => '',
	);

	/** @var bool Enqueue già fatto? */
	private static $enqueued = false;

	/**
	 * Bootstrapping: chiama una sola volta nel file principale del plugin.
	 *
	 * @param array $cfg { plugin_slug: string, dark_setting?: string }.
	 *                      dark_setting è accettato per back-compat ma ignorato (v2.0: solo light).
	 */
	public static function init( $cfg = array() ) {
		/* v2.0: solo plugin_slug è usato; dark_setting e altre chiavi
		 * legacy sono accettate e ignorate silenziosamente. */
		if ( isset( $cfg['plugin_slug'] ) ) {
			self::$cfg['plugin_slug'] = (string) $cfg['plugin_slug'];
		}

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Registra il foglio di stile (una sola volta per request). Versione da
	 * filemtime per cache-busting automatico a ogni aggiornamento del kit.
	 */
	public static function register_assets() {
		$base_url = plugin_dir_url( self::kit_path() . '/1shot-ui.css' );
		$css_path = self::kit_path() . '/1shot-ui.css';
		$ver      = self::VERSION . '.' . (string) filemtime( $css_path );
		wp_register_style( 'shot-ui', $base_url . '1shot-ui.css', array(), $ver );
	}

	/**
	 * Enqueue del CSS. Chiamare nelle view/pagine admin che usano il kit.
	 */
	public static function enqueue() {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		wp_enqueue_style( 'shot-ui' );
	}

	/**
	 * Percorso assoluto della cartella del kit.
	 */
	private static function kit_path() {
		return __DIR__;
	}

	/* ==================================================================
	 * Helper di markup (opzionali, tutti escapati)
	 * ================================================================ */

	/**
	 * Page head minimalista (v1.1.0) — il DEFAULT senza banner:
	 * h1 + trattino-accento gradiente + sottotitolo, modello nexus-news-ai.
	 *
	 * @param string $title   Titolo h1.
	 * @param string $tagline Sottotitolo opzionale.
	 * @param array  $actions Array di array { label, url?, primary?, attrs? }.
	 */
	public static function page_head( $title, $tagline = '', $actions = array() ) {
		echo '<div class="sui-page-head">';
		echo '<h1 class="sui-page-head__title">' . esc_html( $title ) . '</h1>';
		if ( $tagline ) {
			echo '<p class="sui-page-head__sub">' . esc_html( $tagline ) . '</p>';
		}
		if ( $actions ) {
			echo '<div class="sui-page-head__actions">';
			foreach ( $actions as $a ) {
				self::btn(
					isset( $a['label'] ) ? $a['label'] : '',
					array(
						'primary' => ! empty( $a['primary'] ),
						'href'    => isset( $a['url'] ) ? $a['url'] : '',
						'attrs'   => isset( $a['attrs'] ) ? $a['attrs'] : array(),
					)
				);
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Hero navy (variante banner): gradiente blu scuro→chiaro, titolo con
	 * trattino-accento. Per la home del plugin; le sottopagine usano
	 * page_head().
	 *
	 * @param string $title   Titolo h1.
	 * @param string $tagline Sottotitolo.
	 * @param string $icon    Emoji/logo testuale (viene escapata: passare testo, non markup).
	 * @param array  $actions Array di array { label, url?, primary?, attrs? }.
	 */
	public static function hero( $title, $tagline = '', $icon = '🛡️', $actions = array() ) {
		echo '<div class="sui-hero">';
		echo '<div class="sui-hero__brand">';
		echo '<div class="sui-hero__logo" aria-hidden="true">' . esc_html( $icon ) . '</div>';
		echo '<div><h1 class="sui-hero__title">' . esc_html( $title ) . '</h1>';
		if ( $tagline ) {
			echo '<p class="sui-hero__tagline">' . esc_html( $tagline ) . '</p>';
		}
		echo '</div></div>';
		if ( $actions ) {
			echo '<div class="sui-hero__actions">';
			foreach ( $actions as $a ) {
				self::btn(
					$a['label'],
					array(
						'primary' => ! empty( $a['primary'] ),
						'href'    => isset( $a['url'] ) ? $a['url'] : '',
						'attrs'   => isset( $a['attrs'] ) ? $a['attrs'] : array(),
					)
				);
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Bottone/ancora kit.
	 *
	 * NOTA: $args['attrs'] è trusted input (escape hatch per sviluppatori host):
	 * i NOMI degli attributi non sono filtrati (es. serve per data-* e aria-*).
	 * I VALORI sono sempre escapati.
	 *
	 * @param string $label Etichetta.
	 * @param array  $args  { primary?, ghost?, danger?, small?, href?, attrs? }.
	 */
	public static function btn( $label, $args = array() ) {
		$classes = array( 'sui-btn' );
		if ( ! empty( $args['primary'] ) ) { $classes[] = 'sui-btn--primary'; }
		if ( ! empty( $args['ghost'] ) )   { $classes[] = 'sui-btn--ghost'; }
		if ( ! empty( $args['danger'] ) )  { $classes[] = 'sui-btn--danger'; }
		if ( ! empty( $args['small'] ) )   { $classes[] = 'sui-btn--sm'; }

		$attrs = isset( $args['attrs'] ) ? $args['attrs'] : array();
		$attr_str = '';
		foreach ( $attrs as $k => $v ) {
			$attr_str .= sprintf( ' %s="%s"', esc_attr( $k ), esc_attr( $v ) );
		}

		if ( ! empty( $args['href'] ) ) {
			printf(
				'<a class="%s" href="%s"%s>%s</a>',
				esc_attr( implode( ' ', $classes ) ),
				esc_url( $args['href'] ),
				$attr_str,
				esc_html( $label )
			);
		} else {
			printf(
				'<button type="button" class="%s"%s>%s</button>',
				esc_attr( implode( ' ', $classes ) ),
				$attr_str,
				esc_html( $label )
			);
		}
	}

	/**
	 * Badge di stato.
	 *
	 * @param string $status ok|warn|err|info|neutral.
	 * @param string $label  Testo.
	 */
	public static function badge( $status, $label ) {
		$map = array(
			'ok'      => 'sui-badge--ok',
			'warn'    => 'sui-badge--warn',
			'err'     => 'sui-badge--err',
			'info'    => 'sui-badge--info',
			'neutral' => 'sui-badge--neutral',
		);
		$cls = isset( $map[ $status ] ) ? $map[ $status ] : 'sui-badge--neutral';
		printf( '<span class="sui-badge %s">%s</span>', esc_attr( $cls ), esc_html( $label ) );
	}

	/**
	 * Pill on/off.
	 *
	 * @param bool   $on    Stato.
	 * @param string $label Testo.
	 */
	public static function pill( $on, $label ) {
		printf(
			'<span class="sui-pill %s">%s</span>',
			$on ? 'sui-pill--on' : 'sui-pill--off',
			esc_html( $label )
		);
	}

	/**
	 * Toggle switch accessibile (checkbox nativa + traccia CSS, zero JS).
	 *
	 * @param string $name    Name/id dell'input.
	 * @param bool   $checked Stato.
	 * @param string $label   Etichetta visibile.
	 */
	public static function toggle( $name, $checked, $label ) {
		printf(
			'<label class="sui-toggle" for="%1$s"><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s><span class="sui-toggle__track" aria-hidden="true"></span><span class="sui-toggle__label">%3$s</span></label>',
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Empty state.
	 *
	 * @param string $icon         Emoji/logo testuale (escapata).
	 * @param string $title        Titolo.
	 * @param string $text         Testo descrittivo.
	 * @param string $action_label Etichetta azione opzionale (bottone/link).
	 * @param array  $action_attrs Attributi extra per l'azione (trusted per i nomi, escapati i valori).
	 */
	public static function empty_state( $icon, $title, $text = '', $action_label = '', $action_attrs = array() ) {
		echo '<div class="sui-empty">';
		echo '<div class="sui-empty__icon" aria-hidden="true">' . esc_html( $icon ) . '</div>';
		echo '<h3 class="sui-empty__title">' . esc_html( $title ) . '</h3>';
		if ( $text ) {
			echo '<p class="sui-empty__text">' . esc_html( $text ) . '</p>';
		}
		if ( $action_label ) {
			$attrs = '';
			foreach ( $action_attrs as $k => $v ) {
				$attrs .= sprintf( ' %s="%s"', esc_attr( $k ), esc_attr( $v ) );
			}
			printf( '<button type="button" class="sui-btn sui-btn--primary"%s>%s</button>', $attrs, esc_html( $action_label ) );
		}
		echo '</div>';
	}
}
