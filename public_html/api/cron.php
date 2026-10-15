<?php
// Optional: point a Plesk Scheduled Task here every 5 minutes (Fetch URL) so unpaid-order follow-ups go out even when
// nobody is visiting the site. Without it the same job still runs after normal page visits.
require __DIR__ . '/_lib.php';
db();
cron_tick(true);
json_out(['ok' => true, 'at' => date('c')]);
