<?php
/**
 * transcode.php — Job-Queue-Endpunkt für die Video-Transkodierung per NAS.
 *
 * Der NAS-Worker bedient diese Endpunkte (alle mit ?key=<transcode_secret>):
 *
 *   GET  ?action=next                → nächsten Job holen (JSON) | 204 kein Job
 *   POST ?action=result             → job_id, duration, file=@out.mp4 (multipart)
 *   POST ?action=fail               → job_id, error
 *
 * Zusätzlich (für Tests / interne Nutzung durch push2ig):
 *   POST ?action=enqueue            → source_url [, kind=reel|story] [, max_duration=90]
 *   GET  ?action=status&job_id=…    → Status eines Jobs
 *   GET  ?action=list               → letzte Jobs (Debug)
 *
 * Ergebnis-Videos landen öffentlich unter  …/push2ig/media/<datei>.mp4
 * (immer online, damit Instagram sie abrufen kann).
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');   // Fehler nie ins JSON mischen
header('Content-Type: application/json; charset=utf-8');

// Config: bevorzugt außerhalb des Web-Roots, Fallback lokal (s. push2ig.php)
$configFile = dirname(__DIR__, 2) . '/push2ig-config.php';
if (!is_file($configFile)) {
    $configFile = __DIR__ . '/config.php';
}
$config = @include $configFile;
if (!is_array($config)) {
    http_response_code(500);
    echo json_encode(['error' => 'config']);
    exit;
}

// ── Auth: gemeinsames Geheimnis ────────────────────────────────────
// Key aus X-Api-Key-Header (NAS-Worker) ODER ?key= / POST-key (Fallback).
$expected = (string) ($config['transcode_secret'] ?? '');
$given    = (string) ($_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? $_POST['key'] ?? '');
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

// ── DB ─────────────────────────────────────────────────────────────
try {
    $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db']);
    exit;
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `push2ig_transcode_jobs` (
        `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `source_id`    VARCHAR(64)  DEFAULT NULL,   -- Pixelfed-Status-ID (Herkunft)
        `source_url`   VARCHAR(768) NOT NULL,       -- URL des Quellvideos
        `kind`         VARCHAR(8)   NOT NULL DEFAULT 'reel',
        `max_duration` INT UNSIGNED NOT NULL DEFAULT 90,
        `status`       VARCHAR(12)  NOT NULL DEFAULT 'pending',
        `result_file`  VARCHAR(128) DEFAULT NULL,
        `result_url`   VARCHAR(768) DEFAULT NULL,
        `duration`     INT UNSIGNED DEFAULT NULL,
        `error`        VARCHAR(512) DEFAULT NULL,
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$mediaDir = __DIR__ . '/media';
if (!is_dir($mediaDir)) {
    @mkdir($mediaDir, 0755, true);
}
// Kanonische Basis-URL aus der Config (immun gegen Host-Header-Spielereien);
// HTTP_HOST nur als Fallback, falls public_base_url nicht gesetzt ist.
$baseUrl = rtrim((string) ($config['public_base_url'] ?? ''), '/');
if ($baseUrl === '') {
    $baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
             . dirname(strtok($_SERVER['REQUEST_URI'] ?? '/transcode.php', '?'));
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ── Endpunkte ──────────────────────────────────────────────────────
switch ($action) {

    case 'next':
        // Hängengebliebene Jobs (>1 h in 'processing') zurück in die Queue
        $pdo->exec("UPDATE push2ig_transcode_jobs SET status='pending'
                    WHERE status='processing' AND updated_at < (NOW() - INTERVAL 1 HOUR)");
        // Ältesten offenen Job atomar beanspruchen
        $pdo->beginTransaction();
        $job = $pdo->query("SELECT * FROM push2ig_transcode_jobs
                            WHERE status='pending' ORDER BY id ASC LIMIT 1 FOR UPDATE")->fetch();
        if (!$job) {
            $pdo->commit();
            http_response_code(204);   // kein Job
            exit;
        }
        $pdo->prepare("UPDATE push2ig_transcode_jobs SET status='processing', updated_at=NOW() WHERE id=?")
            ->execute([$job['id']]);
        $pdo->commit();
        out([
            'job_id'       => (string) $job['id'],
            'source_url'   => $job['source_url'],
            'kind'         => $job['kind'],
            'max_duration' => (int) $job['max_duration'],
        ]);
        break;

    case 'result':
        $jobId = (int) ($_POST['job_id'] ?? 0);
        $dur   = (int) ($_POST['duration'] ?? 0);
        if ($jobId <= 0 || empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            out(['error' => 'job_id oder file fehlt'], 400);
        }
        $max = (int) ($config['transcode_max_bytes'] ?? 314572800);
        if (($_FILES['file']['size'] ?? PHP_INT_MAX) > $max) {
            out(['error' => 'Datei zu groß'], 413);
        }
        $job = $pdo->prepare("SELECT * FROM push2ig_transcode_jobs WHERE id=? LIMIT 1");
        $job->execute([$jobId]);
        if (!$job->fetch()) {
            out(['error' => 'unbekannter job_id'], 404);
        }
        $fname = $jobId . '_' . bin2hex(random_bytes(6)) . '.mp4';
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $mediaDir . '/' . $fname)) {
            out(['error' => 'Speichern fehlgeschlagen'], 500);
        }
        $url = $baseUrl . '/media/' . $fname;
        $pdo->prepare("UPDATE push2ig_transcode_jobs
                       SET status='done', result_file=?, result_url=?, duration=?, error=NULL, updated_at=NOW()
                       WHERE id=?")
            ->execute([$fname, $url, $dur > 0 ? $dur : null, $jobId]);
        out(['ok' => true, 'result_url' => $url]);
        break;

    case 'fail':
        $jobId = (int) ($_POST['job_id'] ?? 0);
        $err   = mb_substr((string) ($_POST['error'] ?? 'unbekannt'), 0, 500);
        if ($jobId <= 0) {
            out(['error' => 'job_id fehlt'], 400);
        }
        $pdo->prepare("UPDATE push2ig_transcode_jobs SET status='failed', error=?, updated_at=NOW() WHERE id=?")
            ->execute([$err, $jobId]);
        out(['ok' => true]);
        break;

    case 'enqueue':
        $src = trim((string) ($_POST['source_url'] ?? $_GET['source_url'] ?? ''));
        if ($src === '' || !preg_match('#^https?://#i', $src)) {
            out(['error' => 'source_url (http/https) erforderlich'], 400);
        }
        $kind = ($_POST['kind'] ?? $_GET['kind'] ?? 'reel') === 'story' ? 'story' : 'reel';
        $md   = (int) ($_POST['max_duration'] ?? $_GET['max_duration'] ?? 90);
        $srcId = (string) ($_POST['source_id'] ?? $_GET['source_id'] ?? '') ?: null;
        $stmt = $pdo->prepare("INSERT INTO push2ig_transcode_jobs (source_id, source_url, kind, max_duration)
                               VALUES (?, ?, ?, ?)");
        $stmt->execute([$srcId, $src, $kind, $md > 0 ? $md : 90]);
        out(['ok' => true, 'job_id' => (string) $pdo->lastInsertId()]);
        break;

    case 'status':
        $jobId = (int) ($_GET['job_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id,status,kind,duration,result_url,error,created_at,updated_at
                               FROM push2ig_transcode_jobs WHERE id=? LIMIT 1");
        $stmt->execute([$jobId]);
        $row = $stmt->fetch();
        $row ? out($row) : out(['error' => 'unbekannt'], 404);
        break;

    case 'list':
        $rows = $pdo->query("SELECT id,status,kind,source_url,result_url,duration,error,updated_at
                             FROM push2ig_transcode_jobs ORDER BY id DESC LIMIT 20")->fetchAll();
        out(['jobs' => $rows]);
        break;

    default:
        out(['error' => 'unbekannte action'], 400);
}
