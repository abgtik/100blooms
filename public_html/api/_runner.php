<?php
// Runners: freelance riders who register in the agent portal (agent mode is on at once; runner mode waits for HQ approval).
// HQ creates a delivery job on a paid order: assigned to one runner, or open (first runner to take it gets it).
// The runner moves the order through the usual delivery steps and uploads the recipient photo; HQ pays each job after delivery.
// Policy: 3 damaged deliveries in a row → runner suspended for 7 days. Recipients rate the runner (1–5) on the gift page.

const RUNNER_SUSPEND_DAYS = 7;
const RUNNER_DAMAGE_STREAK = 3;

function runner_schema(bool $my): array {
  $id = $my ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
  $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
  return [
    "CREATE TABLE IF NOT EXISTS runner_jobs (id $id, order_id INT NOT NULL UNIQUE, pay DECIMAL(8,2) NOT NULL DEFAULT 0, mode VARCHAR(8) NOT NULL DEFAULT 'open',
      runner_id INT NULL, status VARCHAR(10) NOT NULL DEFAULT 'open', note VARCHAR(300) NULL, created_at DATETIME NOT NULL, created_by VARCHAR(100) NULL,
      taken_at DATETIME NULL, done_at DATETIME NULL, paid_at DATETIME NULL, paid_ref VARCHAR(60) NULL, paid_by VARCHAR(100) NULL,
      damage TINYINT NOT NULL DEFAULT 0, damage_note VARCHAR(300) NULL)$tail",
    "CREATE TABLE IF NOT EXISTS runner_ratings (id $id, order_id INT NOT NULL UNIQUE, runner_id INT NOT NULL, stars TINYINT NOT NULL, comment VARCHAR(500) NULL, created_at DATETIME NOT NULL)$tail",
  ];
}
/** Columns added to agents for runner mode. */
const RUNNER_COLS = ['runner VARCHAR(10) NULL', 'r_face VARCHAR(60) NULL', 'r_bike VARCHAR(60) NULL', 'r_plate VARCHAR(20) NULL', 'r_area VARCHAR(100) NULL',
  'r_avail DATE NULL', 'r_susp DATETIME NULL', 'r_applied DATETIME NULL'];

function runner_dir(): string { return UPLOAD_DIR . '/_runners'; }
/** none | pending | active | suspended | rejected */
function runner_state(array $ag): string {
  $r = (string)($ag['runner'] ?? '');
  if ($r === '') return 'none';
  if ($r === 'active' && !empty($ag['r_susp']) && $ag['r_susp'] > date('Y-m-d H:i:s')) return 'suspended';
  return $r;
}
function runner_rating(int $rid): array {
  $r = q('SELECT COUNT(*) n, AVG(stars) a FROM runner_ratings WHERE runner_id=?', [$rid])->fetch();
  return ['n' => (int)$r['n'], 'avg' => $r['n'] ? round((float)$r['a'], 1) : null];
}
function runner_msg(array $ag, string $msg): void { wa_agent($ag, 'agent_runner', $msg); }
function job_slot(array $o): string { return $o['del_type'] === 'spec' ? 'Masa khusus ' . $o['del_time'] : (['free' => '10am–6pm', 'A' => 'Slot A 9am–12pm', 'B' => 'Slot B 1pm–4pm', 'C' => 'Slot C 5pm–8pm'][$o['del_type']] ?? ''); }
function job_of_order(int $oid): ?array { return q('SELECT * FROM runner_jobs WHERE order_id=?', [$oid])->fetch() ?: null; }

/** Advance an order one delivery step on behalf of a runner. Returns the fresh order. */
function runner_step(array $o, string $step, string $who): array {
  if ($o['pay_status'] !== 'paid' || $o['status'] === 'batal') fail('Order ni belum dibayar atau dah dibatalkan. Hubungi HQ.');
  $d = jdec($o['del_steps']);
  if ($step === 'otw_pickup' && !isset($d['booked'])) {
    if (derive_status($o) !== 'siap') fail('Hadiah belum siap disediakan. Tunggu team HQ tick semua item dulu.');
    $d['booked'] = ['by' => $who, 'at' => date('Y-m-d H:i:s')];
  }
  $keys = array_keys(DEL_STEPS); $next = $keys[count(array_intersect_key(DEL_STEPS, $d))] ?? null;
  if (!in_array($step, ['otw_pickup', 'picked', 'delivered'], true) || $step !== $next) fail('Ikut urutan: langkah seterusnya ialah "' . (DEL_STEPS[$next] ?? '-') . '".');
  if ($step === 'delivered' && !$o['recipient_photo']) fail('Upload gambar penerima dulu.');
  $d[$step] = ['by' => $who, 'at' => date('Y-m-d H:i:s')];
  q('UPDATE orders SET del_steps=?, updated_by=? WHERE id=?', [json_encode($d, JSON_UNESCAPED_UNICODE), $who, $o['id']]);
  $o = q('SELECT * FROM orders WHERE id=?', [$o['id']])->fetch();
  $st = derive_status($o); if ($st !== $o['status']) { q('UPDATE orders SET status=? WHERE id=?', [$st, $o['id']]); $o['status'] = $st; }
  if ($step === 'picked') wa_order($o, 'otw'); elseif ($step === 'delivered') wa_order($o, 'delivered');
  return $o;
}

/** After HQ marks a job damaged: 3 damaged jobs in a row (since the last suspension) → suspend 7 days. Returns true if suspended now. */
function runner_damage_check(int $rid): bool {
  $ag = q('SELECT * FROM agents WHERE id=?', [$rid])->fetch(); if (!$ag) return false;
  $since = !empty($ag['r_susp']) ? date('Y-m-d H:i:s', strtotime($ag['r_susp']) - RUNNER_SUSPEND_DAYS * 86400) : '1970-01-01 00:00:00';
  $last = q("SELECT damage FROM runner_jobs WHERE runner_id=? AND status='done' AND done_at>? ORDER BY done_at DESC LIMIT " . RUNNER_DAMAGE_STREAK, [$rid, $since])->fetchAll();
  if (count($last) < RUNNER_DAMAGE_STREAK || array_sum(array_column($last, 'damage')) < RUNNER_DAMAGE_STREAK) return false;
  $until = date('Y-m-d H:i:s', time() + RUNNER_SUSPEND_DAYS * 86400);
  q('UPDATE agents SET r_susp=? WHERE id=?', [$until, $rid]);
  runner_msg($ag, "Hai {$ag['name']},\n\n" . RUNNER_DAMAGE_STREAK . " delivery terakhir awak ditanda *rosak*. Ikut polisi runner, mod runner awak *digantung " . RUNNER_SUSPEND_DAYS . " hari* sehingga " . fmt_day(substr($until, 0, 10)) . ".\n\nLink agent awak masih aktif. Hubungi HQ jika ada pertanyaan.\n— 100 Blooms");
  return true;
}

/** Job as the runner sees it. Full recipient details only while the job is theirs and not finished. */
function runner_job_view(array $j, array $o, int $rid): array {
  $mine = (int)$j['runner_id'] === $rid; $live = $mine && $j['status'] === 'taken';
  $d = jdec($o['del_steps']); $keys = array_keys(DEL_STEPS); $next = $keys[count(array_intersect_key(DEL_STEPS, $d))] ?? null;
  if ($next === 'booked') $next = 'otw_pickup'; // the runner starts the job; 'booked' is marked for them
  $v = ['id' => (int)$j['id'], 'code' => $o['code'], 'date' => $o['del_date'], 'slot' => job_slot($o), 'area' => area_of($o['r_addr'], $o['r_post']),
    'pay' => (float)$j['pay'], 'status' => $j['status'], 'items' => order_summary($o, 4), 'mode' => $j['mode'],
    'paid' => $j['paid_at'] !== null, 'paid_at' => $j['paid_at'], 'paid_ref' => $j['paid_ref'], 'done_at' => $j['done_at'], 'damage' => (int)$j['damage'],
    'ready' => derive_status($o) === 'siap' || isset($d['booked']), 'cancelled' => $o['status'] === 'batal'];
  if ($live) $v += ['r_name' => $o['r_name'], 'r_phone' => first_phone((string)$o['r_phone']) ?: $o['r_phone'], 'r_addr' => $o['r_addr'] . ', ' . $o['r_post'],
    'next' => $next, 'next_label' => DEL_STEPS[$next] ?? null, 'steps' => array_map(fn($x) => $x['at'], array_intersect_key($d, DEL_STEPS)), 'has_photo' => (bool)$o['recipient_photo'],
    'pickup' => setting('biz_addr', '') ?: 'Kedai 100 Blooms, Jenjarom', 'notes_runner' => (string)($j['note'] ?? '')];
  return $v;
}

/** Tell runners about a new open job: active, not suspended; for today's jobs only those who marked themselves available today. */
function runner_announce(array $j, array $o): void {
  $today = $o['del_date'] === date('Y-m-d');
  $rows = q("SELECT * FROM agents WHERE runner='active' AND active=1 AND (r_susp IS NULL OR r_susp<?)" . ($today ? ' AND r_avail=?' : '') . ' LIMIT 40',
    $today ? [date('Y-m-d H:i:s'), date('Y-m-d')] : [date('Y-m-d H:i:s')])->fetchAll();
  foreach ($rows as $ag) runner_msg($ag, "🛵 *Job baru 100 Blooms*\n📍 " . area_of($o['r_addr'], $o['r_post']) . "\n📅 " . fmt_day($o['del_date']) . ' · ' . job_slot($o)
    . "\n💰 RM" . number_format((float)$j['pay'], 2) . "\n\nSiapa cepat dia dapat. Ambil di dashboard:\n" . SITE_URL . '/agent/#runner');
}
