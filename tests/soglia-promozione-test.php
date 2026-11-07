<?php
/**
 * Test unitario OFFLINE della promozione draft→pending_approval (v1.1.26,
 * fix/nexus-budget-promote). Replica la NUOVA catena: la promozione avviene
 * SEMPRE (fuori dal budget), mentre approve/publish restano sotto budget.
 */

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) { return abs( (int) $maybeint ); }
}

$GLOBALS['mock_articles'] = array(
    1 => (object) array( 'id' => 1, 'status' => 'draft', 'quality_score' => 95, 'agent_id' => 1 ),
    2 => (object) array( 'id' => 2, 'status' => 'draft', 'quality_score' => 90, 'agent_id' => 1 ),
    3 => (object) array( 'id' => 3, 'status' => 'draft', 'quality_score' => 85, 'agent_id' => 1 ),
    4 => (object) array( 'id' => 4, 'status' => 'draft', 'quality_score' => 40, 'agent_id' => 1 ),
    5 => (object) array( 'id' => 5, 'status' => 'draft', 'quality_score' => 95, 'agent_id' => 1 ),
    6 => (object) array( 'id' => 6, 'status' => 'draft', 'quality_score' => 95, 'agent_id' => 1 ),
);

class Mock_Nexus_DB {
    public static function get_article( $id ) {
        $id = (int) $id;
        return isset( $GLOBALS['mock_articles'][ $id ] ) ? $GLOBALS['mock_articles'][ $id ] : null;
    }
    public static function update_article( $id, $fields ) {
        if ( isset( $fields['status'] ) ) {
            $GLOBALS['mock_articles'][ (int) $id ]->status = $fields['status'];
        }
        return true;
    }
    public static function set_approval( $id, $action, $note = '' ) { return true; }
}

/**
 * Replica la NUOVA catena (v1.1.26): promozione sempre, approve/publish sotto budget.
 */
function run_promozione_chain( $article_id, $mode, $rewritten_now, $score_parsed, $budget_available, $settings ) {
    $article = Mock_Nexus_DB::get_article( $article_id );
    if ( ! $article ) {
        return array( 'status' => 'missing', 'promosso' => false, 'approve' => false );
    }

    $threshold    = absint( isset( $settings['auto_approve_score'] ) ? $settings['auto_approve_score'] : 75 );
    $score        = (float) $article->quality_score;
    $is_full_auto = ( 'fully_autonomous' === $mode );

    // PROMOZIONE: sempre, fuori dal budget.
    $promoted = false;
    if ( 'draft' === $article->status && $score_parsed && $score >= $threshold ) {
        Mock_Nexus_DB::update_article( $article_id, array( 'status' => 'pending_approval' ) );
        $promoted = true;
        $article = Mock_Nexus_DB::get_article( $article_id );
    }

    // approve/publish SOLO se budget disponibile.
    $approve = false;
    if ( $budget_available && $is_full_auto && $score_parsed && $score >= $threshold ) {
        Mock_Nexus_DB::update_article( $article_id, array( 'status' => 'published' ) );
        $approve = true;
    }

    $final = Mock_Nexus_DB::get_article( $article_id );
    return array( 'status' => $final->status, 'promosso' => $promoted, 'approve' => $approve );
}

$settings = array( 'auto_approve_score' => 75 );

$cases = array(
    // (nome, id, mode, rewritten, parsed, budget_avail, atteso_status, atteso_promosso)
    array( 'sopra_soglia_semi_budget_disponibile', 1, 'semi_autonomous', true, true, true,  'pending_approval', true ),
    array( 'sopra_soglia_semi_BUDGET_ESAURITO', 2, 'semi_autonomous', true, true, false, 'pending_approval', true ),
    array( 'sopra_soglia_semi_non_rewritten_no_budget', 3, 'semi_autonomous', false, true, false, 'pending_approval', true ),
    array( 'sotto_soglia_semi_budget_esaurito', 4, 'semi_autonomous', true, true, false, 'draft', false ),
    array( 'non_parsato_no_budget', 5, 'semi_autonomous', true, false, false, 'draft', false ),
    array( 'sopra_soglia_fully_budget_disponibile', 6, 'fully_autonomous', true, true, true, 'published', true ),
);

$fail = 0;
foreach ( $cases as $c ) {
    list( $name, $id, $mode, $rew, $parsed, $budget, $atteso_status, $atteso_promosso ) = $c;
    $GLOBALS['mock_articles'][ $id ]->status = 'draft';

    $r = run_promozione_chain( $id, $mode, $rew, $parsed, $budget, $settings );

    $ok_status = ( $r['status'] === $atteso_status );
    $ok_prom   = ( $r['promosso'] === $atteso_promosso );
    $ok = $ok_status && $ok_prom;

    printf( "%-44s | budget=%d | promosso=%d stato=%-15s | %s\n",
        $name, $budget ? 1 : 0, $r['promosso'] ? 1 : 0, $r['status'], $ok ? 'OK' : 'FAIL'
    );
    if ( ! $ok_status ) { echo "    ↳ atteso stato: $atteso_status\n"; }
    if ( ! $ok_prom ) { echo "    ↳ atteso promosso: " . ( $atteso_promosso ? 'si' : 'no' ) . "\n"; }
    if ( ! $ok ) { $fail++; }
}

echo "\n";
echo $fail === 0 ? "ALL BUDGET-PROMOTE TESTS PASSED\n" : "FAILURES: $fail\n";
exit( $fail === 0 ? 0 : 1 );
