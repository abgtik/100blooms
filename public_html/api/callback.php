<?php
// ToyyibPay server-to-server callback. Verifies the hash, then confirms with getBillTransactions before marking paid.
require __DIR__ . '/_lib.php';
$status = (string)($_POST['status'] ?? $_POST['status_id'] ?? '');
$orderId = (string)($_POST['order_id'] ?? '');
$refno = (string)($_POST['refno'] ?? '');
$hash = (string)($_POST['hash'] ?? '');
$bill = (string)($_POST['billcode'] ?? '');

if ($hash !== '' && !hash_equals(md5(TP_SECRET . $status . $orderId . $refno . 'ok'), $hash)) { error_log("100blooms callback: bad hash for $orderId"); http_response_code(400); exit('BAD HASH'); }

$o = q('SELECT * FROM orders WHERE code=?', [$orderId])->fetch();
if (!$o) { http_response_code(404); exit('NO ORDER'); }
if ($bill !== '' && $o['bill_code'] && $bill !== $o['bill_code']) { http_response_code(400); exit('BILL MISMATCH'); }

if ($o['pay_status'] !== 'paid' && $o['bill_code']) {
  // never trust the POST alone: ask ToyyibPay
  [$paid, $ref, $ch, $amt] = tp_check($o['bill_code']);
  if ($paid) mark_paid($o, $ref ?: $refno, $ch, $amt);
  elseif ($status === '3') q("UPDATE orders SET pay_status='failed' WHERE id=? AND pay_status='pending'", [$o['id']]);
}
echo 'OK';
