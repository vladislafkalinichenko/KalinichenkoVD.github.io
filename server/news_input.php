<?php
declare(strict_types=1);
// The RSS form and the administration API publish to the same catalogue.
function news_create(PDO $pdo,array $input):array {
    $fields=[];
    foreach(['title'=>160,'body'=>10000,'category'=>100] as $name=>$max){
        $value=$input[$name]??($name==='category'?'Обновления':null);
        if(!is_string($value)||!preg_match('//u',$value)||trim($value)===''||preg_match_all('/./us',$value)>$max)throw new InvalidArgumentException('Заполни заголовок (до 160 символов), текст (до 10 000) и категорию (до 100).');
        $fields[$name]=trim($value);
    }
    $author=$input['author_id']??CATALOG_OWNER;
    if(!is_string($author))throw new InvalidArgumentException('Выбери существующего автора.');
    $q=$pdo->prepare('SELECT id FROM authors WHERE id=?');$q->execute([$author]);
    if(!$q->fetchColumn())throw new InvalidArgumentException('Выбери существующего автора.');
    $item=['id'=>bin2hex(random_bytes(16)),...$fields,'published_at'=>gmdate('Y-m-d\TH:i:s\Z'),'source'=>'Свой сайт','source_url'=>'','author_id'=>$author];
    insert_news($pdo,$item);return $item;
}
