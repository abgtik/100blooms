<?php
// Printable invoice (any time) and receipt (once paid) for one order, authorised by the order token like the tracking page.
//   GET ?c=CODE&k=TOKEN&t=invoice|receipt[&print=1]
// Prices follow our set pricing (Combo Jimat + add-ons). The browser's "Save as PDF" turns it into a PDF.
require __DIR__ . '/_lib.php';
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=utf-8');
$o = q('SELECT * FROM orders WHERE code=?', [(string)($_GET['c'] ?? '')])->fetch();
if (!$o || !hash_equals($o['token'], (string)($_GET['k'] ?? ''))) { http_response_code(404); exit('Link tidak sah.'); }
$type = ($_GET['t'] ?? '') === 'receipt' ? 'receipt' : 'invoice';
$paid = $o['pay_status'] === 'paid';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$f = fn($n) => number_format((float)$n, 2, '.', ',');
$dt = fn($s) => $s ? date('j M Y, g:i A', strtotime($s)) : '-';
if ($type === 'receipt' && !$paid) { http_response_code(409); echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Resit belum tersedia</title><body style="font-family:system-ui;padding:40px 20px;text-align:center;color:#3b2732"><h2>Resit belum tersedia</h2><p>Resit dikeluarkan selepas bayaran diterima. Invoice boleh dimuat turun di <a href="?c=' . $e($o['code']) . '&k=' . $e($o['token']) . '&t=invoice">sini</a>.</p>'; exit; }
$R = invoice_rows($o); $B = biz_info();
[$sk, $sl] = order_source($o);
$sales = $sk === 'hq' ? "$sl (Sales HQ)" : ($sk === 'agent' ? "$sl (Agent)" : 'Website (100blooms.my)');
$status = $o['status'] === 'batal' ? 'CANCELLED' : ($paid ? 'CONFIRMED' : 'PENDING PAYMENT');
$title = $type === 'receipt' ? 'Receipt' : 'Invoice';
$docNo = $type === 'receipt' ? 'R-' . $o['code'] : $o['code'];
$wish = $o['wish'] === WISH_NONE ? '(belum diterima)' : $o['wish'] . ($o['sender'] ? ' — ' . $o['sender'] : '');
$track = SITE_URL . '/semak/?c=' . rawurlencode($o['code']) . '&k=' . $o['token'];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($title . ' ' . $docNo . ' · 100 Blooms') ?></title>
<style>
:root{--ink:#2e2329;--soft:#6e5d66;--line:#d9cdd2;--head:#f1ebee;--accent:#d94f74;--ok:#1f8a4c}
*{box-sizing:border-box}html{background:#ece6e9}body{margin:0;font:13px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:var(--ink)}
.page{background:#fff;width:210mm;min-height:297mm;margin:16px auto;padding:16mm 15mm 14mm;box-shadow:0 10px 40px -14px rgba(0,0,0,.25);position:relative}
h1{font-size:24px;margin:0 0 14px;letter-spacing:-.01em}
.top{display:flex;justify-content:space-between;gap:20px;padding-bottom:14px;border-bottom:1px solid var(--line)}
.biz{display:flex;gap:12px;align-items:flex-start}.biz img{width:88px;height:88px;flex:none;border-radius:50%}
.biz b,.meta b{display:block}.meta{text-align:left;min-width:200px}
.row{display:flex;justify-content:space-between;gap:20px;padding:14px 0;border-bottom:1px solid var(--line)}
.lbl{font-weight:700;display:block;margin-top:6px}.lbl:first-child{margin-top:0}
table.st{border-collapse:collapse;align-self:flex-start}table.st td{border:1px solid var(--line);padding:5px 12px}table.st td:first-child{font-weight:700}
.paid{color:var(--ok);font-weight:700}.pend{color:#b26a00;font-weight:700}
h2{font-size:15px;margin:16px 0 8px}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:4px 24px}
table.it{width:100%;border-collapse:collapse;margin-top:6px}table.it th,table.it td{border:1px solid var(--line);padding:6px 8px;vertical-align:top}
table.it th{background:var(--head);text-align:left}table.it .n{text-align:right;white-space:nowrap}table.it .c{text-align:center;width:34px}
table.it .sub{color:var(--soft);font-size:12px;display:block}
table.it tfoot td{font-weight:500}table.it tfoot tr.tot td{font-weight:800;font-size:14px}
.stamp{position:absolute;right:15mm;top:13mm;transform:rotate(-12deg);border:3px solid var(--ok);color:var(--ok);font:800 26px/1 system-ui;padding:8px 16px;border-radius:10px;opacity:.75;letter-spacing:.08em}
.pay{margin-top:14px;padding:10px 12px;background:#f4fbf6;border:1px solid #cde9d7;border-radius:8px}
.pay.inv{background:#fff8ef;border-color:#f0dcbc}
.foot{margin-top:18px;color:var(--soft);font-size:11.5px;display:flex;justify-content:space-between;gap:12px}
.bar{position:sticky;top:0;z-index:5;display:flex;gap:8px;justify-content:center;flex-wrap:wrap;padding:10px;background:#2b1d24}
.bar a,.bar button{font:600 14px system-ui;border:0;border-radius:99px;padding:10px 18px;cursor:pointer;text-decoration:none;background:#fff;color:#2b1d24}
.bar .pri{background:var(--accent);color:#fff}
@media screen and (max-width:820px){.page{width:auto;min-height:0;margin:0;padding:18px 14px;box-shadow:none}.top,.row{flex-direction:column}.cols{grid-template-columns:1fr}.stamp{top:14px;right:14px;font-size:20px}}
table.it tr{break-inside:avoid;page-break-inside:avoid}
@page{size:A4;margin:10mm 12mm}
@media print{html,body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact;font-size:12px}.bar{display:none}.page{margin:0;box-shadow:none;width:auto;min-height:0;padding:0}
 h1{margin-bottom:10px}.row{padding:9px 0}h2{margin:10px 0 6px}.top{padding-bottom:10px}.stamp{top:-2mm;right:0}table.it th,table.it td{padding:4px 7px}.lbl{margin-top:2px}.foot{margin-top:8px}.pay{margin-top:8px;padding:7px 10px}.biz img{width:76px;height:76px}.cols{gap:0 24px}}
</style></head><body>
<div class="bar"><button class="pri" onclick="print()">⬇️ Simpan PDF / Cetak</button>
<?php if ($paid && $type === 'invoice'): ?><a href="?c=<?= $e($o['code']) ?>&k=<?= $e($o['token']) ?>&t=receipt">Lihat resit</a><?php endif ?>
<?php if ($type === 'receipt'): ?><a href="?c=<?= $e($o['code']) ?>&k=<?= $e($o['token']) ?>&t=invoice">Lihat invoice</a><?php endif ?>
<a href="<?= $e($track) ?>">Semak order</a></div>
<div class="page">
<?php if ($type === 'receipt'): ?><div class="stamp">PAID</div><?php endif ?>
<h1><?= $title ?></h1>
<div class="top">
 <div class="biz"><img src="../assets/logo.png" alt="100 Blooms" width="88" height="88">
  <div><b>100 Blooms</b><?= $e($B['name']) ?><br><?= $B['reg'] !== '' ? '(' . $e($B['reg']) . ')<br>' : '' ?><?= $B['addr'] !== '' ? nl2br($e($B['addr'])) . '<br>' : '' ?><?= $e($B['phone']) ?><?= $B['email'] !== '' ? '<br>' . $e($B['email']) : '' ?></div></div>
 <div class="meta"><b><?= $type === 'receipt' ? 'Receipt No:' : 'Invoice No:' ?></b><?= $e($docNo) ?><?php if ($type === 'receipt'): ?><b style="margin-top:4px">Invoice No:</b><?= $e($o['code']) ?><?php endif ?>
  <b style="margin-top:4px">Date:</b><?= $e(date('j M Y', strtotime($type === 'receipt' ? ($o['paid_at'] ?: $o['created_at']) : $o['created_at']))) ?>
  <b style="margin-top:4px">Salesperson:</b><?= $e($sales) ?></div>
</div>
<div class="row">
 <div><span class="lbl">Order</span>Combo Jimat 100 Blooms<span class="lbl">Received At</span><?= $e($dt($o['created_at'])) ?></div>
 <table class="st"><tr><td>Status</td><td><?= $status ?></td></tr><tr><td>Payment</td><td class="<?= $paid ? 'paid' : 'pend' ?>"><?= $paid ? 'PAID' : 'UNPAID' ?></td></tr></table>
</div>
<div class="row" style="display:block"><span class="lbl">Date &amp; Time Slot</span><?= $e($R['date']) ?></div>
<?php if (!empty($o['bill_to'])): ?><div class="row" style="display:block"><span class="lbl">Bill To</span><?= nl2br($e($o['bill_to'])) ?></div><?php endif ?>
<h2>Customer Details</h2>
<div class="cols">
 <div><span class="lbl">Name</span><?= $e($o['b_name']) ?><span class="lbl">Phone Number</span>60<?= $e(norm_phone((string)$o['b_phone'])) ?>
  <span class="lbl">Receiver Name</span><?= $e($o['r_name']) ?><span class="lbl">Receiver Phone Number</span><?= $e($o['r_phone']) ?></div>
 <div><span class="lbl">Delivery Address</span><?= $e($o['r_addr']) ?><br><?= $e($o['r_post']) ?><span class="lbl">Wishcard (FREE)</span><?= nl2br($e($wish)) ?></div>
</div>
<h2>Order Items</h2>
<table class="it"><thead><tr><th class="c">#</th><th>Item</th><th class="c">Qty</th><th class="n">Price (RM)</th><th class="n">Amount (RM)</th></tr></thead><tbody>
<?php foreach ($R['items'] as $i => $it): ?><tr><td class="c"><?= $i + 1 ?></td><td><?= $e($it['name']) ?><?php foreach ($it['subs'] as $sl): ?><div class="sub"><?= $e($sl) ?></div><?php endforeach ?></td><td class="c"><?= (int)$it['qty'] ?></td><td class="n"><?= !empty($it['free']) ? 'FREE' : $f($it['price']) ?></td><td class="n"><?= $f($it['amount']) ?></td></tr>
<?php endforeach ?></tbody><tfoot>
<tr><td colspan="4">Subtotal</td><td class="n"><?= $f($R['sub']) ?></td></tr>
<tr><td colspan="4">Delivery Fee : <?= $e($R['del_name']) ?></td><td class="n"><?= $f($R['del_fee']) ?></td></tr>
<?php foreach ($R['extra'] as [$l, $a]): ?><tr><td colspan="4"><?= $e($l) ?></td><td class="n"><?= $f($a) ?></td></tr><?php endforeach ?>
<?php if ($R['disc'] < -0.009): ?><tr><td colspan="4">Adjustment</td><td class="n"><?= $f(-$R['disc']) ?></td></tr><?php endif ?>
<?php if ($R['disc'] > 0): ?><tr><td colspan="4">Discount<?= $o['voucher'] ? ' (Voucher: ' . $e($o['voucher']) . ')' : '' ?></td><td class="n">-<?= $f($R['disc']) ?></td></tr><?php endif ?>
<tr class="tot"><td colspan="4">Total</td><td class="n">RM <?= $f($o['total']) ?></td></tr>
<?php if ($type === 'receipt'): ?><tr class="tot"><td colspan="4">Amount Paid</td><td class="n">RM <?= $f($o['total']) ?></td></tr><tr><td colspan="4">Balance Due</td><td class="n">0.00</td></tr><?php endif ?>
</tfoot></table>
<?php if ($type === 'receipt'): ?>
<div class="pay"><b>Payment received</b> · <?= $e($dt($o['paid_at'])) ?> · <?= $e($o['pay_channel'] ?: '-') ?><?= $o['pay_ref'] ? ' · Ref: ' . $e($o['pay_ref']) : '' ?></div>
<?php elseif (!$paid && $o['status'] !== 'batal'): ?>
<div class="pay inv"><b>Payment</b> · Please pay RM <?= $f($o['total']) ?> to confirm this order. Pay online or by bank transfer via: <?= $e($track) ?></div>
<?php endif ?>
<div class="foot"><span><?= $type === 'receipt' ? 'This is a computer-generated receipt. No signature is required.' : 'This is a computer-generated invoice. No signature is required.' ?></span><span>© <?= date('Y') ?> 100 Blooms</span></div>
</div>
<?php if (!empty($_GET['print'])): ?><script>addEventListener('load',()=>setTimeout(print,400))</script><?php endif ?>
</body></html>
