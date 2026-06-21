<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$start = date('Y-m-01 00:00:00');
$end = date('Y-m-d 23:59:59', strtotime('+6 months'));

$items = [];
for ($m = 0; $m < 6; $m++) {
    $ts = strtotime("+{$m} months");
    $year = (int) date('Y', $ts);
    $month = (int) date('n', $ts);
    foreach (dashboard_calendar_items($pdo, $user, $year, $month) as $dayItems) {
        foreach ($dayItems as $item) {
            $items[] = $item;
        }
    }
}

header('Content-Type: text/calendar; charset=utf-8');
$appSlug = preg_replace('/[^A-Za-z0-9]+/', '-', config()['app_name'] ?? 'LMS') ?: 'LMS';
header('Content-Disposition: attachment; filename="' . strtolower($appSlug) . '-calendar.ics"');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo 'PRODID:-//' . str_replace(['\\', ';', ','], '', config()['app_name'] ?? 'LMS') . '//Calendar//EN' . "\r\n";
echo "CALSCALE:GREGORIAN\r\n";

foreach ($items as $item) {
    if (empty($item['due_at'])) {
        continue;
    }
    $uid = ($item['item_kind'] ?? 'assignment') . '-' . $item['id'] . '@open-lms';
    $dt = gmdate('Ymd\THis\Z', strtotime($item['due_at']));
    $summary = str_replace(["\r", "\n", ',', ';'], ' ', $item['title']);
    $desc = ($item['code'] ?? '') . ' ' . ucfirst($item['item_kind'] ?? 'assignment');
    echo "BEGIN:VEVENT\r\n";
    echo "UID:{$uid}\r\n";
    echo "DTSTAMP:{$dt}\r\n";
    echo "DTSTART:{$dt}\r\n";
    echo "SUMMARY:" . $summary . "\r\n";
    echo "DESCRIPTION:" . str_replace(["\r", "\n"], ' ', $desc) . "\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";
exit;