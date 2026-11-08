# Piano di conversione «Tutto nativo» — Nexus News AI

> Stato: **PIANO** (nessuna implementazione). Progetto: `nexus-news-ai-wp` v1.1.25.
> Obiettivo del board: ogni articolo della pipeline deve avere il suo **post WordPress nativo** corrispondente in ogni stato (draft, pending, publish) — niente più «articolo Nexus» separato.

---

## 1. Stato attuale (mappa)

### Dove vivono gli articoli
- **Dati principali**: tabella custom `wp_nexus_articles` (`Nexus_DB`). È la fonte di verità per tutta la pipeline: titolo, contenuto, riassunto, categoria, `agent_id`, `source_*`, `factcheck_score`, `quality_score`, `status`, `wp_post_id`, date, views, reazioni.
- **Post WordPress**: creato **solo alla pubblicazione** in `Nexus_Publisher::publish_wp_post()` (`wp_insert_post`/`wp_update_post`), con `wp_post_id` salvato su `wp_nexus_articles` (campo esistente). In draft/pending **non esiste alcun post WP**.
- **Stati articolo Nexus**: `draft | pending_approval | approved | published | rejected`.
- **Ciclo attuale**: collect → `draft` → evaluate → rewrite → (sopra soglia) `pending_approval` → approve → `approved` → publish → `published` (o `rejected`). Il post WP nasce solo nello step `published`.

### Cosa già è «nativo» (parzialmente)
- Il post WP è **post nativo** (`post_type` `post`, filtrabile `nexus_native_post_type`), non CPT separato.
- Autore bot dell'agente (`Nexus_Native::article_author_id`), categoria nativa (`article_term_id`), tag (`article_tags`), redazioni come tassonomia nativa `nexus_redazione` (`assign_post_terms`).
- Meta di collegamento `_nexus_article_id` sul post WP; `wp_post_id` su Nexus.
- Pubblicazione: `enable_wp_posts` + log per piattaforma (Telegram/Discord/X/LinkedIn) + SEO + featured image (`ensure_featured`) + auto-traduzione post-publish.

---

## 2. Modello «tutto nativo» proposto

### 2.1 Principio
Ogni articolo Nexus è **sempre** rappresentato da un post WP. Il post WP viene creato **alla creazione del primo draft** e **aggiornato a ogni cambio di stato**; `wp_post_id` è il legame stabile (`_nexus_article_id` sul post resta il back-reference).

### 2.2 Mappatura stati Nexus ↔ post WP
| Stato Nexus | Stato post WP | Note |
|---|---|---|
| `draft` | `draft` | Post creato al primo draft; visibile solo in admin. |
| `pending_approval` | `pending` | In attesa di revisione; corrisponde alla Coda Approvazioni. |
| `approved` | `draft` (o `pending`) | Approvato ma non ancora pubblicato; restare non-pubblico. |
| `published` | `publish` | Post pubblico nativo. |
| `rejected` | `trash` (o `draft`) | Da decidere: `trash` lo rimuove dal front, `draft` lo tiene in editing. |

> Nota: WordPress non ha uno stato `approved` nativo. La scelta più fedele è: `approved` Nexus → post `draft` (ma marcato con meta `_nexus_approved=1`), finché `publish()` non lo porta a `publish`. In alternativa si può usare `pending` anche per `approved` e distinguere via meta.

### 2.3 Tipo di post e relazioni
- **Tipo**: `post` nativo (nessun CPT nuovo). `nexus_native_post_type` resta per estensibilità.
- **Relazione Nexus ↔ post**: campo `wp_post_id` su `wp_nexus_articles` (esistente) + meta `_nexus_article_id` sul post. Bidirezionale e queryabile.
- **Agenti → autori**: `Nexus_Native::article_author_id()` (autore bot per agente). Già usato.
- **Redazioni → tassonomia**: `nexus_redazione` (nativa) via `Nexus_Redazioni::assign_post_terms()`. Già usato.
- **Categoria**: term dell'agente (`article_term_id`) o categoria garantita. Già usato.
- **Tag**: `article_tags()`. Già usato.

### 2.4 Feature WP da sfruttare (oggi non usate o sottousate)
- **Revisioni** (`revisions`): ogni `wp_update_post` genera una revisione → storico completo per articolo. Richiede `wp_is_post_revisionable` e di non disabilitarle.
- **Workflow status nativi**: `draft`/`pending`/`publish` gestiti da core (permessi, preview, query).
- **Preview/permalink**: il post draft/pending ha URL di preview nativo e permalink definitivo.
- **Permessi/role**: `edit_post`/`publish_posts` nativi per redattori; la Coda Approvazioni può diventare una query a `post_status=pending`.
- **Commenti**: disabilitati oggi; si possono attivare per stato su `publish`.
- **Featured image**: già usata (`ensure_featured`).
- **SEO**: già usato (`Nexus_SEO`).
- **Search/query core**: `WP_Query` su `post_type=post` sostituisce le query custom su `nexus_articles` per listing/viste.
- **`wp_insert_post`/`wp_update_post`**: già usati, diventano l'unico punto di scrittura.

---

## 3. Modifiche necessarie

### 3.1 Punti della pipeline dove creare/aggiornare il post WP
1. **Collect (creazione draft)** → dopo `insert_article` (stato `draft`), creare il post WP in `draft` e salvare `wp_post_id`.
2. **Rewrite** → `wp_update_post` quando cambiano title/content/excerpt (mantiene revisioni).
3. **Promozione a `pending_approval`** → `wp_update_post` → `post_status=pending`.
4. **Approve (`approved`)** → `wp_update_post` → `post_status=draft` (o `pending`) + meta `_nexus_approved=1`.
5. **Reject** → `wp_update_post` → `post_status=trash` (o `draft`).
6. **Publish** → `wp_update_post` → `post_status=publish` (il flusso attuale `publish_wp_post` si riduce a un update di stato + log piattaforma + SEO + featured).
7. **Se un post WP manca** (dati legacy) → la prima scrittura di stato lo crea (backfill idempotente).

### 3.2 Cosa estrarre/rifattorizzare
- **`Nexus_Publisher::publish_wp_post()`**: separare «crea/aggiorna post» da «pubblica + distribuisci». Il sync post diventa un servizio chiamato in ogni transizione di stato.
- **Nuovo helper** (es. `Nexus_Native::sync_post_from_article($article)`): mappa articolo → `wp_insert_post`/`wp_update_post` (title/content/excerpt/status/author/category/tags/redazione/meta).
- **Hook di stato**: centralizzare le transizioni di stato in un metodo unico (`set_status`) che aggiorna sia `nexus_articles` sia il post WP.
- **Query di listing**: replicare gli shortcode (`nexus_magazine`, `nexus_articles`, `nexus_wire`) su `WP_Query`/post nativi, leggendo i metadati Nexus solo dove serve.

### 3.3 Cosa va rimosso (decommissioning)
- **Front-end legacy**: gli shortcode attuali leggono `nexus_articles` con SQL custom → migrare a post nativi. Mantenere lo shortcode come compatibilità, ma alimentato dai post.
- **Tabella parallela**: `nexus_articles` resta come **indice/estensioni** (score, factcheck, source, metrics, reazioni) ma non più come fonte primaria del contenuto editoriale; a lungo termine si può ridurre a metadati.
- **Serializzazioni/percorsi duplicati**: log di pubblicazione e piattaforme restano (dati operativi), ma lo stato editoriale vive sul post.

### 3.4 Rischi di regressione
- **Doppia scrittura**: se sync post fallisce, la pipeline non deve rompersi — degradare a «solo Nexus» con log e retry (pattern attuale best-effort).
- **Stati disallineati**: definire la mappatura e una routine di riconciliazione (resync) per i dati esistenti.
- **Autore/categorie**: già mappati; verificare i fallback per agenti senza term/autore.
- **Shortcode/REST**: le API pubbliche devono continuare a funzionare; durante la migrazione possono servire entrambe le fonti.
- **Prestazioni**: `wp_insert_post` per ogni articolo aggiunge costo; usare `wp_update_post` con `edit_date=false` e `wp_is_post_revisionable` controllato per non esplodere le revisioni.

---

## 4. Fasi di implementazione suggerite (fuori scope qui)
1. **Fase A (sync post)**: nuovo `sync_post_from_article`; creazione alla collect; aggiornamento su każ transizione. Test di regressione.
2. **Fase B (query native)**: migrare shortcode e REST public a `WP_Query` su post.
3. **Fase C (decommissioning)**: deprecare la lettura diretta di `nexus_articles`; backfill dei dati esistenti (creare post per articoli corrente).
4. **Fase D (cleanup)**: rimuovere percorsi legacy non più usati.

---

## 5. Domande aperte per il board
- `rejected` → `trash` o `draft`?
- `approved` Nexus → `draft`+meta o `pending`?
- Gli shortcode/REST pubblici mostrano solo `publish`? (continua a essere così)
- Serve mantenere `nexus_articles` come tabella dati o migrare del tutto a metadati post?
