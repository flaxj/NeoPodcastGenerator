<?php

// Creates disposable, fictional preview data. Run with a new NEO_DATA directory.
declare(strict_types=1);
$store = require dirname(__DIR__).'/bootstrap.php';
if ($store->settings()) {
    fwrite(STDERR, "Preview requires an empty data directory.\n");
    exit(1);
}
$store->run('INSERT INTO settings(id,title,description,base_url,owner_name,owner_email,language,category,explicit,updated_at) VALUES(1,?,?,?,?,?,?,?,?,?)', [
    'The Open Frequency','Conversations with people who see things differently. New ideas, honest stories, and a little curiosity — in audio and video.','https://podcast.example.test','Alex Morgan','hello@example.test','en','Society & Culture',0,time()
]);
$store->run('INSERT INTO admins VALUES(1,?,?)', ['demo',password_hash('local-preview-password', PASSWORD_DEFAULT)]);
$fixture = $argv[1] ?? '';
if (!is_file($fixture)) {
    throw new RuntimeException('Supply an MP4 test fixture path.');
}
$pub = new Neo\Publishing($store);
$uploads = new Neo\Uploads($store);
$worker = new Neo\Worker($store);
foreach (['Finding the extraordinary in the everyday','A slower way to make something that matters','The art of asking better questions'] as $index => $title) {
    $id = $pub->save(null, ['title' => $title,'description' => "What happens when we pause long enough to really listen?\n\nIn this conversation, we explore creativity, community, and the small choices that shape a meaningful life. Join us for a fresh perspective and ideas you can take into your own week.",'number' => (string)(3 - $index)]);
    $u = $uploads->create($id, 'preview.mp4', filesize($fixture));
    $uploads->append($u['id'], 0, file_get_contents($fixture));
    $uploads->finish($u['id']);
    $worker->once();
    $pub->publish($id);
    $store->run('UPDATE episodes SET published_at=? WHERE id=?', [time() - $index * 604800,$id]);
}
echo "Preview data ready. Demo login: demo / local-preview-password\n";
