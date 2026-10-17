<?php
// Buyer claims the thank-you gift on the tracking page: RM10 off or 1 extra polaroid free, for the next order.
//   POST JSON {c, k, choice: 'rm10'|'photo'} → {code, label, ends}. One claim per order; the choice is final.
require __DIR__ . '/_lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST sahaja.', 405);
$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$o = q('SELECT * FROM orders WHERE code=?', [(string)($in['c'] ?? '')])->fetch();
if (!$o || !hash_equals($o['token'], (string)($in['k'] ?? ''))) fail('Link tidak sah. Buka semula halaman semak order.', 404);
$a = aftercare($o);
if (empty($a['offer'])) fail('Hadiah belum tersedia untuk order ini.', 409);
$lab = ['rm10' => 'RM10 off', 'photo' => '1 polaroid tambahan percuma'];
if (!empty($a['gift'])) json_out(['code' => $a['gift']['code'], 'label' => $lab[$a['gift']['choice']] ?? '', 'ends' => $a['gift']['ends']]);
$ch = (string)($in['choice'] ?? ''); if (!isset($lab[$ch])) fail('Pilih salah satu hadiah.');
[$code, $ends] = $ch === 'rm10' ? make_voucher('TQ', 'rm', 10, 'Hadiah terima kasih order ' . $o['code']) : make_voucher('TQ', 'photo', 1, 'Hadiah terima kasih order ' . $o['code'] . ' (polaroid)');
$a['gift'] = ['choice' => $ch, 'code' => $code, 'ends' => $ends, 'at' => date('Y-m-d H:i:s')];
aftercare_save((int)$o['id'], $a);
json_out(['code' => $code, 'label' => $lab[$ch], 'ends' => $ends]);
