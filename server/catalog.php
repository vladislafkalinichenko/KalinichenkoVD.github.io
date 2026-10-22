<?php
declare(strict_types=1);
const CATALOG_OWNER='vlad-kalinichenko';
const CATALOG_EDITOR='mixlab-editorial';
function catalog_schema(PDO $pdo):void {
    // This migration runs before enabling foreign keys on a new SQLite connection.
    // SQLite requires that order when adding a REFERENCES column with a non-null default.
    $pdo->beginTransaction();
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS authors (id VARCHAR(100) PRIMARY KEY, name VARCHAR(160) NOT NULL, role VARCHAR(160) NOT NULL, bio TEXT NOT NULL)');
        $add=$pdo->prepare('INSERT INTO authors(id,name,role,bio) SELECT ?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM authors WHERE id=?)');
        foreach([
            [CATALOG_OWNER,'Калиниченко Владислав Дмитриевич','Автор и владелец MIXLAB','Развивает сайт о сведении музыки и публикует обновления проекта.'],
            [CATALOG_EDITOR,'Редакция MIXLAB','Редакционный профиль проекта','Профиль для справочных публикаций MIXLAB. Его ведёт Калиниченко Владислав Дмитриевич; это не отдельный человек.']
        ] as $a)$add->execute([...$a,$a[0]]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS news (id VARCHAR(100) PRIMARY KEY, title VARCHAR(160) NOT NULL, body TEXT NOT NULL, published_at VARCHAR(30) NOT NULL, source VARCHAR(100) NOT NULL, source_url VARCHAR(2048) NOT NULL, category VARCHAR(100) NOT NULL, author_id VARCHAR(100) NOT NULL DEFAULT 'vlad-kalinichenko' REFERENCES authors(id))");
        $columns=$pdo->query('PRAGMA table_info(news)')->fetchAll(PDO::FETCH_COLUMN,1);
        if(!in_array('author_id',$columns,true))$pdo->exec("ALTER TABLE news ADD COLUMN author_id VARCHAR(100) NOT NULL DEFAULT 'vlad-kalinichenko' REFERENCES authors(id)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS news_category_author ON news(category,author_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS news_publication_date ON news(published_at)');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $pdo->exec('PRAGMA foreign_keys=ON');
}
function catalog_seed(PDO $pdo):void {
    $name='catalog-20261022';
    $q=$pdo->prepare('SELECT value FROM app_meta WHERE name=?');$q->execute([$name]);if($q->fetchColumn())return;
    $seed=json_decode(file_get_contents(dirname(__DIR__).'/data/catalog-publications.json'),true,512,JSON_THROW_ON_ERROR);
    $pdo->beginTransaction();
    try {
        // Serialize first-run population and recheck its marker under the write lock.
        $pdo->exec("UPDATE app_meta SET value=value WHERE name='seeded'");$q->execute([$name]);
        if(!$q->fetchColumn()) {
            $check=$pdo->prepare('SELECT id FROM news WHERE id=?');
            foreach($seed['items'] as $item){$check->execute([$item['id']]);if(!$check->fetchColumn())insert_news($pdo,$item);}
            $q=$pdo->prepare('INSERT INTO app_meta(name,value) VALUES (?,?)');$q->execute([$name,gmdate('c')]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function catalog_authors(PDO $pdo):array {return $pdo->query('SELECT id,name,role,bio FROM authors ORDER BY name,id')->fetchAll();}
function catalog_metadata(PDO $pdo):array {
    $s=$pdo->query("SELECT COUNT(*) AS publications, COUNT(DISTINCT category) AS categories, MIN(published_at) AS first_date, MAX(published_at) AS last_date, SUM(CASE WHEN id<>'' AND title<>'' AND body<>'' AND published_at<>'' AND category<>'' AND source<>'' AND EXISTS(SELECT 1 FROM authors a WHERE a.id=news.author_id) THEN 1 ELSE 0 END) AS complete FROM news")->fetch();
    $s['authors']=(int)$pdo->query('SELECT COUNT(*) FROM authors')->fetchColumn();
    $s['bytes']=(int)$pdo->query('PRAGMA page_count')->fetchColumn()*(int)$pdo->query('PRAGMA page_size')->fetchColumn();
    $s['checked_at']=gmdate('c');return $s;
}
