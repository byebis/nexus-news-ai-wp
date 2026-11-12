# Nexus News AI — Integrazione MCP (Model Context Protocol)

v1.1.31 (gap #3). Il plugin espone una **superficie REST compatibile MCP**
per consentire ad agenti esterni (Claude, ChatGPT, altri) di interagire con
la redazione: leggere stato, estrarre articoli e programmare pubblicazioni.

## Accesso

Gli endpoint richiedono:

- **Autenticazione**: nonce REST WordPress (`X-WP-Nonce`) da un utente con
  capability `manage_options` (admin). Da un agente esterno si usa lo stesso
  flusso del resto dell'API admin del plugin.
- **Licenza Pro**: la feature MCP è premium — senza licenza Pro la risposta
  è `403 nexus_pro_required` (vedi `Nexus_License::is_pro()`).

## Endpoint

| Metodo | Percorso | Equivalente MCP | Descrizione |
|---|---|---|---|
| `GET` | `/wp-json/nexus/v1/mcp/tools` | `tools/list` | Tool discovery + schema input |
| `POST` | `/wp-json/nexus/v1/mcp/call` | `tools/call` | Invocazione di un tool |

Body `call`:

```json
{ "name": "nexus_get_status", "arguments": {} }
```

Risposta (stile MCP `callTool`):

```json
{ "content": [ { "type": "text", "text": "{\"ok\":true,\"stats\":{...}}" } ] }
```

## Strumenti disponibili

| Nome | Descrizione | Argomenti |
|---|---|---|
| `nexus_get_status` | Riepilogo stato redazione (conteggi, agenti, health) | — |
| `nexus_list_articles` | Elenca articoli (filtro per stato) | `status?`, `limit?` |
| `nexus_get_article` | Dettaglio articolo | `id` |
| `nexus_schedule_publish` | Programma pubblicazione (data/ora futura) | `id`, `scheduled_at` |
| `nexus_get_settings_status` | Stato connessioni (flag, senza segreti) | — |

## Esempio (curl)

```bash
# Discovery
curl -H "X-WP-Nonce: $NONCE" \
     https://example.com/wp-json/nexus/v1/mcp/tools

# Invocazione
curl -X POST -H "Content-Type: application/json" -H "X-WP-Nonce: $NONCE" \
     -d '{"name":"nexus_schedule_publish","arguments":{"id":42,"scheduled_at":"2026-12-01 09:00:00"}}' \
     https://example.com/wp-json/nexus/v1/mcp/call
```

## Note

- Solo lettura e scheduling: nessun tool MCP modifica direttamente articoli o
  impostazioni al di fuori del normale flusso del plugin.
- La data di `nexus_schedule_publish` deve essere futura e l'articolo deve
  essere in stato `draft`/`pending_approval`/`approved` (stessa logica di
  `POST /nexus/v1/schedule`).
