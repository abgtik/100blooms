<?php
// English version of the order page: the same page as ../index.html, switched to English.
// Google indexes it separately (hreflang), so English searches can find 100 Blooms.
header('Content-Type: text/html; charset=utf-8');
$h = (string)file_get_contents(dirname(__DIR__) . '/index.html');
$h = preg_replace('/<html lang="ms">/', '<html lang="en">', $h, 1);
$h = preg_replace('/<head>/i', "<head>\n<base href=\"../\">\n<script>window.FORCE_LANG='en'</script>", $h, 1);
$t = '100 Blooms · Bouquet + Secret Recipe Cake + Balloon · Combo RM128';
$d = 'Combo Jimat RM128: bouquet, Secret Recipe cake, balloon, polaroid and wishcard, delivered to their door around KL, Putrajaya and the Klang Valley. Birthday, anniversary, graduation and more. #since2018';
$h = preg_replace('#<title>[^<]*</title>#', '<title>' . $t . '</title>', $h, 1);
$h = preg_replace('#<meta name="description" content="[^"]*">#', '<meta name="description" content="' . $d . '">', $h, 1);
$h = preg_replace('#<meta property="og:title" content="[^"]*">#', '<meta property="og:title" content="' . $t . '">', $h, 1);
$h = preg_replace('#<meta property="og:description" content="[^"]*">#', '<meta property="og:description" content="A complete gift delivered to their door: bouquet, Secret Recipe cake, balloon, polaroid and wishcard.">', $h, 1);
$h = str_replace(['<link rel="canonical" href="https://100blooms.my/">', '<meta property="og:url" content="https://100blooms.my/">'],
  ['<link rel="canonical" href="https://100blooms.my/en/">', '<meta property="og:url" content="https://100blooms.my/en/">'], $h);
echo $h;
