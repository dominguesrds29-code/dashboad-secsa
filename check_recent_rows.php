<?php
$db = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '');
$dbs = ['efetivosj', 'sgp_dtceasj'];

foreach ($dbs as $dbname) {
    echo "=== DB: $dbname ===\n";
    $tables = $db->query("SHOW TABLES FROM `$dbname`")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $cols = $db->query("SHOW COLUMNS FROM `$dbname`.`$t`")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('updated_at', $cols)) {
            $recent = $db->query("SELECT * FROM `$dbname`.`$t` WHERE updated_at >= CURDATE() - INTERVAL 1 DAY ORDER BY updated_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            echo "  Table $t has " . count($recent) . " recently updated rows:\n";
            foreach ($recent as $r) {
                echo "    " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    }
}
