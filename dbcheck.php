<?php
/**
 * dbcheck.php – Prüft NUR die MySQL-Verbindung.
 *
 *   php dbcheck.php
 *
 * Gibt bei Erfolg "OK" + Serverversion aus und zeigt, ob die
 * push2ig-Tabellen schon existieren. Verändert nichts.
 */

declare(strict_types=1);
error_reporting(E_ALL);

$config = require __DIR__ . '/config.php';
$db = $config['db'];

echo "── Verbinde mit MySQL ({$db['user']}@{$db['host']}/{$db['name']}) …\n";

try {
    $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    echo "   FEHLER: " . $e->getMessage() . "\n";
    echo "   → Zugangsdaten im 'db'-Block von config.php prüfen.\n";
    echo "     Auf All-Inkl ist 'host' meist 'localhost' (nur wenn das Script DORT läuft).\n";
    exit(1);
}

$version = $pdo->query('SELECT VERSION()')->fetchColumn();
echo "   OK – Serverversion: $version\n";

// Vorhandene push2ig-Tabellen anzeigen (nur Info, legt nichts an)
$tables = $pdo->query("
    SELECT TABLE_NAME FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'push2ig\\_%'
")->fetchAll(PDO::FETCH_COLUMN);

if ($tables) {
    echo "   Vorhandene Tabellen: " . implode(', ', $tables) . "\n";
} else {
    echo "   Noch keine push2ig-Tabellen – werden beim ersten seed.php/push2ig.php-Lauf angelegt.\n";
}

echo "── Fertig.\n";
