<?php
/**
 * Nexus News AI — Template single per il CPT "nexus_article" (v1.0.1).
 *
 * WIRING (Contratto v1.0.1 §1 e §8):
 *   Nexus_Cpt registra il post type "nexus_article" e aggancia il filtro
 *   'template_include': per un single nexus_article, se questo file esiste
 *   viene restituito il suo path e WordPress lo include AL POSTO del
 *   template del tema. Il file resta compatibile con QUALSIASI tema:
 *   apre con get_header() e chiude con get_footer().
 *
 * FLUSSO DATI:
 *   - Loop principale WordPress sul post corrente (CPT nexus_article).
 *   - Post meta `_nexus_article_id` (ID riga in wp_nexus_articles, scritto
 *     dal publisher) → Nexus_DB::get_article() → riga articolo Nexus
 *     (agente, fonti, letture, reazioni) → Nexus_DB::get_agent().
 *   - Se il meta manca o Nexus_DB non è disponibile la pagina degrada in
 *     modo pulito: titolo, meta WP e contenuto restano funzionanti e le
 *     sezioni Nexus (byline completa, fonti, reazioni) non vengono stampate.
 *
 * FRONTEND (contratto con public/js/nexus-public.js e public/css/nexus-public.css):
 *   - Gli asset del plugin (handle "nexus-public") sono REGISTRATI da
 *     Nexus_Shortcodes::enqueue_public() su 'wp_enqueue_scripts'. Quel hook
 *     parte dentro wp_head(), cioè DOPO l'inclusione di questo template:
 *     un enqueue diretto a inizio file sarebbe "too early" (notifica
 *     doing_it_wrong e handle non ancora registrati). Si usa quindi un
 *     hook dedicato a priorità 20, registrato PRIMA di get_header().
 *   - nexus-public.js riconosce sulla pagina:
 *       .nexus-single-reactions[data-article-id="{ID NEXUS}"] → barra reazioni
 *       .nexus-single-reaction-hint                            → messaggi di stato
 *       .nexus-single-views                                    → contatore letture
 *     dove data-article-id è l'ID NEXUS (riga wp_nexus_articles), NON l'id
 *     del post WP: gli endpoint REST /public/articles/{id}/reaction e /view
 *     lavorano sulle tabelle custom.
 *   - I bottoni reazione replicano il markup del modal (classi .nxa-reactions,
 *     .nxa-reaction-btn, .nxa-reaction-emoji, .nxa-reaction-count): ereditano
 *     così gli stili esistenti e la logica JS (throttle, dedup, rollback).
 *   - CSS: classi nuove con prefisso "nexus-single-" in public/css/nexus-public.css.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nexus_single_enqueue_assets' ) ) {
	/**
	 * Enqueue degli asset pubblici del plugin per la pagina single.
	 *
	 * Gli handle sono già registrati da Nexus_Shortcodes::enqueue_public()
	 * (stesso hook 'wp_enqueue_scripts', priorità 10): qui, a priorità 20,
	 * si fa solo l'enqueue reale se la registrazione è avvenuta. In questo
	 * modo header e footer del tema stampano CSS e JS nei punti corretti.
	 *
	 * @return void
	 */
	function nexus_single_enqueue_assets() {
		if ( wp_script_is( 'nexus-public', 'registered' ) ) {
			wp_enqueue_style( 'nexus-public' );
			wp_enqueue_script( 'nexus-public' );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'nexus_single_enqueue_assets', 20 );

get_header();

while ( have_posts() ) :
	the_post();

	/* ====================== Dati Nexus collegati al post ====================== */

	$nexus_article_id = absint( get_post_meta( get_the_ID(), '_nexus_article_id', true ) );
	$nexus_article    = null;
	$nexus_agent      = null;

	if ( $nexus_article_id > 0 && class_exists( 'Nexus_DB' ) ) {
		$nexus_article = Nexus_DB::get_article( $nexus_article_id );
		if ( $nexus_article && ! empty( $nexus_article->agent_id ) ) {
			$nexus_agent = Nexus_DB::get_agent( (int) $nexus_article->agent_id );
		}
	}

	/* Categoria: prima quella Nexus, fallback alla prima categoria WP nativa. */
	$nexus_cat = ( $nexus_article && ! empty( $nexus_article->category ) ) ? (string) $nexus_article->category : '';
	if ( '' === $nexus_cat ) {
		$nexus_wp_cats = get_the_category();
		if ( ! empty( $nexus_wp_cats ) && is_array( $nexus_wp_cats ) ) {
			$nexus_cat = (string) $nexus_wp_cats[0]->name;
		}
	}

	/* Tempo di lettura: ricalcolato sul contenuto, fallback al campo Nexus. */
	$nexus_read_time = '';
	if ( class_exists( 'Nexus_Utils' ) && method_exists( 'Nexus_Utils', 'reading_time_it' ) ) {
		$nexus_read_time = Nexus_Utils::reading_time_it( get_the_content() );
	} elseif ( $nexus_article && ! empty( $nexus_article->read_time ) ) {
		$nexus_read_time = sprintf(
			/* translators: %d: minuti di lettura. */
			__( '%d min di lettura', 'nexus-news-ai' ),
			(int) $nexus_article->read_time
		);
	}

	/* Letture (contatore Nexus, aggiornato via JS al primo view di sessione). */
	$nexus_views = $nexus_article ? absint( $nexus_article->views ) : 0;
	$nexus_views_label = sprintf(
		/* translators: %s: numero di letture. */
		_n( '%s lettura', '%s letture', $nexus_views, 'nexus-news-ai' ),
		esc_html( number_format_i18n( $nexus_views ) )
	);

	/* Byline: agente Nexus (avatar emoji + nome + categoria). */
	$nexus_avatar      = ( $nexus_agent && ! empty( $nexus_agent->avatar ) ) ? (string) $nexus_agent->avatar : '🤖';
	$nexus_agent_name  = ( $nexus_agent && ! empty( $nexus_agent->name ) ) ? (string) $nexus_agent->name : __( 'Agente AI', 'nexus-news-ai' );
	$nexus_agent_cat   = ( $nexus_agent && ! empty( $nexus_agent->category ) ) ? (string) $nexus_agent->category : '';

	/* Reazioni: emoji ammesse + conteggi correnti (JSON colonna "reactions"). */
	$nexus_emojis = ( class_exists( 'Nexus_Utils' ) && method_exists( 'Nexus_Utils', 'reaction_emojis' ) )
		? Nexus_Utils::reaction_emojis()
		: array( '❤️', '👏', '🔥', '😮', '😂', '🤔' );

	$nexus_counts = $nexus_article ? json_decode( (string) $nexus_article->reactions, true ) : array();
	if ( ! is_array( $nexus_counts ) ) {
		$nexus_counts = array();
	}

	$nexus_emoji_labels = array(
		'❤️' => __( 'Cuore', 'nexus-news-ai' ),
		'👏' => __( 'Applauso', 'nexus-news-ai' ),
		'🔥' => __( 'Fuoco', 'nexus-news-ai' ),
		'😮' => __( 'Stupito', 'nexus-news-ai' ),
		'😂' => __( 'Divertente', 'nexus-news-ai' ),
		'🤔' => __( 'Perplesso', 'nexus-news-ai' ),
	);
	?>

	<article <?php post_class( 'nexus-single' ); ?> data-category="<?php echo esc_attr( $nexus_cat ); ?>">

		<!-- ============ Header articolo: categoria, titolo, meta ============ -->
		<header class="nexus-single-hero">
			<?php if ( '' !== $nexus_cat ) : ?>
				<span class="nexus-single-cat"><?php echo esc_html( $nexus_cat ); ?></span>
			<?php endif; ?>

			<h1 class="nexus-single-title"><?php the_title(); ?></h1>

			<div class="nexus-single-meta">
				<span class="nexus-single-date"><?php echo esc_html( get_the_date() ); ?></span>
				<?php if ( '' !== $nexus_read_time ) : ?>
					<span class="nxa-sep" aria-hidden="true">·</span>
					<span class="nexus-single-read"><?php echo esc_html( $nexus_read_time ); ?></span>
				<?php endif; ?>
				<?php if ( $nexus_article ) : ?>
					<span class="nxa-sep" aria-hidden="true">·</span>
					<span class="nexus-single-views"><?php echo esc_html( $nexus_views_label ); ?></span>
				<?php endif; ?>
			</div>

			<!-- ============ Byline agente (Redazione AI) ============ -->
			<div class="nexus-single-byline">
				<span class="nexus-single-avatar" aria-hidden="true"><?php echo esc_html( $nexus_avatar ); ?></span>
				<span class="nexus-single-agent">
					<span class="nexus-single-agent-name"><?php echo esc_html( $nexus_agent_name ); ?></span>
					<?php if ( '' !== $nexus_agent_cat ) : ?>
						<span class="nexus-single-agent-cat"><?php echo esc_html( $nexus_agent_cat ); ?></span>
					<?php endif; ?>
				</span>
				<span class="nexus-single-badge">🤖 <?php echo esc_html__( 'Redazione AI', 'nexus-news-ai' ); ?></span>
			</div>
		</header>

		<!-- ============ Immagine in evidenza (se presente) ============ -->
		<?php if ( has_post_thumbnail() ) : ?>
			<figure class="nexus-single-media"><?php the_post_thumbnail( 'large' ); ?></figure>
		<?php endif; ?>

		<!-- ============ Contenuto ============ -->
		<div class="nexus-single-content">
			<?php the_content(); ?>
			<?php
			wp_link_pages(
				array(
					'before' => '<nav class="nexus-single-pages" aria-label="' . esc_attr__( 'Pagine dell’articolo', 'nexus-news-ai' ) . '">',
					'after'  => '</nav>',
				)
			);
			?>
		</div>

		<!-- ============ Box fonti (dal record Nexus) ============ -->
		<?php if ( $nexus_article && ( '' !== trim( (string) $nexus_article->source_name ) || '' !== trim( (string) $nexus_article->source_url ) ) ) : ?>
			<aside class="nexus-single-sources">
				<h2 class="nexus-single-sources-title"><?php echo esc_html__( 'Fonte originale', 'nexus-news-ai' ); ?></h2>
				<?php if ( '' !== trim( (string) $nexus_article->source_url ) ) : ?>
					<a class="nexus-single-source-link" href="<?php echo esc_url( (string) $nexus_article->source_url ); ?>" target="_blank" rel="noopener noreferrer nofollow">
						<?php echo esc_html( '' !== trim( (string) $nexus_article->source_name ) ? (string) $nexus_article->source_name : (string) $nexus_article->source_url ); ?>
					</a>
				<?php else : ?>
					<span class="nexus-single-source-name"><?php echo esc_html( (string) $nexus_article->source_name ); ?></span>
				<?php endif; ?>
			</aside>
		<?php endif; ?>

		<!-- ============ Reazioni Nexus (data-article-id = ID NEXUS, non WP) ============ -->
		<?php if ( $nexus_article && ! empty( $nexus_emojis ) ) : ?>
			<div class="nexus-single-reactions" data-article-id="<?php echo esc_attr( absint( $nexus_article_id ) ); ?>">
				<div class="nxa-reactions" role="group" aria-label="<?php esc_attr_e( 'Reazioni all’articolo', 'nexus-news-ai' ); ?>">
					<?php foreach ( $nexus_emojis as $nexus_emoji ) : ?>
						<?php
						$nexus_label = isset( $nexus_emoji_labels[ $nexus_emoji ] ) ? $nexus_emoji_labels[ $nexus_emoji ] : __( 'Reazione', 'nexus-news-ai' );
						$nexus_count = isset( $nexus_counts[ $nexus_emoji ] ) ? absint( $nexus_counts[ $nexus_emoji ] ) : 0;
						?>
						<button type="button" class="nxa-reaction-btn" data-emoji="<?php echo esc_attr( $nexus_emoji ); ?>" aria-pressed="false" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: nome dell'emoji. */ __( 'Reagisci: %s', 'nexus-news-ai' ), $nexus_label ) ); ?>">
							<span class="nxa-reaction-emoji" aria-hidden="true"><?php echo esc_html( $nexus_emoji ); ?></span>
							<span class="nxa-reaction-count"><?php echo esc_html( $nexus_count ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
				<p class="nexus-single-reaction-hint" role="status" hidden></p>
			</div>
		<?php endif; ?>

	</article>

	<?php
endwhile;

get_footer();
