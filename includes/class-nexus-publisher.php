<?php
/**
 * Nexus Publisher — pubblicazione multi-piattaforma degli articoli.
 *
 * Canali gestiti (dalla impostazione social_platforms, lista separata da virgole):
 *  - blog      → pubblicazione REALE sul sito: l'articolo vive nel database Nexus
 *                e viene servito tramite home_url( '/?nexus_article={id}' ).
 *  - social    → SIMULAZIONE ONESTA (filosofia dell'originale): il plugin non ha
 *                credenziali API social, quindi genera un post_id e un URL fittizi
 *                marcati come simulati invece di fingere chiamate reali.
 *
 * CONTRATTO PUBBLICO:
 * Nexus_Publisher::publish( $article ) → array di log:
 *   array( array( 'platform', 'status', 'post_id', 'post_url', 'error', 'note' ), ... )
 * Nessuna scrittura su DB qui: gli insert_publish_log sono responsabilità della pipeline.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Publisher {

        /**
         * Pubblica un articolo su tutte le piattaforme configurate.
         *
         * @param object|array $article Articolo Nexus (riga di nexus_articles).
         * @return array Log di pubblicazione, uno per piattaforma.
         */
        public static function publish( $article ) {
                if ( is_array( $article ) ) {
                        $article = (object) $article;
                }
                if ( ! is_object( $article ) || empty( $article->id ) ) {
                        return array();
                }

                $article_id = absint( $article->id );

                $settings   = Nexus_DB::get_settings();
                $raw        = isset( $settings['social_platforms'] ) ? (string) $settings['social_platforms'] : 'blog';
                $platforms  = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
                if ( empty( $platforms ) ) {
                        $platforms = array( 'blog' );
                }

                $logs = array();
                foreach ( $platforms as $platform ) {
                        $platform = sanitize_key( $platform );
                        if ( '' === $platform ) {
                                continue;
                        }

                        if ( 'blog' === $platform ) {
                                // Canale reale: l'articolo è già nel database Nexus, esposto via front-end.
                                $logs[] = array(
                                        'platform' => 'blog',
                                        'status'   => 'published',
                                        'post_id'  => 'nexus-' . $article_id,
                                        'post_url' => home_url( '/?nexus_article=' . $article_id ),
                                        'error'    => '',
                                );
                                continue;
                        }

                        // Canali social: pubblicazione simulata, dichiarata come tale (nessuna API configurata).
                        $sim_id = 'sim_' . wp_generate_password( 8, false );
                        $logs[] = array(
                                'platform' => $platform,
                                'status'   => 'published',
                                'post_id'  => $sim_id,
                                'post_url' => 'https://social.example/' . $platform . '/' . $sim_id,
                                'error'    => '',
                                'note'     => 'Pubblicazione simulata (demo): nessuna API social configurata.',
                        );
                }

                return $logs;
        }
}
