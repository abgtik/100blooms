<?php
// GET: what the customer app needs from the server: Malaysia time now, closed dates, out-of-stock items.
require __DIR__ . '/_lib.php';
try {
  json_out(['now' => date('Y-m-d\TH:i:s'), 'blocked' => blocked_dates(), 'off' => off_items(), 'wa' => WA_MAIN]);
} catch (Throwable $e) { error_log('100blooms catalog: ' . $e->getMessage()); fail('Sistem sedang diselenggara. Cuba sebentar lagi.', 503); }
