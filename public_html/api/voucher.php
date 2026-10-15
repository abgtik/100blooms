<?php
// POST {code, ref, phone}: checks a voucher for the summary page so the customer sees the effect before paying.
// The order endpoint re-checks everything; this is only a preview. Wrong codes are rate-limited per IP.
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST sahaja.', 405);
$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$ipKey = substr('v' . ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
if ((int)q('SELECT COUNT(*) FROM login_attempts WHERE ip=? AND at>?', [$ipKey, date('Y-m-d H:i:s', time() - 900)])->fetchColumn() >= 10)
  fail('Terlalu banyak cubaan kod voucher. Cuba lagi dalam 15 minit.', 429);
$v = voucher_find((string)($in['code'] ?? ''));
if (!$v) { q('INSERT INTO login_attempts (ip,at) VALUES (?,?)', [$ipKey, date('Y-m-d H:i:s')]); fail('Kod voucher tidak sah.', 404); }
$viaAgent = preg_replace('/[^A-Za-z0-9]/', '', (string)($in['ref'] ?? '')) !== '';
if ($why = voucher_block($v, (string)($in['phone'] ?? ''), $viaAgent)) fail($why, 422);
json_out(['code' => $v['code'], 'type' => $v['type'], 'value' => (float)$v['value'], 'max_off' => $v['max_off'] !== null ? (float)$v['max_off'] : null,
  'gift_name' => $v['gift_name'], 'label' => voucher_label($v)]);
