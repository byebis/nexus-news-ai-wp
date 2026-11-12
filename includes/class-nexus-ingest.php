<?php
/**
 * Nexus Ingest — ingest multi-formato (XML, JSON, CSV, HTML) oltre RSS.
 *
 * v1.1.31 (gap #2): parser per ciascun formato + normalizzazione in item
 * articolo {title, description, link, source, categories} identici all'item
 * RSS, così l'articolo confluisce nella stessa pipeline (title_hash per dedup
 * + insert_article). Include validazione SSRF e fallback onesti.
 *
 * Configurazione: setting JSON 'nexus_ingest_sources' (array [{url, format}]).
 *
 * @package Nexus_News_AI
 * @since   1.1.31
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Nexus_Ingest {

	/** Formati supportati. */
	const FORMATS = array( 'xml', 'json', 'csv', 'html' );

	/**
	 * Legge le fonti ingest configurate (setting JSON).
	 *
	 * @return array<int,array{url:string,format:string}>
	 */
	public static function sources() {
		$settings = ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_settings' ) )
			? Nexus_DB::get_settings()
			: array();
		$raw = isset( $settings['nexus_ingest_sources'] ) ? (string) $settings['nexus_ingest_sources'] : '';
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$out = array();
		foreach ( $decoded as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$url = trim( (string) ( $entry['url'] ?? '' ) );
			$format = isset( $entry['format'] ) ? sanitize_key( (string) $entry['format'] ) : 'xml';
			if ( '' === $url || ! in_array( $format, self::FORMATS, true ) ) {
				continue;
			}
			$out[] = array( 'url' => esc_url_raw( $url ), 'format' => $format );
		}
		return $out;
	}

	/**
	 * Fetch + parse di una fonte nel formato indicato.
	 *
	 * @param string $url    URL fonte.
	 * @param string $format Formato (xml|json|csv|html).
	 * @param int    $limit  Massimo item da restituire.
	 * @return array Item normalizzati {title, description, link, source, categories}.
	 */
	public static function fetch_and_parse( $url, $format, $limit = 10 ) {
		$url = trim( (string) $url );
		$format = sanitize_key( (string) $format );
		$limit = max( 1, min( 50, (int) $limit ) );

		if ( '' === $url || ! in_array( $format, self::FORMATS, true ) ) {
			return array();
		}

		// SSRF guard (stesso pattern di Nexus_Rss).
		if ( class_exists( 'Nexus_Tools' ) && method_exists( 'Nexus_Tools', 'assert_safe_url' )
			&& is_wp_error( Nexus_Tools::assert_safe_url( $url ) ) ) {
			return array();
		}

		$response = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 2 ) );
		if ( is_wp_error( $response ) ) {
			return array();
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array();
		}
		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return array();
		}

		$source = self::source_label( $url );

		switch ( $format ) {
			case 'xml':
				$items = self::parse_xml( $body, $source );
				break;
			case 'json':
				$items = self::parse_json( $body, $source );
				break;
			case 'csv':
				$items = self::parse_csv( $body, $source );
				break;
			case 'html':
			default:
				$items = self::parse_html( $body, $source );
				break;
		}

		return array_slice( $items, 0, $limit );
	}

	/**
	 * Parser XML (RSS/Atom semplice + feed generico con <item>/<entry>).
	 *
	 * @param string $xml    Conteúuto XML.
	 * @param string $source Etichhetta fonte.
	 * @return array
	 */
	private static function parse_xml( $xml, $source ) {
		$out = array();
		$prev = libxml_use_internal_errors( true );
		try {
			$doc = simplexml_load_string( $xml );
		} catch ( \Throwable $e ) {
			$doc = false;
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( false === $doc ) {
			return $out;
		}

		$nodes = array();
		if ( isset( $doc->channel->item ) ) {
			$nodes = $doc->channel->item;
		} elseif ( isset( $doc->entry ) ) {
			$nodes = $doc->entry;
		} elseif ( isset( $doc->article ) ) {
			$nodes = $doc->article;
		}

		foreach ( $nodes as $node ) {
			$title = trim( (string) ( $node->title ?? '' ) );
			$link  = trim( (string) ( $node->link ?? '' ) );
			if ( '' === $title || '' === $link ) {
				continue;
			}
			$desc = trim( (string) ( $node->description ?? $node->summary ?? $node->content ?? '' ) );
			$cats = array();
			if ( isset( $node->category ) ) {
				foreach ( $node->category as $c ) {
					$t = trim( (string) $c );
					if ( '' !== $t ) {
						$cats[] = strtolower( $t );
					}
				}
			}
			$out[] = array(
				'title'       => wp_strip_all_tags( $title ),
				'description' => self::clip( wp_strip_all_tags( $desc ), 400 ),
				'link'        => esc_url_raw( $link ),
				'source'      => $source,
				'categories'  => $cats,
			);
		}

		return $out;
	}

	/**
	 * Parser JSON: accetta array di oggetti o un oggetto con chiave items/entries.
	 *
	 * @param string $json   Contenuto JSON.
	 * @param string $source Etichetta fonte.
	 * @return array
	 */
	private static function parse_json( $json, $source ) {
		$out = array();
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return $out;
		}

		$list = $data;
		foreach ( array( 'items', 'entries', 'articles', 'data' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$list = $data[ $key ];
				break;
			}
		}
		if ( ! is_array( $list ) ) {
			return $out;
		}

		foreach ( $list as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = trim( (string) ( $row['title'] ?? $row['headline'] ?? '' ) );
			$link  = trim( (string) ( $row['link'] ?? $row['url'] ?? '' ) );
			if ( '' === $title || '' === $link ) {
				continue;
			}
			$desc = trim( (string) ( $row['description'] ?? $row['summary'] ?? $row['excerpt'] ?? '' ) );
			$cats = array();
			if ( isset( $row['categories'] ) && is_array( $row['categories'] ) ) {
				foreach ( $row['categories'] as $c ) {
					$t = trim( (string) $c );
					if ( '' !== $t ) {
						$cats[] = strtolower( $t );
					}
				}
			}
			$out[] = array(
				'title'       => wp_strip_all_tags( $title ),
				'description' => self::clip( wp_strip_all_tags( $desc ), 400 ),
				'link'        => esc_url_raw( $link ),
				'source'      => $source,
				'categories'  => $cats,
			);
		}

		return $out;
	}

	/**
	 * Parser CSV (header opzionale; colonne title,link,description,categories).
	 *
	 * @param string $csv    Contenuto CSV.
	 * @param string $source Etichetta fonte.
	 * @return array
	 */
	private static function parse_csv( $csv, $source ) {
		$out = array();
		$lines = preg_split( '/\r\n|\r|\n/', $csv );
		if ( ! is_array( $lines ) ) {
			return $out;
		}

		$header = null;
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$row = str_getcsv( $line );
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( null === $header ) {
				$header = array_map( 'strtolower', array_map( 'trim', $row ) );
				continue;
			}

			$map = array();
			foreach ( $row as $i => $val ) {
				$key = isset( $header[ $i ] ) ? $header[ $i ] : 'col' . $i;
				$map[ $key ] = $val;
			}

			$title = trim( (string) ( $map['title'] ?? '' ) );
			$link  = trim( (string) ( $map['link'] ?? $map['url'] ?? '' ) );
			if ( '' === $title || '' === $link ) {
				continue;
			}
			$desc = trim( (string) ( $map['description'] ?? '' ) );
			$cats = array();
			if ( ! empty( $map['categories'] ) ) {
				foreach ( explode( '|', (string) $map['categories'] ) as $c ) {
					$t = trim( $c );
					if ( '' !== $t ) {
						$cats[] = strtolower( $t );
					}
				}
			}
			$out[] = array(
				'title'       => wp_strip_all_tags( $title ),
				'description' => self::clip( wp_strip_all_tags( $desc ), 400 ),
				'link'        => esc_url_raw( $link ),
				'source'      => $source,
				'categories'  => $cats,
			);
		}

		return $out;
	}

	/**
	 * Parser HTML: estrae articoli dai tag <article> o <h2>/<h3> + link.
	 *
	 * @param string $html   Contenuto HTML.
	 * @param string $source Etichetta fonte.
	 * @return array
	 */
	private static function parse_html( $html, $source ) {
		$out = array();
		$prev = libxml_use_internal_errors( true );
		try {
			$doc = new DOMDocument();
			$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
		} catch ( \Throwable $e ) {
			$doc = null;
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $doc ) {
			return $out;
		}

		$articleNodes = $doc->getElementsByTagName( 'article' );
		if ( $articleNodes->length > 0 ) {
			foreach ( $articleNodes as $node ) {
				$item = self::html_node_to_item( $node, $source );
				if ( $item ) {
					$out[] = $item;
				}
			}
			return $out;
		}

		// Fallback: h2/h3 con primo link.
		foreach ( array( 'h2', 'h3' ) as $tag ) {
			$headings = $doc->getElementsByTagName( $tag );
			foreach ( $headings as $h ) {
				$title = trim( strip_tags( $h->textContent ) );
				if ( '' === $title ) {
					continue;
				}
				$link = '';
				$anchors = $h->parentNode ? $h->parentNode->getElementsByTagName( 'a' ) : null;
				if ( $anchors && $anchors->length > 0 ) {
					$link = $anchors->item( 0 )->getAttribute( 'href' );
				}
				if ( '' === $link ) {
					$anchors = $h->getElementsByTagName( 'a' );
					if ( $anchors && $anchors->length > 0 ) {
						$link = $anchors->item( 0 )->getAttribute( 'href' );
					}
				}
				if ( '' === $link ) {
					continue;
				}
				$out[] = array(
					'title'       => wp_strip_all_tags( $title ),
					'description' => '',
					'link'        => esc_url_raw( $link ),
					'source'      => $source,
					'categories'  => array(),
				);
			}
		}

		return $out;
	}

	/**
	 * Converte un nodo <article> HTML in item.
	 *
	 * @param DOMElement $node   Nodo.
	 * @param string     $source Etichetta fonte.
	 * @return array|null
	 */
	private static function html_node_to_item( $node, $source ) {
		if ( ! $node || ! method_exists( $node, 'getElementsByTagName' ) ) {
			return null;
		}
		$title = '';
		$h = $node->getElementsByTagName( 'h2' );
		if ( $h->length > 0 ) {
			$title = trim( strip_tags( $h->item( 0 )->textContent ) );
		}
		if ( '' === $title ) {
			$h3 = $node->getElementsByTagName( 'h3' );
			if ( $h3->length > 0 ) {
				$title = trim( strip_tags( $h3->item( 0 )->textContent ) );
			}
		}
		if ( '' === $title ) {
			return null;
		}

		$link = '';
		$a = $node->getElementsByTagName( 'a' );
		foreach ( $a as $anchor ) {
			$href = $anchor->getAttribute( 'href' );
			if ( '' !== $href ) {
				$link = $href;
				break;
			}
		}
		if ( '' === $link ) {
			return null;
		}

		$desc = trim( strip_tags( $node->textContent ) );
		$desc = self::clip( $desc, 400 );

		return array(
			'title'       => wp_strip_all_tags( $title ),
			'description' => $desc,
			'link'        => esc_url_raw( $link ),
			'source'      => $source,
			'categories'  => array(),
		);
	}

	/**
	 * Collezione: processa le fonti ingest e inserisce articoli draft
	 * (stessa pipeline delle RSS: title_hash dedup + insert_article).
	 *
	 * @return array{checked:int,inserted:int,duplicates:int,errors:int}
	 */
	public static function collect() {
		$sources = self::sources();
		$checked = 0;
		$inserted = 0;
		$duplicates = 0;
		$errors = 0;

		if ( ! $sources || ! class_exists( 'Nexus_DB' ) ) {
			return array( 'checked' => $checked, 'inserted' => $inserted, 'duplicates' => $duplicates, 'errors' => $errors );
		}

		foreach ( $sources as $src ) {
			$items = self::fetch_and_parse( $src['url'], $src['format'] );
			$checked++;
			if ( ! $items ) {
				$errors++;
				continue;
			}

			foreach ( $items as $item ) {
				$hash = self::title_hash( $item['title'] );
				if ( $hash && class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'article_hash_exists' ) && Nexus_DB::article_hash_exists( $hash ) ) {
					$duplicates++;
					continue;
				}

				$inserted_id = Nexus_DB::insert_article( array(
					'title'       => $item['title'],
					'summary'     => $item['description'],
					'content'     => $item['description'],
					'source_name' => $item['source'],
					'source_url'  => $item['link'],
					'source_type' => 'ingest',
					'category'    => (string) ( $item['categories'][0] ?? '' ),
					'status'      => 'draft',
					'content_hash' => $hash,
				) );
				if ( $inserted_id > 0 ) {
					$inserted++;
				} else {
					$duplicates++;
				}
			}
		}

		return array( 'checked' => $checked, 'inserted' => $inserted, 'duplicates' => $duplicates, 'errors' => $errors );
	}

	/**
	 * Hash del titolo (stessa semantica di Nexus_Pipeline::title_hash).
	 *
	 * @param string $title Titolo.
	 * @return string
	 */
	public static function title_hash( $title ) {
		$title = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $title ) ) );
		if ( '' === $title ) {
			return '';
		}
		$title = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title, 'UTF-8' ) : strtolower( $title );
		return md5( $title );
	}

	/**
	 * Etichetta fonte dall'URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function source_label( $url ) {
		$host = parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? $host : 'ingest';
	}

	/**
	 * Tronca testo multi-riga.
	 *
	 * @param string $text Testo.
	 * @param int    $max  Lunghezza massima.
	 * @return string
	 */
	private static function clip( $text, $max ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		$max = (int) $max;
		if ( '' === $text || $max < 1 || function_exists( 'mb_strlen' ) && mb_strlen( $text ) <= $max ) {
			return $text;
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}
}
