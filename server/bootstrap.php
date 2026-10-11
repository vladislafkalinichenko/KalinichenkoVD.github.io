<?php
declare(strict_types=1);
function config(): array {
    $private = dirname(__DIR__, 2) . '/mixlab-private';
    $config = ['dsn' => 'sqlite:' . $private . '/news.sqlite', 'user' => '', 'password' => '', 'admin_password_hash' => '', 'private_dir' => $private];
    if (is_file($private . '/config.php')) $config = array_merge($config, require $private . '/config.php');
    foreach (['dsn'=>'MIXLAB_DB_DSN','user'=>'MIXLAB_DB_USER','password'=>'MIXLAB_DB_PASSWORD','admin_password_hash'=>'MIXLAB_ADMIN_PASSWORD_HASH'] as $key=>$env) {
        $value = getenv($env); if ($value !== false && $value !== '') $config[$key] = $value;
    }
    return $config;
}
function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $c = config();
    if (str_starts_with($c['dsn'], 'sqlite:')) {
        $dir = dirname(substr($c['dsn'], 7));
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Storage unavailable');
    }
    $pdo = new PDO($c['dsn'], $c['user'], $c['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') $pdo->exec('PRAGMA busy_timeout=5000');
    // Same fields are suitable for an RSS export in laboratory 4.
    $pdo->exec('CREATE TABLE IF NOT EXISTS news (id VARCHAR(100) PRIMARY KEY, title VARCHAR(160) NOT NULL, body TEXT NOT NULL, published_at VARCHAR(30) NOT NULL, source VARCHAR(100) NOT NULL, source_url VARCHAR(2048) NOT NULL, category VARCHAR(100) NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_meta (name VARCHAR(50) PRIMARY KEY, value VARCHAR(100) NOT NULL)');
    $marker = $pdo->query("SELECT value FROM app_meta WHERE name='seeded'")->fetchColumn();
    if (!$marker) {
        $seed = json_decode(file_get_contents(dirname(__DIR__).'/data/news.json'), true, 512, JSON_THROW_ON_ERROR);
        $pdo->beginTransaction();
        try {
            foreach ($seed['items'] as $item) {
                $check=$pdo->prepare('SELECT id FROM news WHERE id=?'); $check->execute([$item['id']]);
                if (!$check->fetchColumn()) insert_news($pdo, $item);
            }
            $pdo->exec("INSERT INTO app_meta (name,value) VALUES ('seeded','1')"); $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }
    return $pdo;
}
function insert_news(PDO $pdo, array $item): void {
    $q=$pdo->prepare('INSERT INTO news (id,title,body,published_at,source,source_url,category) VALUES (?,?,?,?,?,?,?)');
    $q->execute([$item['id'],$item['title'],$item['body'],$item['published_at'],$item['source'],$item['source_url'],$item['category']]);
}
