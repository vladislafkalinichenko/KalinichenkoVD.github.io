<?php
require dirname(__DIR__).'/server/exchange.php';
$state=rss_external(true);
echo 'RSS cache: '.count($state['items']).' items; '.($state['error']===''?'updated':'source unavailable; retained previous snapshot').PHP_EOL;
exit($state['error']===''?0:1);
