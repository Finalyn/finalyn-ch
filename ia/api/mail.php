<?php
/** Envoi d'e-mails finalyn.ia : HTML (DA du site) + repli texte, via SMTP authentifie si configure, sinon mail() natif. */
require_once __DIR__ . '/config_load.php';

/**
 * Envoi e-mail UTF-8 (best effort).
 * $body  : version texte (repli).
 * $ics   : invitation .ics optionnelle (multipart/mixed).
 * $html  : version HTML optionnelle (multipart/alternative).
 * Si SMTP configure, l'expediteur (From) est force a smtp_user (Infomaniak rejette un From different).
 */
function finalyn_send_mail($to, $subject, $body, $from, $replyTo, $ics = null, $html = null) {
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $subjEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $b64text = rtrim(chunk_split(base64_encode($body)));

    // Corps : texte seul, ou alternative texte + HTML
    $hasHtml = ($html !== null && $html !== '');
    $altBnd  = 'alt_' . bin2hex(random_bytes(8));
    if ($hasHtml) {
        $b64html = rtrim(chunk_split(base64_encode($html)));
        $altBody = '--' . $altBnd . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64text . "\r\n"
            . '--' . $altBnd . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64html . "\r\n"
            . '--' . $altBnd . "--\r\n";
    } else {
        $altBody = $b64text;
    }

    if ($ics === null) {
        $ctype   = $hasHtml ? 'multipart/alternative; boundary="' . $altBnd . '"'
                            : "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
        $ctypeHd = $hasHtml ? 'Content-Type: ' . $ctype . "\r\n" : $ctype . "\r\n";
        $content = $altBody;
    } else {
        $bnd = 'mix_' . bin2hex(random_bytes(8));
        $ctypeHd = 'Content-Type: multipart/mixed; boundary="' . $bnd . "\"\r\n";
        $innerHd = $hasHtml ? 'Content-Type: multipart/alternative; boundary="' . $altBnd . "\"\r\n\r\n"
                            : "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $content = '--' . $bnd . "\r\n" . $innerHd . $altBody . "\r\n"
            . '--' . $bnd . "\r\n"
            . "Content-Type: text/calendar; method=REQUEST; charset=UTF-8; name=\"audit.ics\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"audit.ics\"\r\n\r\n"
            . rtrim(chunk_split(base64_encode($ics))) . "\r\n"
            . '--' . $bnd . "--\r\n";
    }

    $cfg = finalyn_config();

    // --- SMTP authentifie (recommande, fiable) ---
    if (!empty($cfg['smtp_host']) && !empty($cfg['smtp_user'])) {
        $envFrom = $cfg['smtp_user']; // l'expediteur DOIT etre la boite authentifiee
        $dom = substr(strrchr($envFrom, '@'), 1) ?: 'finalyn.ch';
        $headers = 'Date: ' . date('r') . "\r\n"
                 . 'From: finalyn.ia <' . $envFrom . '>' . "\r\n"
                 . 'Reply-To: ' . $replyTo . "\r\n"
                 . 'To: ' . $to . "\r\n"
                 . 'Subject: ' . $subjEnc . "\r\n"
                 . 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $dom . ">\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . $ctypeHd;
        return finalyn_smtp_send($cfg, $envFrom, $to, $headers . "\r\n" . $content);
    }

    // --- Repli : fonction mail() native ---
    $headers = 'From: finalyn.ia <' . $from . '>' . "\r\n"
             . 'Reply-To: ' . $replyTo . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . $ctypeHd;
    return @mail($to, $subjEnc, $content, $headers);
}

/** Client SMTP minimal : SSL (465) ou STARTTLS (587), AUTH LOGIN. Debug si FINALYN_MAIL_DEBUG. */
function finalyn_smtp_send($cfg, $from, $to, $message) {
    $host = $cfg['smtp_host'];
    $port = (int)($cfg['smtp_port'] ?? 465);
    $user = $cfg['smtp_user'];
    $pass = (string)($cfg['smtp_pass'] ?? '');
    $secure = $cfg['smtp_secure'] ?? 'ssl';
    $prefix = ($secure === 'ssl') ? 'ssl://' : '';
    $debug = defined('FINALYN_MAIL_DEBUG') && FINALYN_MAIL_DEBUG;

    $fp = @stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 20);
    if (!$fp) {
        $msg = 'connexion impossible a ' . $host . ':' . $port . ' (' . $errstr . ')';
        if ($debug) fwrite(STDOUT, "!! $msg\n");
        error_log('finalyn smtp: ' . $msg);
        return false;
    }
    stream_set_timeout($fp, 20);
    $get = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 600)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $io = function ($c, $secret = false) use ($fp, $get, $debug) {
        if ($debug) fwrite(STDOUT, '>> ' . ($secret ? '(identifiants masques)' : $c) . "\n");
        fwrite($fp, $c . "\r\n");
        $r = $get();
        if ($debug) fwrite(STDOUT, '<< ' . trim($r) . "\n");
        return $r;
    };

    $g = $get();
    if ($debug) fwrite(STDOUT, '<< ' . trim($g) . "\n");
    $io('EHLO finalyn.ch');
    if ($secure === 'tls') {
        $io('STARTTLS');
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('finalyn smtp: STARTTLS echoue'); fclose($fp); return false;
        }
        $io('EHLO finalyn.ch');
    }
    $io('AUTH LOGIN');
    $io(base64_encode($user), true);
    $r = $io(base64_encode($pass), true);
    if (strpos($r, '235') === false) { error_log('finalyn smtp auth: ' . trim($r)); $io('QUIT'); fclose($fp); return false; }
    $io('MAIL FROM:<' . $from . '>');
    $io('RCPT TO:<' . $to . '>');
    $r = $io('DATA');
    if (strpos($r, '354') === false) { error_log('finalyn smtp data: ' . trim($r)); $io('QUIT'); fclose($fp); return false; }
    $message = preg_replace('/^\./m', '..', $message); // dot-stuffing
    fwrite($fp, $message . "\r\n.\r\n");
    $r = $get();
    if ($debug) fwrite(STDOUT, '<< ' . trim($r) . "\n");
    $io('QUIT');
    fclose($fp);
    return strpos($r, '250') !== false;
}

/**
 * Gabarit e-mail HTML dans la DA finalyn (carte warm-white, accent violet, boutons pilules).
 * $paragraphsHtml : contenu HTML deja echappe.
 * $buttons : [ ['label'=>..,'url'=>..,'primary'=>bool], ... ]
 */
function finalyn_email_html($heading, $paragraphsHtml, $buttons = []) {
    $btns = '';
    foreach ($buttons as $b) {
        if (empty($b['url']) || empty($b['label'])) continue;
        $style = !empty($b['primary'])
            ? 'background:#0E0E0E;color:#ffffff;border:1px solid #0E0E0E;'
            : 'background:#ffffff;color:#0E0E0E;border:1px solid #DCD6CB;';
        $btns .= '<a href="' . htmlspecialchars($b['url']) . '" style="display:inline-block;' . $style
              . 'text-decoration:none;font-weight:600;font-size:14px;padding:12px 24px;border-radius:999px;margin:6px 10px 6px 0;">'
              . htmlspecialchars($b['label']) . '</a>';
    }
    $btnRow = $btns !== '' ? '<tr><td style="padding:18px 0 4px;">' . $btns . '</td></tr>' : '';

    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1.0"></head>'
        . '<body style="margin:0;padding:0;background:#FAF8F4;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FAF8F4;padding:30px 14px;'
        . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;'
        . 'border:1px solid #DCD6CB;border-radius:18px;overflow:hidden;">'
        . '<tr><td style="padding:26px 32px 2px;font-size:20px;font-weight:700;color:#0E0E0E;letter-spacing:-0.4px;">'
        . 'finalyn<span style="color:#7C4DEF;">.ia</span></td></tr>'
        . '<tr><td style="padding:8px 32px 0;"><h1 style="margin:6px 0 14px;font-size:21px;line-height:1.3;'
        . 'color:#0E0E0E;letter-spacing:-0.3px;font-weight:600;">' . $heading . '</h1></td></tr>'
        . '<tr><td style="padding:0 32px;font-size:15px;line-height:1.65;color:#3a3a3a;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr><td style="font-size:15px;'
        . 'line-height:1.65;color:#3a3a3a;">' . $paragraphsHtml . '</td></tr>' . $btnRow . '</table></td></tr>'
        . '<tr><td style="padding:24px 32px 30px;"><div style="border-top:1px solid #ECE7DE;margin-bottom:14px;"></div>'
        . '<div style="font-size:13px;line-height:1.6;color:#9a958c;">L\'équipe finalyn.ia<br>'
        . '<a href="mailto:contact@finalyn.com" style="color:#7C4DEF;text-decoration:none;">contact@finalyn.com</a> · +41 79 639 36 84<br>'
        . '<a href="https://finalyn.ch/ia" style="color:#9a958c;text-decoration:none;">finalyn.ch/ia</a></div></td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * E-mail dedie selon l'action sur une reservation (HTML + texte).
 * $type : 'cancel_admin' (on annule), 'client_cancel' (le client annule), 'done' (audit fait).
 * $b : ligne bookings (tableau associatif).
 */
function finalyn_booking_notify($type, $b) {
    if (!is_array($b) || empty($b['email']) || !filter_var($b['email'], FILTER_VALIDATE_EMAIL)) return false;
    $cfg   = finalyn_config();
    $from  = $cfg['from_email'] ?? 'noreply@finalyn.com';
    $team  = $cfg['notify_email'] ?? '';
    $reply = ($team !== '' && filter_var($team, FILTER_VALIDATE_EMAIL)) ? $team : $from;

    $months = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $dts = strtotime(($b['slot_date'] ?? '') . ' ' . ($b['slot_time'] ?? ''));
    $when = $dts ? ((int)date('j', $dts) . ' ' . $months[(int)date('n', $dts)] . ' ' . date('Y', $dts) . ' à ' . ($b['slot_time'] ?? '')) : '';
    $first = $b['firstname'] ?? '';
    $rebook = 'https://finalyn.ch/ia/#audit';
    $sign  = "\n\nÀ bientôt,\nL'équipe finalyn.ia\ncontact@finalyn.com · +41 79 639 36 84";

    if ($type === 'cancel_admin') {
        $subj = 'Votre rendez-vous a été reporté · finalyn.ia';
        $text = 'Bonjour ' . $first . ",\n\nNous devons malheureusement annuler votre rendez-vous prévu le " . $when
            . ".\n\nToutes nos excuses pour ce contretemps. Reprenez le créneau qui vous arrange ici, on se refait ça avec plaisir : " . $rebook . $sign;
        $heading = 'Votre rendez-vous a été reporté';
        $par = '<p style="margin:0 0 14px;">Bonjour ' . htmlspecialchars($first) . ',</p>'
            . '<p style="margin:0 0 14px;">Nous devons malheureusement annuler votre rendez-vous prévu le <strong>' . htmlspecialchars($when) . '</strong>.</p>'
            . '<p style="margin:0 0 6px;">Toutes nos excuses pour ce contretemps. On se refait ça avec plaisir quand vous voulez :</p>';
        $btns = [['label' => 'Reprendre un rendez-vous', 'url' => $rebook, 'primary' => true]];
    } elseif ($type === 'client_cancel') {
        $subj = 'Votre rendez-vous est annulé · finalyn.ia';
        $text = 'Bonjour ' . $first . ",\n\nVotre rendez-vous du " . $when . " est bien annulé.\n\n"
            . "Pas de souci : dès que vous le souhaitez, reprenez le créneau qui vous arrange ici : " . $rebook . "\n\nOn espère vous revoir très vite !" . $sign;
        $heading = 'Votre rendez-vous est annulé';
        $par = '<p style="margin:0 0 14px;">Bonjour ' . htmlspecialchars($first) . ',</p>'
            . '<p style="margin:0 0 14px;">Votre rendez-vous du <strong>' . htmlspecialchars($when) . '</strong> est bien annulé.</p>'
            . '<p style="margin:0 0 6px;">Pas de souci : dès que vous le souhaitez, reprenez le créneau qui vous arrange. On espère vous revoir très vite !</p>';
        $btns = [['label' => 'Reprendre un rendez-vous', 'url' => $rebook, 'primary' => true]];
    } elseif ($type === 'done') {
        $subj = 'Merci pour votre audit · finalyn.ia';
        $text = 'Bonjour ' . $first . ",\n\nMerci pour cet échange, c'était un plaisir.\n\n"
            . "Si une question vous vient ou si vous souhaitez avancer, écrivez-nous simplement, on reste à votre disposition.\n\n"
            . "Et pour approfondir un autre sujet, reprenez un créneau quand vous voulez : " . $rebook . $sign;
        $heading = 'Merci pour votre audit';
        $par = '<p style="margin:0 0 14px;">Bonjour ' . htmlspecialchars($first) . ',</p>'
            . '<p style="margin:0 0 14px;">Merci pour cet échange, c\'était un plaisir.</p>'
            . '<p style="margin:0 0 6px;">Si une question vous vient ou si vous souhaitez avancer, écrivez-nous simplement, on reste à votre disposition. Et pour approfondir un autre sujet :</p>';
        $btns = [['label' => 'Reprendre un rendez-vous', 'url' => $rebook, 'primary' => true]];
    } else {
        return false;
    }
    $htmlMail = finalyn_email_html($heading, $par, $btns);
    return finalyn_send_mail($b['email'], $subj, $text, $from, $reply, null, $htmlMail);
}

/** Genere l'invitation .ics (METHOD:REQUEST) pour un rendez-vous. Compatible Apple, Outlook, Google, etc. */
function finalyn_build_ics($firstname, $lastname, $email, $date, $time, $duration, $organizer) {
    try {
        $tz = new DateTimeZone('Europe/Zurich'); $utc = new DateTimeZone('UTC');
        $start = new DateTime($date . ' ' . $time . ':00', $tz);
        $end = (clone $start)->modify('+' . max(15, (int)$duration) . ' minutes');
        $start->setTimezone($utc); $end->setTimezone($utc);
        $startStr = $start->format('Ymd\THis\Z');
        $endStr   = $end->format('Ymd\THis\Z');
        $stampStr = (new DateTime('now', $utc))->format('Ymd\THis\Z');
    } catch (Throwable $e) { return ''; }
    $esc = function ($s) { return str_replace([',', ';', "\n"], ['\\,', '\\;', '\\n'], $s); };
    $sum = 'Rendez-vous finalyn.ia (visio) - ' . $firstname . ' ' . $lastname;
    $desc = "Rendez-vous de 30 min en visioconference avec finalyn.ia. Le lien de connexion vous sera envoye avant le rendez-vous.";
    $uid = 'audit-' . $date . '-' . str_replace(':', '', $time) . '-' . substr(md5($email), 0, 8) . '@finalyn.com';
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//finalyn.ia//Audit//FR\r\nCALSCALE:GREGORIAN\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\n"
        . "UID:" . $uid . "\r\n"
        . "DTSTAMP:" . $stampStr . "\r\n"
        . "DTSTART:" . $startStr . "\r\n"
        . "DTEND:" . $endStr . "\r\n"
        . "SUMMARY:" . $esc($sum) . "\r\n"
        . "DESCRIPTION:" . $esc($desc) . "\r\n"
        . "LOCATION:Visioconference\r\n"
        . "ORGANIZER;CN=finalyn.ia:mailto:" . $organizer . "\r\n"
        . "ATTENDEE;CN=" . $esc($firstname . ' ' . $lastname) . ";RSVP=TRUE:mailto:" . $email . "\r\n"
        . "STATUS:CONFIRMED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
}
