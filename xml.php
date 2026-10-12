<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require __DIR__.'/server/xml_catalog.php';
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
try {
    echo catalog_page(catalog_render(catalog_params($_GET)));
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo catalog_page('<p class="notice">'.htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p><a class="btn" href="xml.php">Сбросить фильтры</a>');
} catch (Throwable $e) {
    error_log('MIXLAB XML: '.$e->getMessage());
    http_response_code(503);
    echo catalog_page('<p class="notice">Не удалось загрузить заметки. Попробуй позже.</p>');
}
