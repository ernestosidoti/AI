<?php
/**
 * Flow /storico — mostra lo storico ordini + consegne AI di un cliente
 */

if (!defined('AILAB')) { http_response_code(403); exit('Accesso negato'); }

require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/estrai_engine.php';

class FlowStorico
{
    const LIMIT = 15;

    public static function run(int $chatId, array $user, array $intent): void
    {
        if (empty($intent['cliente_hint'])) {
            TG::sendMessage($chatId, "⚠️ Dimmi quale cliente: es. <i>fammi vedere cosa ha acquistato ediwater</i>");
            return;
        }

        // Risolvi cliente con filtri opzionali
        $filters = [];
        if (!empty($intent['cliente_regione']))  $filters['regione']   = $intent['cliente_regione'];
        if (!empty($intent['cliente_zona']))     $filters['zona']      = $intent['cliente_zona'];
        if (!empty($intent['cliente_provincia']))$filters['provincia'] = $intent['cliente_provincia'];
        $candidates = EstraiEngine::findClienti($intent['cliente_hint'], $filters, 5);

        if (!$candidates) {
            TG::sendMessage($chatId, "❌ Nessun cliente trovato per \"" . htmlspecialchars($intent['cliente_hint']) . "\".");
            return;
        }
        if (count($candidates) > 1) {
            $m = "🔎 Più clienti — specifica meglio:\n";
            foreach ($candidates as $c) {
                $nome = $c['ragione_sociale'] ?: ($c['nome'] . ' ' . $c['cognome']);
                $m .= "• <b>" . htmlspecialchars($nome) . "</b>";
                if ($c['partita_iva']) $m .= " · P.IVA " . $c['partita_iva'];
                if ($c['comune']) $m .= " · " . htmlspecialchars($c['comune']);
                $m .= "\n";
            }
            TG::sendMessage($chatId, $m);
            return;
        }
        $cliente = $candidates[0];

        // Carica ordini + deliveries
        $orders = self::fetchOrders((int)$cliente['id']);
        $deliveries = self::fetchDeliveries((int)$cliente['id']);

        // Formatta
        $msg = self::format($cliente, $orders, $deliveries);
        TG::sendMessage($chatId, $msg);
        FlowEstrai::mainMenu($chatId);
    }

    private static function fetchOrders(int $clienteId): array
    {
        $pdo = remoteDb('backoffice');
        $s = $pdo->prepare("
            SELECT o.id, o.data_ora, o.quantita, o.tipo, o.zona, o.stato, o.importo_bonifico, p.nome AS prodotto_nome
            FROM orders o
            LEFT JOIN prodotti p ON p.id = o.prodotto_id
            WHERE o.cliente_id = ?
            ORDER BY o.data_ora DESC, o.id DESC
            LIMIT " . self::LIMIT);
        $s->execute([$clienteId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function fetchDeliveries(int $clienteId): array
    {
        $pdo = remoteDb('ai_laboratory');
        $s = $pdo->prepare("
            SELECT id, sent_at, prodotto, area, contatti_inviati, prezzo_eur, file_name
            FROM deliveries
            WHERE cliente_id = ?
            ORDER BY sent_at DESC
            LIMIT " . self::LIMIT);
        $s->execute([$clienteId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function format(array $cliente, array $orders, array $deliveries): string
    {
        $nome = $cliente['ragione_sociale'] ?: ($cliente['nome'] . ' ' . $cliente['cognome']);
        $msg  = "📋 <b>Storico — " . htmlspecialchars($nome) . "</b>\n";
        $sub = [];
        if ($cliente['partita_iva']) $sub[] = "P.IVA " . $cliente['partita_iva'];
        if ($cliente['comune'])      $sub[] = $cliente['comune'];
        if ($cliente['email'])       $sub[] = $cliente['email'];
        if ($sub) $msg .= "<i>" . htmlspecialchars(implode(' · ', $sub)) . "</i>\n";

        // Aggregazione prodotti
        $totByProd = [];
        $totQty = 0; $totEur = 0;
        foreach ($orders as $o) {
            $p = $o['prodotto_nome'] ?: 'n/d';
            if (!isset($totByProd[$p])) $totByProd[$p] = ['count'=>0,'qty'=>0,'eur'=>0];
            $totByProd[$p]['count']++;
            $totByProd[$p]['qty'] += (int)$o['quantita'];
            $totByProd[$p]['eur'] += (float)$o['importo_bonifico'];
            $totQty += (int)$o['quantita'];
            $totEur += (float)$o['importo_bonifico'];
        }

        if ($orders) {
            $msg .= "\n📦 <b>Ordini commerciali</b> (ultimi " . count($orders) . ")\n";
            $msg .= "  Totale record: " . number_format($totQty, 0, ',', '.') . " · Totale €: " . number_format($totEur, 2, ',', '.') . "\n\n";

            foreach (array_slice($orders, 0, 10) as $o) {
                $d = substr($o['data_ora'] ?? '', 0, 10);
                $prod = $o['prodotto_nome'] ?: 'n/d';
                $q = number_format((int)$o['quantita'], 0, ',', '.');
                $e = number_format((float)$o['importo_bonifico'], 0, ',', '.');
                $zona = $o['zona'] ? ' · ' . substr(htmlspecialchars($o['zona']), 0, 40) : '';
                $stato = $o['stato'] ? ' <i>[' . htmlspecialchars($o['stato']) . ']</i>' : '';
                $msg .= sprintf("  %s · <b>%s</b> · %s rec · €%s%s%s\n",
                    $d, htmlspecialchars($prod), $q, $e, $zona, $stato);
            }

            if (count($totByProd) > 1) {
                $msg .= "\n  📊 <b>Per categoria</b>:\n";
                arsort($totByProd);
                uasort($totByProd, fn($a,$b)=>$b['qty']-$a['qty']);
                foreach ($totByProd as $p => $v) {
                    $msg .= "    • " . htmlspecialchars($p) . " → " . $v['count'] . " ordini · " . number_format($v['qty'], 0, ',', '.') . " rec · €" . number_format($v['eur'], 0, ',', '.') . "\n";
                }
            }
        } else {
            $msg .= "\n📦 Ordini commerciali: <i>nessuno</i>\n";
        }

        if ($deliveries) {
            $msg .= "\n🤖 <b>Consegne AI Lab</b> (ultime " . count($deliveries) . ")\n";
            foreach ($deliveries as $d) {
                $dt = substr($d['sent_at'], 0, 10);
                $msg .= sprintf("  %s · <b>%s</b> · %s rec · €%s · %s\n",
                    $dt, htmlspecialchars($d['prodotto']),
                    number_format((int)$d['contatti_inviati'], 0, ',', '.'),
                    number_format((float)$d['prezzo_eur'], 0, ',', '.'),
                    htmlspecialchars($d['area'] ?? ''));
            }
        } else {
            $msg .= "\n🤖 Consegne AI Lab: <i>nessuna</i>\n";
        }

        return $msg;
    }
}
