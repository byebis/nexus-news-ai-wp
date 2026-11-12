# Nexus News AI — API Pubblica Esterna (`nexus-public/v1`)

v1.1.32. REST API pubblica per **marketplace e integrazioni terze parti**:
senza login admin, autenticata con **API key dedicata**. È il superset /
complemento del server MCP (vedi `docs/MCP.md`), con la stessa logica delle
route interne admin ma autenticazione via chiave.

## Autenticazione

La chiave si genera dalla pagina admin **Strumenti → API pubblica**
(`POST /nexus/v1/tools/api-key`, v1.0.5). L'API si attiva da Impostazioni →
**Lingua e API pubblica** (`enable_public_api = 1`).

Passa la chiave in uno di questi modi:

```
X-Nexus-Key: <chiave>      # header (alias, v1.1.32)
X-Api-Key: <chiave>        # header (storico)
?api_key=<chiave>          # query string (per client JS cross-origin)
```

La chiave è salvata **solo come hash SHA-256** in DB (mai in chiaro). Il
fallimento di autenticazione è sempre 401 identico, senza rivelare il formato.
Nessuna chiave compare nei log.

## Gating Pro

- **Read-only base** (articles, article/{id}, agents, stats, sources): free
  (la licenza Pro resta richiesta dall'attuale gate dell'API — comportamento
  mantenuto dalla v1.1.28).
- **Scrittura/pubblicazione** (`POST /schedule`): richiede **licenza Pro**.

  Fallisce con:
  ```json
  { "code": "nexus_pro_required", "message": "Questa operazione richiede il piano Pro.", "data": { "status": 403, "details": { "scope": "write", "resource": "schedule" } } }
  ```

## Rate limit

120 richieste/minuto per chiave (finestra fissa di 60s). Al superamento:
`429 nexus_rate_limited` con header `Retry-After`.

## Endpoint

| Metodo | Percorso | Descrizione | Pro |
|---|---|---|---|
| `GET` | `/nexus-public/v1/articles` | Lista articoli **pubblicati** (page, per_page, category, redazione, lang) | — |
| `GET` | `/nexus-public/v1/articles/{id}` | Singolo articolo pubblicato (404 se non pubblicato) | — |
| `GET` | `/nexus-public/v1/agents` | Agenti attivi + conteggio articoli | — |
| `GET` | `/nexus-public/v1/stats` | Contatori pubblici (total, published, agents_active, views) | — |
| `GET` | `/nexus-public/v1/sources` | Fonti configurate (RSS globali + ingest multi-formato), sola lettura | — |
| `POST` | `/nexus-public/v1/schedule` | Programma pubblicazione `{id, scheduled_at}` | ✅ |

## Payload ed errori

Tutte le risposte sono JSON. Errori standardizzati in formato WP REST:

```json
{ "code": "...", "message": "...", "data": { "status": 404 } }
```

| Codice | HTTP | Significato |
|---|---|---|
| `nexus_not_available` | 501 | DB non disponibile |
| `nexus_api_disabled` | 503 | API disattivata |
| `nexus_api_key_missing` | 401 | Chiave mancante |
| `nexus_api_key_not_configured` | 503 | Chiave non generata |
| `nexus_api_key_invalid` | 401 | Chiave non valida |
| `nexus_pro_required` | 403 | Operazione Pro senza licenza |
| `nexus_public_article_not_found` | 404 | Articolo non trovato/non pubblicato |
| `nexus_public_invalid_date` / `nexus_public_past_date` | 400 | Data programmazione non valida |
| `nexus_public_cannot_schedule` | 409 | Stato articolo non programmabile |
| `nexus_rate_limited` | 429 | Rate limit superato |

## Esempi curl

```bash
# Lista articoli pubblicati
curl -H "X-Nexus-Key: LA_TUA_CHIAVE" \
     https://example.com/wp-json/nexus-public/v1/articles

# Singolo articolo
curl -H "X-Nexus-Key: LA_TUA_CHIAVE" \
     https://example.com/wp-json/nexus-public/v1/articles/42

# Statistiche
curl -H "X-Nexus-Key: LA_TUA_CHIAVE" \
     https://example.com/wp-json/nexus-public/v1/stats

# Fonti configurate
curl -H "X-Nexus-Key: LA_TUA_CHIAVE" \
     https://example.com/wp-json/nexus-public/v1/sources

# Programma pubblicazione (Pro)
curl -X POST -H "Content-Type: application/json" -H "X-Nexus-Key: LA_TUA_CHIAVE" \
     -d '{"id":42,"scheduled_at":"2026-12-01 09:00:00"}' \
     https://example.com/wp-json/nexus-public/v1/schedule
```

## Sicurezza

- Nessuna chiave in chiaro nei log/risposte (solo hash in DB).
- Header CORS `Access-Control-Allow-Origin: *` inviato su tutte le risposte.
- Rate limit per chiave; niente credenziali admin richieste.
- Routes registrate con `permission_callback` → `auth()` / `auth_pro()`.
