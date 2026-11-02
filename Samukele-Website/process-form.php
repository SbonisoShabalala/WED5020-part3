<?php
/** Part 3 POE form processing: validation, sanitisation, and mail delivery. */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function reply(int $code, string $message, ?string $reference = null): never {
    http_response_code($code);
    echo json_encode(['ok' => $code === 200, 'message' => $message, 'reference' => $reference]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, 'Only POST requests are accepted.');
if (!empty($_POST['website'])) reply(400, 'Invalid submission.');
$kind = $_POST['form_type'] ?? '';
if (!is_string($kind) || !in_array($kind, ['contact', 'enquiry'], true)) reply(400, 'Invalid form type.');
$required = $kind === 'enquiry' ? ['name', 'email', 'origin', 'destination', 'date', 'load', 'service'] : ['name', 'email', 'message', 'topic'];
$allowed = array_merge(['company', 'name', 'email', 'phone', 'origin', 'destination', 'date', 'load', 'service', 'message', 'topic'], []);
$data = [];
foreach ($allowed as $field) {
    $raw = $_POST[$field] ?? '';
    if (!is_string($raw)) reply(422, 'Invalid field value: ' . $field);
    $data[$field] = trim(str_replace(["\r", "\0"], '', $raw));
    if (strlen($data[$field]) > ($field === 'load' || $field === 'message' ? 500 : 120)) reply(422, 'Text is too long in ' . $field . '.');
}
foreach ($required as $field) if ($data[$field] === '') reply(422, 'Please complete ' . $field . '.');
if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $data['email'])) reply(422, 'Please enter a valid email address.');
if (($_POST['consent'] ?? '') !== 'yes') reply(422, 'Please confirm consent to be contacted.');
if ($kind === 'enquiry') {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $data['date']);
    if (!$date || $date->format('Y-m-d') !== $data['date']) reply(422, 'Please enter a valid transport date.');
    if (!in_array($data['service'], ['Abnormal load escort','Route planning','Traffic management','Multiple services'], true)) reply(422, 'Please choose a valid service.');
} else if (!in_array($data['topic'], ['General question','Existing service','Safety and compliance','Other'], true)) reply(422, 'Please choose a valid category.');
// A real mailbox must be configured in production. No misleading success on mail failure.
$destination = getenv('SAMUKELE_CONTACT_EMAIL') ?: '';
if (!filter_var($destination, FILTER_VALIDATE_EMAIL)) reply(503, 'The server email destination has not been configured. Please contact the website administrator.');
$reference = strtoupper($kind[0]) . '-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
$lines = ["Reference: $reference", "Form type: $kind", 'Submitted (UTC): ' . gmdate('c')];
foreach ($data as $key => $val) if ($val !== '') $lines[] = strtoupper($key) . ': ' . $val;
$subject = 'Samukele website ' . $kind . ' ' . $reference;
$headers = ['From: no-reply@' . (filter_var($_SERVER['SERVER_NAME'] ?? '', FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ? $_SERVER['SERVER_NAME'] : 'localhost.localdomain'), 'Reply-To: ' . $data['email'], 'Content-Type: text/plain; charset=UTF-8', 'X-Mailer: Samukele POE'];
if (!mail($destination, $subject, implode("\n", $lines), implode("\r\n", $headers))) reply(503, 'The message could not be delivered. Please try again or contact the administrator.');
reply(200, 'Your request has been sent successfully.', $reference);
