<?php

declare(strict_types=1);

function scheduled_report_period_options(): array
{
    return [
        'day' => 'Daily',
        'week' => 'Weekly',
        'month' => 'Monthly',
        'quarter' => 'Quarterly',
        'half_year' => 'Semi-annual',
        'year' => 'Annual',
    ];
}

function scheduled_report_latest_closed_start(DateTimeImmutable $now, string $period): DateTimeImmutable
{
    $today = $now->setTime(0, 0);
    if ($period === 'day') {
        return $today->modify('-1 day');
    }
    if ($period === 'week') {
        return $today->modify('monday this week')->modify('-1 week');
    }
    if ($period === 'month') {
        return $today->modify('first day of this month')->modify('-1 month');
    }
    if ($period === 'quarter') {
        $month = (int)(floor(((int)$today->format('n') - 1) / 3) * 3 + 1);
        return $today->setDate((int)$today->format('Y'), $month, 1)->modify('-3 months');
    }
    if ($period === 'half_year') {
        $month = (int)$today->format('n') <= 6 ? 1 : 7;
        return $today->setDate((int)$today->format('Y'), $month, 1)->modify('-6 months');
    }
    if ($period === 'year') {
        return $today->setDate((int)$today->format('Y'), 1, 1)->modify('-1 year');
    }
    throw new InvalidArgumentException('Unsupported scheduled report period.');
}

function scheduled_report_next_start(DateTimeImmutable $start, string $period): DateTimeImmutable
{
    $intervals = [
        'day' => '+1 day',
        'week' => '+1 week',
        'month' => '+1 month',
        'quarter' => '+3 months',
        'half_year' => '+6 months',
        'year' => '+1 year',
    ];
    if (!isset($intervals[$period])) {
        throw new InvalidArgumentException('Unsupported scheduled report period.');
    }
    return $start->modify($intervals[$period]);
}

function scheduled_report_periods_to_send(
    PDO $db,
    DateTimeImmutable $now,
    array $schedule,
    array $recipients
): array {
    $periodOptions = scheduled_report_period_options();
    $periods = [];
    foreach ($schedule as $period) {
        if (!isset($periodOptions[$period])) {
            continue;
        }
        $targetStart = scheduled_report_latest_closed_start($now, $period);
        foreach ($recipients as $recipient) {
            $stmt = $db->prepare(
                'SELECT MAX(period_start) FROM scheduled_report_runs
                 WHERE period_type = ? AND recipient_email = ? AND status = \'sent\''
            );
            $stmt->execute([$period, $recipient]);
            $lastSent = $stmt->fetchColumn();

            if ($lastSent === false || $lastSent === null) {
                $stmt = $db->prepare(
                    'SELECT MIN(period_start) FROM scheduled_report_runs
                     WHERE period_type = ? AND recipient_email = ?'
                );
                $stmt->execute([$period, $recipient]);
                $firstAttempt = $stmt->fetchColumn();
                $start = $firstAttempt
                    ? new DateTimeImmutable((string)$firstAttempt)
                    : $targetStart;
            } else {
                $start = scheduled_report_next_start(new DateTimeImmutable((string)$lastSent), $period);
            }

            for (; $start <= $targetStart; $start = scheduled_report_next_start($start, $period)) {
                $endExclusive = scheduled_report_next_start($start, $period);
                $periods[] = [
                    'type' => $period,
                    'label' => $periodOptions[$period],
                    'start' => $start->format('Y-m-d'),
                    'end' => $endExclusive->modify('-1 day')->format('Y-m-d'),
                    'end_exclusive' => $endExclusive->format('Y-m-d'),
                    'recipient' => $recipient,
                ];
            }
        }
    }
    return $periods;
}

function scheduled_report_summary(PDO $db, string $startDate, string $endExclusive): array
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS registrations, COALESCE(SUM(total_fee), 0) AS fees_billed
         FROM students WHERE enrollment_date >= ? AND enrollment_date < ?'
    );
    $stmt->execute([$startDate, $endExclusive]);
    $registrations = $stmt->fetch();

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS enquiries,
                COALESCE(SUM(source = 'public'), 0) AS public_enquiries,
                COALESCE(SUM(source = 'staff'), 0) AS staff_enquiries
         FROM enquiries WHERE created_at >= ? AND created_at < ?"
    );
    $stmt->execute([$startDate, $endExclusive]);
    $enquiries = $stmt->fetch();

    $stmt = $db->prepare(
        'SELECT COUNT(*) AS transactions, COUNT(DISTINCT p.student_id) AS students_paid,
                COALESCE(SUM(p.amount), 0) AS collected,
                COALESCE(SUM(CASE WHEN s.enrollment_date >= ? THEN p.amount ELSE 0 END), 0) AS new_enrollment_collections,
                COALESCE(SUM(CASE WHEN s.enrollment_date < ? THEN p.amount ELSE 0 END), 0) AS topups
         FROM student_payments p
         JOIN students s ON s.id = p.student_id
         WHERE p.paid_at >= ? AND p.paid_at < ?'
    );
    $stmt->execute([$startDate, $startDate, $startDate, $endExclusive]);
    $payments = $stmt->fetch();

    $stmt = $db->prepare(
        'SELECT COUNT(*) AS students_to_date, COALESCE(SUM(s.total_fee), 0) AS billed,
                COALESCE(SUM(GREATEST(s.total_fee - COALESCE(p.paid, 0), 0)), 0) AS balance
         FROM students s
         LEFT JOIN (
             SELECT student_id, SUM(amount) AS paid
             FROM student_payments WHERE paid_at < ? GROUP BY student_id
         ) p ON p.student_id = s.id
         WHERE s.enrollment_date < ?'
    );
    $stmt->execute([$endExclusive, $endExclusive]);
    $balances = $stmt->fetch();

    return [
        'registrations' => $registrations,
        'enquiries' => $enquiries,
        'payments' => $payments,
        'balances' => $balances,
    ];
}

function scheduled_report_message(
    array $period,
    array $summary,
    array $config
): array {
    $label = $period['label'];
    $start = $period['start'];
    $end = $period['end'];
    $link = app_url(
        $config,
        'admin/reports.php?' . http_build_query([
            'period' => $period['type'],
            'date' => $start,
        ])
    );
    $registrations = $summary['registrations'];
    $enquiries = $summary['enquiries'];
    $payments = $summary['payments'];
    $balances = $summary['balances'];
    $subject = sprintf('BeckynDS %s Report: %s to %s', $label, $start, $end);
    $body = implode("\n", [
        'BeckynDS ' . $label . ' Report',
        'Period: ' . $start . ' to ' . $end,
        '',
        'ENROLLMENTS',
        'New registrations: ' . (int)$registrations['registrations'],
        'Fees billed to new registrations: KES ' . number_format((float)$registrations['fees_billed'], 2),
        '',
        'ENQUIRIES',
        'Total: ' . (int)$enquiries['enquiries'],
        'Website submissions: ' . (int)$enquiries['public_enquiries'],
        'Staff-recorded: ' . (int)$enquiries['staff_enquiries'],
        '',
        'COLLECTIONS',
        'Total collected: KES ' . number_format((float)$payments['collected'], 2),
        'From new enrollments: KES ' . number_format((float)$payments['new_enrollment_collections'], 2),
        'Top-ups from earlier enrollments: KES ' . number_format((float)$payments['topups'], 2),
        'Transactions: ' . (int)$payments['transactions'],
        'Students who paid: ' . (int)$payments['students_paid'],
        '',
        'PERIOD-END FEE POSITION',
        'Students enrolled by period end: ' . (int)$balances['students_to_date'],
        'Fees billed using current student fee totals: KES ' . number_format((float)$balances['billed'], 2),
        'Outstanding balance: KES ' . number_format((float)$balances['balance'], 2),
        '',
        'View the filtered report after signing in: ' . $link,
        '',
        'Balances use current fee totals and payments recorded through the period end.',
    ]);
    return [$subject, $body];
}
