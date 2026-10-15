<?php
// GET ?code=&k=: payment status for the thank-you page. Needs the per-order token so codes can't be enumerated.
// If still pending, asks the payment gateway directly (covers a late or missed callback).
require __DIR__ . '/_lib.php';
$o = q('SELECT * FROM orders WHERE code=?', [(string)($_GET['code'] ?? '')])->fetch();
if (!$o || !hash_equals($o['token'], (string)($_GET['k'] ?? ''))) fail('Order tidak dijumpai.', 404);
if ($o['pay_status'] !== 'paid' && $o['bill_code']) {
  try { [$paid, $ref, $ch, $amt] = pay_check($o['bill_code']); if ($paid && mark_paid($o, $ref, $ch, $amt)) $o['pay_status'] = 'paid'; }
  catch (Throwable $e) { error_log('100blooms status: ' . $e->getMessage()); }
  $o = q('SELECT * FROM orders WHERE id=?', [$o['id']])->fetch();
}
json_out(['code' => $o['code'], 'paid' => $o['pay_status'] === 'paid', 'pay_status' => $o['pay_status'],
  'total' => (float)$o['total'], 'buyer' => explode(' ', $o['b_name'])[0], 'call' => $o['call_name'], 'date' => $o['del_date'],
  'payUrl' => ($o['pay_status'] !== 'paid' && $o['bill_code']) ? pay_url($o['bill_code']) : null]);
