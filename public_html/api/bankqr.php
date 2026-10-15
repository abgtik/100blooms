<?php
// Public: the shop's bank / DuitNow QR for customers paying an invoice (uploaded by the owner in admin).
require __DIR__ . '/_lib.php';
$p = bank_qr_path();
if (!$p) { http_response_code(404); exit; }
header('Content-Type: ' . (str_ends_with($p, '.png') ? 'image/png' : 'image/jpeg')); header('Cache-Control: public, max-age=300');
readfile($p);
