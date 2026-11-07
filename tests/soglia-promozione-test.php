<?php
/**
 * Test unitario OFFLINE della catena di promozione draft→pending_approval
 * (run_full_agent, FASI 4-5). Replica ESATTAMENTE la catena if/elseif di
 * includes/class-nexus-pipeline.php (righe ~1578-1702 della v1.1.25), con
 * mock di Nexus_DB::update_article / get_article / approve / publish.
 *
 * Lo scopo è capire perché la promozione non scatta a runtime: quale
 * variabile è falsa, se la logica è corretta, e se il bug è nel codice o
 * nell'ambiente (budget/tempo/articoli non nel loop).
 *
 * Uso: php tests/soglia-promozione-test.php
 */

/* ============ POLYFILL WP ============ */

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
    }
}

/* ============ MOCK ============ */

// Stato del "DB" in-memory.
$GLOBALS['mock_articles'] = array(
    1 => (object) array( 'id' => 1, 'status' => 'draft', 'quality_score' => 95, 'agent_id' => 1 ),
    2 => (object) array( 'id' => 2, 'status' => 'draft', 'quality_score' => 90, 'agent_id' => 1 ),
    3 => (object) array( 'id' => 3, 'status' => 'draft', 'quality_score' => 85, 'agent_id' => 1 ),
    4 => (object) array( 'id' => 4, 'status' => 'draft', 'quality_score' => 40, 'agent_id' => 1 ),
    5 => (object) array( 'id' => 5, 'status' => 'draft', 'quality_score' => 95, 'agent_id' => 1 ),
    6 => (object) array( 'id' => 6, 'status' => 'draft', 'quality_score' => 95, 'agent_id' => 1 ),
);
$GLOBALS['update_calls'] = array();

// Mock di Nexus_DB.
class Mock_Nexus_DB {
    public static function get_article( $id ) {
        $id = (int) $id;
        return isset( $GLOBALS['mock_articles'][ $id ] ) ? $GLOBALS['mock_articles'][ $id ] : null;
    }
    public static function update_article( $id, $fields ) {
        $GLOBALS['update_calls'][] = array( 'id' => (int) $id, 'fields' => $fields );
        if ( isset( $fields['status'] ) ) {
            $GLOBALS['mock_articles'][ (int) $id ]->status = $fields['status'];
        }
        if ( isset( $fields['quality_score'] ) ) {
            $GLOBALS['mock_articles'][ (int) $id ]->quality_score = $fields['quality_score'];
        }
        return true;
    }
    public static function set_approval( $id, $action, $note = '' ) {
        return true;
    }
}

/**
 * Replica ESATTA della catena if/elseif di run_full_agent() (FASI 4-5).
 */
function run_promozione_chain( $article_id, $mode, $rewritten_now, $score_parsed, $settings ) {
    // Stato "articolo tornato in draft dal fact-check" (simulato dal chiamante).
    $article = Mock_Nexus_DB::get_article( $article_id );
    if ( ! $article ) {
        return array( 'status' => 'missing', 'ramo' => 'articolo_non_trovato' );
    }

    // FASI 4-5 (il budget si assume disponibile; verifichiamo il ramo).
    $threshold    = absint( isset( $settings['auto_approve_score'] ) ? $settings['auto_approve_score'] : 75 );
    $score        = (float) $article->quality_score;
    $is_full_auto = ( 'fully_autonomous' === $mode );
    $chief_gate   = $is_full_auto && false; // nessun chief nel test

    // Contatore per il ramo.
    $ramo = 'nessuna_promozione';

    if ( $rewritten_now && $is_full_auto && $score_parsed && $score >= $threshold && ! $chief_gate ) {
        $ramo = 'fully_auto_approva_pubblica';
        if ( 'draft' === $article->status ) {
            Mock_Nexus_DB::update_article( $article_id, array( 'status' => 'pending_approval' ) );
        }
        // approve + publish riusciti
        Mock_Nexus_DB::update_article( $article_id, array( 'status' => 'published' ) );
    } elseif ( $rewritten_now && $chief_gate ) {
        $ramo = 'chief_gate';
    } elseif ( $rewritten_now && $is_full_auto && ! $score_parsed ) {
        $ramo = 'full_auto_non_parsato';
    } elseif ( $rewritten_now && $is_full_auto ) {
        $ramo = 'full_auto_sotto_soglia';
    } elseif ( $rewritten_now && ! empty( $settings['auto_publish'] ) && 'approved' === $article->status ) {
        $ramo = 'semi_auto_publish_già_approvato';
        Mock_Nexus_DB::update_article( $article_id, array( 'status' => 'published' ) );
    } elseif ( $rewritten_now && 'draft' === $article->status && $score_parsed && $score >= $threshold ) {
        $ramo = 'semi_auto_promuovi_rewritten';
        Mock_Nexus_DB::update_article( $article_id, array( 'status' => 'pending_approval' ) );
    } elseif ( 'draft' === $article->status && $score_parsed && $score >= $threshold ) {
        $ramo = 'promuovi_senza_rewrite';
        Mock_Nexus_DB::update_article( $article_id, array( 'status' => 'pending_approval' ) );
    } else {
        $ramo = 'nessuna_promozione';
    }

    $final = Mock_Nexus_DB::get_article( $article_id );
    return array(
        'status' => $final->status,
        'ramo'   => $ramo,
        'score'  => $score,
        'threshold' => $threshold,
        'rewritten_now' => $rewritten_now,
        'score_parsed'  => $score_parsed,
    );
}

/* ============ CASI ============ */

$settings = array( 'auto_evaluate' => 1, 'auto_rewrite' => 1, 'auto_publish' => 0, 'auto_approve_score' => 75 );

$cases = array(
    // (nome, article_id, mode, rewritten_now, score_parsed, stato_iniziale, atteso_status, atteso_ramo)
    array( 'sopra_soglia_semi_rewritten', 1, 'semi_autonomous', true,  true, 'draft', 'pending_approval', 'semi_auto_promuovi_rewritten' ),
    array( 'sopra_soglia_semi_non_rewritten', 2, 'semi_autonomous', false, true, 'draft', 'pending_approval', 'promuovi_senza_rewrite' ),
    array( 'sotto_soglia_semi_rewritten', 4, 'semi_autonomous', true,  true, 'draft', 'draft', 'nessuna_promozione' ),
    array( 'sotto_soglia_semi_non_rewritten', 4, 'semi_autonomous', false, true, 'draft', 'draft', 'nessuna_promozione' ),
    array( 'non_parsato_semi_rewritten', 5, 'semi_autonomous', true,  false, 'draft', 'draft', 'nessuna_promozione' ),
    array( 'sopra_soglia_fully_rewritten', 6, 'fully_autonomous', true, true, 'draft', 'published', 'fully_auto_approva_pubblica' ),
    array( 'factcheck_rimette_a_draft_sopra', 3, 'semi_autonomous', true, true, 'draft', 'pending_approval', 'semi_auto_promuovi_rewritten' ),
);

$fail = 0;
foreach ( $cases as $c ) {
    list( $name, $id, $mode, $rew, $parsed, $status_in, $atteso, $ramo_atteso ) = $c;
    // Setup stato iniziale (incl. simulazione fact-check che rimette a draft).
    $GLOBALS['mock_articles'][ $id ]->status = $status_in;
    $GLOBALS['update_calls'] = array();

    $r = run_promozione_chain( $id, $mode, $rew, $parsed, $settings );

    $ok_status = ( $r['status'] === $atteso );
    $ok_ramo  = ( $r['ramo'] === $ramo_atteso );
    $ok = $ok_status && $ok_ramo;

    printf( "%-38s | score=%-3s th=%s rewritten=%d parsed=%d | ramo=%-28s stato=%-15s | %s\n",
        $name, $r['score'], $r['threshold'], $rew ? 1 : 0, $parsed ? 1 : 0,
        $r['ramo'], $r['status'], $ok ? 'OK' : 'FAIL'
    );
    if ( ! $ok_status ) { echo "    ↳ atteso stato: $atteso\n"; }
    if ( ! $ok_ramo ) { echo "    ↳ atteso ramo: $ramo_atteso\n"; }
    if ( ! $ok ) { $fail++; }
}

echo "\n";
echo $fail === 0 ? "ALL SOGLIA UNIT TESTS PASSED\n" : "FAILURES: $fail\n";
exit( $fail === 0 ? 0 : 1 );
