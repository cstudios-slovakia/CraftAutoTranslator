<?php
$pdo = new PDO("mysql:host=db.r5.websupport.sk;port=3317;dbname=msMPeWeE", "WehwftqC", "vR7yW8@6Z*DRigw.hZeX");
$rows = $pdo->query('SELECT id, description, fail, timeUpdated, timePushed, progress FROM queue ORDER BY id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r) {
    $updated = $r['timeUpdated'] ? date('H:i:s', $r['timeUpdated']) : 'null';
    $pushed  = $r['timePushed']  ? date('H:i:s', $r['timePushed'])  : 'null';
    echo "id={$r['id']} fail={$r['fail']} pushed={$pushed} updated={$updated} progress={$r['progress']}% desc={$r['description']}\n";
}
if (empty($rows)) echo "queue is empty\n";
