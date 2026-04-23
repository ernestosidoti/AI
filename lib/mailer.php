<?php
/**
 * AI Lab — Mailer
 * Invio email via SMTP diretto (socket) verso Aruba con supporto allegati.
 * Pattern proven-working su MAMP: smtps.aruba.it:465 SSL.
 */

if (!defined('AILAB')) {
    http_response_code(403);
    exit('Accesso negato');
}

// Credenziali SMTP info@listetelemarketing.eu su Aruba
if (!defined('AI_SMTP_HOST'))     define('AI_SMTP_HOST',     'smtps.aruba.it');
if (!defined('AI_SMTP_PORT'))     define('AI_SMTP_PORT',     465);
if (!defined('AI_SMTP_USER'))     define('AI_SMTP_USER',     'info@listetelemarketing.eu');
if (!defined('AI_SMTP_PASS'))     define('AI_SMTP_PASS',     'Ikeabusiness1!');

/**
 * Invia email con eventuale allegato via SMTP diretto.
 *
 * @param string      $to             Email destinatario
 * @param string      $toName         Nome destinatario
 * @param string      $subject        Oggetto
 * @param string      $htmlBody       Corpo HTML
 * @param string|null $attachmentPath Path assoluto a file da allegare (opzionale)
 * @return array{success:bool, error:?string}
 */
function aiSendMail(string $to, string $toName, string $subject, string $htmlBody, ?string $attachmentPath = null): array
{
    $ctx = stream_context_create([
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);
    $socket = @stream_socket_client(
        'ssl://' . AI_SMTP_HOST . ':' . AI_SMTP_PORT,
        $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $ctx
    );
    if (!$socket) {
        return ['success' => false, 'error' => "Connessione SMTP fallita: $errno $errstr"];
    }

    $read = function() use ($socket): string {
        $out = '';
        while (($line = fgets($socket)) !== false) {
            $out .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $out;
    };
    $write = function(string $cmd) use ($socket): void {
        fwrite($socket, $cmd . "\r\n");
    };
    $expect = function(string $resp, string $code, string $step) {
        if (strpos($resp, $code) !== 0) {
            throw new RuntimeException("SMTP $step atteso $code, ricevuto: " . trim($resp));
        }
    };

    try {
        $expect($read(),                                   '220', 'banner');
        $write('EHLO listetelemarketing.eu');      $expect($read(), '250', 'EHLO');
        $write('AUTH LOGIN');                      $expect($read(), '334', 'AUTH');
        $write(base64_encode(AI_SMTP_USER));       $expect($read(), '334', 'user');
        $write(base64_encode(AI_SMTP_PASS));       $expect($read(), '235', 'pass');
        $write('MAIL FROM:<' . AI_SMTP_USER . '>'); $expect($read(), '250', 'MAIL FROM');
        $write('RCPT TO:<' . $to . '>');           $expect($read(), '250', 'RCPT TO');
        $write('DATA');                            $expect($read(), '354', 'DATA');

        $boundary = '=_boundary_' . bin2hex(random_bytes(8)) . '=_';
        $msgId    = uniqid('', true) . '@listetelemarketing.eu';
        $fromName = 'Listetelemarketing';

        // Headers
        $h  = 'From: ' . $fromName . ' <' . AI_SMTP_USER . ">\r\n";
        $h .= 'To: ' . aiMimeEncode($toName) . ' <' . $to . ">\r\n";
        $h .= 'Reply-To: ' . AI_SMTP_USER . "\r\n";
        $h .= 'Subject: ' . aiMimeEncode($subject) . "\r\n";
        $h .= 'Date: ' . date('r') . "\r\n";
        $h .= 'Message-ID: <' . $msgId . ">\r\n";
        $h .= "MIME-Version: 1.0\r\n";

        if ($attachmentPath && is_file($attachmentPath)) {
            $h .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n\r\n";
            $body  = "This is a multi-part message in MIME format.\r\n\r\n";
            // HTML part
            $body .= '--' . $boundary . "\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
            // Allegato
            $fname = basename($attachmentPath);
            $fdata = file_get_contents($attachmentPath);
            $mime  = aiGuessMime($fname);
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Type: ' . $mime . '; name="' . $fname . '"' . "\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= 'Content-Disposition: attachment; filename="' . $fname . '"' . "\r\n\r\n";
            $body .= chunk_split(base64_encode($fdata)) . "\r\n";
            $body .= '--' . $boundary . "--\r\n";
        } else {
            $h .= "Content-Type: text/html; charset=UTF-8\r\n";
            $h .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body = chunk_split(base64_encode($htmlBody));
        }

        // Dot-stuffing: linee che iniziano con "." vanno protette
        $data = preg_replace('/^\./m', '..', $h . $body);
        fwrite($socket, $data . "\r\n.\r\n");
        $expect($read(), '250', 'DATA end');

        $write('QUIT'); @fgets($socket);
        fclose($socket);
        return ['success' => true, 'error' => null];
    } catch (\Throwable $e) {
        @fclose($socket);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function aiMimeEncode(string $s): string
{
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function aiGuessMime(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($ext) {
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls'  => 'application/vnd.ms-excel',
        'csv'  => 'text/csv',
        'pdf'  => 'application/pdf',
        'zip'  => 'application/zip',
        default => 'application/octet-stream',
    };
}

/**
 * Template HTML branded per consegna liste.
 * Usato da aiSendListDelivery().
 */
function aiBuildDeliveryEmailHtml(array $opts): string
{
    $cliente   = htmlspecialchars($opts['cliente']    ?? '');
    $contatto  = htmlspecialchars($opts['contatto']   ?? '');
    $prodotto  = htmlspecialchars($opts['prodotto']   ?? '');
    $area      = htmlspecialchars($opts['area']       ?? '');
    $records   = (int)($opts['records'] ?? 0);
    $filename  = htmlspecialchars($opts['filename']   ?? '');
    $orderNum  = htmlspecialchars($opts['order_num']  ?? '');
    $noteDedup = htmlspecialchars($opts['note_dedup'] ?? '');
    $extra     = $opts['extra_html'] ?? '';
    $year      = date('Y');
    $dataOggi  = date('d/m/Y');

    return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Consegna Lista</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
<div style="max-width:620px;margin:0 auto;padding:32px 20px;">

  <div style="background:#1e3a8a;background:linear-gradient(135deg,#1e3a8a 0%,#1e40af 100%);border-radius:12px 12px 0 0;padding:32px 28px;text-align:left;">
    <div style="color:#93c5fd;font-size:12px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:6px;">Listetelemarketing</div>
    <h1 style="margin:0;color:#fff;font-size:24px;line-height:1.3;font-weight:700;">Lista pronta per la consegna</h1>
    <div style="color:#cbd5e1;font-size:14px;margin-top:8px;">Ordine del $dataOggi</div>
  </div>

  <div style="background:#fff;padding:28px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">
    <p style="margin:0 0 18px 0;font-size:15px;line-height:1.6;color:#334155;">
      Ciao $contatto,<br>
      la lista richiesta per <strong>$cliente</strong> è pronta ed è allegata a questa email.
    </p>

    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:20px;margin:20px 0;">
      <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <tr><td style="padding:6px 0;color:#64748b;width:40%;">Cliente</td><td style="padding:6px 0;font-weight:600;color:#0f172a;">$cliente</td></tr>
        <tr><td style="padding:6px 0;color:#64748b;">Prodotto</td><td style="padding:6px 0;font-weight:600;color:#0f172a;text-transform:capitalize;">$prodotto</td></tr>
        <tr><td style="padding:6px 0;color:#64748b;">Area geografica</td><td style="padding:6px 0;font-weight:600;color:#0f172a;">$area</td></tr>
        <tr><td style="padding:6px 0;color:#64748b;">Record consegnati</td><td style="padding:6px 0;font-weight:700;color:#059669;font-size:16px;">$records</td></tr>
HTML
    . ($orderNum ? "<tr><td style=\"padding:6px 0;color:#64748b;\">Ordine</td><td style=\"padding:6px 0;font-weight:600;color:#0f172a;\">#$orderNum</td></tr>" : "")
    . <<<HTML

        <tr><td style="padding:6px 0;color:#64748b;vertical-align:top;">File allegato</td><td style="padding:6px 0;font-family:'SF Mono',Menlo,Consolas,monospace;font-size:12px;color:#1e40af;word-break:break-all;">$filename</td></tr>
      </table>
    </div>

HTML
    . ($noteDedup ? "<div style=\"background:#ecfdf5;border-left:3px solid #10b981;padding:12px 16px;border-radius:4px;margin:16px 0;font-size:13px;color:#065f46;line-height:1.5;\"><strong>Dedup attiva</strong> — $noteDedup</div>" : "")
    . $extra
    . <<<HTML

    <p style="margin:20px 0 0 0;font-size:14px;line-height:1.6;color:#475569;">
      Qualsiasi cosa non torni nella lista o nei volumi consegnati, rispondi a questa email e risolviamo subito.
    </p>

    <p style="margin:24px 0 0 0;font-size:14px;color:#475569;">
      Buon lavoro,<br>
      <strong style="color:#0f172a;">Team Listetelemarketing</strong>
    </p>
  </div>

  <div style="background:#0f172a;color:#94a3b8;padding:20px 28px;border-radius:0 0 12px 12px;font-size:12px;text-align:center;line-height:1.6;">
    <div style="color:#cbd5e1;font-weight:600;margin-bottom:4px;">listetelemarketing.eu</div>
    <div>Supporto: <a href="mailto:info@listetelemarketing.eu" style="color:#60a5fa;text-decoration:none;">info@listetelemarketing.eu</a> — WhatsApp: +39 393 336 4377</div>
    <div style="margin-top:8px;color:#64748b;">© $year ICS BESTEAST SRL — Strada Alba Iulia 113, Chișinău</div>
  </div>

</div>
</body>
</html>
HTML;
}

/**
 * Wrapper alto livello: prepara HTML + invia con allegato xlsx.
 * Email CLIENTE-FACING — senza info interne (magazzino, dedup).
 */
function aiSendListDelivery(string $to, string $toName, array $opts, string $attachmentPath): array
{
    $subject = sprintf('📋 Lista %s — %s record (%s)',
        ucfirst($opts['prodotto'] ?? 'estratta'),
        number_format((int)($opts['records'] ?? 0), 0, ',', '.'),
        $opts['area'] ?? ''
    );
    // Forza rimozione info interne dal template
    unset($opts['note_dedup'], $opts['magazzino'], $opts['fonte_db'], $opts['filtri']);
    $html = aiBuildDeliveryEmailHtml($opts + ['filename' => basename($attachmentPath)]);
    return aiSendMail($to, $toName, $subject, $html, $attachmentPath);
}

/**
 * Email INTERNA di report — con tutti i dettagli tecnici (fonte, filtri, magazzino).
 * Per il team, non per il cliente.
 */
function aiBuildInternalReportHtml(array $r): string
{
    $rows = [
        'Cliente'            => ($r['cliente']     ?? '') . (isset($r['cliente_id'])  ? ' <span style="color:#94a3b8;font-weight:400;">(ID '.htmlspecialchars($r['cliente_id']).')</span>' : ''),
        'Contatto'           => $r['contatto']    ?? '',
        'P.IVA'              => $r['piva']        ?? '',
        'Prodotto'           => $r['prodotto']    ?? '',
        'Area'               => $r['area']        ?? '',
        'Fonte DB'           => '<code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12px;">' . htmlspecialchars($r['fonte_db'] ?? '') . '</code>',
        'Filtri'             => $r['filtri']      ?? '',
        'Pool eleggibile'    => isset($r['pool'])    ? number_format((int)$r['pool'],    0, ',', '.') : '',
        'Record estratti'    => isset($r['records']) ? '<strong style="color:#059669;font-size:15px;">' . number_format((int)$r['records'], 0, ',', '.') . '</strong>' : '',
        'Comuni coperti'     => $r['comuni']      ?? '',
        'Magazzino'          => isset($r['magazzino']) ? ('<code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12px;">' . htmlspecialchars($r['magazzino']) . '</code>') : '<span style="color:#dc2626;">nessuno</span>',
        'Dedup'              => $r['dedup']       ?? '',
        'Insert post-delivery' => $r['insert_info'] ?? '',
        'File'               => '<code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:11px;word-break:break-all;">' . htmlspecialchars($r['filename'] ?? '') . '</code>',
        'Invia a'            => isset($r['send_to']) ? ('<strong style="color:#1e40af;">' . htmlspecialchars($r['send_to']) . '</strong>') : '',
    ];

    $tableRows = '';
    foreach ($rows as $label => $val) {
        if ($val === '' || $val === null) continue;
        $tableRows .= "<tr><td style=\"padding:8px 12px;color:#64748b;font-size:13px;vertical-align:top;width:42%;border-bottom:1px solid #f1f5f9;\">$label</td><td style=\"padding:8px 12px;color:#0f172a;font-size:14px;border-bottom:1px solid #f1f5f9;\">$val</td></tr>";
    }

    $ts = date('d/m/Y H:i:s');
    return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><title>Delivery Report</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
<div style="max-width:680px;margin:0 auto;padding:24px 16px;">

  <div style="background:#0f172a;border-radius:10px 10px 0 0;padding:24px 24px;color:#fff;">
    <div style="color:#94a3b8;font-size:11px;font-weight:600;letter-spacing:2px;text-transform:uppercase;">Internal · AI Laboratory</div>
    <h1 style="margin:6px 0 0 0;font-size:22px;line-height:1.3;font-weight:700;">Delivery report</h1>
    <div style="color:#64748b;font-size:13px;margin-top:4px;">$ts</div>
  </div>

  <div style="background:#fff;padding:8px 8px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;border-radius:0 0 10px 10px;">
    <table style="width:100%;border-collapse:collapse;">
      $tableRows
    </table>
  </div>

  <div style="text-align:center;color:#94a3b8;font-size:11px;margin-top:16px;">Email interna — non inoltrare al cliente</div>

</div>
</body>
</html>
HTML;
}

function aiSendInternalReport(string $to, string $toName, array $reportData, string $attachmentPath): array
{
    $subject = sprintf('📊 [INTERNAL] Delivery %s — %s · %s record · %s',
        $reportData['cliente'] ?? '',
        $reportData['prodotto'] ?? '',
        number_format((int)($reportData['records'] ?? 0), 0, ',', '.'),
        $reportData['area'] ?? ''
    );
    $html = aiBuildInternalReportHtml($reportData + ['filename' => basename($attachmentPath)]);
    return aiSendMail($to, $toName, $subject, $html, $attachmentPath);
}
