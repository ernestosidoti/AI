<?php
/**
 * Libreria spiegazioni comandi — per action=explain
 */

if (!defined('AILAB')) { http_response_code(403); exit('Accesso negato'); }

class Explanations
{
    public static function get(string $topic): string
    {
        $topic = strtolower(trim($topic));
        $m = [
            'estrai'             => self::estrai(),
            'stat'               => self::stat(),
            'storico'            => self::storico(),
            'list_stats'         => self::listStats(),
            'view_stat'          => self::viewStat(),
            'ripeti'             => self::ripeti(),
            'magazzino'          => self::magazzino(),
            'menu'               => self::menu(),
            'tutto'              => self::tutto(),
            'business_examples'  => self::businessExamples(),
            'business'           => self::businessExamples(),
            'esempi_business'    => self::businessExamples(),
            'consumer_examples'  => self::consumerExamples(),
            'consumer'           => self::consumerExamples(),
            'esempi_consumer'    => self::consumerExamples(),
            'residenziali'       => self::consumerExamples(),
            'esempi_residenziali'=> self::consumerExamples(),
            'esempi_privati'     => self::consumerExamples(),
            'esempi'             => self::esempi(),
        ];
        return $m[$topic] ?? self::notFound($topic);
    }

    private static function estrai(): string
    {
        return "📥 <b>Estrazione lista</b>\n\n"
          . "<b>Cosa fa</b>: genera un file xlsx di contatti (mobile + anagrafica) per un cliente, da una fonte DB scelta in base al prodotto, e invia email con allegato a cliente + team.\n\n"
          . "<b>Flusso passo passo</b>:\n"
          . "1. Parser Claude estrae dalla tua richiesta: cliente, prodotto, quantità, area, filtri\n"
          . "2. Se cliente ambiguo → chiede numero tra candidati\n"
          . "3. Magazzino: usa quello salvato o chiede A/B/C\n"
          . "4. Chiede il prezzo di vendita\n"
          . "5. Mostra riepilogo + chiede conferma estrazione\n"
          . "6. Genera il file xlsx (anti-join contro magazzino se attivo)\n"
          . "7. Ti mostra report + preview xlsx + chiede conferma invio email\n"
          . "8. Su SI: invia email cliente + team, aggiorna magazzino, registra in deliveries\n\n"
          . "<b>Esempi</b>:\n"
          . "• <i>estrai 2000 depurazione Cerullo provincia Milano non stranieri</i>\n"
          . "• <i>voglio 3000 numeri fotovoltaico per Ediwater in Lombardia</i>\n"
          . "• <i>mandami 500 cessione quinto per Lopez in Campania</i>\n"
          . "• <i>dammi 10000 email Lazio per Cerullo</i> <i>(usa fonte SKY_2023)</i>\n\n"
          . "<b>Filtri riconosciuti</b>: non stranieri (CF italiani), regione, provincia, comune.\n"
          . "<b>Prodotti validi</b>: energia, fotovoltaico, depurazione, telefonia, cessione_quinto, finanziarie, alimentari, immobiliari, cosmetica, generiche, email, gdpr, digital_mkt, lead_voip.";
    }

    private static function stat(): string
    {
        return "📊 <b>Statistica disponibilità</b>\n\n"
          . "<b>Cosa fa</b>: ti dice quanti record puoi ancora consegnare a un cliente per un prodotto+area, tenendo conto del magazzino (già consegnati). Interroga 3 fonti di qualità e può approfondire su altre 4.\n\n"
          . "<b>Output</b>:\n"
          . "• Totale record · 📱 mobili · ☎️ fissi\n"
          . "• 🗄 Già consegnati (da anti-join col magazzino salvato)\n"
          . "• ✅ Disponibili = totale − consegnati\n"
          . "• Breakdown per fonte (SKY 2022, Edicus 2023, Edicus 2021 Lug, ...)\n"
          . "• Breakdown per provincia/comune/regione (top 20)\n\n"
          . "<b>Esempi</b>:\n"
          . "• <i>stat lombardia per provincia per Cerullo depurazione</i>\n"
          . "• <i>quanti ne abbiamo in Sicilia per Ediwater</i>\n"
          . "• <i>statistica Campania per Lopez cessione quinto</i>\n"
          . "• <i>conteggio per comune in Milano per Cerullo</i>\n\n"
          . "<b>Note</b>:\n"
          . "• Se non specifichi il prodotto, lo deduco dall'ultima consegna/ordine del cliente\n"
          . "• Prime 3 fonti → poi ti chiedo «approfondisco sulle altre 4?»\n"
          . "• Se approfondisci, la stat viene AGGIORNATA nel record (not duplicata)\n"
          . "• Ogni stat è salvata in <code>stat_history</code> e richiamabile per ID";
    }

    private static function storico(): string
    {
        return "📋 <b>Storico cliente</b>\n\n"
          . "<b>Cosa fa</b>: mostra cosa ha comprato un cliente nel tempo — sia dagli <b>ordini commerciali</b> (<code>backoffice.orders</code>) sia dalle <b>consegne AI Lab</b> (<code>ai_laboratory.deliveries</code>).\n\n"
          . "<b>Output</b>:\n"
          . "• Ultimi 10 ordini (data · prodotto · quantità · €)\n"
          . "• Aggregato per categoria (quante volte, totale record, totale €)\n"
          . "• Ultime consegne AI Lab (se ce ne sono)\n\n"
          . "<b>Esempi</b>:\n"
          . "• <i>fammi vedere Ediwater cosa ha acquistato</i>\n"
          . "• <i>storico di Cerullo</i>\n"
          . "• <i>ultimi ordini di Lopez</i>\n"
          . "• <i>cronologia acquisti di Grazia e Virginia</i>\n\n"
          . "<b>Note</b>: la ricerca cliente è fuzzy (match anche con spazi/inversioni) e supporta filtri per regione/zona (es. <i>«storico cliente calabrese»</i>).";
    }

    private static function listStats(): string
    {
        return "💾 <b>Stat salvate</b>\n\n"
          . "<b>Cosa fa</b>: elenca le statistiche eseguite in passato. Ogni stat che generi viene salvata in <code>ai_laboratory.stat_history</code> con ID progressivo e dati completi.\n\n"
          . "<b>Filtri supportati</b>:\n"
          . "• Per <b>cliente</b>: solo stat di quel cliente\n"
          . "• Per <b>periodo</b>: oggi, ieri, questa/scorsa settimana, questo/scorso mese, range esplicito\n\n"
          . "<b>Esempi</b>:\n"
          . "• <i>mostrami le stat salvate</i>\n"
          . "• <i>stat di ieri</i>\n"
          . "• <i>stat di questa settimana</i>\n"
          . "• <i>stat del mese scorso</i>\n"
          . "• <i>stat dal 10 al 20 aprile</i>\n"
          . "• <i>stat salvate di Cerullo</i>\n"
          . "• <i>stat di Ediwater di marzo</i>\n\n"
          . "<b>Output per ogni stat</b>: ID · data · cliente · prodotto · area · totale · disponibili · pulsante /vedistat per richiamare.";
    }

    private static function viewStat(): string
    {
        return "♻️ <b>Richiama stat per ID</b>\n\n"
          . "<b>Cosa fa</b>: ri-invia il messaggio completo di una stat già eseguita in passato. Utile per condividerla con un commerciale o riguardare i numeri senza rilanciare la query.\n\n"
          . "<b>Esempi</b>:\n"
          . "• <i>mostrami stat 7</i>\n"
          . "• <i>vedi stat 12</i>\n"
          . "• <i>richiamami la 15</i>\n"
          . "• <i>/vedistat 7</i>\n\n"
          . "<b>Dove trovi gli ID</b>: dopo ogni nuova stat vedi <i>«💾 Stat salvata come #7»</i>, oppure usa <i>stat salvate</i> per elencarle.";
    }

    private static function ripeti(): string
    {
        return "🔁 <b>Ripeti ultima spedizione (contesto post-consegna)</b>\n\n"
          . "<b>Cosa fa</b>: subito dopo aver consegnato una lista, il bot ricorda <b>tutto</b> (cliente, prodotto, area, filtri, prezzo). Puoi quindi chiedere cose brevi che ereditano il contesto.\n\n"
          . "<b>Esempi di follow-up immediati</b>:\n"
          . "• <i>altri 100</i> → stessa cosa ma 100 record\n"
          . "• <i>ancora 500 ma in provincia di Bergamo</i> → cambia area\n"
          . "• <i>stessa cosa per Nastasi</i> → cambia cliente\n"
          . "• <i>altri 200 senza filtro stranieri</i> → cambia filtro\n\n"
          . "<b>Come funziona</b>:\n"
          . "Dopo ogni spedizione rimango in stato «post-consegna». Claude riceve il contesto precedente assieme alla tua nuova frase. Se la frase è incompleta (es. solo un numero) eredita tutto il resto; se sposta esplicitamente un campo, quel campo viene sovrascritto.\n\n"
          . "<b>Chiudere il contesto</b>: scrivi <i>«ok»</i>, <i>«grazie»</i>, <i>«basta»</i> o usa <code>/annulla</code>.";
    }

    private static function magazzino(): string
    {
        return "🗄 <b>Magazzino (dedup storico)</b>\n\n"
          . "<b>Cos'è</b>: tabella per cliente nel DB <code>clienti</code> (es. <code>109_Michele_cerullo_CF</code>) che contiene i mobile già consegnati. Serve a non riconsegnare numeri già chiamati.\n\n"
          . "<b>Come viene usato</b>:\n"
          . "• In <b>estrazione</b>: LEFT JOIN con la fonte → escludi chi è già in magazzino\n"
          . "• In <b>statistica</b>: mostra «già consegnati» e «disponibili»\n"
          . "• Dopo l'estrazione: INSERT dei nuovi mobile nel magazzino (in TEST MODE viene saltato)\n\n"
          . "<b>Scelta iniziale</b>: al primo giro il bot trova le tabelle storiche del cliente e chiede A/B/C:\n"
          . "• A = usa la più recente\n"
          . "• B = nessun magazzino\n"
          . "• C = altra tabella\n"
          . "La scelta viene <b>memorizzata</b> in <code>ai_laboratory.cliente_magazzino</code> e riusata in automatico.\n\n"
          . "<b>Gestione</b>:\n"
          . "• <code>/magazzini</code> — elenco mappature salvate\n"
          . "• <code>/magazzino_reset cerullo</code> — dimentica la scelta e richiedi al prossimo giro";
    }

    private static function menu(): string
    {
        return "💬 <b>Menu «cosa vuoi fare?»</b>\n\n"
          . "<b>Cosa fa</b>: al termine di ogni azione (consegna, stat, storico, ecc.) il bot elenca tutte le cose che può fare.\n\n"
          . "<b>Viene mostrato anche quando</b>:\n"
          . "• Chiedi <i>«aiuto»</i> / <i>«cosa sai fare»</i>\n"
          . "• Scrivi qualcosa che non capisco\n"
          . "• Finisci uno storico o una stat\n\n"
          . "<b>Contiene</b>: tutti i comandi in linguaggio naturale + esempi reali che puoi copia-incollare.";
    }

    private static function tutto(): string
    {
        $parts = [
            "📚 <b>Guida completa a tutti i comandi</b>",
            self::estrai(),
            self::stat(),
            self::storico(),
            self::listStats(),
            self::viewStat(),
            self::ripeti(),
            self::magazzino(),
        ];
        return implode("\n\n" . str_repeat('━', 12) . "\n\n", $parts);
    }

    private static function notFound(string $topic): string
    {
        return "🤔 Non ho una spiegazione dedicata per <b>«" . htmlspecialchars($topic) . "»</b>.\n\n"
          . "Argomenti disponibili: <i>estrazione</i>, <i>statistica</i>, <i>storico</i>, <i>stat salvate</i>, <i>richiamo stat</i>, <i>ripeti ultima spedizione</i>, <i>magazzino</i>, <i>menu</i>, <i>esempi business</i>.\n\n"
          . "Esempio: <i>spiegami le stat salvate</i>";
    }

    /** Esempi di richieste BUSINESS che il bot può eseguire */
    private static function businessExamples(): string
    {
        return "💼 <b>ESEMPI BUSINESS / B2B</b>\n\n"
          . "Il sistema usa il <b>master B2B</b> consolidato (5,3M aziende deduplicate) per tutte le richieste business senza POD/PDR.\n"
          . "Sotto, esempi pratici raggruppati per categoria — copiali e adattali alle tue esigenze.\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "🌍 <b>1) PER GEOGRAFIA</b>\n"
          . "<code>5000 aziende in Lombardia</code>\n"
          . "<code>10000 imprese provincia di Milano</code>\n"
          . "<code>3000 aziende Centro Italia (Lazio, Toscana, Umbria, Marche)</code>\n"
          . "<code>tutte le aziende a Roma con mobile</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "🏷 <b>2) PER SETTORE ATECO</b>\n"
          . "<code>imprese ATECO 47 in Sicilia</code>  <i>(commercio dettaglio)</i>\n"
          . "<code>aziende settore alimentari (10) in Lombardia</code>\n"
          . "<code>5000 ristoranti (56) Roma + Milano</code>\n"
          . "<code>professionisti studi tecnici (71) Veneto</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "📞 <b>3) MOBILE vs FISSO</b>\n"
          . "<code>2000 aziende mobile in Piemonte</code>\n"
          . "<code>fissi business Sardegna</code>\n"
          . "<code>10000 numeri fissi azienda Campania</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "✉️ <b>4) CON EMAIL / PEC / SITO</b>\n"
          . "<code>aziende con PEC in Lazio</code>  <i>(per direct mail / certificate)</i>\n"
          . "<code>3000 imprese con email Lombardia</code>  <i>(email marketing)</i>\n"
          . "<code>aziende con sito web Milano</code>\n"
          . "<code>5000 PEC ATECO 62 (informatica) in tutta Italia</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "🔢 <b>5) PER QUANTITÀ E DEDUP</b>\n"
          . "<code>tutti i business in Veneto</code>  <i>(estrazione completa)</i>\n"
          . "<code>1000 aziende Roma con dedup magazzino cerullo</code>  <i>(no doppioni rispetto allo storico)</i>\n"
          . "<code>5000 PIVA Sicilia cold</code>  <i>(no dedup, freschi)</i>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "📊 <b>6) STATISTICHE BUSINESS</b>\n"
          . "<code>stat aziende per provincia in Campania</code>\n"
          . "<code>quanti business mobile abbiamo per ATECO 47</code>\n"
          . "<code>quante aziende con PEC ci sono nel Lazio</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "⚡ <b>7) ENERGIA BUSINESS (con POD/PDR)</b>\n"
          . "<i>Quando servono POD o PDR il sistema NON usa il master B2B (manca quei campi) e attinge dalle fonti energia legacy.</i>\n"
          . "<code>5000 POD business Lombardia</code>\n"
          . "<code>energia business con attivazione aprile 2026 in Sicilia</code>\n"
          . "<code>PDR business gas Milano</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "💡 <b>Tips</b>:\n"
          . "• Combina più filtri liberamente (provincia + ATECO + tipo telefono)\n"
          . "• Indica un cliente per associare la consegna allo storico (es. \"per cerullo\")\n"
          . "• Scrivi <i>«esempi consumer»</i> per esempi di estrazioni privati\n"
          . "• Scrivi <i>«spiegami magazzino»</i> per la dedup\n";
    }

    /** Esempi di richieste CONSUMER / B2C / residenziali */
    private static function consumerExamples(): string
    {
        return "👤 <b>ESEMPI CONSUMER / RESIDENZIALI / B2C</b>\n\n"
          . "Per le richieste residenziali il sistema attinge da fonti come Edicus (POD/PDR luce+gas), LIBERO, SKY (email), DBU (anagrafiche+CF) e arricchisce con il <b>master CF</b> (40,5M numeri).\n"
          . "Sotto, esempi pratici raggruppati per categoria — copiali e adattali.\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "⚡ <b>1) ENERGIA RESIDENZIALE (luce + gas)</b>\n"
          . "<code>3000 numeri energia in Lombardia</code>\n"
          . "<code>5000 contatti energia provincia di Roma</code>\n"
          . "<code>10000 luce e gas Sicilia non stranieri</code>\n"
          . "<code>energia 2000 numeri Sardegna attivazione aprile 2026</code>\n"
          . "<code>4000 PDR gas Lombardia</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "☀️ <b>2) FOTOVOLTAICO</b>\n"
          . "<i>Il sistema esclude automaticamente capoluoghi e cinture (cerca case unifamiliari).</i>\n"
          . "<code>2000 fotovoltaico Veneto</code>\n"
          . "<code>5000 fotovoltaico Sicilia con potenza ≥ 6 kW</code>\n"
          . "<code>3000 pannelli solari Sardegna no stranieri</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "💧 <b>3) DEPURAZIONE / ACQUA</b>\n"
          . "<code>4000 depurazione acqua provincia di Milano</code>\n"
          . "<code>2000 purificatori acqua Toscana</code>\n"
          . "<code>3000 depurazione Campania età 30-60</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "📞 <b>4) TELEFONIA / FIBRA</b>\n"
          . "<code>5000 telefonia Lombardia</code>\n"
          . "<code>3000 numeri telefono Lazio mobile</code>\n"
          . "<code>10000 contatti fibra/internet Sud Italia</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "💰 <b>5) FINANZIARIE / CESSIONE DEL QUINTO</b>\n"
          . "<code>3000 cessione del quinto Veneto età 30-60</code>\n"
          . "<code>5000 finanziarie Lazio non stranieri</code>\n"
          . "<code>2000 prestiti personali Lombardia</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "🏠 <b>6) IMMOBILIARI (privati)</b>\n"
          . "<code>2000 immobiliari Toscana</code>\n"
          . "<code>3000 contatti casa Liguria età 35-55</code>\n"
          . "<code>5000 immobiliari Loano</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "🛒 <b>7) ALIMENTARI / COSMETICA / GENERICHE</b>\n"
          . "<code>5000 alimentari Sicilia</code>\n"
          . "<code>3000 cosmetica Lombardia mobile</code>\n"
          . "<code>10000 generiche Campania età 25-50</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "✉️ <b>8) EMAIL MARKETING (SKY)</b>\n"
          . "<code>10000 email Lazio</code>\n"
          . "<code>20000 newsletter Lombardia</code>\n"
          . "<code>5000 mail Toscana età 30-50</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "🎂 <b>9) PER FASCIA D'ETÀ (da CF)</b>\n"
          . "<i>Età calcolata dalle posizioni 7-8 del codice fiscale.</i>\n"
          . "<code>5000 giovani 18-30 in Campania</code>\n"
          . "<code>3000 over 50 Lombardia</code>\n"
          . "<code>energia 4000 Sicilia età 35-55</code>\n"
          . "<code>2000 senior (oltre 65) Roma</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "📅 <b>10) DATA DI ATTIVAZIONE (energia)</b>\n"
          . "<code>energia 5000 Lombardia attivazione aprile 2026</code>\n"
          . "<code>3000 luce attivati negli ultimi 6 mesi</code>\n"
          . "<code>4000 energia da marzo 2026 a ritroso</code>\n"
          . "<code>2000 energia da gennaio 2024 a marzo 2026</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "📞📱 <b>11) MOBILE / FISSO + NUMERI EXTRA</b>\n"
          . "<code>3000 numeri solo mobile Lombardia</code>\n"
          . "<code>5000 con numeri aggiuntivi (più telefoni per persona)</code>\n"
          . "<code>energia 2000 Veneto solo mobile</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "🌍 <b>12) PER GEOGRAFIA AVANZATA</b>\n"
          . "<code>tutti i numeri di Verona</code>  <i>(comune singolo)</i>\n"
          . "<code>5000 Nord Italia escluso Milano</code>\n"
          . "<code>10000 Sud Italia (Campania, Puglia, Calabria, Sicilia)</code>\n"
          . "<code>2000 numeri CAP 20100, 20121, 20122</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "🚫 <b>13) FILTRI ESCLUSIONI</b>\n"
          . "<code>5000 energia Lombardia non stranieri</code>  <i>(esclude CF stranieri)</i>\n"
          . "<code>3000 depurazione Roma non capoluogo</code>\n"
          . "<code>2000 finanziarie con dedup magazzino cerullo</code>  <i>(no doppioni storico)</i>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "📊 <b>14) STATISTICHE CONSUMER</b>\n"
          . "<code>quanti contatti energia in Campania</code>\n"
          . "<code>stat depurazione Toscana per provincia</code>\n"
          . "<code>quanti numeri abbiamo a Verona</code>\n"
          . "<code>disponibilità fotovoltaico in Sicilia</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "📑 <b>15) ORDINE MULTI-FOGLIO</b>\n"
          . "<i>Un singolo xlsx con più fogli (ognuno per criterio diverso).</i>\n"
          . "<code>8000 energia Lombardia split per provincia (MI 50%, BG 30%, BS 20%)</code>\n"
          . "<code>5000 fotovoltaico: 3000 attivazione 2026 + 2000 attivazione 2025</code>\n\n"

          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "💡 <b>Tips</b>:\n"
          . "• Aggiungi sempre il <b>cliente</b> per associare la consegna allo storico (es. \"per cerullo\")\n"
          . "• Combina liberamente filtri (regione + età + tipo telefono + data attivazione)\n"
          . "• Scrivi <i>«esempi business»</i> per estrazioni B2B\n"
          . "• Scrivi <i>«spiegami magazzino»</i> per la dedup\n";
    }

    /** Esempi generali (consumer + business) */
    private static function esempi(): string
    {
        return "📝 <b>ESEMPI DI RICHIESTE</b>\n\n"
          . "<b>👤 Consumer (privati):</b>\n"
          . "<code>2000 numeri energia in Lombardia</code>\n"
          . "<code>5000 contatti depurazione Sardegna no stranieri</code>\n"
          . "<code>10000 numeri Sud Italia età 30-50</code>\n"
          . "<code>tutti i numeri di Verona</code>\n\n"

          . "<b>💼 Business (aziende):</b>\n"
          . "<code>5000 aziende in Lombardia con PEC</code>\n"
          . "<code>imprese ATECO 47 a Milano</code>\n"
          . "<code>3000 PIVA Centro Italia con mobile</code>\n"
          . "<code>fissi business Sardegna</code>\n\n"

          . "<b>📊 Statistiche:</b>\n"
          . "<code>quanti contatti energia abbiamo per provincia in Campania</code>\n"
          . "<code>stat business ATECO 47 per regione</code>\n"
          . "<code>statistica disponibilità Lazio per cerullo</code>\n\n"

          . "<b>📥 Storico/ripeti:</b>\n"
          . "<code>storico ordini cerullo</code>\n"
          . "<code>ripeti ultima spedizione</code>\n"
          . "<code>stat di ieri</code>\n\n"

          . "Per esempi specifici: <i>«esempi business»</i> · <i>«spiegami stat»</i>";
    }
}
