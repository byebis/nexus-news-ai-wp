<?php
/**
 * Nexus Caps — capabilities per ramo redazionale e profilo utente (v1.1.1).
 *
 * MODELLO ( richiesta di prodotto v1.1.1, contratto CONTRATTO-v1.1.1.md, T-05 ):
 * ogni utente WP editoriale puo' avere UNA redazione assegnata (term ID salvato
 * nello user meta 'nexus_redazione_id'; 0 o assente = nessuna restrizione).
 * Da quel momento l'utente edita SOLO gli articoli della sua redazione e di
 * tutte le sub-redazioni. Esempio tipico: un editore sportivo assegnato a
 * "Redazione Sport" edita solo gli articoli di Sport e delle sub-redazioni
 * Calcio, Basket e F1 (il subtree del termine, esattamente come l'archivio
 * nativo di una categoria include i figli).
 *
 * COME SI APPLICA (due punti di controllo, zero tabelle nuove):
 *  1. SINGOLO ARTICOLO — filtro 'map_meta_cap': per le cap 'edit_post' e
 *     'delete_post', se i termini redazione del post NON intersecano il
 *     subtree dell'utente (Nexus_Redazioni::descendant_ids), la cap viene
 *     mappata a 'do_not_allow' (l'utente non puo' fare l'operazione).
 *  2. LISTA ARTICOLI — azione 'parse_query' su edit.php: la main query viene
 *     forzata con una clausola tax_query sul subtree (include_children true,
 *     relazione AND), cosi' l'utente scoped vede solo il proprio ramo.
 *
 * GUARDIE OBBLIGATORIE (in ordine nel filtro map_meta_cap, ognuna puo'
 * uscire con "nessun tocco" al posto della restrizione):
 *  - cap diversa da 'edit_post' o 'delete_post': fuori scope, intatto;
 *  - utente con 'manage_options' (sia quello corrente sia quello mappato):
 *    MAI bloccato — un admin resta admin anche se gli viene assegnata una
 *    redazione, il filtro non puo' mai limitare un amministratore;
 *  - user meta 'nexus_redazione_id' assente o minore di 1: nessuna
 *    restrizione, comportamento WP standard;
 *  - post ID assente negli argomenti o post inesistente: nessun tocco;
 *  - post type fuori dalla lista scoped (default 'post' e 'nexus_article',
 *    estendibile con il filtro 'nexus_caps_scoped_post_types'): nessun tocco;
 *  - post SENZA termini redazione: nessun tocco (decisione di contratto:
 *    i contenuti senza redazione restano editabili da tutti, scelta
 *    conservativa che non blocca contenuti legacy o orfani);
 *  - nessuna intersezione tra termini del post e subtree dell'utente:
 *    la cap diventa 'do_not_allow'.
 *
 * PROFILO UTENTE: il campo "Redazione assegnata" (select) e' visibile e
 * salvabile SOLO a chi ha manage_options — un editore NON puo' togliersi
 * la restrizione da solo (nemmeno forgando la richiesta: il salvataggio
 * e' protetto da nonce 'nexus_caps_profile' e da current_user_can).
 *
 * SCOPO E LIMITI (onesti): la restrizione copre wp-admin (cap di singolo
 * articolo + lista edit.php). Le cap standard WP restano la base per tutto
 * il resto (REST, front-end, media): le query non-admin non vengono toccate.
 * Se la redazione assegnata viene eliminata, l'utente resta senza subtree
 * valido (comportamento fail-closed): l'amministratore riassegna dal profilo.
 *
 * NOTA PER L'INTEGRATORE: il require del bootstrap e la chiamata
 * Nexus_Caps::init() sono da aggiungere da parte dell'integratore
 * (il file viene consegnato senza aggancio al bootstrap).
 *
 * @package Nexus_News_AI
 * @since   1.1.1
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Caps {

        /**
         * User meta con il term ID della redazione assegnata all'utente WP.
         *
         * 0 o assente = nessuna restrizione (cap WP standard).
         *
         * @var string
         */
        const USER_META = 'nexus_redazione_id';

        /**
         * Registra i punti di hook: map_meta_cap, profilo utente, lista articoli.
         *
         * parse_query usa priorita' 20: gira DOPO il filtro dropdown di
         * Nexus_Redazioni (priorita' 10), cosi' la clausola di restrizione
         * viene APPESA alla tax_query esistente e non puo' essere sovrascritta.
         *
         * @return void
         */
        public static function init() {
                add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
                add_action( 'show_user_profile', array( __CLASS__, 'profile_field' ) );
                add_action( 'edit_user_profile', array( __CLASS__, 'profile_field' ) );
                add_action( 'personal_options_update', array( __CLASS__, 'save_profile_field' ) );
                add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_field' ) );
                add_action( 'parse_query', array( __CLASS__, 'scope_posts_list' ), 20, 1 );
        }

        /* ============ PROFILO UTENTE ============ */

        /**
         * Campo "Redazione assegnata" nel profilo utente.
         *
         * Visibile SOLO a chi ha manage_options (su show_user_profile e
         * edit_user_profile): un editore non vede nemmeno il campo, quindi
         * non puo' togliersi la restrizione da solo.
         *
         * @param WP_User $user Utente del profilo corrente.
         * @return void
         */
        public static function profile_field( $user ) {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return;
                }
                if ( ! class_exists( 'Nexus_Redazioni' ) || ! taxonomy_exists( Nexus_Redazioni::TAX ) ) {
                        return;
                }

                $user_id  = ( $user instanceof WP_User ) ? absint( $user->ID ) : 0;
                $selected = self::user_redazione_id( $user_id );

                wp_nonce_field( 'nexus_caps_profile', 'nexus_caps_profile_nonce' );
                ?>
                <table class="form-table" role="presentation">
                        <tr>
                                <th scope="row">
                                        <label for="<?php echo esc_attr( self::USER_META ); ?>"><?php esc_html_e( 'Redazione assegnata', 'nexus-news-ai' ); ?></label>
                                </th>
                                <td>
                                        <select name="<?php echo esc_attr( self::USER_META ); ?>" id="<?php echo esc_attr( self::USER_META ); ?>">
                                                <option value="0"<?php selected( $selected, 0 ); ?>><?php esc_html_e( '(tutte — nessuna restrizione)', 'nexus-news-ai' ); ?></option>
                                                <?php echo self::redazione_options_html( $selected ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML costruito ed escaped dentro redazione_options_html(). ?>
                                        </select>
                                        <p class="description"><?php esc_html_e( 'L\'utente edita solo gli articoli della redazione scelta e delle sue sub-redazioni (esempio: Redazione Sport include Calcio, Basket e F1). Solo un amministratore puo\' modificare questo campo.', 'nexus-news-ai' ); ?></p>
                                </td>
                        </tr>
                </table>
                <?php
        }

        /**
         * Salvataggio del campo profilo.
         *
         * Doppia guardia: nonce 'nexus_caps_profile' + manage_options
         * dell'utente corrente. Valore 0 = nessuna restrizione (il meta
         * viene rimosso con delete_user_meta); un term ID inesistente non
         * viene accettato (nessuna scrittura, valore precedente resta).
         *
         * @param int $user_id ID dell'utente del profilo salvato.
         * @return void
         */
        public static function save_profile_field( $user_id ) {
                $user_id = absint( $user_id );
                if ( $user_id < 1 || ! current_user_can( 'manage_options' ) ) {
                        return;
                }

                if ( ! isset( $_POST['nexus_caps_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nexus_caps_profile_nonce'] ) ), 'nexus_caps_profile' ) ) {
                        return;
                }

                $term_id = isset( $_POST[ self::USER_META ] ) ? absint( wp_unslash( $_POST[ self::USER_META ] ) ) : 0;

                /* 0 = nessuna restrizione: rimozione del meta. */
                if ( $term_id < 1 ) {
                        delete_user_meta( $user_id, self::USER_META );
                        return;
                }

                /* Accetta solo termini redazione realmente esistenti. */
                if ( ! class_exists( 'Nexus_Redazioni' ) || ! get_term( $term_id, Nexus_Redazioni::TAX ) instanceof WP_Term ) {
                        return;
                }

                update_user_meta( $user_id, self::USER_META, $term_id );
        }

        /**
         * Opzioni della select redazioni, indentate per profondita'.
         *
         * La profondita' e' calcolata risalendo i parent dei termini (stessa
         * semantica gerarchica delle categorie WP, con guardia anti-ciclo);
         * ogni livello aggiunge un trattino davanti al nome, come nel
         * dropdown categorie di WP.
         *
         * @param int $selected Term ID selezionato (0 = nessuno).
         * @return string HTML delle opzioni, escaped.
         */
        private static function redazione_options_html( $selected ) {
                $terms = Nexus_Redazioni::all_terms();
                if ( empty( $terms ) ) {
                        return '';
                }

                $parents = array();
                foreach ( $terms as $term ) {
                        $parents[ (int) $term->term_id ] = absint( $term->parent );
                }

                $out = '';
                foreach ( $terms as $term ) {
                        $term_id = (int) $term->term_id;

                        $depth  = 0;
                        $cursor = $term_id;
                        $seen   = array();
                        while ( isset( $parents[ $cursor ] ) && $parents[ $cursor ] > 0 && ! isset( $seen[ $cursor ] ) ) {
                                $seen[ $cursor ] = true;
                                $cursor          = $parents[ $cursor ];
                                $depth++;
                        }

                        $label = str_repeat( '— ', $depth ) . $term->name;
                        $out  .= "\t" . '<option value="' . esc_attr( $term_id ) . '"' . selected( $selected, $term_id, false ) . '>' . esc_html( $label ) . '</option>' . "\n";
                }

                return $out;
        }

        /* ============ SINGOLO ARTICOLO: map_meta_cap ============ */

        /**
         * Restringe 'edit_post' e 'delete_post' al subtree della redazione.
         *
         * Guardie in ordine (vedi docblock di classe): cap fuori scope,
         * admin corrente o mappato, meta assente, post ID assente o post
         * inesistente, post type non scoped, post senza termini redazione —
         * in tutti questi casi le cap tornano INTATTE. Solo quando il post
         * ha termini redazione e nessuno interseca il subtree dell'utente
         * la cap viene mappata a 'do_not_allow'.
         *
         * Nota tecnica: nel filtro 'map_meta_cap' il post ID arriva come
         * primo argomento extra di current_user_can (args[0], semantica
         * core di edit_post e delete_post); args[1] e' accettato come
         * fallback per tollerare firme alternative.
         *
         * @param array  $caps    Cap calcolate da WP fino a ora.
         * @param string $cap     Cap in mapping (edit_post o delete_post).
         * @param int    $user_id Utente di cui si mappano le cap.
         * @param array  $args    Argomenti extra (il post ID e' il primo).
         * @return array
         */
        public static function map_meta_cap( $caps, $cap, $user_id, $args = array() ) {
                /* (a) Solo edit_post e delete_post sono in scope. */
                if ( 'edit_post' !== $cap && 'delete_post' !== $cap ) {
                        return $caps;
                }

                /* Degradazione onesta: senza le fondamenta non si restringe nulla. */
                if ( ! class_exists( 'Nexus_Redazioni' ) ) {
                        return $caps;
                }

                /* (b) Mai bloccare un amministratore (corrente o mappato). */
                if ( current_user_can( 'manage_options' ) ) {
                        return $caps;
                }
                if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
                        return $caps;
                }

                /* (c) Senza redazione assegnata: nessuna restrizione. */
                $term_id = self::user_redazione_id( $user_id );
                if ( $term_id < 1 ) {
                        return $caps;
                }

                /* (d) Post ID assente: nessun tocco. */
                if ( ! is_array( $args ) || empty( $args ) ) {
                        return $caps;
                }
                $post_id = 0;
                if ( isset( $args[0] ) ) {
                        $post_id = absint( $args[0] );
                }
                if ( $post_id < 1 && isset( $args[1] ) ) {
                        $post_id = absint( $args[1] );
                }
                if ( $post_id < 1 ) {
                        return $caps;
                }

                $post = get_post( $post_id );
                if ( ! $post instanceof WP_Post ) {
                        return $caps;
                }

                /* Post type fuori dalla lista scoped: nessun tocco. */
                if ( ! in_array( $post->post_type, self::scoped_post_types(), true ) ) {
                        return $caps;
                }

                /* (e) Post senza redazione: editabile da tutti (conservativo). */
                $post_terms = self::post_term_ids( $post->ID );
                if ( empty( $post_terms ) ) {
                        return $caps;
                }

                /* (f) Nessuna intersezione con il subtree dell'utente: negato. */
                $branch = Nexus_Redazioni::descendant_ids( $term_id );
                if ( empty( array_intersect( $post_terms, $branch ) ) ) {
                        return array( 'do_not_allow' );
                }

                return $caps;
        }

        /**
         * Post type coperti dalla restrizione.
         *
         * Default: 'post' (nativo, dalla v1.1.0) e 'nexus_article' (CPT
         * legacy). Estendibile con il filtro 'nexus_caps_scoped_post_types'
         * (array vuoto = restrizione disattivata).
         *
         * @return array
         */
        public static function scoped_post_types() {
                $types = apply_filters( 'nexus_caps_scoped_post_types', array( 'post', 'nexus_article' ) );
                if ( ! is_array( $types ) ) {
                        $types = array( 'post', 'nexus_article' );
                }
                $types = array_filter( array_map( 'sanitize_key', $types ) );
                return array_values( array_unique( $types ) );
        }

        /**
         * Term ID redazione assegnati a un post (solo ID, senza gerarchia).
         *
         * @param int $post_id Post ID.
         * @return array
         */
        private static function post_term_ids( $post_id ) {
                if ( ! taxonomy_exists( Nexus_Redazioni::TAX ) || ! function_exists( 'wp_get_object_terms' ) ) {
                        return array();
                }
                $ids = wp_get_object_terms( absint( $post_id ), Nexus_Redazioni::TAX, array( 'fields' => 'ids' ) );
                if ( is_wp_error( $ids ) || ! is_array( $ids ) ) {
                        return array();
                }
                return array_values( array_filter( array_map( 'absint', $ids ) ) );
        }

        /**
         * Term ID della redazione assegnata a un utente (0 = nessuna).
         *
         * @param int $user_id User ID.
         * @return int
         */
        public static function user_redazione_id( $user_id ) {
                $user_id = absint( $user_id );
                if ( $user_id < 1 || ! function_exists( 'get_user_meta' ) ) {
                        return 0;
                }
                return absint( get_user_meta( $user_id, self::USER_META, true ) );
        }

        /* ============ LISTA ARTICOLI: parse_query ============ */

        /**
         * Forza la lista articoli di wp-admin al subtree dell'utente.
         *
         * Attivo SOLO su: is_admin, main query, schermata edit.php con
         * post_type nella lista scoped, utente con redazione assegnata e
         * SENZA manage_options. La main query riceve una clausola tax_query
         * sul subtree (include_children true, relazione AND con le clausole
         * gia' presenti, vedi force_tax_query): l'utente scoped vede SOLO
         * gli articoli del suo ramo. Le altre schermate e le query non-admin
         * non vengono toccate.
         *
         * @param WP_Query $query Query corrente (passata da WP per riferimento).
         * @return WP_Query
         */
        public static function scope_posts_list( $query ) {
                if ( ! $query instanceof WP_Query || ! is_admin() || ! $query->is_main_query() ) {
                        return $query;
                }

                global $pagenow;
                if ( 'edit.php' !== $pagenow ) {
                        return $query;
                }

                $post_type = $query->get( 'post_type' );
                if ( ! in_array( (string) $post_type, self::scoped_post_types(), true ) ) {
                        return $query;
                }

                $current_user_id = get_current_user_id();
                if ( $current_user_id < 1 || current_user_can( 'manage_options' ) ) {
                        return $query;
                }

                if ( ! class_exists( 'Nexus_Redazioni' ) ) {
                        return $query;
                }

                $term_id = self::user_redazione_id( $current_user_id );
                if ( $term_id < 1 ) {
                        return $query;
                }

                $branch = Nexus_Redazioni::descendant_ids( $term_id );
                if ( empty( $branch ) ) {
                        return $query;
                }

                $clause = array(
                        'taxonomy'         => Nexus_Redazioni::TAX,
                        'field'            => 'term_id',
                        'terms'            => array_map( 'intval', $branch ),
                        'include_children' => true,
                        'operator'         => 'IN',
                );
                self::force_tax_query( $query, $clause );

                return $query;
        }

        /**
         * Appende una clausola tax_query preservando quelle gia' presenti.
         *
         * La restrizione deve SEMPRE restringere: le clausole esistenti
         * restano in relazione AND (default WP). Se una tax_query esistente
         * ha relazione OR di primo livello, viene annidata come clausola
         * unica: un OR di primo livello allargherebbe la lista invece di
         * limitarla. Clausola singola gia' presente viene normalizzata a
         * lista prima di appendere.
         *
         * @param WP_Query $query  Query da modificare.
         * @param array    $clause Clausola (taxonomy, field, terms, include_children).
         * @return void
         */
        private static function force_tax_query( $query, $clause ) {
                $existing = $query->get( 'tax_query' );

                /* Nessuna tax_query presente: si imposta da zero. */
                if ( empty( $existing ) || ! is_array( $existing ) ) {
                        $query->set( 'tax_query', array( $clause ) );
                        return;
                }

                /* Clausola singola gia' presente: normalizzata a lista. */
                if ( isset( $existing['taxonomy'] ) ) {
                        $existing = array( $existing );
                }

                if ( isset( $existing['relation'] ) && 'OR' === strtoupper( (string) $existing['relation'] ) ) {
                        $query->set(
                                'tax_query',
                                array(
                                        'relation' => 'AND',
                                        $existing,
                                        $clause,
                                )
                        );
                        return;
                }

                /* Relazione AND (default WP): la clausola di restrizione si appende. */
                $existing[] = $clause;
                $query->set( 'tax_query', $existing );
        }
}
