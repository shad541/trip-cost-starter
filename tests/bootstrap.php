<?php

require_once __DIR__ . '/../lib/billing.php';

function seeded_database(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->exec('PRAGMA foreign_keys = ON');
    foreach (['schema.sql', 'seed.sql'] as $file) {
        $db->exec(file_get_contents(__DIR__ . '/../data/' . $file));
    }
    return $db;
}
