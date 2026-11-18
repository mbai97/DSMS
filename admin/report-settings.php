<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles('admin');

$periodOptions = [
    'day' => 'Daily',
    'week' => 'Weekly',
    'month' => 'Monthly',
    'quarter' => 'Quarterly',
    'half_year' => 'Semi-annual',
    'year' => 'Annual',
];
$errors = [];
$success = '';
$recipientInput = setting($db, 'report_recipients');
$senderInput = setting($db, 'report_sender');
$selectedPeriods = array_values(array_filter(
    array_map('trim', explode(',', setting($db, 'report_schedule', 'day,week,month,quarter,half_year,year'))),
    static fn(string $period): bool => isset($periodOptions[$period])
));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $recipientInput = is_string($_POST['report_recipients'] ?? null) ? trim($_POST['report_recipients']) : '';
    $senderInput = is_string($_POST['report_sender'] ?? null) ? strtolower(trim($_POST['report_sender'])) : '';
    $requestedPeriods = is_array($_POST['periods'] ?? null) ? $_POST['periods'] : [];
    $selectedPeriods = [];
    foreach ($requestedPeriods as $period) {
        if (is_string($period) && isset($periodOptions[$period]) && !in_array($period, $selectedPeriods, true)) {
            $selectedPeriods[] = $period;
        }
    }

    $recipientValues = preg_split('/[\s,;]+/', $recipientInput, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $recipients = [];
    foreach ($recipientValues as $recipient) {
        $recipient = strtolower(trim($recipient));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter valid email addresses, separated by commas.';
            break;
        }
        if (!in_array($recipient, $recipients, true)) {
            $recipients[] = $recipient;
        }
    }
    if (count($recipients) > 20) {
        $errors[] = 'You can configure up to 20 report recipients.';
    }
    if ($senderInput !== '' && !filter_var($senderInput, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid sender email address.';
    }
    if ($selectedPeriods && !$recipients) {
        $errors[] = 'Add at least one recipient or disable all scheduled periods.';
    }
    if ($selectedPeriods && $senderInput === '') {
        $errors[] = 'Set the sender email address before enabling scheduled reports.';
    }
    if (!in_array($action, ['save', 'test_email'], true)) {
        $errors[] = 'Select a valid report settings action.';
    }

    if (!$errors) {
        update_setting($db, 'report_recipients', implode(',', $recipients));
        update_setting($db, 'report_sender', $senderInput);
        update_setting($db, 'report_schedule', implode(',', $selectedPeriods));
        $success = 'Report settings saved.';

        if ($action === 'test_email') {
            if (!$recipients || $senderInput === '') {
                $errors[] = 'Set a sender and at least one recipient before sending a test email.';
                $success = '';
            } elseif (!function_exists('mail')) {
                $errors[] = 'PHP mail() is unavailable on this server. Configure an email transport before testing delivery.';
                $success = '';
            } else {
                $subject = 'BeckynDS report email test';
                $body = "This test confirms that the server accepted a report email for delivery.\n";
                $headers = "From: {$senderInput}\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: BeckynDS scheduled reports";
                $failed = [];
                foreach ($recipients as $recipient) {
                    try {
                        if (!mail($recipient, $subject, $body, $headers)) {
                            $failed[] = $recipient;
                        }
                    } catch (Throwable $exception) {
                        error_log('Report email test failed: ' . $exception->getMessage());
                        $failed[] = $recipient;
                    }
                }
                if ($failed) {
                    $errors[] = 'Settings were saved, but the server did not accept the test email for: ' . implode(', ', $failed) . '.';
                    $success = '';
                } else {
                    $success = 'Settings saved and the test email was accepted by the server for all recipients.';
                }
            }
        }
    }
}

$stmt = $db->query(
    'SELECT period_type, period_start, period_end, recipient_email, status, error_message, attempted_at, sent_at
     FROM scheduled_report_runs ORDER BY attempted_at DESC, id DESC LIMIT 20'
);
$recentRuns = $stmt->fetchAll();
$title = 'Report Settings';
require __DIR__ . '/../includes/header.php';
?>
<div class="mb-4">
    <div class="portal-eyebrow">Automation</div>
    <h1 class="fw-bold mb-1">Scheduled Report Settings</h1>
    <p class="text-muted mb-0">Choose report periods and who receives summaries automatically.</p>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

<div class="card p-4 mb-4">
    <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label" for="report-recipients">Recipients</label><textarea class="form-control" id="report-recipients" name="report_recipients" rows="3" placeholder="manager@example.com, director@example.com"><?= e($recipientInput) ?></textarea><div class="form-text">Separate addresses with commas, semicolons, or spaces. Each recipient receives a separate message.</div></div>
        <div class="mb-4"><label class="form-label" for="report-sender">Sender email</label><input class="form-control" id="report-sender" type="email" name="report_sender" maxlength="190" value="<?= e($senderInput) ?>"><div class="form-text">Use an address permitted by your hosting provider’s mail service.</div></div>
        <fieldset class="mb-4">
            <legend class="form-label">Email completed periods</legend>
            <div class="row g-2"><?php foreach ($periodOptions as $key => $label): ?><div class="col-sm-6 col-lg-4"><label class="form-check"><input class="form-check-input" type="checkbox" name="periods[]" value="<?= e($key) ?>" <?= in_array($key, $selectedPeriods, true) ? 'checked' : '' ?>><span class="form-check-label"><?= e($label) ?></span></label></div><?php endforeach; ?></div>
            <div class="form-text">The scheduled task sends only closed periods: daily yesterday, weekly last week, and so on. It catches up missed periods and avoids resending successful deliveries.</div>
        </fieldset>
        <button class="btn btn-primary" name="action" value="save">Save settings</button>
        <button class="btn btn-outline-primary" name="action" value="test_email">Save and send test email</button>
    </form>
</div>

<div class="card p-4 mb-4">
    <h5 class="fw-bold">Enable the daily scheduled task</h5>
    <p class="mb-2">Configure your hosting scheduler to run this command once each day. The runner sends all selected periods that are due.</p>
    <code>php /path/to/your/project/scripts/send_scheduled_reports.php</code>
    <p class="small text-muted mt-3 mb-0">The server must have PHP CLI, database access, and a working PHP mail transport. A successful test means the server accepted the message; it does not guarantee inbox delivery.</p>
</div>

<div class="card p-4">
    <h5 class="fw-bold mb-3">Recent scheduled deliveries</h5>
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Period</th><th>Date range</th><th>Recipient</th><th>Status</th><th>Attempted</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($recentRuns as $run): ?>
            <tr>
                <td><?= e($periodOptions[$run['period_type']] ?? $run['period_type']) ?></td>
                <td><?= e($run['period_start']) ?> to <?= e($run['period_end']) ?></td>
                <td><?= e($run['recipient_email']) ?></td>
                <td><span class="badge text-bg-<?= $run['status'] === 'sent' ? 'success' : 'danger' ?>"><?= e(ucfirst($run['status'])) ?></span></td>
                <td><?= e($run['attempted_at']) ?></td>
                <td><?= e($run['error_message'] ?? ($run['sent_at'] ?? '—')) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$recentRuns): ?><tr><td colspan="6" class="text-center text-muted py-4">No scheduled reports have been attempted yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
