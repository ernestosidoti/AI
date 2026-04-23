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
            'estrai'     => self::estrai(),
            'stat'       => self::stat(),
            'storico'    => self::storico(),
            'list_stats' => self::listStats(),
            'view_stat'  => self::viewStat(),
            'ripeti'     => self::ripeti(),
            'magazzino'  => self::magazzino(),
            'menu'       => self::menu(),
            'tutto'      => self::tutto(),
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
          . "Argomenti disponibili: <i>estrazione</i>, <i>statistica</i>, <i>storico</i>, <i>stat salvate</i>, <i>richiamo stat</i>, <i>ripeti ultima spedizione</i>, <i>magazzino</i>, <i>menu</i>.\n\n"
          . "Esempio: <i>spiegami le stat salvate</i>";
    }
}
