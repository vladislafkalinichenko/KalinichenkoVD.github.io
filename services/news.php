<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require dirname(__DIR__).'/server/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
try {
    $items = db()->query('SELECT n.*,a.name AS author_name FROM news n JOIN authors a ON a.id=n.author_id ORDER BY n.published_at DESC, n.id DESC')->fetchAll();
    $fragment = '<div class="news-list" data-news-feed aria-live="polite">';
    foreach ($items as $item) {
        $date = new DateTimeImmutable($item['published_at']);
        $label = $date->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');
        $id = $escape($item['id']);
        $fragment .= '<article class="news-item" id="news-'.$id.'"><h3><a href="#news-'.$id.'">'.$escape($item['title']).'</a></h3><time datetime="'.$escape($item['published_at']).'">'.$label.'</time><p class="small">'.$escape($item['category']).' · '.$escape($item['author_name']).'</p><p class="news-body">'.$escape($item['body']).'</p></article>';
    }
    if (!$items) $fragment .= '<p>Новостей пока нет.</p>';
    $fragment .= '</div>';
} catch (Throwable $e) {
    error_log('MIXLAB news page: '.$e->getMessage());
    http_response_code(503);
    $fragment = '<p class="notice">Не удалось загрузить новости. Попробуй позже.</p>';
}
$template = file_get_contents(__DIR__.'/news.html');
$a = strpos($template, '<!-- NEWS:START -->');
$b = strpos($template, '<!-- NEWS:END -->');
echo substr($template, 0, $a + strlen('<!-- NEWS:START -->')).$fragment.substr($template, $b);
