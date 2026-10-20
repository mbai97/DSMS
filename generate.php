<?php

declare(strict_types=1);

// This file lives in the project root (next to includes/, vendor/, storage/).
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

if (!class_exists(\Dompdf\Dompdf::class)) {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
}

use Dompdf\Dompdf;
use Dompdf\Options;

/* ------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------- */

$fail = function (string $stage, Throwable $e): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Certificate generation failed at: ' . $stage . "\n" . $e->getMessage();
    exit;
};

/** Uppercase only the first letter, leave the rest untouched. */
function cap_first(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return $s;
    }
    return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
}

/** Uppercase the first letter of every word, leave the rest untouched. */
function cap_words(string $s): string
{
    return (string)preg_replace_callback(
        '/(^|[\s\-\'(])(\p{Ll})/u',
        static fn(array $m): string => $m[1] . mb_strtoupper($m[2], 'UTF-8'),
        trim($s)
    );
}

/** First non-empty value among several possible column names. */
function pick(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
            return trim((string)$row[$k]);
        }
    }
    return $default;
}

function fmt_date(string $value): string
{
    if ($value === '') {
        return '-';
    }
    $ts = strtotime($value);
    return $ts ? date('d M Y', $ts) : $value;
}

/* ------------------------------------------------------------------
 * Load certificate
 * ---------------------------------------------------------------- */

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Missing certificate id.');
}

try {
    $stmt = $db->prepare('SELECT * FROM certificates WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $cert = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $fail('database read', $e);
}

if (!$cert) {
    http_response_code(404);
    exit('Certificate not found.');
}

/* Optional course lookup (only used if the certificate has a course_id). */
$course = [];
if (!empty($cert['course_id'])) {
    try {
        $c = $db->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
        $c->execute([(int)$cert['course_id']]);
        $course = $c->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $course = [];
    }
}

/* ------------------------------------------------------------------
 * Field values (with capitalization applied to user input)
 * ---------------------------------------------------------------- */

$certificateNumber = pick($cert, ['certificate_number', 'certificate_no', 'reference', 'number'], 'CERT-' . $id);
$safeNumber = preg_replace('/[^A-Za-z0-9_\-]/', '_', $certificateNumber);

$holderName = cap_words(pick($cert, ['holder_name', 'student_name', 'learner_name', 'full_name', 'name'], 'Unnamed Holder'));
$learnerId = pick($cert, ['learner_id', 'student_id', 'admission_no', 'admission_number']);
$courseName = cap_words(pick($cert, ['course_name', 'course_title', 'course'], pick($course, ['name', 'title'], 'Course')));
$certificateType = cap_words(pick($cert, ['certificate_type', 'type'], pick($course, ['certificate_type'], 'Certificate of Completion')));

$issueDate = fmt_date(pick($cert, ['issue_date', 'issued_at', 'created_at']));
$completionDate = fmt_date(pick($cert, ['completion_date', 'completed_at', 'end_date']));
$validityRaw = pick($cert, ['valid_until', 'validity_date', 'expiry_date', 'expires_at']);
$validity = $validityRaw !== '' ? fmt_date($validityRaw) : 'No expiry';

$durationRaw = pick($cert, ['training_duration', 'duration'], pick($course, ['duration']));
$durationText = '';
if ($durationRaw !== '') {
    if (is_numeric($durationRaw)) {
        $n = (float)$durationRaw;
        $durationText = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') . ($n == 1.0 ? ' day' : ' days');
    } else {
        $durationText = $durationRaw;
    }
}

/* Programme areas: JSON array, or one per line / semicolon / comma-less text. */
$topicsRaw = pick($cert, ['course_topics', 'topics', 'programme_areas', 'program_areas'], pick($course, ['topics', 'programme_areas']));
$topics = [];
if ($topicsRaw !== '') {
    $decoded = json_decode($topicsRaw, true);
    $list = is_array($decoded) ? $decoded : preg_split('/[\r\n;]+/', $topicsRaw);
    foreach ($list as $t) {
        $t = trim((string)$t);
        $t = trim((string)preg_replace('/^[\x{2022}\x{2713}\-\*\s]+/u', '', $t));
        if ($t !== '') {
            $topics[] = cap_first($t);
        }
    }
}

/* ------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------- */

$brandColor = setting($db, 'brand_color', '#12394f');
$accentColor = setting($db, 'accent_color', '#a31f4b');
$goldColor = setting($db, 'gold_color', '#c49b52');

$issuerName = trim((string)($config['issuer_name'] ?? ''));
if ($issuerName === '') {
    $issuerName = setting($db, 'school_name', 'Beckyn Driving & Mechanical School');
}
$tagline = setting($db, 'tagline', 'Training • Safety • Excellence');

$directorName = cap_words(pick($cert, ['director_name'], setting($db, 'director_name', 'Mr Adams Mbai')));
$directorTitle = setting($db, 'director_title', 'School Director');
$hodName = cap_words(setting($db, 'hod_name', 'Madam Mary Mutua'));
$hodTitle = setting($db, 'hod_title', 'Head of Department');

/* Optional signature images stored in storage/signatures. */
$signatureImg = static function (string $file) use ($config): string {
    if ($file === '') {
        return '';
    }
    $path = __DIR__ . '/storage/signatures/' . basename($file);
    if (!is_file($path)) {
        return '';
    }
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        default => 'image/png',
    };
    return '<img src="data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path))
        . '" style="max-width:40mm; max-height:12mm;" alt="Signature">';
};
$directorSigImg = $signatureImg(setting($db, 'signature_file', ''));
$hodSigImg = $signatureImg(setting($db, 'hod_signature_file', ''));

/* ------------------------------------------------------------------
 * QR code
 * ---------------------------------------------------------------- */

// Same URL as admin/view-certificate.php: the public route is c/<verification_token>.
$verifyToken = pick($cert, ['verification_token']);
$verifyUrl = $verifyToken !== ''
    ? app_url($config, 'c/' . $verifyToken)
    : app_url($config, '?number=' . rawurlencode($certificateNumber));
$qrHost = (string)(parse_url($verifyUrl, PHP_URL_HOST) ?: '');

$qrDir = storage_path('qrcodes');
if (!is_dir($qrDir)) {
    @mkdir($qrDir, 0775, true);
}
$qrFile = $qrDir . '/' . $safeNumber . '.png';
$qrBinary = '';

try {
    if (class_exists(\chillerlan\QRCode\QRCode::class)) {
        $opts = new \chillerlan\QRCode\QROptions([
            'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
            'imageBase64' => false,
            'scale' => 8,
        ]);
        $qrBinary = (string)(new \chillerlan\QRCode\QRCode($opts))->render($verifyUrl);
    } elseif (class_exists(\Endroid\QrCode\Builder\Builder::class)) {
        $builder = new \Endroid\QrCode\Builder\Builder(data: $verifyUrl, size: 300, margin: 0);
        $qrBinary = $builder->build()->getString();
    }
} catch (Throwable $e) {
    $qrBinary = '';
}

if ($qrBinary !== '') {
    @file_put_contents($qrFile, $qrBinary);
} elseif (is_file($qrFile)) {
    $qrBinary = (string)file_get_contents($qrFile);
}
$qrData = $qrBinary !== '' ? base64_encode($qrBinary) : '';

/* ------------------------------------------------------------------
 * HTML blocks
 * ---------------------------------------------------------------- */

$learnerHtml = $learnerId !== ''
    ? '<div class="learner-id">Learner ID: ' . e($learnerId) . '</div>'
    : '';

$topicsSection = '';
if ($topics) {
    $half = (int)ceil(count($topics) / 2);
    $cols = [array_slice($topics, 0, $half), array_slice($topics, $half)];
    $topicsSection = '<div class="topics-title">Programme Areas</div><table class="topics"><tr>';
    foreach ($cols as $col) {
        $topicsSection .= '<td>';
        foreach ($col as $t) {
            $topicsSection .= '<div class="topic"><span>&#10003;</span> ' . e($t) . '</div>';
        }
        $topicsSection .= '</td>';
    }
    $topicsSection .= '</tr></table>';
}

$detailCells = [
    ['Certificate No.', $certificateNumber],
    ['Issue Date', $issueDate],
    ['Completion Date', $completionDate],
    ['Validity', $validity],
];
if ($durationText !== '') {
    $detailCells[] = ['Training Duration', $durationText];
}
$detailsHtml = '<table class="details"><tr>';
foreach ($detailCells as $d) {
    $detailsHtml .= '<td><div class="detail-label">' . e($d[0]) . '</div><div class="detail-value">' . e($d[1]) . '</div></td>';
}
$detailsHtml .= '</tr></table>';

$qrBlock = $qrData !== ''
    ? '<img class="qr-image" src="data:image/png;base64,' . $qrData . '" alt="QR code">'
    : '<div class="qr-image"></div>';

/* ------------------------------------------------------------------
 * Template (A4 landscape = 297mm x 210mm; everything absolutely
 * positioned with explicit widths so nothing runs past the right edge)
 * ---------------------------------------------------------------- */

$template = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { size: A4 landscape; margin: 0; }

html, body { margin: 0; padding: 0; }

body {
    font-family: DejaVu Sans, sans-serif;
    color: #1d2d4d;
}

.page {
    position: relative;
    width: 296mm;
    height: 208mm;
    overflow: hidden;
    background: #ffffff;
}

.frame {
    position: absolute;
    left: 4mm;
    top: 4mm;
    width: 286mm;
    height: 200mm;
    border: 1px solid {{GOLD}};
}

.bar-main {
    position: absolute;
    left: 4mm;
    top: 4mm;
    width: 231mm;
    height: 2.5mm;
    background: {{BRAND}};
}

.bar-gold {
    position: absolute;
    left: 235mm;
    top: 4mm;
    width: 55mm;
    height: 2.5mm;
    background: {{GOLD}};
}

/* ---------- header ---------- */
.school {
    position: absolute;
    left: 14mm;
    top: 16mm;
    width: 180mm;
}

.school-name {
    margin: 0;
    color: {{BRAND}};
    font-family: DejaVu Serif, serif;
    font-size: 19pt;
    font-weight: 700;
    line-height: 1.15;
}

.tagline {
    margin-top: 3px;
    color: #6f7d8c;
    font-size: 7pt;
    letter-spacing: 1.2px;
    text-transform: uppercase;
}

.header-right {
    position: absolute;
    right: 14mm;
    top: 17mm;
    width: 78mm;
    text-align: right;
    color: #6f7d8c;
    font-size: 7pt;
    line-height: 1.5;
    word-wrap: break-word;
}

.header-right strong {
    display: block;
    color: {{BRAND}};
    font-size: 7.5pt;
    letter-spacing: .6px;
    text-transform: uppercase;
}

.header-rule {
    position: absolute;
    left: 14mm;
    top: 34mm;
    width: 268mm;
    border-top: 1px solid #d9dfe3;
}

/* ---------- body ---------- */
.content {
    position: absolute;
    left: 20mm;
    top: 40mm;
    width: 256mm;
    text-align: center;
}

.cert-heading {
    color: {{BRAND}};
    font-family: DejaVu Serif, serif;
    font-size: 10pt;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
}

.cert-type {
    margin-top: 5px;
    color: {{ACCENT}};
    font-family: DejaVu Serif, serif;
    font-size: 24pt;
    font-weight: 700;
    line-height: 1.1;
    text-transform: uppercase;
    word-wrap: break-word;
}

.rule {
    width: 28mm;
    margin: 7px auto 0;
    border-top: 2px solid {{GOLD}};
}

.awarded {
    margin-top: 9px;
    color: #5d6c7a;
    font-family: DejaVu Serif, serif;
    font-size: 9.5pt;
    font-style: italic;
}

.holder {
    margin-top: 5px;
    color: #12253a;
    font-family: DejaVu Serif, serif;
    font-size: 26pt;
    font-weight: 700;
    line-height: 1.1;
    word-wrap: break-word;
}

.learner-id {
    margin-top: 5px;
    color: #5d6c7a;
    font-size: 8pt;
}

.award-copy {
    margin-top: 9px;
    color: #526779;
    font-size: 9pt;
}

.course {
    margin-top: 5px;
    color: {{BRAND}};
    font-family: DejaVu Serif, serif;
    font-size: 17pt;
    font-weight: 700;
    line-height: 1.15;
    word-wrap: break-word;
}

.topics-title {
    margin-top: 10px;
    color: {{BRAND}};
    font-size: 7.5pt;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
}

.topics {
    width: 190mm;
    margin: 4px auto 0;
    border-collapse: collapse;
    table-layout: fixed;
}

.topics td {
    width: 50%;
    padding: 0 4px;
    vertical-align: top;
    text-align: left;
}

.topic {
    padding: 1.5px 0;
    color: #435a70;
    font-size: 8pt;
    line-height: 1.35;
}

.topic span {
    color: {{ACCENT}};
    font-weight: 700;
}

.details {
    width: 238mm;
    margin: 10px auto 0;
    border-top: 1px solid #dde2e6;
    border-bottom: 1px solid #dde2e6;
    border-collapse: collapse;
    table-layout: fixed;
}

.details td {
    padding: 7px 4px 6px;
    text-align: center;
    vertical-align: top;
}

.detail-label {
    color: #71818b;
    font-size: 6.5pt;
    font-weight: 700;
    letter-spacing: .8px;
    text-transform: uppercase;
}

.detail-value {
    margin-top: 3px;
    color: #12253a;
    font-size: 8.5pt;
    font-weight: 700;
    word-wrap: break-word;
}

/* ---------- footer ---------- */
.sign {
    position: absolute;
    bottom: 17mm;
    width: 72mm;
    text-align: left;
}

.sign-1 { left: 14mm; }
.sign-2 { left: 96mm; }

.sign-img { height: 13mm; }

.sign-line { border-top: 1px solid #5d7283; }

.sign-name {
    margin-top: 3px;
    color: {{ACCENT}};
    font-size: 9pt;
    font-weight: 700;
}

.sign-title {
    margin-top: 1px;
    color: #6f7d8c;
    font-size: 7.5pt;
}

.reference {
    position: absolute;
    left: 14mm;
    bottom: 9mm;
    width: 150mm;
    color: #6f7d8c;
    font-size: 6.5pt;
    font-weight: 700;
    letter-spacing: .3px;
    text-transform: uppercase;
}

.qr-box {
    position: absolute;
    right: 14mm;
    bottom: 11mm;
    width: 44mm;
    padding: 2.5mm 2mm 2mm;
    border: 1px solid #d9dfe3;
    background: #ffffff;
    text-align: center;
}

.qr-title {
    color: {{BRAND}};
    font-size: 6pt;
    font-weight: 700;
    letter-spacing: .6px;
    text-transform: uppercase;
}

.qr-image {
    display: block;
    width: 30mm;
    height: 30mm;
    margin: 2mm auto;
}

.qr-host {
    color: #7d8d9b;
    font-size: 5.5pt;
    word-wrap: break-word;
}
</style>
</head>
<body>
<div class="page">
    <div class="frame"></div>
    <div class="bar-main"></div>
    <div class="bar-gold"></div>

    <div class="school">
        <div class="school-name">{{ISSUER}}</div>
        <div class="tagline">{{TAGLINE}}</div>
    </div>

    <div class="header-right">
        <strong>Certificate Record</strong>
        Issued by the named institution.<br>
        Verify online using the QR code.
    </div>

    <div class="header-rule"></div>

    <div class="content">
        <div class="cert-heading">Certificate of Completion</div>
        <div class="cert-type">{{TYPE}}</div>
        <div class="rule"></div>

        <div class="awarded">This certificate is proudly awarded to</div>
        <div class="holder">{{HOLDER}}</div>
        {{LEARNER}}

        <div class="award-copy">in recognition of the successful completion of</div>
        <div class="course">{{COURSE}}</div>

        {{TOPICS}}
        {{DETAILS}}
    </div>

    <div class="sign sign-1">
        <div class="sign-img">{{DIRECTOR_SIG}}</div>
        <div class="sign-line"></div>
        <div class="sign-name">{{DIRECTOR_NAME}}</div>
        <div class="sign-title">{{DIRECTOR_TITLE}}</div>
    </div>

    <div class="sign sign-2">
        <div class="sign-img">{{HOD_SIG}}</div>
        <div class="sign-line"></div>
        <div class="sign-name">{{HOD_NAME}}</div>
        <div class="sign-title">{{HOD_TITLE}}</div>
    </div>

    <div class="reference">Certificate reference: {{REFERENCE}}</div>

    <div class="qr-box">
        <div class="qr-title">Scan to verify</div>
        {{QRBLOCK}}
        <div class="qr-title">Scan to verify validity</div>
        <div class="qr-host">{{QRHOST}}</div>
    </div>
</div>
</body>
</html>
HTML;

$html = strtr($template, [
    '{{BRAND}}' => e($brandColor),
    '{{ACCENT}}' => e($accentColor),
    '{{GOLD}}' => e($goldColor),
    '{{ISSUER}}' => e($issuerName),
    '{{TAGLINE}}' => e($tagline),
    '{{TYPE}}' => e(mb_strtoupper($certificateType, 'UTF-8')),
    '{{HOLDER}}' => e($holderName),
    '{{LEARNER}}' => $learnerHtml,
    '{{COURSE}}' => e($courseName),
    '{{TOPICS}}' => $topicsSection,
    '{{DETAILS}}' => $detailsHtml,
    '{{DIRECTOR_SIG}}' => $directorSigImg,
    '{{DIRECTOR_NAME}}' => e($directorName),
    '{{DIRECTOR_TITLE}}' => e($directorTitle),
    '{{HOD_SIG}}' => $hodSigImg,
    '{{HOD_NAME}}' => e($hodName),
    '{{HOD_TITLE}}' => e($hodTitle),
    '{{REFERENCE}}' => e($certificateNumber),
    '{{QRBLOCK}}' => $qrBlock,
    '{{QRHOST}}' => e($qrHost),
]);

/* ------------------------------------------------------------------
 * Render PDF
 * ---------------------------------------------------------------- */

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);

try {
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
} catch (Throwable $e) {
    $fail('PDF rendering', $e);
}

$pdfOutput = $dompdf->output();

if (!is_string($pdfOutput) || strlen($pdfOutput) < 100) {
    $fail('PDF output', new RuntimeException('Dompdf returned an empty or invalid PDF.'));
}

$pdfDirectory = storage_path('certificates');
$pdfPath = $pdfDirectory . '/' . $safeNumber . '.pdf';

if (!is_dir($pdfDirectory) || !is_writable($pdfDirectory)) {
    $fail('PDF storage', new RuntimeException('storage/certificates does not exist or is not writable.'));
}

if (file_put_contents($pdfPath, $pdfOutput, LOCK_EX) === false) {
    $fail('PDF save', new RuntimeException('Could not save the generated PDF.'));
}

$relativePdf = 'storage/certificates/' . $safeNumber . '.pdf';
$relativeQr = 'storage/qrcodes/' . $safeNumber . '.png';

try {
    $update = $db->prepare('UPDATE certificates SET pdf_path = ?, qr_path = ? WHERE id = ?');
    $update->execute([$relativePdf, $relativeQr, $id]);
} catch (Throwable $e) {
    $fail('database update', $e);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $safeNumber . '.pdf"');
header('Content-Length: ' . strlen($pdfOutput));
header('X-Content-Type-Options: nosniff');

echo $pdfOutput;

exit;