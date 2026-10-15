<?php
// POST multipart: data=<json>, photo[]=<jpeg/png files>. Validates, prices, saves the order as pending payment,
// creates a payment request (HitPay or ToyyibPay) and returns where to send the customer to pay.
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST sahaja.', 405);

$in = json_decode((string)($_POST['data'] ?? ''), true);
if (!is_array($in)) fail('Data order tidak lengkap. Muat semula halaman dan cuba lagi.');

$files = collect_photos(); // verified before touching the database

try {
  $r = build_order($in, count($files));
  if (isset($r['errors'])) fail(implode(' ', $r['errors']), 422);
  $o = $r['order'];
  $o['code'] = new_code();
  $o['token'] = bin2hex(random_bytes(12));
  $o['created_at'] = date('Y-m-d H:i:s');
  $ref = preg_replace('/[^A-Za-z0-9]/', '', (string)($in['ref'] ?? ''));
  $o['agent_ref'] = $ref !== '' ? substr($ref, 0, 20) : null;
  // Test mode: owner opens the site with ?test=<TEST_KEY>; the whole order is charged RM1 and labelled.
  if (defined('TEST_KEY') && TEST_KEY !== '' && is_string($in['test'] ?? null) && hash_equals(TEST_KEY, $in['test'])) {
    $o['price_lines'] = json_encode([['UJIAN SISTEM (bukan order sebenar)', 1]], JSON_UNESCAPED_UNICODE);
    $o['total'] = 1; $o['notes'] = 'ORDER UJIAN RM1. Jangan hantar.';
  }

  $saved = save_photos($o['code'], $files);
  $o['photos'] = json_encode($saved);

  $cols = array_keys($o);
  q('INSERT INTO orders (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($o));
  $o['id'] = (int)db()->lastInsertId();
} catch (Throwable $e) {
  error_log('100blooms order: ' . $e->getMessage());
  fail('Order tak dapat disimpan sekarang. Cuba sekali lagi, atau WhatsApp kami.', 500);
}

// The order is saved even if the payment page fails, so the team can follow up.
try {
  [$bill, $url] = pay_create($o);
  q('UPDATE orders SET bill_code=? WHERE id=?', [$bill, $o['id']]);
  json_out(['code' => $o['code'], 'total' => $o['total'], 'payUrl' => $url]);
} catch (Throwable $e) {
  error_log('100blooms bill: ' . $e->getMessage());
  q('UPDATE orders SET notes=? WHERE id=?', ['Gagal cipta bayaran ' . gateway_name() . ': ' . mb_substr($e->getMessage(), 0, 200), $o['id']]);
  json_out(['code' => $o['code'], 'total' => $o['total'], 'payUrl' => null,
    'error' => 'Order ' . $o['code'] . ' dah disimpan, tapi halaman bayaran tak dapat dibuka. WhatsApp kami dengan kod ni dan kami bantu.'], 502);
}
