<?php
/**
 * ClientParser — Estrae dati cliente da testo libero
 * Riconosce P.IVA, CF, email, telefono, indirizzo, CAP, provincia, comune, ragione sociale.
 */

if (!defined('AILAB')) {
    http_response_code(403);
    exit('Accesso negato');
}

class ClientParser
{
    public static function parse(string $text): array
    {
        $result = [
            'ragione_sociale' => null, 'nome' => null, 'cognome' => null,
            'partita_iva' => null, 'codice_fiscale' => null,
            'email' => null, 'numero_cellulare' => null,
            'indirizzo' => null, 'civico' => null,
            'cap' => null, 'comune' => null, 'provincia' => null,
        ];

        $text = trim($text);
        if ($text === '') return $result;

        // Email
        if (preg_match('/\b([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/', $text, $m)) {
            $result['email'] = strtolower($m[1]);
        }

        // P.IVA (11 cifre, preceduta o meno da "p.iva"/"piva"/"vat")
        if (preg_match('/\b(?:p\.?\s*iva|partita\s*iva|vat)[\s:]*(\d{11})\b/i', $text, $m)) {
            $result['partita_iva'] = $m[1];
        } elseif (preg_match('/\b(\d{11})\b/', $text, $m)) {
            $result['partita_iva'] = $m[1];
        }

        // Codice Fiscale (16 alfanum, preceduto o meno da "c.f."/"cf"/"codice fiscale")
        if (preg_match('/\b(?:c\.?\s*f\.?|cod(?:ice)?\s*fisc)[\s:]*([A-Z0-9]{16})\b/i', $text, $m)) {
            $result['codice_fiscale'] = strtoupper($m[1]);
        } elseif (preg_match('/\b([A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z])\b/i', $text, $m)) {
            $result['codice_fiscale'] = strtoupper($m[1]);
        }

        // Mobile italiano
        $txtNorm = preg_replace('/[\s\-\(\)]/', '', $text);
        if (preg_match('/(?:\+39|0039)?(3\d{9})/', $txtNorm, $m)) {
            $result['numero_cellulare'] = $m[1];
        }

        // CAP (5 cifre, generalmente prima/dopo il comune)
        if (preg_match('/\b(\d{5})\b/', $text, $m)) {
            // Evita di matchare le cifre della P.IVA
            if ($m[1] !== substr($result['partita_iva'] ?? '', 0, 5)) {
                $result['cap'] = $m[1];
            }
        }

        // Provincia (2 lettere maiuscole isolate)
        if (preg_match('/\b([A-Z]{2})\b(?![A-Z])/', $text, $m)) {
            $allowed = ['AG','AL','AN','AO','AR','AP','AT','AV','BA','BT','BL','BN','BG','BI','BO','BZ','BS','BR','CA',
                'CL','CB','CE','CT','CZ','CH','CO','CS','CR','KR','CN','EN','FM','FE','FI','FG','FC','FR','GE','GO','GR',
                'IM','IS','AQ','SP','LT','LE','LC','LI','LO','LU','MC','MN','MS','MT','ME','MI','MO','MB','NA','NO','NU',
                'OR','PD','PA','PR','PV','PG','PU','PE','PC','PI','PT','PN','PZ','PO','RG','RA','RC','RE','RI','RN','RM',
                'RO','SA','SS','SV','SI','SR','SO','SU','TA','TE','TR','TO','TP','TN','TV','TS','UD','VA','VE','VB','VC','VR','VV','VI','VT'];
            if (in_array($m[1], $allowed, true)) {
                $result['provincia'] = $m[1];
            }
        }

        // Indirizzo e civico: "Via|Viale|Piazza|Corso|Largo|Vicolo NAME NUMERO"
        if (preg_match('/\b(via|viale|piazza|corso|largo|vicolo|str(?:ada)?|p\.zza)\.?\s+([A-Za-zÀ-ÿ\'\s\.]+?)(?:[,\s]+(\d+[A-Za-z]?(?:\/\d+[A-Za-z]?)?))?[,\s]/iu', $text . ' ', $m)) {
            $indirizzo = trim($m[1]) . ' ' . trim($m[2]);
            $indirizzo = preg_replace('/\s+/', ' ', $indirizzo);
            $result['indirizzo'] = ucwords(strtolower(trim($indirizzo)));
            if (!empty($m[3])) $result['civico'] = trim($m[3]);
        }

        // Comune: cerca dopo il CAP o prima della provincia
        if ($result['cap']) {
            if (preg_match('/\b' . preg_quote($result['cap']) . '\b[\s,]+([A-Za-zÀ-ÿ\'\s]+?)(?:[\s,]+[A-Z]{2}\b|$)/iu', $text, $m)) {
                $result['comune'] = trim(preg_replace('/\s+/', ' ', $m[1]));
            } elseif (preg_match('/([A-Za-zÀ-ÿ\'\s]+?)[\s,]+\b' . preg_quote($result['cap']) . '\b/iu', $text, $m)) {
                $result['comune'] = trim(preg_replace('/\s+/', ' ', $m[1]));
            }
        }

        // Ragione sociale / Nome cognome: euristica
        // Prima riga non vuota che non contiene email/piva/cf/cap viene considerata "nome"
        $lines = array_map('trim', explode("\n", $text));
        foreach ($lines as $line) {
            if ($line === '') continue;
            if (!preg_match('/@/', $line)
                && !preg_match('/\b\d{11}\b/', $line)
                && !preg_match('/\b[A-Z0-9]{16}\b/i', $line)
                && !preg_match('/\b\d{5}\b/', $line)
                && !preg_match('/(?:via|viale|piazza|corso|largo|vicolo|strada|p\.zza)/i', $line)
                && mb_strlen($line) >= 2 && mb_strlen($line) <= 100)
            {
                // Se sembra "Nome Cognome" separali, altrimenti usa come ragione sociale
                $words = preg_split('/\s+/', trim($line));
                if (count($words) === 2 && preg_match('/^[A-ZÀ-Ü]/u', $words[0]) && preg_match('/^[A-ZÀ-Ü]/u', $words[1])) {
                    $result['nome'] = ucfirst(strtolower($words[0]));
                    $result['cognome'] = ucfirst(strtolower($words[1]));
                    $result['ragione_sociale'] = $line;
                } else {
                    $result['ragione_sociale'] = $line;
                }
                break;
            }
        }

        // Se cognome non trovato ma abbiamo ragione sociale con "Nome Cognome"
        if (!$result['cognome'] && $result['ragione_sociale']) {
            $words = preg_split('/\s+/', trim($result['ragione_sociale']));
            if (count($words) === 2) {
                $result['nome'] = ucfirst(strtolower($words[0]));
                $result['cognome'] = ucfirst(strtolower($words[1]));
            }
        }

        return $result;
    }

    /**
     * Verifica se esiste già un cliente con questa P.IVA o CF
     * Ritorna l'eventuale cliente esistente con agent_name
     */
    public static function findDuplicate(PDO $backDb, ?string $piva, ?string $cf): ?array
    {
        $conditions = [];
        $params = [];
        if ($piva) { $conditions[] = "c.partita_iva = ?"; $params[] = $piva; }
        if ($cf) { $conditions[] = "c.codice_fiscale = ?"; $params[] = $cf; }
        if (empty($conditions)) return null;

        $sql = "SELECT c.*, u.name AS agent_name FROM clientes c
                LEFT JOIN users u ON c.user_id = u.id
                WHERE " . implode(' OR ', $conditions) . " LIMIT 1";
        $stmt = $backDb->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
