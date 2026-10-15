<?php
// Sales HQ order page: the same page customers use (../index.html), switched to "Sales HQ" mode.
// The team member signs in with their own admin account; the order is saved under their name and
// ends with a WhatsApp invoice (with a payment link) for the buyer instead of the payment page.
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
$h = (string)file_get_contents(dirname(__DIR__) . '/index.html');
$h = preg_replace('/<head>/i', "<head>\n<base href=\"../\">\n<meta name=\"robots\" content=\"noindex,nofollow\">\n<script>window.HQ_MODE=1</script>", $h, 1);
$h = preg_replace('#<title>[^<]*</title>#', '<title>100 Blooms · Order Sales HQ</title>', $h, 1);
echo $h;
