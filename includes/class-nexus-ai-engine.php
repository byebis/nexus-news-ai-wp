<?php
/**
 * Nexus AI Engine — motore AI del plugin: chiamate chat/completions verso il
 * provider configurato (default OpenRouter), prompt editoriali in italiano e
 * parsing robusto delle risposte JSON.
 *
 * Port PHP fedele di nexus-src/src/lib/ai-engine.ts + openrouter.ts.
 *
 * CONTRATTO PUBBLICO (usato dagli altri moduli):
 * Nexus_AI_Engine::chat( $messages, $opts )          — POST {ai_base_url}/chat/completions.
 *                                                      Ritorna SEMPRE array:
 *                                                      array( 'ok' => bool, 'content' => string, 'error' => string, 'simulated' => bool ).
 *                                                      Nessun testo simulato viene mai generato qui ('simulated' è sempre false).
 * Nexus_AI_Engine::extract_json( $text )             — estrazione/riparazione JSON (port di repairParse TS), ritorna array|null.
 *                                                      Nota: per risposte scalari (es. il punteggio numerico della valutazione)
 *                                                      il chiamante deve convertire direttamente con (int).
 * Nexus_AI_Engine::collect_prompt( $category, $personality, $items_text, $source_name )
 * Nexus_AI_Engine::evergreen_prompt( $category, $personality )
 * Nexus_AI_Engine::evaluate_prompt( $article_title, $article_summary, $article_content, $category )
 * Nexus_AI_Engine::rewrite_prompt( $article_title, $article_summary, $article_content, $category, $agent_name, $agent_personality )
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_AI_Engine {

	/**
	 * Chiama POST {ai_base_url}/chat/completions con le impostazioni del plugin
	 * (Nexus_DB::get_settings(): ai_api_key, ai_base_url, ai_model).
	 *
	 * $opts accetta:
	 * - 'temperature' (float,  default 0.7)
	 * - 'max_tokens'  (intero, default 2000)
	 *
	 * Ritorna SEMPRE un array con le chiavi 'ok', 'content', 'error', 'simulated'.
	 * In caso di errore: 'ok' => false, 'error' descrittivo in italiano, 'content' vuoto.
	 *
	 * @param array $messages Messaggi in formato chat (array di array con 'role' e 'content').
	 * @param array $opts     Opzioni opzionali (temperature, max_tokens).
	 * @return array
	 */
	public static function chat( $messages, $opts = array() ) {
		$fail = array(
			'ok'        => false,
			'content'   => '',
			'error'     => '',
			'simulated' => false,
		);

		if ( ! class_exists( 'Nexus_DB' ) ) {
			$fail['error'] = 'Il layer dati Nexus_DB non è disponibile: il plugin Nexus News AI non è caricato correttamente.';
			return $fail;
		}

		$opts = is_array( $opts ) ? $opts : array();

		$temperature = isset( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.7;
		$temperature = max( 0.0, min( 2.0, $temperature ) );

		$max_tokens = isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : 2000;
		$max_tokens = max( 1, $max_tokens );

		// Normalizza e ripulisce i messaggi in input (ruoli ammessi: system, user, assistant).
		$normalized = array();
		foreach ( (array) $messages as $message ) {
			if ( ! is_array( $message ) && ! is_object( $message ) ) {
				continue;
			}
			$m      = (array) $message;
			$role   = isset( $m['role'] ) ? strtolower( trim( (string) $m['role'] ) ) : 'user';
			$role   = in_array( $role, array( 'system', 'user', 'assistant' ), true ) ? $role : 'user';
			$content = isset( $m['content'] ) ? trim( wp_strip_all_tags( (string) $m['content'] ) ) : '';
			if ( '' === $content ) {
				continue;
			}
			$normalized[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		if ( empty( $normalized ) ) {
			$fail['error'] = 'Nessun messaggio valido da inviare al modello AI.';
			return $fail;
		}

		$settings = Nexus_DB::get_settings();

		$api_key  = isset( $settings['ai_api_key'] ) ? trim( (string) $settings['ai_api_key'] ) : '';
		$base_url = isset( $settings['ai_base_url'] ) ? trim( (string) $settings['ai_base_url'] ) : '';
		$model    = isset( $settings['ai_model'] ) ? trim( (string) $settings['ai_model'] ) : '';

		if ( '' === $api_key ) {
			$fail['error'] = 'Chiave API AI non configurata: imposta la chiave API (ai_api_key) nelle impostazioni del plugin Nexus News AI.';
			return $fail;
		}

		if ( '' === $base_url ) {
			$base_url = 'https://openrouter.ai/api/v1';
		}
		if ( '' === $model ) {
			$model = 'openai/gpt-4o-mini';
		}

		$endpoint = untrailingslashit( $base_url ) . '/chat/completions';

		$body = array(
			'model'       => $model,
			'messages'    => $normalized,
			'temperature' => $temperature,
			'max_tokens'  => $max_tokens,
		);

		$encoded_body = wp_json_encode( $body );
		if ( false === $encoded_body ) {
			$fail['error'] = 'Impossibile preparare la richiesta per il modello AI (encoding JSON fallito).';
			return $fail;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					// Intestazioni di attribuzione consigliate da OpenRouter.
					'HTTP-Referer'  => home_url( '/' ),
					'X-Title'       => 'Nexus News AI',
				),
				'body'    => $encoded_body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$fail['error'] = 'Errore di connessione al servizio AI: ' . $response->get_error_message();
			return $fail;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			$detail = self::error_detail_from_body( $raw );
			if ( 401 === $code || 403 === $code ) {
				$fail['error'] = 'Chiave API non valida o non autorizzata (HTTP ' . $code . '). Verifica ai_api_key nelle impostazioni del plugin.' . ( '' !== $detail ? ' Dettaglio: ' . $detail : '' );
			} elseif ( 429 === $code ) {
				$fail['error'] = 'Limite di richieste verso il servizio AI raggiunto (HTTP 429). Riprova più tardi.' . ( '' !== $detail ? ' Dettaglio: ' . $detail : '' );
			} else {
				$fail['error'] = 'Il servizio AI ha risposto con errore HTTP ' . $code . ( '' !== $detail ? ': ' . $detail : '.' );
			}
			return $fail;
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$fail['error'] = 'La risposta del servizio AI non è un JSON valido.';
			return $fail;
		}

		$content = isset( $data['choices'][0]['message']['content'] ) ? (string) $data['choices'][0]['message']['content'] : '';
		if ( '' === trim( $content ) ) {
			$fail['error'] = 'Il modello AI (' . $model . ') ha restituito una risposta vuota.';
			return $fail;
		}

		return array(
			'ok'        => true,
			'content'   => $content,
			'error'     => '',
			'simulated' => false,
		);
	}

	/**
	 * Estrazione JSON robusta (port di repairParse in ai-engine.ts).
	 *
	 * Trova il primo blocco JSON (array [...] o oggetto {...}) nel testo
	 * (incluse le risposte racchiuse in blocchi markdown ```json), prova
	 * json_decode; se fallisce rimuove le virgole finali, ripara newline/tab
	 * dentro le stringhe (escapandoli) e recupera array/oggetti troncati.
	 *
	 * Ritorna un array (lista o associativo) oppure null se il JSON non è recuperabile.
	 * Le risposte scalari (es. un numero) NON vengono ritornate: in quel caso il
	 * chiamante deve convertire il testo direttamente con (int).
	 *
	 * @param string $text Testo completo della risposta del modello.
	 * @return array|null
	 */
	public static function extract_json( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return null;
		}

		// Rimuove i blocchi <think>...</think> dei reasoning model e i tag think orfani.
		$cleaned = preg_replace( '/<think>[\s\S]*?<\/think>/i', '', $text );
		$cleaned = preg_replace( '/<\/?think>/i', '', (string) $cleaned );

		$json_str = '';

		// 1. Blocco markdown ```json ... ```
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/i', (string) $cleaned, $m ) && isset( $m[1] ) && '' !== trim( $m[1] ) ) {
			$json_str = trim( $m[1] );
		}

		// 2. Primo array [...] nel testo.
		if ( '' === $json_str ) {
			$first = strpos( (string) $cleaned, '[' );
			$last  = strrpos( (string) $cleaned, ']' );
			if ( false !== $first && false !== $last && $last > $first ) {
				$json_str = substr( (string) $cleaned, $first, $last - $first + 1 );
			}
		}

		// 3. Primo oggetto {...} nel testo.
		if ( '' === $json_str ) {
			$first = strpos( (string) $cleaned, '{' );
			$last  = strrpos( (string) $cleaned, '}' );
			if ( false !== $first && false !== $last && $last > $first ) {
				$json_str = substr( (string) $cleaned, $first, $last - $first + 1 );
			}
		}

		// 4. Fallback: prova con tutto il testo ripulito.
		if ( '' === $json_str ) {
			$json_str = trim( (string) $cleaned );
		}

		if ( '' === $json_str ) {
			return null;
		}

		// 1. Decode diretto.
		$parsed = self::try_decode( $json_str );
		if ( null !== $parsed ) {
			return $parsed;
		}

		// 2. Rimozione delle virgole finali (trailing commas).
		$no_trailing = self::strip_trailing_commas( $json_str );
		$parsed      = self::try_decode( $no_trailing );
		if ( null !== $parsed ) {
			return $parsed;
		}

		// 3. Escape dei caratteri di controllo (newline/tab) dentro le stringhe JSON.
		$out = self::escape_control_chars( $json_str );
		if ( $out !== $json_str ) {
			$parsed = self::try_decode( $out );
			if ( null !== $parsed ) {
				return $parsed;
			}
			$parsed = self::try_decode( self::strip_trailing_commas( $out ) );
			if ( null !== $parsed ) {
				return $parsed;
			}
		}

		// 4. Array troncato: mantieni solo gli oggetti completi e chiudi l'array.
		foreach ( array( $json_str, $out ) as $candidate_src ) {
			$candidate_src = trim( (string) $candidate_src );
			if ( '' === $candidate_src || '[' !== $candidate_src[0] ) {
				continue;
			}
			$last_obj = strrpos( $candidate_src, '}' );
			if ( false === $last_obj || $last_obj <= 0 ) {
				continue;
			}
			$candidate = substr( $candidate_src, 0, $last_obj + 1 ) . ']';
			$parsed    = self::try_decode( $candidate );
			if ( null !== $parsed ) {
				return $parsed;
			}
			$parsed = self::try_decode( self::strip_trailing_commas( $candidate ) );
			if ( null !== $parsed ) {
				return $parsed;
			}
		}

		// 5. Singolo oggetto troncato.
		foreach ( array( $json_str, $out ) as $candidate_src ) {
			$candidate_src = (string) $candidate_src;
			$first_obj     = strpos( $candidate_src, '{' );
			$last_obj      = strrpos( $candidate_src, '}' );
			if ( false === $first_obj || false === $last_obj || $last_obj <= $first_obj ) {
				continue;
			}
			$parsed = self::try_decode( substr( $candidate_src, $first_obj, $last_obj - $first_obj + 1 ) );
			if ( null !== $parsed ) {
				return $parsed;
			}
		}

		return null;
	}

	/**
	 * Prompt di RACCOLTA: l'AI sceglie le 2 notizie più interessanti dalla lista
	 * RSS fornita e risponde SOLO con un array JSON di candidati.
	 *
	 * @param string $category    Categoria dell'agente (es. "Tecnologia").
	 * @param string $personality Personalità del giornalista AI.
	 * @param string $items_text  Lista testuale delle notizie RSS (titolo, estratto, link).
	 * @param string $source_name Nome della sorgente (es. "ANSA").
	 * @return string
	 */
	public static function collect_prompt( $category, $personality, $items_text, $source_name ) {
		$category    = trim( wp_strip_all_tags( (string) $category ) );
		$personality = trim( wp_strip_all_tags( (string) $personality ) );
		$source_name = trim( sanitize_text_field( (string) $source_name ) );
		$items_text  = trim( wp_strip_all_tags( (string) $items_text ) );

		$lines = array(
			'Sei un assistente editoriale specializzato in notizie di categoria "' . $category . '".',
			'Ti fornisco le notizie REALI di oggi raccolte dall\'agente da ' . $source_name . ':',
			'',
			$items_text,
			'',
			'Personalità del giornalista: ' . $personality,
			'',
			'Il tuo compito: scegli le 2 notizie PIÙ INTERESSANTI e RILEVANTI per la categoria "' . $category . '" e restituiscile come candidati per articoli.',
			'',
			'IMPORTANTE: Devi restituire SOLO un array JSON valido con questa struttura esatta:',
			'[',
			'  {',
			'    "title": "Titolo della notizia originale",',
			'    "summary": "Riassunto di 2-3 frasi con i fatti principali della notizia",',
			'    "score": 85,',
			'    "sourceName": "' . $source_name . '",',
			'    "sourceUrl": "URL della notizia originale"',
			'  }',
			']',
			'',
			'Requisiti:',
			'- Usa SOLO le notizie fornite sopra (non inventare notizie)',
			'- Riporta i fatti reali con i dettagli presenti negli estratti',
			'- I title e summary possono essere leggermente riformulati in stile giornalistico',
			'- "score" = punteggio di rilevanza editoriale da 0 a 100 per la categoria (escludi le notizie banali: seleziona solo punteggi >= 50)',
			'- Se meno di 2 notizie sono rilevanti, restituisci solo quelle valide',
			'- NON includere commenti o testo fuori dal JSON',
		);

		return implode( "\n", $lines );
	}

	/**
	 * Prompt EVERGREEN: analisi editoriale originale quando i feed RSS sono
	 * irraggiungibili (senza false rivendicazioni di notizie in tempo reale).
	 *
	 * @param string $category    Categoria dell'agente.
	 * @param string $personality Personalità del giornalista AI.
	 * @return string
	 */
	public static function evergreen_prompt( $category, $personality ) {
		$category    = trim( wp_strip_all_tags( (string) $category ) );
		$personality = trim( wp_strip_all_tags( (string) $personality ) );

		$lines = array(
			'Sei un assistente editoriale specializzato in ' . $category . '.',
			'Il tuo compito è proporre 1 analisi editoriale ORIGINALE su un tema di grande attualità e interesse per "' . $category . '" (un approfondimento di sfondo, non una notizia di ultim\'ora).',
			'',
			'Personalità del giornalista: ' . $personality,
			'',
			'IMPORTANTE: Devi restituire SOLO un array JSON valido con questa struttura esatta:',
			'[',
			'  {',
			'    "title": "Titolo dell\'analisi",',
			'    "summary": "Riassunto di 2-3 frasi",',
			'    "sourceName": "Nexus News AI - Analisi editoriale",',
			'    "sourceUrl": "' . esc_url_raw( home_url( '/' ) ) . '"',
			'  }',
			']',
			'',
			'NON includere commenti o testo fuori dal JSON',
		);

		return implode( "\n", $lines );
	}

	/**
	 * Prompt di VALUTAZIONE (editor capo): criteri rilevanza 30%, qualità 25%,
	 * originalità 20%, dati/fonti 15%, scrittura 10%. Risposta SOLO un numero
	 * intero da 0 a 100.
	 *
	 * @param string $article_title    Titolo dell'articolo.
	 * @param string $article_summary  Riassunto dell'articolo.
	 * @param string $article_content Contenuto dell'articolo (se vuoto usa il riassunto).
	 * @param string $category         Categoria.
	 * @return string
	 */
	public static function evaluate_prompt( $article_title, $article_summary, $article_content, $category ) {
		$category   = trim( wp_strip_all_tags( (string) $category ) );
		$title      = trim( wp_strip_all_tags( (string) $article_title ) );
		$summary    = trim( wp_strip_all_tags( (string) $article_summary ) );
		$content    = trim( wp_strip_all_tags( (string) $article_content ) );
		$to_evaluate = '' !== $content ? $content : $summary;
		$to_evaluate = self::clip( $to_evaluate, 1500 );

		$lines = array(
			'Sei un editor capo di una redazione giornalistica specializzata in ' . $category . '.',
			'Valuta questo articolo su una scala da 0 a 100.',
			'',
			'Titolo: ' . $title,
			'Riassunto: ' . $summary,
			'Contenuto: ' . $to_evaluate,
			'',
			'Criteri di valutazione:',
			'- Rilevanza per la categoria (30%)',
			'- Qualità e profondità del contenuto (25%)',
			'- Originalità e novità (20%)',
			'- Presenza di dati concreti e fonti (15%)',
			'- Qualità della scrittura (10%)',
			'',
			'Restituisci SOLO un numero intero da 0 a 100, nient\'altro.',
		);

		return implode( "\n", $lines );
	}

	/**
	 * Prompt di RISCRITTURA: articoli originali in stile per categoria.
	 * Risposta SOLO JSON {title, subtitle, content (min 400 parole), summary}.
	 *
	 * @param string $article_title     Titolo della notizia di partenza.
	 * @param string $article_summary    Fatti principali della notizia.
	 * @param string $article_content    Estratto della notizia (troncato a 800 caratteri).
	 * @param string $category           Categoria.
	 * @param string $agent_name        Nome dell'agente giornalista AI.
	 * @param string $agent_personality Personalità dell'agente.
	 * @return string
	 */
	public static function rewrite_prompt( $article_title, $article_summary, $article_content, $category, $agent_name, $agent_personality ) {
		$category   = trim( wp_strip_all_tags( (string) $category ) );
		$title      = trim( wp_strip_all_tags( (string) $article_title ) );
		$summary    = trim( wp_strip_all_tags( (string) $article_summary ) );
		$excerpt    = self::clip( trim( wp_strip_all_tags( (string) $article_content ) ), 800 );
		$agent_name = trim( wp_strip_all_tags( (string) $agent_name ) );
		$personality = trim( wp_strip_all_tags( (string) $agent_personality ) );

		// Mappa stili editoriali per categoria (fedele all'originale).
		$styles = array(
			'Tecnologia' => 'analitico e orientato all\'impatto pratico per lettori tech-savvy',
			'Politica'   => 'equilibrato e oggettivo con contesto storico e geopolitico',
			'Economia'   => 'rigoroso ma accessibile con dati concreti e implicazioni per investitori',
			'Scienza'    => 'divulgativo ma preciso, con spiegazioni chiare per un pubblico colto',
			'Sport'      => 'emozionante e narrativo con statistiche e analisi tattiche',
			'Cultura'    => 'elegante e riflessivo con riferimenti storici e artistici',
			'Salute'     => 'rassicurante e basato su evidenze scientifiche con consigli pratici',
		);
		$style = isset( $styles[ $category ] ) ? $styles[ $category ] : 'professionale e oggettivo';

		$lines = array(
			'Sei ' . $agent_name . ', un giornalista AI con personalità: "' . $personality . '".',
			'Il tuo stile editoriale è ' . $style . '.',
			'',
			'Scrivi un articolo ORIGINALE per la rivista Nexus News AI basato su questa notizia reale, riportandone i fatti e arricchendolo con contesto, analisi e implicazioni.',
			'',
			'Notizia di partenza:',
			'- Titolo: ' . $title,
			'- Fatti principali: ' . $summary,
			'- Estratto: ' . $excerpt,
			'',
			'Restituisci SOLO un JSON con questa struttura esatta:',
			'{',
			'  "title": "Nuovo titolo creativo e accattivante",',
			'  "subtitle": "Sottotitolo di 1 frase che cattura l\'essenza",',
			'  "content": "Articolo completo, minimo 400 parole, con intro editoriale, corpo dettagliato e conclusione",',
			'  "summary": "Riassunto di 2-3 frasi"',
			'}',
			'',
			'NON includere markdown, commenti o testo fuori dal JSON.',
		);

		return implode( "\n", $lines );
	}

	/* ============ HELPER PRIVATI ============ */

	/**
	 * Prova il json_decode e ritorna l'array solo se il parsing riesce e il
	 * risultato è un array (lista o associativo). Altrimenti null.
	 *
	 * @param string $str Presunta stringa JSON.
	 * @return array|null
	 */
	private static function try_decode( $str ) {
		$decoded = json_decode( (string) $str, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $decoded;
		}
		return null;
	}

	/**
	 * Rimuove le virgole finali prima di ] o } (errore comune dei modelli).
	 *
	 * @param string $str Stringa JSON.
	 * @return string
	 */
	private static function strip_trailing_commas( $str ) {
		return (string) preg_replace( '/,\s*([\]}])/', '$1', (string) $str );
	}

	/**
	 * Escapa i caratteri di controllo grezzi (newline/tab/ecc.) dentro le
	 * stringhe JSON, rispettando gli escape già presenti e i delimitatori "...".
	 *
	 * @param string $str Stringa JSON potenzialmente corrotta.
	 * @return string
	 */
	private static function escape_control_chars( $str ) {
		$str    = (string) $str;
		$out    = '';
		$len    = strlen( $str );
		$in_str = false;

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $str[ $i ];
			if ( $in_str ) {
				if ( '\\' === $ch ) {
					// Escape già presente: copia la sequenza \x com'è.
					$out .= $ch;
					if ( $i + 1 < $len ) {
						$out .= $str[ $i + 1 ];
						$i++;
					}
					continue;
				}
				if ( '"' === $ch ) {
					$in_str = false;
					$out   .= $ch;
					continue;
				}
				if ( "\n" === $ch ) {
					$out .= '\\n';
					continue;
				}
				if ( "\r" === $ch ) {
					continue;
				}
				if ( "\t" === $ch ) {
					$out .= '\\t';
					continue;
				}
				$ord = ord( $ch );
				if ( $ord < 32 ) {
					$out .= sprintf( '\u%04x', $ord );
					continue;
				}
				$out .= $ch;
			} else {
				if ( '"' === $ch ) {
					$in_str = true;
				}
				$out .= $ch;
			}
		}

		return $out;
	}

	/**
	 * Estrae un dettaglio di errore leggibile dal body HTTP (se decodificabile).
	 *
	 * @param string $raw Body della risposta HTTP.
	 * @return string
	 */
	private static function error_detail_from_body( $raw ) {
		$detail = '';
		$data   = json_decode( (string) $raw, true );
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
				$detail = $data['error']['message'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$detail = $data['error'];
			} elseif ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
				$detail = $data['message'];
			}
		}
		if ( '' === $detail ) {
			$detail = self::clip( trim( wp_strip_all_tags( (string) $raw ) ), 200 );
		}
		return $detail;
	}

	/**
	 * Tronca una stringa alla lunghezza indicata (multibyte quando disponibile).
	 *
	 * @param string $text   Testo.
	 * @param int    $length Lunghezza massima in caratteri.
	 * @return string
	 */
	private static function clip( $text, $length ) {
		$text = trim( (string) $text );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, (int) $length );
		}
		return substr( $text, 0, (int) $length );
	}
}
