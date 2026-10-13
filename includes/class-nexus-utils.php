<?php
/**
 * Nexus Utils — helper statici di formattazione per l'interfaccia italiana.
 *
 * Contratto pubblico:
 * Nexus_Utils::time_ago_it( $mysql_datetime )          — tempo trascorso umano in italiano ('—' se null)
 * Nexus_Utils::excerpt_it( $text, $words )             — estratto pulito con ellissi (default 30 parole)
 * Nexus_Utils::reading_time_it( $text )                — "3 min di lettura" (200 parole/minuto)
 * Nexus_Utils::word_count_it( $text )                  — conteggio parole robusto (supporto UTF-8)
 * Nexus_Utils::reaction_emojis()                       — emoji ammesse per le reazioni
 * Nexus_Utils::category_color( $category )             — colore hex per categoria editoriale
 * Nexus_Utils::status_label_it( $status )              — etichetta italiana dello stato articolo
 * Nexus_Utils::platform_label_it( $platform )          — etichetta piattaforma di pubblicazione
 * Nexus_Utils::format_settings_for_form( $settings )   — settings normalizzate per il form admin
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Utils {

	/**
	 * Parole al minuto usate per il calcolo del tempo di lettura.
	 */
	const WORDS_PER_MINUTE = 200;

	/**
	 * Differenza umana in italiano tra una data MySQL e adesso.
	 *
	 * Esempi: 'adesso', '5 minuti fa', '3 ore fa', '2 giorni fa',
	 * '1 settimana fa', '2 mesi fa', '1 anno fa'.
	 *
	 * @param string|null $mysql_datetime Data MySQL (Y-m-d H:i:s, ora locale del sito).
	 * @return string
	 */
	public static function time_ago_it( $mysql_datetime ) {
		if ( empty( $mysql_datetime ) ) {
			return '—';
		}

		$timestamp = strtotime( (string) $mysql_datetime );

		if ( false === $timestamp ) {
			return '—';
		}

		$diff = current_time( 'timestamp' ) - $timestamp;

		// Data nel futuro (differenze di fuso o tick in arrivo): considerala "adesso".
		if ( $diff < 0 ) {
			return 'adesso';
		}

		if ( $diff < MINUTE_IN_SECONDS ) {
			return 'adesso';
		}

		if ( $diff < HOUR_IN_SECONDS ) {
			$minutes = (int) floor( $diff / MINUTE_IN_SECONDS );
			return self::plural_it( $minutes, 'minuto', 'minuti' ) . ' fa';
		}

		if ( $diff < DAY_IN_SECONDS ) {
			$hours = (int) floor( $diff / HOUR_IN_SECONDS );
			return self::plural_it( $hours, 'ora', 'ore' ) . ' fa';
		}

		if ( $diff < WEEK_IN_SECONDS ) {
			$days = (int) floor( $diff / DAY_IN_SECONDS );
			return self::plural_it( $days, 'giorno', 'giorni' ) . ' fa';
		}

		if ( $diff < MONTH_IN_SECONDS ) {
			$weeks = (int) floor( $diff / WEEK_IN_SECONDS );
			return self::plural_it( $weeks, 'settimana', 'settimane' ) . ' fa';
		}

		if ( $diff < YEAR_IN_SECONDS ) {
			$months = (int) floor( $diff / MONTH_IN_SECONDS );
			return self::plural_it( $months, 'mese', 'mesi' ) . ' fa';
		}

		$years = (int) floor( $diff / YEAR_IN_SECONDS );
		return self::plural_it( $years, 'anno', 'anni' ) . ' fa';
	}

	/**
	 * Estratto in italiano: strip_tags + wp_trim_words con ellissi '…'.
	 *
	 * @param string|null $text Testo completo.
	 * @param int         $words Numero massimo di parole (default 30).
	 * @return string
	 */
	public static function excerpt_it( $text, $words = 30 ) {
		$text = ( null === $text ) ? '' : (string) $text;
		$text = trim( wp_strip_all_tags( $text ) );

		if ( '' === $text ) {
			return '';
		}

		$words = max( 1, absint( $words ) );

		return wp_trim_words( $text, $words, '…' );
	}

	/**
	 * Tempo di lettura stimato: "3 min di lettura" (minimo 1).
	 *
	 * @param string|null $text Testo dell'articolo.
	 * @return string
	 */
	public static function reading_time_it( $text ) {
		$count   = self::word_count_it( $text );
		$minutes = max( 1, (int) ceil( $count / self::WORDS_PER_MINUTE ) );

		return $minutes . ' min di lettura';
	}

	/**
	 * Conteggio parole robusto per testo italiano (split su spazi Unicode).
	 *
	 * @param string|null $text Testo (HTML ammesso, i tag vengono rimossi).
	 * @return int
	 */
	public static function word_count_it( $text ) {
		$text = ( null === $text ) ? '' : (string) $text;
		$text = trim( wp_strip_all_tags( $text ) );

		if ( '' === $text ) {
			return 0;
		}

		$chunks = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $chunks ) ) {
			// Fallback estremo in caso di errore PCRE.
			return count( explode( ' ', preg_replace( '/\s+/', ' ', $text ) ) );
		}

		return count( $chunks );
	}

	/**
	 * Emoji ammesse per le reazioni agli articoli.
	 *
	 * @return array
	 */
	public static function reaction_emojis() {
		return array( '❤️', '👏', '🔥', '😮', '😂', '🤔' );
	}

	/**
	 * Mappa categoria editoriale → colore hex (usata per badge e accenti UI).
	 *
	 * @param string $category Nome categoria.
	 * @return string Colore hex.
	 */
	public static function category_color( $category ) {
		$map = array(
			'Tecnologia' => '#38bdf8',
			'Politica'   => '#f59e0b',
			'Economia'   => '#34d399',
			'Scienza'    => '#a78bfa',
			'Sport'      => '#f87171',
			'Cultura'    => '#f472b6',
			'Salute'     => '#4ade80',
		);

		$key = mb_strtolower( trim( (string) $category ), 'UTF-8' );

		foreach ( $map as $name => $color ) {
			if ( mb_strtolower( $name, 'UTF-8' ) === $key ) {
				return $color;
			}
		}

		return '#94a3b8';
	}

	/**
	 * Etichetta italiana dello stato di un articolo.
	 * Stati sconosciuti: restituisce il valore grezzo.
	 *
	 * @param string $status Stato articolo.
	 * @return string
	 */
	public static function status_label_it( $status ) {
		$map = array(
			'draft'            => 'Bozza',
			'pending_approval' => 'In attesa',
			'approved'         => 'Approvato',
			'published'        => 'Pubblicato',
			'rejected'         => 'Rifiutato',
		);

		$key = strtolower( trim( (string) $status ) );

		return isset( $map[ $key ] ) ? $map[ $key ] : (string) $status;
	}

	/**
	 * Etichetta italiana della piattaforma di pubblicazione.
	 * Piattaforme sconosciute: restituisce il valore grezzo.
	 *
	 * @param string $platform Slug piattaforma.
	 * @return string
	 */
	public static function platform_label_it( $platform ) {
		$map = array(
			'blog'      => 'Blog',
			'twitter'   => 'Twitter/X',
			'facebook'  => 'Facebook',
			'linkedin'  => 'LinkedIn',
			'instagram' => 'Instagram',
		);

		$key = strtolower( trim( (string) $platform ) );

		return isset( $map[ $key ] ) ? $map[ $key ] : (string) $platform;
	}

	/**
	 * Normalizza le impostazioni per il form admin: ogni valore diventa
	 * una stringa o un numero sicuro per esc_attr (niente bool/null/array).
	 *
	 * - bool  → '1' / '0'
	 * - null  → ''
	 * - array → lista join con virgola (es. social_platforms)
	 * - altro → ''
	 *
	 * @param array $settings Impostazioni grezze (es. Nexus_DB::get_settings()).
	 * @return array
	 */
	public static function format_settings_for_form( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();

		if ( class_exists( 'Nexus_DB' ) ) {
			$settings = wp_parse_args( $settings, Nexus_DB::default_settings() );
		}

		$form = array();

		foreach ( $settings as $key => $value ) {
			$key = (string) $key;

			if ( is_bool( $value ) ) {
				$form[ $key ] = $value ? '1' : '0';
				continue;
			}

			if ( null === $value ) {
				$form[ $key ] = '';
				continue;
			}

			if ( is_scalar( $value ) ) {
				$form[ $key ] = is_int( $value ) || is_float( $value ) ? $value : (string) $value;
				continue;
			}

			if ( is_array( $value ) ) {
				$form[ $key ] = implode( ',', array_map( 'strval', $value ) );
				continue;
			}

			$form[ $key ] = '';
		}

		return $form;
	}

	/* ============ HELPER PRIVATI ============ */

	/**
	 * Plurale italiano: "1 minuto" / "5 minuti".
	 *
	 * @param int    $count       Numero.
	 * @param string $singular    Forma singolare.
	 * @param string $plural      Forma plurale.
	 * @return string
	 */
	private static function plural_it( $count, $singular, $plural ) {
		return 1 === (int) $count ? '1 ' . $singular : (int) $count . ' ' . $plural;
	}
}
