<?php
// Customer order tracking.
//   POST {code, phone}  → looks up by order code + the buyer's WhatsApp number (rate-limited per IP)
//   GET  ?c=CODE&k=TOKEN → direct link (thank-you page / WhatsApp from the team)
// Returns only what the buyer already knows: no recipient phone or full address.
require __DIR__ . '/_lib.php';

$ipKey = substr('t' . ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $in = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST;
  if ((int)q('SELECT COUNT(*) FROM login_attempts WHERE ip=? AND at>?', [$ipKey, date('Y-m-d H:i:s', time() - 900)])->fetchColumn() >= 10)
    fail('Terlalu banyak cubaan. Cuba lagi dalam 15 minit.', 429);
  $code = strtoupper(preg_replace('/\s+/', '', (string)($in['code'] ?? '')));
  $o = $code !== '' ? q('SELECT * FROM orders WHERE code=?', [$code])->fetch() : false;
  $phone = norm_phone((string)($in['phone'] ?? ''));
  if (!$o || $phone === '' || !hash_equals(norm_phone($o['b_phone']), $phone)) {
    q('INSERT INTO login_attempts (ip,at) VALUES (?,?)', [$ipKey, date('Y-m-d H:i:s')]);
    fail('Kod order atau nombor WhatsApp tak sepadan. Semak semula dan cuba lagi.', 404);
  }
} else {
  $o = q('SELECT * FROM orders WHERE code=?', [(string)($_GET['c'] ?? '')])->fetch();
  if (!$o || !hash_equals($o['token'], (string)($_GET['k'] ?? ''))) fail('Link tidak sah. Masukkan kod order dan nombor WhatsApp.', 404);
}

// a late payment may not have been recorded yet
if ($o['pay_status'] !== 'paid' && $o['bill_code'] && $o['status'] !== 'batal') {
  try { [$paid, $ref, $ch, $amt] = pay_check($o['bill_code']); if ($paid) { mark_paid($o, $ref, $ch, $amt); $o = q('SELECT * FROM orders WHERE id=?', [$o['id']])->fetch(); } }
  catch (Throwable $e) { error_log('100blooms track: ' . $e->getMessage()); }
}

$C = catalog();
$extra = json_decode((string)$o['extra_cakes'], true) ?: [];
json_out([
  'code' => $o['code'], 'k' => $o['token'],
  'paid' => $o['pay_status'] === 'paid', 'pay_status' => $o['pay_status'], 'status' => $o['status'],
  'created_at' => $o['created_at'], 'paid_at' => $o['paid_at'],
  'del_date' => $o['del_date'], 'del_type' => $o['del_type'], 'del_time' => $o['del_time'], 'del_label' => DELIVERY[$o['del_type']]['label'] ?? '',
  'occasion' => $C['occ'][$o['occasion']] ?? $o['occasion'], 'call' => $o['call_name'], 'r_name' => $o['r_name'], 'r_post' => $o['r_post'],
  'bouquet' => ['id' => $o['bouquet'], 'name' => $C['bq'][$o['bouquet']] ?? $o['bouquet']],
  'balloon' => ['id' => $o['balloon'], 'name' => $C['bal'][$o['balloon']] ?? $o['balloon']],
  'cake' => $o['cake'], 'cake_flavours' => json_decode((string)$o['cake_flavours'], true) ?: [], 'extra_cakes' => $extra,
  'photos' => json_decode((string)$o['photos'], true) ?: [], 'recipient_photo' => in_array($o['status'], ['sampai', 'selesai'], true) ? $o['recipient_photo'] : null,
  'sender' => $o['sender'], 'total' => (float)$o['total'], 'b_name' => explode(' ', $o['b_name'])[0],
  'payUrl' => ($o['pay_status'] !== 'paid' && $o['bill_code'] && $o['status'] !== 'batal') ? pay_url($o['bill_code']) : null,
  'wa' => WA_MAIN,
]);
