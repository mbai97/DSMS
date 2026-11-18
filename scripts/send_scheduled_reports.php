<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/scheduled_reports.php';

$recipientValues = preg_split('/[\s,;]+/', setting($db, 'report_recipients'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$recipients = [];
foreach ($recipientValues as $recipient) {
    $normalized = strtolower(trim($recipient));
    if (filter_var($normalized, FILTER_VALIDATE_EMAIL) && !in_array($normalized, $recipients, true)) {
        $recipients[] = $normalized;
    }
}
$sender = trim(setting($db, 'report_sender'));
$schedule = array_values(array_filter(
    array_map('trim', explode(',', setting($db, 'report_schedule'))),
    static fn(string $period): bool => isset(scheduled_report_period_options()[$period])
));

if (!$schedule) {
    fwrite(STDOUT, "No scheduled reporting periods are enabled.\n");
    exit(0);
}
if (!$recipients || !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Scheduled reports are disabled: configure a valid sender address and at least one recipient in Report Settings.\n");
    exit(1);
}
if (!function_exists('mail')) {
    fwrite(STDERR, "PHP mail() is unavailable; configure an email transport before enabling scheduled reports.\n");
    exit(1);
}

$lockName = 'beckyn-scheduled-report-runner';
$lock = $db->prepare('SELECT GET_LOCK(?, 0)');
$lock->execute([$lockName]);
if ((int)$lock->fetchColumn() !== 1) {
    fwrite(STDOUT, "Another scheduled report run is already in progress.\n");
    exit(0);
}

$periods = scheduled_report_periods_to_send($db, new DateTimeImmutable('today'), $schedule, $recipients);
$failedDeliveries = 0;
$blockedRecipients = [];
$summaryCache = [];
$upsert = $db->prepare(
    "INSERT INTO scheduled_report_runs
        (period_type, period_start, period_end, recipient_email, status, error_message, attempted_at, sent_at)
     VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
     ON DUPLICATE KEY UPDATE period_end = VALUES(period_end), status = VALUES(status),
        error_message = VALUES(error_message), attempted_at = NOW(), sent_at = VALUES(sent_at)"
);

foreach ($periods as $period) {
    $recipientKey = $period['type'] . '|' . $period['recipient'];
    if (isset($blockedRecipients[$recipientKey])) {
        continue;
    }

    $summaryKey = $period['type'] . '|' . $period['start'];
    if (!isset($summaryCache[$summaryKey])) {
        $summaryCache[$summaryKey] = scheduled_report_summary($db, $period['start'], $period['end_exclusive']);
    }
    [$subject, $body] = scheduled_report_message($period, $summaryCache[$summaryKey], $config);
    $headers = [
        'From: ' . $sender,
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: BeckynDS scheduled reports',
    ];

    $error = null;
    $sent = false;
    try {
        $sent = mail($period['recipient'], $subject, $body, implode("\r\n", $headers));
        if (!$sent) {
            $error = 'PHP mail() did not accept the message.';
        }
    } catch (Throwable $exception) {
        $error = mb_substr($exception->getMessage(), 0, 500);
        error_log('Scheduled report email failed: ' . $exception->getMessage());
    }

    $upsert->execute([
        $period['type'],
        $period['start'],
        $period['end'],
        $period['recipient'],
        $sent ? 'sent' : 'failed',
        $error,
        $sent ? date('Y-m-d H:i:s') : null,
    ]);

    if ($sent) {
        fwrite(STDOUT, sprintf("Sent %s report (%s to %s) to %s.\n", $period['label'], $period['start'], $period['end'], $period['recipient']));
    } else {
        $failedDeliveries++;
        $blockedRecipients[$recipientKey] = true;
        fwrite(STDERR, sprintf("Failed %s report (%s to %s) for %s: %s\n", $period['label'], $period['start'], $period['end'], $period['recipient'], $error));
    }
}

$releaseLock = $db->prepare('SELECT RELEASE_LOCK(?)');
$releaseLock->execute([$lockName]);
if ($failedDeliveries > 0) {
    exit(1);
}
fwrite(STDOUT, "Scheduled report run completed.\n");
