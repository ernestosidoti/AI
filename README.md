# AI Laboratory — Listetelemarketing

Sistema PHP per estrazione liste contatti commerciali + bot Telegram interattivo con Claude Sonnet 4.5.

## Componenti principali

- **Web UI** (`/ai/`): dashboard admin per gestione clienti, ordini, fatture, log estrazioni
- **Bot Telegram** (`@listetelemarketing_bot`): interfaccia conversazionale AI per i commerciali
- **Estrazione liste**: query multi-fonte su DB MySQL remoto (Edicus, SKY, LIBERO, PDR), dedup via anti-join su magazzino cliente, output xlsx
- **Stat su disponibilità**: conteggi per provincia/comune/regione, con group-by dinamico
- **Numeri extra** (`master_cf_numeri`): arricchimento output xlsx con telefoni aggiuntivi per CF
- **Filtro età da CF**: estrazioni per fascia anagrafica (es. "giovani 18-30")

## Setup

```bash
# 1. Clona
git clone https://github.com/ernestosidoti/AI.git ai
cd ai

# 2. Configura (i file config/*.php sono in .gitignore)
cp config/config.example.php   config/config.php
cp config/telegram.example.php config/telegram.php
# ...poi modifica i valori (DB credenziali, bot token, ecc.)

# 3. Python per xlsx
pip3 install openpyxl

# 4. Avvia poller Telegram (auto-restart on crash)
./tools/telegram_poller_runner.sh &
```

## Struttura

```
ai/
├── api/                  endpoint REST (token-auth per app esterne)
├── config/               config.php + telegram.php (NON committati)
├── lib/                  logica core
│   ├── db.php                      connessione MySQL con auto-reconnect
│   ├── estrai_engine.php           query builder + xlsx output
│   ├── estrai_parser.php           Claude API → intent strutturato
│   ├── telegram_flow_agent.php     orchestrator conversazionale AI
│   ├── telegram_flow_estrai.php    flusso estrazione guidato
│   ├── telegram_flow_stats.php     flusso statistiche
│   ├── telegram_flow_storico.php   storico ordini cliente
│   ├── telegram_flow_magazzino.php gestione dedup cliente
│   ├── telegram_flow_new_client.php  creazione cliente via blob-paste
│   ├── mailer.php                  SMTP direct socket (aruba)
│   └── stats_sources.php           catalogo fonti + mapping colonne
├── tools/                script CLI + cron
│   ├── telegram_poller.php         long-polling bot
│   ├── telegram_poller_runner.sh   wrapper auto-restart
│   ├── extract_by_age.php          estrazione CLI filtro età
│   └── cron_marketing_campaigns.php  invio campagne schedulate
├── storage/              (gitignored) logs, offsets, estrazioni
├── downloads/            (gitignored) xlsx generati per cliente
└── *.php                 pagine UI (dashboard, clienti, ordini, fatture, log)
```

## DB

- **MySQL 5.7** remoto: `$AI_DB_HOST:$AI_DB_PORT`
- Database principali usati:
  - `ai_laboratory`: settings, deliveries, stat_history, tg_conversations, queries
  - `backoffice`: clientes, orders, users (telegram_chat_id matching)
  - `Edicus_2023_marzo`, `Edicus2021_luglio`, `SKY_2023`, `LIBERO_2020`, `PDR_*`: fonti dati
  - `clienti`: tabelle magazzino per dedup anti-join per cliente
  - `trovacodicefiscale2.master_cf_numeri`: 42M+ righe, lookup numeri per CF

## AI / modelli

- **Claude Sonnet 4.5** (`claude-sonnet-4-5-20250929`) per:
  - parsing richieste in linguaggio naturale → intent JSON (EstraiParser)
  - orchestrator conversazionale multi-turn con accumulo intent (FlowAgent)
  - riconoscimento filtri (data attivazione, età, area, prodotto, ecc.)
- API key in `ai_laboratory.settings(setting_key='anthropic_api_key')`

## Tech stack

- PHP 8.3 CLI + MAMP Apache
- MySQL 5.7
- Python 3 + openpyxl per generazione xlsx
- SMTP socket diretto (bypass PHPMailer) per Aruba

## License

Private / proprietary. Tutti i diritti riservati.
