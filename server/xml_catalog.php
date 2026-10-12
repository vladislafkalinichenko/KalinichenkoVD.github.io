<?php
declare(strict_types=1);
// Only fixed, repository-owned XML/XSD/XSL paths are loaded. User input is data, never XPath.
function catalog_params(array $input): array {
    $allowed = [
        'category' => ['', 'Основы', 'Тембр', 'Динамика', 'Пространство', 'Стерео', 'Проверка'],
        'level' => ['', 'Начальный', 'Средний', 'Продвинутый'],
        'sort' => ['date', 'title', 'minutes'], 'order' => ['descending', 'ascending']
    ];
    $out = [];
    foreach ($allowed as $key => $values) {
        $value = $input[$key] ?? $values[0];
        if (!is_string($value) || !in_array($value, $values, true)) throw new InvalidArgumentException('Проверь параметры отбора.');
        $out[$key] = $value;
    }
    $query = $input['q'] ?? '';
    if (!is_string($query) || !preg_match('//u', $query) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $query) || preg_match_all('/./us', $query) > 120) throw new InvalidArgumentException('Поисковый запрос должен содержать не более 120 символов.');
    $out['q'] = preg_replace('/\s+/u', ' ', trim($query));
    return $out;
}
function catalog_document(string $path): DOMDocument {
    $doc = new DOMDocument();
    $doc->resolveExternals = false;
    $doc->substituteEntities = false;
    if (!$doc->load($path, LIBXML_NONET) || $doc->doctype !== null) throw new RuntimeException('Invalid XML document');
    return $doc;
}
function catalog_render(array $params, bool $static = false): string {
    if (!class_exists('DOMDocument') || !class_exists('XSLTProcessor')) throw new RuntimeException('PHP DOM and XSL extensions required');
    $root = dirname(__DIR__);
    $previous = libxml_use_internal_errors(true);
    try {
        $xml = catalog_document($root.'/data/mixing-news.xml');
        if (!$xml->schemaValidate($root.'/data/mixing-news.xsd', LIBXML_NONET)) throw new RuntimeException('XML schema validation failed');
        $xsl = catalog_document($root.'/data/mixing-news.xsl');
        $processor = new XSLTProcessor();
        $processor->setSecurityPrefs(XSL_SECPREF_READ_FILE | XSL_SECPREF_WRITE_FILE | XSL_SECPREF_CREATE_DIRECTORY | XSL_SECPREF_READ_NETWORK | XSL_SECPREF_WRITE_NETWORK);
        if (!$processor->importStylesheet($xsl)) throw new RuntimeException('XSL import failed');
        foreach ($params as $key => $value) $processor->setParameter('', $key, $value);
        $processor->setParameter('', 'mode', $static ? 'static' : 'server');
        $processor->setParameter('', 'action', $static ? 'xml.html' : 'xml.php');
        $result = $processor->transformToXML($xml);
        if ($result === false) throw new RuntimeException('XSL transformation failed');
        return $result;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
}
function catalog_page(string $fragment): string {
    $template = file_get_contents(dirname(__DIR__).'/xml.html');
    $start = '<!-- CATALOG:START -->'; $end = '<!-- CATALOG:END -->';
    $a = strpos($template, $start); $b = strpos($template, $end);
    if ($a === false || $b === false || $b < $a) throw new RuntimeException('Catalog template markers missing');
    return substr($template,0,$a+strlen($start))."\n".$fragment."\n".substr($template,$b);
}
