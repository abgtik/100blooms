<?php
// HitPay webhook (Dashboard → Developers → Webhook Endpoints, event payment_request.completed).
// Verifies the HMAC-SHA256 signature of the raw body with the salt, then confirms with the HitPay API before marking paid.
require __DIR__ . '/_lib.php';
$raw = file_get_contents('php://input') ?: '';
$sig = (string)($_SERVER['HTTP_HITPAY_SIGNATURE'] ?? '');
if ($sig === '' || !hash_equals(hash_hmac('sha256', $raw, HP_SALT), $sig)) { error_log('100blooms hitpay: bad signature'); http_response_code(401); exit('BAD SIGNATURE'); }

$j = json_decode($raw, true) ?: [];
$ref = (string)($j['reference_number'] ?? '');
$id = (string)($j['id'] ?? $j['payment_request_id'] ?? '');
$o = $ref !== '' ? q('SELECT * FROM orders WHERE code=?', [$ref])->fetch() : false;
if (!$o && $id !== '') $o = q('SELECT * FROM orders WHERE bill_code=?', [$id])->fetch();
if (!$o) { http_response_code(200); exit('IGNORED'); } // not one of ours (e.g. a test event)

if ($o['pay_status'] !== 'paid' && $o['bill_code']) {
  try { [$paid, $pref, $ch, $amt] = pay_check($o['bill_code']); if ($paid) mark_paid($o, $pref, $ch, $amt); }
  catch (Throwable $e) { error_log('100blooms hitpay webhook: ' . $e->getMessage()); http_response_code(500); exit('RETRY'); }
}
echo 'OK';
