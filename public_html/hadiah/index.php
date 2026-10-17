<?php
// Recipient page: the card's message, their delivery photo, what was in the gift, and an RM10 first-order voucher.
// Link sent to the recipient after delivery: /hadiah/?c=CODE&r=RECIPIENT_KEY (separate from the buyer's tracking token).
require __DIR__ . '/../api/_lib.php';
header('X-Robots-Tag: noindex, nofollow');
$o = q('SELECT * FROM orders WHERE code=?', [(string)($_GET['c'] ?? '')])->fetch();
$ok = $o && hash_equals(rcp_key($o), (string)($_GET['r'] ?? '')) && $o['pay_status'] === 'paid' && $o['status'] !== 'batal';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
if (!$ok) { http_response_code(404); ?><!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>100 Blooms</title>
<body style="font-family:system-ui;text-align:center;padding:60px 20px;color:#3b2732;background:#fbeef0"><h2>Link tidak sah</h2><p>Cuba buka semula link daripada WhatsApp.</p><p><a href="/" style="color:#d94f74">100blooms.my</a></p></body><?php exit; }

$C = catalog(); $a = aftercare($o);
try { [$vcode, $vends] = rcp_voucher($o); } catch (Throwable $x) { $vcode = null; $vends = null; }
if (empty($a['rcp_viewed_at'])) { $a = aftercare(q('SELECT aftercare FROM orders WHERE id=?', [$o['id']])->fetch() ?: []); $a['rcp_viewed_at'] = date('Y-m-d H:i:s'); aftercare_save((int)$o['id'], $a); }
$used = $vcode && (int)q("SELECT COUNT(*) FROM orders WHERE voucher=? AND pay_status='paid'", [$vcode])->fetchColumn() > 0;

$T = [ // occasion themes: [title, emoji, bg1, bg2, accent, line]
  'birthday' => ['Selamat Hari Jadi!', '🎂', '#fff1f4', '#ffd9e2', '#d94f74', 'Semoga hari ni penuh senyuman.'],
  'anniv' => ['Selamat Ulang Tahun!', '💍', '#fff4f0', '#ffd8cc', '#c2410c', 'Untuk kenangan yang terus bertambah.'],
  'pujuk' => ['Untuk awak, dengan ikhlas', '🌷', '#fdf2f8', '#fbd5ea', '#be185d', 'Kadang-kadang bunga lebih pandai berkata-kata.'],
  'convo' => ['Tahniah, Graduan!', '🎓', '#eef4ff', '#cfdffb', '#2f6fc1', 'Penat lelah awak akhirnya berbaloi.'],
  'rindu' => ['Ada yang teringatkan awak', '💌', '#f6f1ff', '#e2d6fb', '#7c3aed', 'Jauh di mata, dekat di hati.'],
  'thanks' => ['Terima kasih untuk awak', '🙏', '#f1fbf5', '#cdeedb', '#2f8f63', 'Kebaikan awak sangat dihargai.'],
  'sembuh' => ['Semoga cepat sembuh', '🌼', '#fffbea', '#fdecb0', '#b07a10', 'Rehat secukupnya, kami doakan awak cepat pulih.'],
  'baby' => ['Tahniah & selamat datang si kecil!', '👶', '#eefaff', '#cdeefb', '#0e7aa8', 'Semoga keluarga awak sentiasa bahagia.'],
  'saja' => ['Hadiah istimewa untuk awak', '🌸', '#fff1f4', '#ffd9e2', '#d94f74', 'Tak perlu sebab untuk buat seseorang tersenyum.'],
];
$en = order_en($o); $L = fn($ms, $eng) => $en ? $eng : $ms;
if ($en) foreach ([ // English titles and lines, same colours
  'birthday' => ['Happy Birthday!', 'Wishing you a day full of smiles.'], 'anniv' => ['Happy Anniversary!', 'To memories that keep growing.'],
  'pujuk' => ['For you, sincerely', 'Sometimes flowers say it better.'], 'convo' => ['Congratulations, Graduate!', 'All your hard work finally paid off.'],
  'rindu' => ['Someone is thinking of you', 'Far apart, close at heart.'], 'thanks' => ['Thank you, from the heart', 'Your kindness is truly appreciated.'],
  'sembuh' => ['Get well soon', 'Rest well, we hope you feel better soon.'], 'baby' => ['Congratulations & welcome, little one!', 'Wishing your family every happiness.'],
  'saja' => ['A special gift for you', 'No reason needed to make someone smile.']] as $k => [$t1, $l1]) { $T[$k][0] = $t1; $T[$k][5] = $l1; }
[$title, $emo, $bg1, $bg2, $acc, $line] = $T[$o['occasion']] ?? $T['saja'];
$rf = trim(explode(' ', trim((string)$o['r_name']))[0] ?? '') ?: $L('awak', 'you');
$from = trim((string)($o['sender'] ?? '')) ?: (trim(explode(' ', trim((string)$o['b_name']))[0] ?? '') ?: $L('seseorang istimewa', 'someone special'));
$wish = $o['wish'] !== WISH_NONE ? (string)$o['wish'] : '';
$hasPhoto = $o['recipient_photo'] && in_array($o['status'], ['sampai', 'selesai'], true);
$ph = '../api/rphoto.php?c=' . rawurlencode($o['code']) . '&r=' . rcp_key($o);
$img = fn($p) => '../assets/img/' . $p;
$slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$o['cake'])), '-'); $cake = is_file(__DIR__ . '/../assets/img/cake/' . $slug . '.webp') ? $slug : null;
$tx = fn($s) => $en ? tr_en((string)$s) : (string)$s;
$items = [['Bouquet ' . ($C['bq'][$o['bouquet']] ?? ''), $img('bouquet/' . $o['bouquet'] . '.webp')], ['Balloon ' . $tx($C['bal'][$o['balloon']] ?? ''), $img('balloon/' . $o['balloon'] . '.webp')]];
if ($cake) $items[] = [$L('Kek ', 'Cake: ') . $o['cake'], $img('cake/' . $cake . '.webp')];
?><!doctype html>
<html lang="<?= $en ? 'en' : 'ms' ?>"><head>
<link rel="icon" href="/favicon.ico?v=2" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/assets/icon-32.png?v=2"><link rel="apple-touch-icon" href="/assets/apple-touch-icon.png?v=2"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= $e($L("Hadiah untuk $rf", "A gift for $rf") . ' · 100 Blooms') ?></title>
<meta property="og:title" content="<?= $e($L("$emo Ada hadiah istimewa untuk $rf", "$emo A special gift for $rf")) ?>"><meta property="og:description" content="<?= $e($L("Daripada $from, bersama 100 Blooms", "From $from, with 100 Blooms")) ?>">
<meta property="og:image" content="<?= $e(SITE_URL . '/assets/img/combo-poster.webp') ?>"><meta name="theme-color" content="<?= $e($bg1) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Figtree:wght@400;500;600;700&display=swap">
<style>
:root{--bg1:<?= $bg1 ?>;--bg2:<?= $bg2 ?>;--acc:<?= $acc ?>;--ink:#2e2329;--soft:#6e5d66;--card:#fff;--display:'Fredoka',system-ui,sans-serif;--body:'Figtree',system-ui,sans-serif}
*{box-sizing:border-box}body{margin:0;font:16px/1.55 var(--body);color:var(--ink);background:linear-gradient(180deg,var(--bg2),var(--bg1) 45%,#fff);min-height:100vh}
main{max-width:520px;margin:0 auto;padding:28px 18px 40px}
.hero{text-align:center;padding:18px 6px 8px;position:relative}
.emo{font-size:62px;line-height:1;display:inline-block;animation:pop .9s cubic-bezier(.2,.9,.3,1.4) both}
@keyframes pop{from{transform:scale(.3) rotate(-12deg);opacity:0}}
.to{font-size:13px;letter-spacing:.14em;text-transform:uppercase;color:var(--acc);font-weight:700;margin:14px 0 4px}
h1{font:600 32px/1.15 var(--display);margin:0 0 10px}
.from{color:var(--soft);margin:0}.from b{color:var(--ink)}
.card{background:var(--card);border-radius:24px;padding:22px 20px;margin-top:18px;box-shadow:0 18px 40px -26px rgba(60,20,40,.35)}
.card h2{font:600 19px var(--display);margin:0 0 12px}
.wish{position:relative;background:#fffdf8;border:1px solid #f0e2d6;border-radius:18px;padding:26px 22px 20px;font-size:18px;line-height:1.6;text-align:center;white-space:pre-wrap}
.wish:before{content:"“";position:absolute;top:-8px;left:14px;font:700 64px/1 Georgia,serif;color:var(--acc);opacity:.35}
.wish .sig{display:block;margin-top:12px;font-weight:600;color:var(--acc)}
.photo img{width:100%;border-radius:18px;display:block}
.btn{display:block;text-align:center;text-decoration:none;font:700 16px var(--body);padding:15px 18px;border-radius:16px;background:var(--acc);color:#fff;margin-top:12px;border:0;width:100%;cursor:pointer}
.btn.ghost{background:transparent;color:var(--acc);border:2px solid var(--acc)}
.items{display:grid;grid-template-columns:repeat(<?= count($items) ?>,1fr);gap:10px}
.items figure{margin:0;text-align:center;font-size:12.5px;color:var(--soft)}.items img{width:100%;aspect-ratio:1;object-fit:contain;background:#fff;border-radius:14px;border:1px solid #f1e6ea}
.gift{background:linear-gradient(135deg,var(--acc),#2e2329);color:#fff;text-align:center}
.gift h2{color:#fff;font-size:22px}.gift p{margin:6px 0;opacity:.92}
.code{display:flex;align-items:center;justify-content:center;gap:10px;margin:14px auto 6px;background:#fff;color:var(--ink);border-radius:14px;padding:12px 16px;font:700 22px var(--display);letter-spacing:.12em;max-width:300px;border:2px dashed var(--acc)}
.code button{font:600 13px var(--body);border:0;background:var(--bg2);color:var(--ink);border-radius:99px;padding:6px 12px;cursor:pointer;letter-spacing:0}
.gift .btn{background:#fff;color:var(--acc)}.small{font-size:12.5px;opacity:.85}
footer{text-align:center;margin-top:28px;color:var(--soft);font-size:13px}footer img{width:64px;height:64px;border-radius:50%;display:block;margin:0 auto 8px}
.conf{position:fixed;inset:0;pointer-events:none;overflow:hidden}.conf i{position:absolute;top:-12px;width:9px;height:14px;border-radius:2px;opacity:.85;animation:fall linear forwards}
@keyframes fall{to{transform:translateY(105vh) rotate(540deg)}}
@media (prefers-reduced-motion:reduce){.emo,.conf i{animation:none}.conf{display:none}}
</style></head><body>
<div class="conf" aria-hidden="true"></div>
<main>
 <section class="hero"><div class="emo" aria-hidden="true"><?= $emo ?></div>
  <p class="to"><?= $L('Untuk', 'For') ?> <?= $e($rf) ?></p><h1><?= $e($title) ?></h1>
  <p class="from"><?= $L('Hadiah istimewa daripada', 'A special gift from') ?> <b><?= $e($from) ?></b> 💐<br><?= $e($line) ?></p></section>
 <?php if ($wish !== ''): ?><section class="card"><h2>💌 <?= $L('Ayat dalam kad awak', 'The message in your card') ?></h2><div class="wish"><?= $e($wish) ?><?php if (!empty($o['sender'])): ?><span class="sig">— <?= $e($o['sender']) ?></span><?php endif ?></div></section><?php endif ?>
 <?php if ($hasPhoto): ?><section class="card photo"><h2>📸 <?= $L('Kenangan hari ni', "Today's memory") ?></h2><img src="<?= $e($ph) ?>" alt="<?= $e($L("Gambar $rf semasa menerima hadiah", "$rf receiving the gift")) ?>"><a class="btn ghost" href="<?= $e($ph) ?>&dl=1"><?= $L('Simpan gambar', 'Save photo') ?></a></section><?php endif ?>
 <section class="card"><h2>🎁 <?= $L('Dalam hadiah awak', 'In your gift') ?></h2><div class="items"><?php foreach ($items as [$t, $src]): ?><figure><img src="<?= $e($src) ?>" alt="<?= $e($t) ?>" loading="lazy" onerror="this.style.visibility='hidden'"><figcaption><?= $e($t) ?></figcaption></figure><?php endforeach ?></div></section>
 <?php if ($vcode): ?><section class="card gift"><h2><?= $L('Hadiah kecil daripada kami', 'A little gift from us') ?> 🌸</h2>
  <?php if ($used): ?><p><?= $L('Voucher awak dah digunakan. Terima kasih kerana memilih 100 Blooms!', 'Your voucher has been used. Thank you for choosing 100 Blooms!') ?> 💕</p><a class="btn" href="../<?= $en ? 'en/' : '' ?>"><?= $L('Lihat hadiah kami', 'See our gifts') ?></a>
  <?php else: ?><p><?= $L('Giliran awak pula buat orang tersayang tersenyum.', 'Now it’s your turn to make someone you love smile.') ?></p><p><b>RM10 off</b> <?= $L('untuk order pertama awak di 100 Blooms.', 'your first order at 100 Blooms.') ?></p>
  <div class="code"><span id="vc"><?= $e($vcode) ?></span><button type="button" id="cp"><?= $L('Salin', 'Copy') ?></button></div>
  <p class="small"><?= $L('Sah hingga', 'Valid until') ?> <?= $e(date('j/n/Y', strtotime($vends))) ?> · <?= $L('sekali guna', 'one-time use') ?></p>
  <a class="btn" href="../<?= $en ? 'en/' : '' ?>?v=<?= $e(rawurlencode($vcode)) ?>"><?= $L('Pilih hadiah sekarang', 'Choose a gift now') ?> →</a><?php endif ?></section><?php endif ?>
 <footer><img src="../assets/logo-icon.svg" alt="100 Blooms">100 Blooms · <?= $L('Hadiah lengkap, terus ke pintu dia', 'A complete gift, delivered to their door') ?><br><a href="../" style="color:var(--acc)">100blooms.my</a></footer>
</main>
<script>
(()=>{const b=document.getElementById('cp');if(b)b.onclick=async()=>{try{await navigator.clipboard.writeText(document.getElementById('vc').textContent);b.textContent='<?= $L('✓ Disalin', '✓ Copied') ?>'}catch(e){}};
 if(matchMedia('(prefers-reduced-motion:reduce)').matches)return;const c=document.querySelector('.conf'),cols=['<?= $acc ?>','#ffd36e','#f19bb2','#9fd3c7','#b9a7f0'];
 for(let i=0;i<40;i++){const x=document.createElement('i');x.style.left=Math.random()*100+'%';x.style.background=cols[i%cols.length];x.style.animationDuration=(2.6+Math.random()*2.4)+'s';x.style.animationDelay=(Math.random()*1.2)+'s';c.appendChild(x)}
 setTimeout(()=>c.remove(),6500)})();
</script></body></html>
