<?php
/**
 * Seed – Bestehende Pixelfed-Posts als "bereits gepostet" markieren
 *
 * EINMAL vor dem ersten Cronjob-Lauf ausführen:
 *   php seed.php
 *
 * Lädt alle bisherigen Media-Posts vom Pixelfed-Account und trägt sie
 * als 'seeded' in die DB ein, OHNE sie auf Instagram zu posten.
 * Danach postet push2ig.php nur noch neue Posts.
 */

declare(strict_types=1);
error_reporting(E_ALL);

require __DIR__ . '/push2ig.php';   // lädt auch die Config (inkl. Web-Root-Suchpfad)

echo "── Tabellen vorbereiten\n";
ensureTables();

echo "── Pixelfed-Posts laden und als geseedet markieren\n";

$maxId   = null;
$total   = 0;
$skipped = 0;
$pageSize = (int) ($config['batch_size'] ?? 20);

do {
    $page = fetchStatusesPage($maxId, $pageSize);
    if (!$page) {
        break;
    }

    $lastId = null;
    foreach ($page as $status) {
        $id        = (string) ($status['id'] ?? '');
        $lastId    = $id;
        $url       = (string) ($status['url'] ?? '');
        $createdAt = $status['created_at'] ?? date('c');

        if ($id === '') {
            continue;
        }
        if (getRecord($id) !== null) {
            $skipped++;
            continue;
        }
        markSeeded($id, $url, $createdAt);
        $total++;
    }

    $maxId = $lastId;
    echo "   $total Posts markiert...\r";

} while ($maxId !== null);

echo "\n── Fertig: $total Posts als geseedet eingetragen, $skipped waren schon drin\n";
echo "   Du kannst jetzt den Cronjob einrichten.\n";
