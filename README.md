# Nexus News AI

![Version](https://img.shields.io/badge/versione-1.1.25-blue)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-green)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)

Il giornale di nuova generazione, scritto da intelligenze artificiali. Nexus News AI costruisce una redazione di agenti giornalisti AI dentro WordPress: ogni agente ha nome, categoria, personalità e modello AI propri; scegli le fonti, le regole di qualità e il livello di autonomia, e la redazione lavora da sola tramite WP-Cron.

Il plugin parte da zero agenti: il wizard «Crea la tua redazione» propone pacchetti pronti (Testata generalista, News veloci, Solo Tecnologia, Agenzia completa) oppure la creazione manuale.

## Come funziona la pipeline

1. **Raccolta** — gli agenti leggono le fonti configurate (RSS, siti web, YouTube, Telegram, Reddit, ricerche web, server MCP, pagine singole) e preparano le bozze con una selezione fatta dal modello AI.
2. **Valutazione** — ogni notizia riceve un punteggio di qualità da 0 a 100.
3. **Riscrittura** — il modello riscrive le notizie migliori nello stile editoriale dell'agente, con fact-check e controllo anti-duplicato.
4. **Approvazione** — in modalità semi-autonoma l'articolo attende un revisore umano nella Coda Approvazioni; in modalità autonoma viene pubblicato da solo se supera la soglia di qualità. Il Capo Redattore AI può approvare, correggere o rifiutare gli articoli.
5. **Pubblicazione** — l'articolo diventa un post WordPress nativo con autore bot, categoria e tag reali, immagine in evidenza e SEO automatico. Se configurata, parte anche la distribuzione su Telegram, Discord, X e LinkedIn.

## Requisiti

- WordPress 6.0 o superiore.
- PHP 7.4 o superiore.
- Nessuna dipendenza esterna: solo PHP puro e JavaScript vanilla, senza framework da compilare.
- Una chiave AI compatibile OpenAI/OpenRouter per generare gli articoli. Non serve per installare il plugin o esplorare il pannello.

## Sezione principale

Riferimento completo: [readme.txt](readme.txt) (descrizione, installazione, FAQ e changelog).
