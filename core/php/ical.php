<?php
/* Export iCalendar d'un élève : cours (VEVENT), devoirs (VEVENT journée
 * entière, ou VTODO avec &todo=1) et vacances de l'établissement (VEVENT
 * journée entière, &vacances=0 pour les retirer).
 *
 * Abonnement : /plugins/pronote/core/php/ical.php?apikey=<clé du plugin>&id=<élève>
 *
 * Sécurité : la clé API du plugin (pas celle du core) est exigée avant toute
 * lecture ; l'équipement doit être un élève Pronote ; rien de ce qui est
 * écrit ne vient d'une entrée utilisateur non échappée (RFC 5545).
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

if (!jeedom::apiAccess(init('apikey'), 'pronote')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Accès refusé';
    exit;
}

$eqLogic = eqLogic::byId((int)init('id'));
if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== 'pronote') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Élève introuvable';
    exit;
}

$todo = (init('todo', 0) == 1);
$withHolidays = (init('vacances', 1) != 0);

$data = $eqLogic->getData();
$name = trim((string)$eqLogic->getConfiguration('student_name', '')) ?: $eqLogic->getName();
$tz = date_default_timezone_get();

function ical_escape($s) {
    return str_replace(array("\\", ";", ",", "\r\n", "\n", "\r"), array("\\\\", "\;", "\\,", "\\n", "\\n", "\\n"), (string)$s);
}

/** Pliage RFC 5545 : 75 octets max par ligne, sans couper un caractère UTF-8. */
function ical_fold($line) {
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line;
}

function ical_line($key, $value) {
    return ical_fold($key . ':' . ical_escape($value)) . "\r\n";
}

function ical_dt($date, $hhmm) {
    $ts = strtotime($date . ' ' . $hhmm . ':00');
    return $ts ? date('Ymd\THis', $ts) : '';
}

$lines = "BEGIN:VCALENDAR\r\n"
       . "VERSION:2.0\r\n"
       . "PRODID:-//Jeedom//Plugin Pronote//FR\r\n"
       . "CALSCALE:GREGORIAN\r\n"
       . "METHOD:PUBLISH\r\n"
       . ical_line('X-WR-CALNAME', 'Pronote — ' . $name)
       . ical_line('X-WR-TIMEZONE', $tz)
       . "REFRESH-INTERVAL;VALUE=DURATION:PT6H\r\n"
       . "X-PUBLISHED-TTL:PT6H\r\n";

$stamp = date('Ymd\THis\Z', isset($data['_updated']) ? (int)$data['_updated'] : time());
$uidBase = 'pronote-' . (int)$eqLogic->getId() . '@jeedom';

foreach ((isset($data['_lessons']) && is_array($data['_lessons'])) ? $data['_lessons'] : array() as $i => $l) {
    if (empty($l['date']) || empty($l['start'])) {
        continue;
    }
    $start = ical_dt($l['date'], $l['start']);
    $end = !empty($l['end']) ? ical_dt($l['date'], $l['end']) : '';
    if ($start === '') {
        continue;
    }
    $summary = (string)($l['subject'] ?? 'Cours');
    if (!empty($l['cancelled'])) {
        $summary = '[Annulé] ' . $summary;
    }
    $desc = array();
    if (!empty($l['teacher'])) { $desc[] = $l['teacher']; }
    if (!empty($l['status'])) { $desc[] = $l['status']; }
    $lines .= "BEGIN:VEVENT\r\n"
            . ical_line('UID', 'lesson-' . $l['date'] . '-' . str_replace(':', '', $l['start']) . '-' . md5($summary) . '-' . $uidBase)
            . 'DTSTAMP:' . $stamp . "\r\n"
            . 'DTSTART;TZID=' . $tz . ':' . $start . "\r\n"
            . ($end !== '' ? 'DTEND;TZID=' . $tz . ':' . $end . "\r\n" : '')
            . ical_line('SUMMARY', $summary)
            . (!empty($l['room']) ? ical_line('LOCATION', $l['room']) : '')
            . (count($desc) ? ical_line('DESCRIPTION', implode(' — ', $desc)) : '')
            . (!empty($l['cancelled']) ? "STATUS:CANCELLED\r\nTRANSP:TRANSPARENT\r\n" : "STATUS:CONFIRMED\r\n")
            . ical_line('CATEGORIES', 'Cours')
            . "END:VEVENT\r\n";
}

foreach ((isset($data['_homework']) && is_array($data['_homework'])) ? $data['_homework'] : array() as $i => $h) {
    if (empty($h['date'])) {
        continue;
    }
    $day = date('Ymd', strtotime($h['date']));
    $summary = 'Devoir : ' . (string)($h['subject'] ?? '');
    $uid = 'homework-' . $h['date'] . '-' . md5($summary . (string)($h['description'] ?? '')) . '-' . $uidBase;
    if ($todo) {
        $lines .= "BEGIN:VTODO\r\n"
                . ical_line('UID', $uid)
                . 'DTSTAMP:' . $stamp . "\r\n"
                . 'DUE;VALUE=DATE:' . $day . "\r\n"
                . ical_line('SUMMARY', $summary)
                . ical_line('DESCRIPTION', (string)($h['description'] ?? ''))
                . (!empty($h['done']) ? "STATUS:COMPLETED\r\nPERCENT-COMPLETE:100\r\n" : "STATUS:NEEDS-ACTION\r\n")
                . "END:VTODO\r\n";
    } else {
        $lines .= "BEGIN:VEVENT\r\n"
                . ical_line('UID', $uid)
                . 'DTSTAMP:' . $stamp . "\r\n"
                . 'DTSTART;VALUE=DATE:' . $day . "\r\n"
                . 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($h['date'] . ' +1 day')) . "\r\n"
                . ical_line('SUMMARY', (!empty($h['done']) ? '✓ ' : '') . $summary)
                . ical_line('DESCRIPTION', (string)($h['description'] ?? ''))
                . "TRANSP:TRANSPARENT\r\n"
                . ical_line('CATEGORIES', 'Devoirs')
                . "END:VEVENT\r\n";
    }
}

if ($withHolidays) {
    foreach ((isset($data['_holidays']) && is_array($data['_holidays'])) ? $data['_holidays'] : array() as $h) {
        if (empty($h['start']) || empty($h['end'])) {
            continue;
        }
        $lines .= "BEGIN:VEVENT\r\n"
                . ical_line('UID', 'holiday-' . $h['start'] . '-' . md5((string)($h['name'] ?? '')) . '-' . $uidBase)
                . 'DTSTAMP:' . $stamp . "\r\n"
                . 'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($h['start'])) . "\r\n"
                . 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($h['end'] . ' +1 day')) . "\r\n"
                . ical_line('SUMMARY', (string)($h['name'] ?? 'Vacances'))
                . "TRANSP:TRANSPARENT\r\n"
                . ical_line('CATEGORIES', 'Vacances')
                . "END:VEVENT\r\n";
    }
}

$lines .= "END:VCALENDAR\r\n";

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="pronote-' . (int)$eqLogic->getId() . '.ics"');
header('Cache-Control: private, max-age=900');
header('X-Content-Type-Options: nosniff');
echo $lines;
