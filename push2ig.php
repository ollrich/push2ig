<?php
/**
 * push2ig – Zwei-Wege-Sync zwischen Pixelfed und Instagram
 *
 * Läuft als Cronjob (URL oder CLI) auf Shared Hosting (All-Inkl) mit MySQL.
 *
 * Pixelfed → Instagram (Opt-in per Hashtag, Control-Tag wird danach entfernt):
 *  - #igpost  + 1 Bild      → Feed-Post (Caption + Alt-Text)
 *  - #igpost  + 2–10 Bilder → Carousel (Alt-Text je Bild)
 *  - #igpost  + Video       → Reel   (async über NAS-Transkodierung, ≤90 s)
 *  - #igstory + Bild        → Story
 *  - #igstory + Video       → Video-Story (async über NAS, ≤60 s)
 *  - Nicht-JPEG-Bilder (PNG/WebP/GIF) werden per GD nach JPEG konvertiert
 *
 * Instagram → Pixelfed:
 *  - aktive IG-Stories (Bild + Video) → Pixelfed-Stories (Dedup je Story-ID)
 *  - Einschränkung der IG-API: Reshares (geteilte Reels/Posts) und Stories
 *    mit Musik-Sticker/interaktiven Elementen werden NICHT ausgeliefert
 *
 * Robustheit:
 *  - Status-/Retry-Tracking je Post; Video-Statusmaschine mit 48-h-Timeout
 *  - Lock gegen überlappende Cron-Läufe; Backoff-Retries bei 429/5xx
 *  - Beide Tokens (IG 60 T., Pixelfed ~1 J.) werden automatisch refreshed
 *  - Token-Health-Check je Lauf + E-Mail-Alarm bei Widerruf (Error 190)
 *
 * Wartungs-CLI: import-stories · refresh-pixelfed · set-ig-token [TOKEN] ·
 *   set-pixelfed-token · requeue-failed · reprocess <id> · test-alert
 *
 * Setup, Config-Referenz und Cron: siehe README.md
 * NAS-Transkodier-Worker: siehe video-transcoding-nas.md + transcode.php
 */

declare(strict_types=1);
error_reporting(E_ALL);
// Fehler nie in die Web-Antwort schreiben (Cron-Antwort bleibt sauber; Log reicht)
if (php_sapi_name() !== 'cli') {
    ini_set('display_errors', '0');
}

// ─── Config ────────────────────────────────────────────────────────
/**
 * Config laden – bevorzugt von AUSSERHALB des Web-Roots (2 Ebenen über diesem
 * Ordner, z. B. /www/htdocs/wXXXX/push2ig-config.php), damit die Secrets selbst
 * bei einer PHP-Fehlkonfiguration nie über HTTP ausgeliefert werden können.
 * Fallback: config.php im Script-Ordner (durch .htaccess geschützt).
 */
function p2iLoadConfig(): array {
    $outside = dirname(__DIR__, 2) . '/push2ig-config.php';
    $local   = __DIR__ . '/config.php';
    $file    = is_file($outside) ? $outside : $local;
    $cfg     = @include $file;
    if (!is_array($cfg)) {
        http_response_code(500);
        exit("Config nicht ladbar: $file\n");
    }
    return $cfg;
}
$config = p2iLoadConfig();

$GLOBALS['p2i_last_error'] = null;

// ─── Logger ────────────────────────────────────────────────────────
function p2iLog(string $msg, string $level = 'INFO'): void {
    global $config;
    static $rotated = false;

    $file = $config['log_file'];

    if (!$rotated) {
        $rotated  = true;
        $maxBytes = $config['log_max_bytes'] ?? 5_000_000;
        if ($maxBytes > 0 && is_file($file) && filesize($file) > $maxBytes) {
            @rename($file, $file . '.1');
        }
    }

    $line = date('Y-m-d H:i:s') . " [$level] $msg\n";
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    if (php_sapi_name() === 'cli') {
        echo $line;
    }
}

/** Tokens/Secrets aus Strings entfernen, bevor sie ins Log geschrieben werden. */
function redactSecrets(string $s): string {
    // Query-Form:  key=wert
    $s = preg_replace('/((?:access_token|refresh_token|client_secret)=)[^&\s"]+/i', '$1***', $s);
    // JSON-Form:   "key":"wert"
    $s = preg_replace('/("(?:access_token|refresh_token|client_secret)"\s*:\s*")[^"]+/i', '$1***', $s);
    return $s;
}

function lastError(): ?string {
    return $GLOBALS['p2i_last_error'] ?? null;
}

// ─── DB ────────────────────────────────────────────────────────────
function getDb(): PDO {
    global $config;
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function ensureTables(): void {
    $db = getDb();
    // Post-State: ein Eintrag je Pixelfed-Status
    $db->exec("
        CREATE TABLE IF NOT EXISTS `push2ig_posts` (
            `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `source_id`     VARCHAR(64)  NOT NULL UNIQUE,
            `source_url`    VARCHAR(512) DEFAULT NULL,
            `ig_creation_id` VARCHAR(64) DEFAULT NULL,
            `ig_media_id`   VARCHAR(64)  DEFAULT NULL,
            `status`        VARCHAR(16)  NOT NULL DEFAULT 'posted',
            `target`        VARCHAR(8)   NOT NULL DEFAULT 'feed',
            `attempts`      INT UNSIGNED NOT NULL DEFAULT 0,
            `last_error`    VARCHAR(512) DEFAULT NULL,
            `created_at`    DATETIME     NOT NULL,
            `posted_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_created` (`created_at`),
            INDEX `idx_status`  (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Migration für bereits bestehende Installationen: target-Spalte nachrüsten
    if (!columnExists('push2ig_posts', 'target')) {
        $db->exec("ALTER TABLE `push2ig_posts` ADD COLUMN `target` VARCHAR(8) NOT NULL DEFAULT 'feed' AFTER `status`");
    }
    // Key/Value-Store für Token & Co.
    $db->exec("
        CREATE TABLE IF NOT EXISTS `push2ig_settings` (
            `k`          VARCHAR(64) NOT NULL PRIMARY KEY,
            `v`          TEXT        DEFAULT NULL,
            `updated_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Transkodier-Jobs (Video → Reel; wird auch von transcode.php genutzt)
    $db->exec("
        CREATE TABLE IF NOT EXISTS `push2ig_transcode_jobs` (
            `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `source_id`    VARCHAR(64)  DEFAULT NULL,
            `source_url`   VARCHAR(768) NOT NULL,
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
    // Rückrichtung: importierte Instagram-Stories (Dedup)
    $db->exec("
        CREATE TABLE IF NOT EXISTS `push2ig_imported_stories` (
            `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `ig_story_id` VARCHAR(64)  NOT NULL UNIQUE,
            `pf_media_id` VARCHAR(64)  DEFAULT NULL,
            `status`      VARCHAR(16)  NOT NULL DEFAULT 'imported',
            `attempts`    INT UNSIGNED NOT NULL DEFAULT 0,
            `last_error`  VARCHAR(512) DEFAULT NULL,
            `imported_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function getImportedStory(string $igStoryId): ?array {
    $stmt = getDb()->prepare("SELECT status, attempts FROM push2ig_imported_stories WHERE ig_story_id = ? LIMIT 1");
    $stmt->execute([$igStoryId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function recordImportedStory(string $igStoryId, ?string $pfMediaId, string $status, ?string $error): void {
    $bump = ($status === 'imported') ? 0 : 1;
    $stmt = getDb()->prepare("
        INSERT INTO push2ig_imported_stories (ig_story_id, pf_media_id, status, attempts, last_error)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            pf_media_id = VALUES(pf_media_id),
            status      = VALUES(status),
            attempts    = attempts + $bump,
            last_error  = VALUES(last_error),
            imported_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$igStoryId, $pfMediaId, $status, $bump, $error !== null ? mb_substr($error, 0, 500) : null]);
}

function columnExists(string $table, string $column): bool {
    $stmt = getDb()->prepare("
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function getSetting(string $key): ?string {
    $stmt = getDb()->prepare("SELECT v FROM push2ig_settings WHERE k = ? LIMIT 1");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : $v;
}

function setSetting(string $key, ?string $value): void {
    $stmt = getDb()->prepare("
        INSERT INTO push2ig_settings (k, v) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$key, $value]);
}

function getRecord(string $sourceId): ?array {
    $stmt = getDb()->prepare("SELECT status, attempts FROM push2ig_posts WHERE source_id = ? LIMIT 1");
    $stmt->execute([$sourceId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Soll dieser Post (anhand seines DB-Zustands) verarbeitet werden?
 *  - kein Eintrag                       → ja, neu posten
 *  - status = failed & attempts < max   → ja (transienter Fehler)
 *  - sonst (seeded/posted/skipped/…)    → nein
 */
function shouldProcess(?array $row, int $maxAttempts): bool {
    if ($row === null) {
        return true;
    }
    if ($row['status'] === 'failed' && (int) $row['attempts'] < $maxAttempts) {
        return true;
    }
    return false;
}

// ISO-8601 → MySQL DATETIME
function normalizeDatetime(string $value): string {
    $ts = strtotime($value);
    return date('Y-m-d H:i:s', $ts !== false ? $ts : time());
}

function markSeeded(string $sourceId, string $sourceUrl, string $createdAt): void {
    $stmt = getDb()->prepare("
        INSERT IGNORE INTO push2ig_posts (source_id, source_url, created_at, status, attempts)
        VALUES (?, ?, ?, 'seeded', 0)
    ");
    $stmt->execute([$sourceId, $sourceUrl, normalizeDatetime($createdAt)]);
}

/**
 * Ergebnis eines Post-Versuchs festhalten (Upsert).
 * Bei erneutem Versuch wird attempts hochgezählt statt zu duplizieren.
 * 'skipped' setzt attempts NICHT hoch und wird nie erneut versucht.
 */
function recordAttempt(string $sourceId, string $sourceUrl, string $createdAt, ?string $creationId, ?string $mediaId, string $status, ?string $error, string $target = 'feed'): void {
    $bumpAttempts = ($status === 'skipped') ? 0 : 1;
    $stmt = getDb()->prepare("
        INSERT INTO push2ig_posts (source_id, source_url, created_at, ig_creation_id, ig_media_id, status, target, attempts, last_error)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            source_url     = VALUES(source_url),
            ig_creation_id = VALUES(ig_creation_id),
            ig_media_id    = VALUES(ig_media_id),
            status         = VALUES(status),
            target         = VALUES(target),
            attempts       = attempts + $bumpAttempts,
            last_error     = VALUES(last_error),
            posted_at      = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $sourceId,
        $sourceUrl,
        normalizeDatetime($createdAt),
        $creationId,
        $mediaId,
        $status,
        $target,
        $bumpAttempts,
        $error !== null ? mb_substr($error, 0, 500) : null,
    ]);
}

// ─── Lock (verhindert überlappende Läufe) ─────────────────────────
function acquireLock(): mixed {
    global $config;
    $lockFile = $config['lock_file'] ?? (__DIR__ . '/push2ig.lock');
    $fh = fopen($lockFile, 'c');
    if ($fh === false) {
        p2iLog("Lockdatei $lockFile nicht öffenbar, fahre ohne Lock fort", 'WARN');
        return null;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return false;
    }
    return $fh;
}

// ─── HTTP Client ───────────────────────────────────────────────────
/**
 * Generischer HTTP-Request mit Backoff-Retries.
 * Rückgabe: ['code'=>int, 'body'=>mixed (decoded JSON|raw string), 'error'=>?string]
 *
 * Retry-Politik (wie bluesky-mirror):
 *  - 429 → immer wiederholbar (hatte garantiert keinen Effekt)
 *  - 5xx / Verbindungsfehler → nur bei GET wiederholen (POST könnte
 *    serverseitig angekommen sein und würde sonst doppelt ausgeführt)
 */
function httpRequest(string $method, string $url, array $query = [], ?array $postFields = null, array $headers = []): array {
    global $config;
    $timeout     = (int) ($config['http_timeout'] ?? 30);
    $maxAttempts = max(1, (int) ($config['retry_count'] ?? 2) + 1);
    $retryDelay  = max(0, (int) ($config['retry_delay'] ?? 3));

    if ($query) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
    $logUrl = redactSecrets($url);
    $debug  = !empty($config['debug_log']);

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields ?? []));
        } elseif ($method === 'PUT' || $method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($postFields !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
            }
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        $isLast = ($attempt === $maxAttempts);

        // Debug: vollständige API-Antwort mitschreiben (Token redacted)
        if ($debug) {
            $snippet = redactSecrets(mb_substr((string) $response, 0, 1500));
            p2iLog("API $method $logUrl → HTTP $httpCode"
                . ($curlErr !== '' ? " curl: $curlErr" : '')
                . ($snippet !== '' ? " body: $snippet" : ''), 'DEBUG');
        }

        if ($curlErr !== '') {
            $GLOBALS['p2i_last_error'] = "cURL: $curlErr";
            if (!$isLast && $method === 'GET') {
                p2iLog("cURL-Fehler bei $logUrl (Versuch $attempt/$maxAttempts), neuer Versuch: $curlErr", 'WARN');
                if ($retryDelay > 0) { sleep($retryDelay); }
                continue;
            }
            p2iLog("cURL error bei $logUrl: $curlErr", 'ERROR');
            return ['code' => 0, 'body' => null, 'error' => $curlErr];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $GLOBALS['p2i_last_error'] = "HTTP $httpCode: " . redactSecrets(mb_substr((string) $response, 0, 480));
            $retryable = ($httpCode === 429) || ($method === 'GET' && $httpCode >= 500);
            if ($retryable && !$isLast) {
                p2iLog("HTTP $httpCode von $logUrl (Versuch $attempt/$maxAttempts), neuer Versuch: "
                    . redactSecrets(mb_substr((string) $response, 0, 300)), 'WARN');
                if ($retryDelay > 0) { sleep($retryDelay); }
                continue;
            }
            p2iLog("HTTP $httpCode von $logUrl: " . redactSecrets(mb_substr((string) $response, 0, 800)), 'ERROR');
            $decoded = json_decode((string) $response, true);
            // Instagram code 190 = Token ungültig/widerrufen → klarer Hinweis ins Log
            if (is_array($decoded) && (int) ($decoded['error']['code'] ?? 0) === 190) {
                p2iLog("⚠ INSTAGRAM-TOKEN UNGÜLTIG (code 190) – Neu-Autorisierung nötig: "
                    . "neuen Token holen, in config.php eintragen, dann 'php push2ig.php set-ig-token'", 'ERROR');
            }
            return ['code' => $httpCode, 'body' => $decoded, 'error' => "HTTP $httpCode"];
        }

        $GLOBALS['p2i_last_error'] = null;
        return ['code' => $httpCode, 'body' => json_decode((string) $response, true), 'error' => null];
    }

    return ['code' => 0, 'body' => null, 'error' => 'unreachable'];
}

// ─── Pixelfed Token (DB-backed, Auto-Refresh) ──────────────────────
/** Aktuellen Pixelfed-Token aus der DB holen; beim ersten Mal aus der Config seeden. */
function getPixelfedToken(): string {
    global $config;
    $token = getSetting('pf_access_token');
    if ($token === null || $token === '') {
        $token = (string) $config['pixelfed']['token'];
        setSetting('pf_access_token', $token);
        setSetting('pf_refresh_token', (string) ($config['pixelfed']['refresh_token'] ?? ''));
        // Aktueller Token ist ~1 Jahr gültig (heute ausgestellt) → konservativ setzen.
        // KEIN Sofort-Refresh erzwingen (anders als beim IG-Token).
        setSetting('pf_token_expires_at', date('Y-m-d H:i:s', time() + 360 * 86400));
        p2iLog("Pixelfed-Token aus Config in DB übernommen");
    }
    return $token;
}

/**
 * Pixelfed-Token automatisch refreshen, wenn die Restlaufzeit unter den
 * Schwellwert fällt. Laravel Passport rotiert dabei den Refresh-Token
 * (Single-Use) → der NEUE refresh_token wird gespeichert.
 * Gibt die Token-Restlaufzeit in Tagen zurück (für die Lauf-Summary).
 */
function refreshPixelfedTokenIfNeeded(bool $force = false): ?int {
    global $config;
    $thresholdDays = (int) ($config['pixelfed_token_refresh_threshold_days'] ?? 30);
    $expiresAt = getSetting('pf_token_expires_at');
    $expiresTs = $expiresAt ? strtotime($expiresAt) : 0;
    $daysLeft  = $expiresTs ? (int) floor(($expiresTs - time()) / 86400) : -1;

    if (!$force && $expiresTs && ($expiresTs - time()) > $thresholdDays * 86400) {
        return $daysLeft;   // noch gültig, kein Refresh – nicht loggen (Leerlauf-Spam)
    }

    $clientId     = (string) ($config['pixelfed']['client_id'] ?? '');
    $clientSecret = (string) ($config['pixelfed']['client_secret'] ?? '');
    $refreshToken = getSetting('pf_refresh_token');
    if ($refreshToken === null || $refreshToken === '') {
        $refreshToken = (string) ($config['pixelfed']['refresh_token'] ?? '');
    }
    if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
        p2iLog("Pixelfed-Token-Refresh übersprungen: client_id/client_secret/refresh_token fehlen in der Config", 'WARN');
        return $daysLeft >= 0 ? $daysLeft : null;
    }

    p2iLog($force
        ? "Pixelfed-Token Refresh erzwungen"
        : "Pixelfed-Token läuft bald ab ($daysLeft Tage) – Refresh-Versuch");

    $url = rtrim($config['pixelfed']['instance'], '/') . '/oauth/token';
    $res = httpRequest('POST', $url, [], [
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
    ]);

    if ($res['error'] === null && !empty($res['body']['access_token'])) {
        $newToken   = (string) $res['body']['access_token'];
        $newRefresh = (string) ($res['body']['refresh_token'] ?? $refreshToken);
        $expiresIn  = (int) ($res['body']['expires_in'] ?? 31536000); // ~1 Jahr
        setSetting('pf_access_token', $newToken);
        setSetting('pf_refresh_token', $newRefresh);
        setSetting('pf_token_expires_at', date('Y-m-d H:i:s', time() + $expiresIn));
        $daysLeft = (int) floor($expiresIn / 86400);
        p2iLog("Pixelfed-Token refreshed, neue Laufzeit $daysLeft Tage");
    } else {
        // Kein Abbruch – mit bestehendem Token weiterarbeiten, solange er gültig ist.
        // Bei dauerhaftem Fehlschlag: OAuth-Flow neu durchlaufen (siehe README).
        p2iLog("Pixelfed-Token-Refresh fehlgeschlagen: " . (lastError() ?? 'unbekannt'), 'WARN');
    }
    return $daysLeft >= 0 ? $daysLeft : null;
}

// ─── Pixelfed ──────────────────────────────────────────────────────
function pixelfedHeaders(): array {
    return ['Authorization: Bearer ' . getPixelfedToken()];
}

/**
 * Control-Hashtags (#igpost/#igstory) aus einem bereits gepushten Pixelfed-Post
 * entfernen. Braucht einen Pixelfed-Token mit write-Scope!
 * Da Pixelfed keinen /source-Endpoint hat, wird der Text aus dem HTML
 * rekonstruiert; vorhandene Medien werden über media_ids erhalten.
 */
function removePixelfedHashtags(array $status, array $tagNames): void {
    global $config;
    $id = (string) ($status['id'] ?? '');
    if ($id === '') {
        return;
    }
    // Nur editieren, wenn der Post wirklich einen der Tags trägt
    $hasTag = false;
    foreach ($tagNames as $t) {
        if (hasHashtag($status, $t)) { $hasTag = true; break; }
    }
    if (!$hasTag) {
        return;
    }

    $newText  = stripControlHashtags(htmlToCaption((string) ($status['content'] ?? '')), $tagNames);
    $mediaIds = [];
    foreach ($status['media_attachments'] ?? [] as $m) {
        if (!empty($m['id'])) {
            $mediaIds[] = (string) $m['id'];
        }
    }

    $fields = ['status' => $newText];
    foreach ($mediaIds as $i => $mid) {
        $fields["media_ids[$i]"] = $mid;
    }
    // Sensitive-Flag und Content-Warnung erhalten (würden sonst beim Edit
    // verloren gehen). Ort lässt sich über die Mastodon-API nicht erhalten.
    if (!empty($status['sensitive'])) {
        $fields['sensitive'] = '1';
    }
    if (($status['spoiler_text'] ?? '') !== '') {
        $fields['spoiler_text'] = (string) $status['spoiler_text'];
    }

    $url = rtrim($config['pixelfed']['instance'], '/') . '/api/v1/statuses/' . $id;
    $res = httpRequest('PUT', $url, [], $fields, pixelfedHeaders());
    if ($res['error'] === null) {
        p2iLog("Pixelfed-Hashtags entfernt aus Post $id");
    } else {
        p2iLog("Pixelfed-Hashtag-Entfernung fehlgeschlagen für $id: " . (lastError() ?? 'unbekannt'), 'WARN');
    }
}

/** Eine Seite Media-Posts holen (älter als $maxId, falls gesetzt). */
function fetchStatusesPage(?string $maxId, int $limit): array {
    global $config;
    $base = rtrim($config['pixelfed']['instance'], '/')
          . '/api/v1/accounts/' . $config['pixelfed']['account_id'] . '/statuses';
    $query = ['only_media' => 'true', 'limit' => $limit];
    if ($maxId !== null) {
        $query['max_id'] = $maxId;
    }
    $res = httpRequest('GET', $base, $query, null, pixelfedHeaders());
    if ($res['error'] !== null || !is_array($res['body'])) {
        return [];
    }
    return $res['body'];
}

/** Schlanke Variante für test.php: die neuesten N Media-Posts. */
function fetchRecentStatuses(int $limit): array {
    return fetchStatusesPage(null, $limit);
}

/**
 * Sammelt alle zu postenden Posts (neu oder erneut zu versuchen) ein.
 * Blättert per max_id durch die Historie, bis ein bereits bekannter Post
 * auftaucht (= aufgeholt) oder das Seitenlimit erreicht ist.
 * Ergebnis ist chronologisch (ältester zuerst).
 */
function collectNewPosts(int $maxAttempts, int $pageSize, int $maxPages): array {
    $maxId     = null;
    $pages     = 0;
    $toProcess = [];

    do {
        $page = fetchStatusesPage($maxId, $pageSize);
        if (!$page) {
            break;
        }

        $reachedKnown = false;
        $lastId       = null;
        foreach ($page as $status) {
            $id     = (string) ($status['id'] ?? '');
            $lastId = $id;

            // Replies/Reblogs/nicht-öffentliche werden nie gepostet
            if (!empty($status['in_reply_to_id']) || !empty($status['reblog'])) {
                continue;
            }
            if (($status['visibility'] ?? 'public') !== 'public') {
                continue;
            }

            $row = getRecord($id);
            if ($row !== null) {
                $reachedKnown = true;
            }
            if (shouldProcess($row, $maxAttempts)) {
                $toProcess[] = $status;
            }
        }

        $maxId = $lastId;   // nächste Seite: ältere Posts
        $pages++;

        if ($reachedKnown) {
            break;
        }
    } while ($maxId !== null && $pages < $maxPages);

    return array_reverse($toProcess);   // ältester zuerst
}

// ─── Caption / Bild ────────────────────────────────────────────────
/** Pixelfed-HTML-Content in eine Instagram-taugliche Plaintext-Caption wandeln. */
function htmlToCaption(string $html): string {
    // Zeilenumbrüche aus block/break-Elementen erhalten
    $text = preg_replace('#<br\s*/?>#i', "\n", $html);
    $text = preg_replace('#</p>#i', "\n\n", $text);
    $text = preg_replace('#<p[^>]*>#i', '', $text);
    // Restliche Tags entfernen (Hashtag-/Mention-Links lassen ihren Text "#tag" stehen)
    $text = strip_tags($text);
    // HTML-Entities dekodieren
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Whitespace aufräumen
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

/**
 * Hat der Post den angegebenen Hashtag? Erkennung über das strukturierte
 * tags-Array (exakter Name, case-insensitive – kein Text-Parsing).
 */
function hasHashtag(array $status, string $tagName): bool {
    $tagName = mb_strtolower(trim($tagName));
    if ($tagName === '') {
        return false;
    }
    foreach ($status['tags'] ?? [] as $tag) {
        if (mb_strtolower((string) ($tag['name'] ?? '')) === $tagName) {
            return true;
        }
    }
    return false;
}

function isStoryTagged(array $status): bool {
    global $config;
    return hasHashtag($status, (string) ($config['story_hashtag'] ?? ''));
}

function isPostTagged(array $status): bool {
    global $config;
    return hasHashtag($status, (string) ($config['post_hashtag'] ?? ''));
}

/**
 * Control-Hashtags (#igpost/#igstory) aus der Caption entfernen, damit sie
 * nicht auf Instagram auftauchen. Entfernt nur die exakten Steuer-Tags.
 */
function stripControlHashtags(string $caption, array $tagNames): string {
    foreach ($tagNames as $t) {
        $t = trim($t);
        if ($t === '') {
            continue;
        }
        // "#tag" case-insensitiv, nur als ganzes Hashtag (nicht #tagXY)
        $caption = preg_replace('/#' . preg_quote($t, '/') . '(?![\p{L}\p{N}_])/iu', '', $caption);
    }
    // aufgeräumte Leerzeichen/Leerzeilen
    $caption = preg_replace('/[ \t]+/u', ' ', $caption);
    $caption = preg_replace('/ *\n/u', "\n", $caption);
    $caption = preg_replace('/\n{3,}/u', "\n\n", $caption);
    return trim($caption);
}

/**
 * Ein Media-Attachment als JPEG-URL liefern. Ist es schon JPEG → Original-URL.
 * Ist es PNG/WebP/GIF → wird auf All-Inkl nach JPEG konvertiert und die öffentliche
 * URL der konvertierten Datei zurückgegeben. Video/gifv gehört nicht hierher.
 * Rückgabe: ['url'=>…, 'alt'=>…] bei Erfolg, oder ['skip'=>'grund'] sonst.
 */
function pickJpegImage(array $m): array {
    $type = $m['type'] ?? '';
    if ($type === 'video' || $type === 'gifv') {
        return ['skip' => 'kein Bild (type=' . $type . ')'];
    }
    $url = (string) ($m['url'] ?? '');
    if ($url === '') {
        return ['skip' => 'Media ohne URL'];
    }
    $alt = trim((string) ($m['description'] ?? ''));
    $alt = $alt !== '' ? $alt : null;

    if (isJpegUrl($url)) {
        return ['url' => $url, 'alt' => $alt];   // schon JPEG
    }
    // Nicht-JPEG (PNG/WebP/GIF …) → nach JPEG konvertieren
    $converted = convertImageToJpeg($url);
    if ($converted === null) {
        return ['skip' => 'Bild-Konvertierung nach JPEG fehlgeschlagen: ' . (lastError() ?? '?')];
    }
    p2iLog("Bild nach JPEG konvertiert: " . basename(parse_url($url, PHP_URL_PATH) ?: $url));
    return ['url' => $converted, 'alt' => $alt];
}

/**
 * Nicht-JPEG-Bild herunterladen, nach JPEG wandeln (GD; Transparenz → weiß),
 * öffentlich unter media/ ablegen. Gibt die öffentliche URL zurück oder null.
 */
function convertImageToJpeg(string $sourceUrl): ?string {
    global $config;
    if (!function_exists('imagecreatefromstring')) {
        $GLOBALS['p2i_last_error'] = 'GD nicht verfügbar';
        return null;
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $sourceUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $data = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code < 200 || $code >= 300 || $data === false || $data === '') {
        $GLOBALS['p2i_last_error'] = "Bild-Download HTTP $code";
        return null;
    }
    $img = @imagecreatefromstring($data);
    if ($img === false) {
        $GLOBALS['p2i_last_error'] = 'Bildformat nicht dekodierbar (GD)';
        return null;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    // Auf weißen Hintergrund legen (JPEG kann keine Transparenz)
    $bg = imagecreatetruecolor($w, $h);
    imagefilledrectangle($bg, 0, 0, $w, $h, imagecolorallocate($bg, 255, 255, 255));
    imagecopy($bg, $img, 0, 0, 0, 0, $w, $h);

    $dir = __DIR__ . '/media';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $fname = 'conv_' . uniqid('', true) . '.jpg';
    $ok = imagejpeg($bg, $dir . '/' . $fname, 90);
    if (!$ok) {
        $GLOBALS['p2i_last_error'] = 'imagejpeg fehlgeschlagen';
        return null;
    }

    $base = rtrim((string) ($config['public_base_url'] ?? ''), '/');
    if ($base === '') {
        $base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
              . dirname(strtok($_SERVER['REQUEST_URI'] ?? '/push2ig/push2ig.php', '?'));
    }
    return $base . '/media/' . $fname;
}

/**
 * media/-Ablage aufräumen:
 *  - konvertierte Bilder (conv_*.jpg) älter als 1 h (IG holt sie im selben Lauf)
 *  - transkodierte Videos (*.mp4) älter als 7 Tage (Normalfall wird direkt nach
 *    dem Publish gelöscht; das hier fängt verwaiste Dateien fehlgeschlagener
 *    oder doppelt gelieferter Jobs ab)
 */
function cleanupMediaFiles(): void {
    $dir = __DIR__ . '/media';
    foreach (glob($dir . '/conv_*.jpg') ?: [] as $f) {
        if (is_file($f) && (time() - filemtime($f)) > 3600) {
            @unlink($f);
        }
    }
    foreach (glob($dir . '/*.mp4') ?: [] as $f) {
        if (is_file($f) && (time() - filemtime($f)) > 7 * 86400) {
            @unlink($f);
        }
    }
}

/**
 * Feed-Pfad: genau EIN JPEG-Bild (Carousel kommt später).
 */
function pickSingleJpeg(array $status): array {
    $media = $status['media_attachments'] ?? [];
    if (count($media) === 0) {
        return ['skip' => 'keine Medien'];
    }
    if (count($media) > 1) {
        return ['skip' => 'mehrere Bilder (Carousel – noch nicht unterstützt)'];
    }
    return pickJpegImage($media[0]);
}

/**
 * Story-Pfad: erstes BILD im Post (nicht stur media[0] – ein Mischpost
 * könnte das Video vorne haben und dahinter ein brauchbares Bild).
 */
function pickFirstJpeg(array $status): array {
    $media = $status['media_attachments'] ?? [];
    if (count($media) === 0) {
        return ['skip' => 'keine Medien'];
    }
    foreach ($media as $m) {
        $t = $m['type'] ?? '';
        if ($t !== 'video' && $t !== 'gifv') {
            return pickJpegImage($m);
        }
    }
    return ['skip' => 'kein Bild im Post (nur Video)'];
}

/**
 * Carousel-Pfad: alle Bilder als JPEG (Nicht-JPEG wird konvertiert). Enthält der
 * Post ein Video, wird der ganze Post übersprungen (kein Misch-Carousel).
 * Rückgabe ['images'=>[['url','alt'],…]] oder ['skip'=>'grund'].
 */
function pickCarouselJpegs(array $status): array {
    $media = $status['media_attachments'] ?? [];
    $items = [];
    foreach ($media as $m) {
        $t = $m['type'] ?? '';
        if ($t === 'video' || $t === 'gifv') {
            return ['skip' => 'Carousel enthält Video – noch nicht unterstützt'];
        }
        $pick = pickJpegImage($m);
        if (isset($pick['skip'])) {
            return ['skip' => 'Carousel: ' . $pick['skip']];
        }
        $items[] = $pick;
    }
    if (count($items) < 2) {
        return ['skip' => 'weniger als 2 Bilder'];
    }
    return ['images' => $items];
}

/** Einzelvideo-Post erkennen. Gibt die Video-URL zurück oder null. */
function pickVideo(array $status): ?string {
    $media = $status['media_attachments'] ?? [];
    if (count($media) === 1 && in_array(($media[0]['type'] ?? ''), ['video', 'gifv'], true)) {
        $url = (string) ($media[0]['url'] ?? '');
        return $url !== '' ? $url : null;
    }
    return null;
}

/** JPEG anhand der Dateiendung erkennen, sonst per HEAD-Content-Type. */
function isJpegUrl(string $url): bool {
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'jpg' || $ext === 'jpeg') {
        return true;
    }
    if ($ext === 'png' || $ext === 'webp' || $ext === 'gif') {
        return false;
    }
    // Keine eindeutige Endung → Content-Type prüfen
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    return stripos($ct, 'image/jpeg') !== false;
}

// ─── Instagram Graph API ───────────────────────────────────────────
function igBase(): string {
    global $config;
    return 'https://graph.instagram.com/' . ($config['instagram']['api_version'] ?? 'v25.0');
}

/** Aktuellen IG-Token aus der DB holen; beim ersten Mal aus der Config seeden. */
function getIgToken(): string {
    global $config;
    $token = getSetting('ig_access_token');
    if ($token === null || $token === '') {
        $token = $config['instagram']['token'];
        setSetting('ig_access_token', $token);
        // Ablauf unbekannt → auf "jetzt" setzen, damit der erste Lauf refreshed
        // und ein exaktes Ablaufdatum etabliert.
        setSetting('ig_token_expires_at', date('Y-m-d H:i:s'));
        p2iLog("IG-Token aus Config in DB übernommen");
    }
    return $token;
}

/**
 * Token automatisch refreshen, wenn die Restlaufzeit unter den Schwellwert fällt.
 * Gibt die Token-Restlaufzeit in Tagen zurück (für die Lauf-Summary).
 */
function refreshIgTokenIfNeeded(): ?int {
    global $config;
    $thresholdDays = (int) ($config['token_refresh_threshold_days'] ?? 7);
    $expiresAt = getSetting('ig_token_expires_at');
    $expiresTs = $expiresAt ? strtotime($expiresAt) : 0;
    $daysLeft  = $expiresTs ? (int) floor(($expiresTs - time()) / 86400) : -1;

    if ($expiresTs && ($expiresTs - time()) > $thresholdDays * 86400) {
        return $daysLeft;   // noch gültig, kein Refresh – nicht loggen (Leerlauf-Spam)
    }

    p2iLog("IG-Token läuft bald ab ($daysLeft Tage) – Refresh-Versuch");
    $res = httpRequest('GET', 'https://graph.instagram.com/refresh_access_token', [
        'grant_type'   => 'ig_refresh_token',
        'access_token' => getIgToken(),
    ]);
    if ($res['error'] === null && !empty($res['body']['access_token'])) {
        $newToken  = $res['body']['access_token'];
        $expiresIn = (int) ($res['body']['expires_in'] ?? 5184000); // ~60 Tage
        setSetting('ig_access_token', $newToken);
        setSetting('ig_token_expires_at', date('Y-m-d H:i:s', time() + $expiresIn));
        $daysLeft = (int) floor($expiresIn / 86400);
        p2iLog("IG-Token refreshed, neue Laufzeit $daysLeft Tage");
    } else {
        // Kein Abbruch – mit bestehendem Token weiterposten, solange er gültig ist
        p2iLog("IG-Token-Refresh fehlgeschlagen: " . (lastError() ?? 'unbekannt'), 'WARN');
    }
    return $daysLeft >= 0 ? $daysLeft : null;
}

/**
 * Prüft aktiv den IG-Token (GET /me). Unterscheidet echten Token-Tod von
 * einem IG-Ausfall, damit kein falscher "Token ungültig"-Alarm rausgeht:
 *  'ok'          → Token gültig
 *  'invalid'     → Token tot (OAuth-Fehler/code 190) → Alarm berechtigt
 *  'unavailable' → IG nicht erreichbar (5xx/Netzwerk) → kein Alarm, nur warten
 */
function igTokenState(): string {
    $res = httpRequest('GET', igBase() . '/me', [
        'fields'       => 'user_id',
        'access_token' => getIgToken(),
    ]);
    if ($res['error'] === null && !empty($res['body']['user_id'])) {
        return 'ok';
    }
    $igCode = (int) ($res['body']['error']['code'] ?? 0);
    $igType = (string) ($res['body']['error']['type'] ?? '');
    if ($igCode === 190 || $igType === 'OAuthException') {
        return 'invalid';
    }
    return 'unavailable';
}

/**
 * E-Mail-Alarm senden – aber höchstens 1× pro 6 h je $key (Anti-Spam).
 * Zustand wird in push2ig_settings gemerkt.
 */
function sendAlertOnce(string $key, string $subject, string $body): bool {
    global $config;
    $email = trim((string) ($config['alert_email'] ?? ''));
    if ($email === '') {
        return false;
    }
    $last = getSetting('alert_' . $key);
    if ($last !== null && $last !== '' && (time() - (int) $last) < 6 * 3600) {
        return false;   // vor Kurzem schon gewarnt
    }
    // Absender MUSS eine eigene Domain sein (SPF!) – nie eine fremde wie pixelfed.de
    $from = trim((string) ($config['alert_from'] ?? ''));
    if ($from === '') {
        $from = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    $headers = "From: push2ig <$from>\r\n";
    // -f (Envelope-Sender) verbessert die Zustellung auf Shared Hosting (SPF).
    // Domain muss auf dem Hosting-Account liegen (z. B. eichhof.co).
    $ok = @mail($email, $subject, $body, $headers, '-f' . $from);
    if ($ok) {
        // Drossel erst NACH erfolgreichem Versand – sonst frisst ein
        // transienter mail()-Fehler den Alarm für 6 h
        setSetting('alert_' . $key, (string) time());
    }
    p2iLog(($ok ? "Alarm-Mail an $email: $subject" : "Alarm-Mail an $email FEHLGESCHLAGEN (mail() false)"), $ok ? 'INFO' : 'ERROR');
    return $ok;
}

/** Alarm-Sperre für $key zurücksetzen (nach Erholung), damit erneut gewarnt wird. */
function clearAlert(string $key): void {
    if (getSetting('alert_' . $key)) {
        setSetting('alert_' . $key, '');
    }
}

/** Caption auf das Instagram-Limit kappen (2200 Zeichen). */
function igCaption(string $caption): string {
    return mb_substr($caption, 0, 2200);
}

/** Schritt 1 (Feed): Media-Container anlegen. Gibt creation_id zurück oder null. */
function igCreateContainer(string $imageUrl, string $caption, ?string $altText): ?string {
    global $config;
    $fields = [
        'image_url'    => $imageUrl,
        'caption'      => igCaption($caption),
        'access_token' => getIgToken(),
    ];
    if ($altText !== null && $altText !== '') {
        $fields['alt_text'] = $altText;
    }
    $url = igBase() . '/' . $config['instagram']['user_id'] . '/media';
    $res = httpRequest('POST', $url, [], $fields);
    if ($res['error'] === null && !empty($res['body']['id'])) {
        return (string) $res['body']['id'];
    }
    return null;
}

/**
 * Schritt 1 (Story): Story-Container anlegen (keine Caption/Alt).
 * $isVideo=false → Bild (image_url), true → Video (video_url, async-Verarbeitung).
 */
function igCreateStoryContainer(string $mediaUrl, bool $isVideo = false): ?string {
    global $config;
    $url = igBase() . '/' . $config['instagram']['user_id'] . '/media';
    $res = httpRequest('POST', $url, [], [
        'media_type'   => 'STORIES',
        ($isVideo ? 'video_url' : 'image_url') => $mediaUrl,
        'access_token' => getIgToken(),
    ]);
    if ($res['error'] === null && !empty($res['body']['id'])) {
        return (string) $res['body']['id'];
    }
    return null;
}

/** Schritt 1 (Carousel): Kind-Container für ein einzelnes Bild anlegen. */
function igCreateCarouselChild(string $imageUrl, ?string $altText): ?string {
    global $config;
    $fields = [
        'image_url'        => $imageUrl,
        'is_carousel_item' => 'true',
        'access_token'     => getIgToken(),
    ];
    if ($altText !== null && $altText !== '') {
        $fields['alt_text'] = $altText;   // Alt-Text je Bild
    }
    $url = igBase() . '/' . $config['instagram']['user_id'] . '/media';
    $res = httpRequest('POST', $url, [], $fields);
    if ($res['error'] === null && !empty($res['body']['id'])) {
        return (string) $res['body']['id'];
    }
    return null;
}

/** Schritt 1 (Carousel): Carousel-Container aus den Kind-Containern anlegen. */
function igCreateCarouselContainer(array $childIds, string $caption): ?string {
    global $config;
    $url = igBase() . '/' . $config['instagram']['user_id'] . '/media';
    $res = httpRequest('POST', $url, [], [
        'media_type'   => 'CAROUSEL',
        'children'     => implode(',', $childIds),
        'caption'      => igCaption($caption),
        'access_token' => getIgToken(),
    ]);
    if ($res['error'] === null && !empty($res['body']['id'])) {
        return (string) $res['body']['id'];
    }
    return null;
}

/** Container-Status pollen, bis FINISHED (true), ERROR/EXPIRED/Timeout (false). */
function igWaitForContainer(string $creationId): bool {
    global $config;
    $attempts = (int) ($config['container_poll_attempts'] ?? 20);
    $delay    = (int) ($config['container_poll_delay'] ?? 3);

    for ($i = 1; $i <= $attempts; $i++) {
        $res = httpRequest('GET', igBase() . '/' . $creationId, [
            'fields'       => 'status_code',
            'access_token' => getIgToken(),
        ]);
        $code = $res['body']['status_code'] ?? null;
        if ($code === 'FINISHED') {
            return true;
        }
        if ($code === 'ERROR' || $code === 'EXPIRED') {
            $GLOBALS['p2i_last_error'] = "Container-Status $code";
            p2iLog("Container $creationId Status $code", 'ERROR');
            return false;
        }
        // IN_PROGRESS / PUBLISHED-noch-nicht → warten
        if ($i < $attempts) {
            sleep($delay);
        }
    }
    $GLOBALS['p2i_last_error'] = "Container nicht rechtzeitig FINISHED";
    p2iLog("Container $creationId nach $attempts Versuchen nicht FINISHED", 'WARN');
    return false;
}

/** Schritt 2: Container veröffentlichen. Gibt ig_media_id zurück oder null. */
function igPublish(string $creationId): ?string {
    global $config;
    $url = igBase() . '/' . $config['instagram']['user_id'] . '/media_publish';
    $res = httpRequest('POST', $url, [], [
        'creation_id'  => $creationId,
        'access_token' => getIgToken(),
    ]);
    if ($res['error'] === null && !empty($res['body']['id'])) {
        return (string) $res['body']['id'];
    }
    return null;
}

/** Schritt 2+3: auf FINISHED warten und veröffentlichen. Gibt ig_media_id oder null. */
function igFinalize(string $creationId): ?string {
    if (!igWaitForContainer($creationId)) {
        return null;   // p2i_last_error ist gesetzt
    }
    return igPublish($creationId);
}

// ─── Instagram-Story → Pixelfed-Story (Rückrichtung) ───────────────
/** Aktuell aktive Instagram-Stories holen (nur die laufenden, 24 h). */
function fetchIgStories(): array {
    global $config;
    $url = igBase() . '/' . $config['instagram']['user_id'] . '/stories';
    $res = httpRequest('GET', $url, [
        'fields'       => 'id,media_type,media_url,timestamp',
        'access_token' => getIgToken(),
    ]);
    if ($res['error'] !== null || !isset($res['body']['data']) || !is_array($res['body']['data'])) {
        return [];
    }
    return $res['body']['data'];
}

/**
 * Eine Medien-URL in eine temporäre Datei laden. Gibt ['path','mime'] oder null.
 * Streamt direkt in die Datei (CURLOPT_FILE) statt in den RAM – wichtig für
 * Videos, die sonst am PHP memory_limit scheitern könnten.
 */
function downloadToTemp(string $url): ?array {
    $path = sys_get_temp_dir() . '/p2i_story_' . uniqid('', true);
    $fh = fopen($path, 'wb');
    if ($fh === false) {
        return null;
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FILE, $fh);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $ok   = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    fclose($fh);
    clearstatcache(true, $path);
    if ($ok === false || $code < 200 || $code >= 300 || (int) filesize($path) === 0) {
        @unlink($path);
        return null;
    }
    $isVideo = stripos($ct, 'video') !== false;
    $final   = $path . ($isVideo ? '.mp4' : '.jpg');
    if (!rename($path, $final)) {
        @unlink($path);
        return null;
    }
    return ['path' => $final, 'mime' => $isVideo ? 'video/mp4' : 'image/jpeg'];
}

/** Datei als Pixelfed-Story hochladen (multipart). Gibt die media_id oder null. */
function pixelfedStoryAdd(string $filePath, string $mime): ?string {
    global $config;
    $url = rtrim($config['pixelfed']['instance'], '/') . '/api/v1.1/stories/add';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'file' => new CURLFile($filePath, $mime, basename($filePath)),
    ]);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . getPixelfedToken(),
        'Accept: application/json',
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    if ($err !== '' || $code < 200 || $code >= 300) {
        $GLOBALS['p2i_last_error'] = $err !== '' ? "cURL: $err" : "HTTP $code: " . mb_substr((string) $resp, 0, 300);
        p2iLog("stories/add fehlgeschlagen: " . lastError(), 'ERROR');
        return null;
    }
    $d = json_decode((string) $resp, true);
    if (!is_array($d) || empty($d['media_id'])) {
        $GLOBALS['p2i_last_error'] = "stories/add ohne media_id: " . mb_substr((string) $resp, 0, 200);
        return null;
    }
    return (string) $d['media_id'];
}

/**
 * Hochgeladene Story-Datei veröffentlichen.
 * $duration = Anzeigedauer (0–30 s). Bei Bildern die konfigurierte Dauer,
 * bei Videos das Maximum (30), damit nichts vorzeitig gekappt wird.
 */
function pixelfedStoryPublish(string $mediaId, int $duration): bool {
    global $config;
    $url = rtrim($config['pixelfed']['instance'], '/') . '/api/v1.1/stories/publish';
    $res = httpRequest('POST', $url, [], [
        'media_id'  => $mediaId,
        'duration'  => max(0, min(30, $duration)),
        'can_reply' => !empty($config['ig_story_can_reply']) ? '1' : '0',
        'can_react' => !empty($config['ig_story_can_react']) ? '1' : '0',
    ], pixelfedHeaders());
    return $res['error'] === null;
}

/** Orchestrierung: aktive IG-Stories als Pixelfed-Stories spiegeln (mit Dedup). */
function importIgStories(bool $force = false): void {
    global $config;
    if (!$force && empty($config['import_ig_stories'])) {
        return;
    }
    $maxAttempts = (int) ($config['max_attempts'] ?? 5);
    $stories = fetchIgStories();
    if (!$stories) {
        if (!empty($config['debug_log'])) {
            p2iLog("Keine aktiven Instagram-Stories", 'DEBUG');
        }
        return;
    }

    $imported = 0; $failed = 0;
    foreach ($stories as $s) {
        $sid = (string) ($s['id'] ?? '');
        if ($sid === '') {
            continue;
        }
        // Dedup: schon importiert (oder endgültig gescheitert)?
        if (!shouldProcess(getImportedStory($sid), $maxAttempts)) {
            continue;
        }
        $murl = (string) ($s['media_url'] ?? '');
        if ($murl === '') {
            recordImportedStory($sid, null, 'failed', 'keine media_url');
            $failed++;
            continue;
        }

        $dl = downloadToTemp($murl);
        if ($dl === null) {
            recordImportedStory($sid, null, 'failed', 'Download fehlgeschlagen');
            p2iLog("IG-Story $sid: Download fehlgeschlagen", 'ERROR');
            $failed++;
            continue;
        }

        $mediaId = pixelfedStoryAdd($dl['path'], $dl['mime']);
        @unlink($dl['path']);
        if ($mediaId === null) {
            recordImportedStory($sid, null, 'failed', lastError() ?? 'stories/add fehlgeschlagen');
            $failed++;
            continue;
        }

        // Video → volle 30 s (nicht kappen); Bild → konfigurierte Dauer
        $isVideo  = ($s['media_type'] ?? '') === 'VIDEO';
        $duration = $isVideo ? 30 : (int) ($config['ig_story_duration'] ?? 10);
        if (pixelfedStoryPublish($mediaId, $duration)) {
            recordImportedStory($sid, $mediaId, 'imported', null);
            p2iLog("IG-Story $sid → Pixelfed-Story (media $mediaId)");
            $imported++;
        } else {
            recordImportedStory($sid, $mediaId, 'failed', lastError() ?? 'stories/publish fehlgeschlagen');
            p2iLog("IG-Story $sid: publish fehlgeschlagen", 'ERROR');
            $failed++;
        }
    }
    if ($imported + $failed > 0) {
        p2iLog("Instagram-Stories: $imported importiert, $failed fehlgeschlagen");
    }
}

// ─── Video → Instagram-Reel (über NAS-Transkodierung, asynchron) ───
/** Einen Transkodier-Job in die Queue legen (NAS-Worker holt ihn ab). */
function enqueueTranscodeJob(string $sourceId, string $sourceUrl, string $kind, int $maxDuration): void {
    $stmt = getDb()->prepare("
        INSERT INTO push2ig_transcode_jobs (source_id, source_url, kind, max_duration)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$sourceId, $sourceUrl, $kind, $maxDuration]);
}

/** Einzelnen Pixelfed-Status nachladen (für die Caption in Phase 2). */
function fetchSingleStatus(string $id): ?array {
    global $config;
    $url = rtrim($config['pixelfed']['instance'], '/') . '/api/v1/statuses/' . $id;
    $res = httpRequest('GET', $url, [], null, pixelfedHeaders());
    return ($res['error'] === null && is_array($res['body'])) ? $res['body'] : null;
}

/** Reel-Container anlegen (media_type=REELS, öffentliche Video-URL). */
function igCreateReelContainer(string $videoUrl, string $caption): ?string {
    global $config;
    $res = httpRequest('POST', igBase() . '/' . $config['instagram']['user_id'] . '/media', [], [
        'media_type'   => 'REELS',
        'video_url'    => $videoUrl,
        'caption'      => igCaption($caption),
        'access_token' => getIgToken(),
    ]);
    if ($res['error'] === null && !empty($res['body']['id'])) {
        return (string) $res['body']['id'];
    }
    return null;
}

/** Einmalige (nicht-blockierende) Statusabfrage eines Containers. */
function igContainerStatus(string $creationId): ?string {
    $res = httpRequest('GET', igBase() . '/' . $creationId, [
        'fields'       => 'status_code',
        'access_token' => getIgToken(),
    ]);
    return $res['body']['status_code'] ?? null;
}

/** Post-Zeile direkt aktualisieren (für die Video-Statusmaschine). */
function updatePostRow(string $sourceId, array $fields): void {
    if (!$fields) return;
    $set = [];
    $vals = [];
    foreach ($fields as $k => $v) { $set[] = "`$k` = ?"; $vals[] = $v; }
    $vals[] = $sourceId;
    $stmt = getDb()->prepare("UPDATE push2ig_posts SET " . implode(', ', $set) . " WHERE source_id = ?");
    $stmt->execute($vals);
}

/**
 * Video-Statusmaschine (läuft jeden Lauf, nicht-blockierend):
 *  A) status=transcoding  → Job fertig? → Reel-Container anlegen → reel_processing
 *  B) status=reel_processing → Container FINISHED? → publish → posted
 */
function processVideoPosts(): void {
    global $config;
    $controlTags = [
        (string) ($config['post_hashtag'] ?? ''),
        (string) ($config['story_hashtag'] ?? ''),
    ];

    // ── Timeout: Video-Posts, die >48 h feststecken, endgültig aufgeben ──
    // (posted_at = Zeitpunkt des Enqueues; deckt sowohl endlos scheiternde
    // Container-Erstellung als auch nie fertig werdende IG-Container ab)
    $n = getDb()->exec("
        UPDATE push2ig_posts
        SET status = 'failed', attempts = 99,
            last_error = 'Video-Verarbeitung nach 48 h abgebrochen (Timeout)'
        WHERE status IN ('transcoding','reel_processing','story_processing')
          AND posted_at < (NOW() - INTERVAL 48 HOUR)
    ");
    if ($n > 0) {
        p2iLog("$n Video-Post(s) nach 48 h Timeout aufgegeben", 'WARN');
    }

    // ── A) Wartet auf Transkodierung ───────────────────────────────
    $rows = getDb()->query("SELECT source_id, target FROM push2ig_posts WHERE status = 'transcoding'")->fetchAll();
    foreach ($rows as $r) {
        $sid      = (string) $r['source_id'];
        $isStory  = ($r['target'] === 'story');
        $jstmt = getDb()->prepare("SELECT status, result_url, error FROM push2ig_transcode_jobs WHERE source_id = ? ORDER BY id DESC LIMIT 1");
        $jstmt->execute([$sid]);
        $job = $jstmt->fetch();
        if (!$job) {
            continue;   // Job (noch) nicht da – unwahrscheinlich, nächster Lauf
        }
        if ($job['status'] === 'failed') {
            updatePostRow($sid, ['status' => 'failed', 'attempts' => 99, 'last_error' => 'Transkodierung fehlgeschlagen: ' . mb_substr((string) $job['error'], 0, 400)]);
            p2iLog("Video $sid: Transkodierung fehlgeschlagen", 'ERROR');
            continue;
        }
        if ($job['status'] !== 'done' || empty($job['result_url'])) {
            continue;   // pending/processing → weiter warten
        }
        // Job fertig → richtigen Container anlegen (Video-Story ohne Caption, Reel mit)
        if ($isStory) {
            $cid = igCreateStoryContainer((string) $job['result_url'], true);
            $ok  = $cid !== null;
            if ($ok) { updatePostRow($sid, ['status' => 'story_processing', 'ig_creation_id' => $cid]); }
            p2iLog($ok ? "Video-Story-Container erstellt für $sid ($cid)"
                       : "Video-Story-Container-Erstellung fehlgeschlagen für $sid: " . (lastError() ?? '?') . " – erneut nächster Lauf", $ok ? 'INFO' : 'WARN');
        } else {
            $status  = fetchSingleStatus($sid);
            $caption = $status ? stripControlHashtags(htmlToCaption((string) ($status['content'] ?? '')), $controlTags) : '';
            $cid = igCreateReelContainer((string) $job['result_url'], $caption);
            $ok  = $cid !== null;
            if ($ok) { updatePostRow($sid, ['status' => 'reel_processing', 'ig_creation_id' => $cid]); }
            p2iLog($ok ? "Reel-Container erstellt für $sid ($cid)"
                       : "Reel-Container-Erstellung fehlgeschlagen für $sid: " . (lastError() ?? '?') . " – erneut nächster Lauf", $ok ? 'INFO' : 'WARN');
        }
    }

    // ── B) Wartet auf Instagram-Verarbeitung (Reel ODER Video-Story) ──
    $rows = getDb()->query("SELECT source_id, ig_creation_id, status FROM push2ig_posts WHERE status IN ('reel_processing','story_processing')")->fetchAll();
    foreach ($rows as $r) {
        $sid   = (string) $r['source_id'];
        $cid   = (string) $r['ig_creation_id'];
        $label = $r['status'] === 'story_processing' ? 'Video-Story' : 'Reel';
        $sc    = igContainerStatus($cid);
        if ($sc === 'FINISHED') {
            $mid = igPublish($cid);
            if ($mid !== null) {
                updatePostRow($sid, ['status' => 'posted', 'ig_media_id' => $mid, 'last_error' => null]);
                p2iLog("$label gepostet: $sid → IG media $mid");
                // Ggf. transkodiertes Video auf All-Inkl aufräumen (nur Reels haben eine Datei)
                $jstmt = getDb()->prepare("SELECT result_file FROM push2ig_transcode_jobs WHERE source_id = ? ORDER BY id DESC LIMIT 1");
                $jstmt->execute([$sid]);
                $rf = (string) ($jstmt->fetchColumn() ?: '');
                if ($rf !== '' && is_file(__DIR__ . '/media/' . $rf)) {
                    @unlink(__DIR__ . '/media/' . $rf);
                }
                // Control-Hashtags auf Pixelfed entfernen (wie beim Bild-Flow)
                if (!empty($config['remove_push_hashtags'])) {
                    $st = fetchSingleStatus($sid);
                    if ($st) {
                        removePixelfedHashtags($st, $controlTags);
                    }
                }
            } else {
                p2iLog("$label-Publish fehlgeschlagen für $sid – erneut nächster Lauf", 'WARN');
            }
        } elseif ($sc === 'ERROR' || $sc === 'EXPIRED') {
            updatePostRow($sid, ['status' => 'failed', 'attempts' => 99, 'last_error' => "$label-Container $sc"]);
            p2iLog("$label-Container $sc für $sid", 'ERROR');
        }
        // IN_PROGRESS / null → weiter warten
    }
}

// ─── Hauptlogik ────────────────────────────────────────────────────
function main(): void {
    global $config;

    $lock = acquireLock();
    if ($lock === false) {
        p2iLog("Ein anderer Lauf ist noch aktiv, überspringe diesen Lauf", 'WARN');
        return;
    }

    if (!empty($config['debug_log'])) {
        p2iLog("=== push2ig-Lauf gestartet ===", 'DEBUG');
    }
    ensureTables();

    $maxAttempts = (int) ($config['max_attempts'] ?? 5);
    $pageSize    = (int) ($config['batch_size']   ?? 20);
    $maxPages    = (int) ($config['max_pages']    ?? 10);

    // Pixelfed-Token sicherstellen + ggf. refreshen (vor dem Posts-Holen)
    getPixelfedToken();
    $pfDays = refreshPixelfedTokenIfNeeded();

    // IG-Token sicherstellen + ggf. refreshen
    getIgToken();
    $igDays = refreshIgTokenIfNeeded();

    // Health-Check: ist der IG-Token wirklich gültig? (fängt Widerruf/Error 190 früh)
    $tokenState = igTokenState();
    if ($tokenState !== 'ok') {
        if ($tokenState === 'invalid') {
            p2iLog("⚠ INSTAGRAM-TOKEN UNGÜLTIG – Lauf abgebrochen, Neu-Autorisierung nötig", 'ERROR');
            sendAlertOnce(
                'ig_token',
                'push2ig: Instagram-Token ungültig',
                "Der Instagram-Token ist ungültig (Error 190 / Autorisierung widerrufen).\n\n"
                . "So reaktivierst du ihn:\n"
                . "1. Meta-Dashboard → Instagram → API-Einrichtung → 'Token generieren'\n"
                . "2. Auf dem Server: php push2ig.php set-ig-token \"<NEUER_TOKEN>\"\n"
                . "3. Auf dem Server: php push2ig.php requeue-failed\n\n"
                . "Es wird nicht weiter gepostet, bis der Token wieder gültig ist."
            );
        } else {
            // IG down/Netzwerkproblem → KEIN Token-Alarm, nächster Cron probiert neu
            p2iLog("Instagram nicht erreichbar – Lauf übersprungen (kein Token-Problem)", 'WARN');
        }
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return;
    }
    clearAlert('ig_token');   // Token wieder ok → Alarm für nächsten Vorfall scharf

    $posts = collectNewPosts($maxAttempts, $pageSize, $maxPages);
    if (count($posts) > 0) {
        p2iLog(count($posts) . " zu verarbeitende Posts gefunden");
    }

    $posted = 0; $failed = 0; $skipped = 0; $ignored = 0; $queued = 0;

    foreach ($posts as $status) {
        $id        = (string) ($status['id'] ?? '');
        $url       = (string) ($status['url'] ?? '');
        $createdAt = $status['created_at'] ?? date('c');

        // Routing: #igstory → Story, #igpost → Feed; sonst (bei require_hashtag) ignorieren
        $isStory     = isStoryTagged($status);
        $isPost      = isPostTagged($status);
        $requireTag  = !empty($config['require_hashtag']);
        $mediaCount  = count($status['media_attachments'] ?? []);
        $controlTags = [
            (string) ($config['post_hashtag'] ?? ''),
            (string) ($config['story_hashtag'] ?? ''),
        ];

        $creationId = null;
        $skipReason = null;

        if ($isStory) {
            $target = 'story';
            // Video-Story → über NAS transkodieren (≤60 s, IG-Story-Format), dann publishen
            $videoUrl = pickVideo($status);
            if ($videoUrl !== null) {
                enqueueTranscodeJob($id, $videoUrl, 'story', 60);
                recordAttempt($id, $url, $createdAt, null, null, 'transcoding', null, 'story');
                p2iLog("Video-Story erkannt ($id) → Transkodier-Job angelegt (Story folgt nach Transkodierung)");
                $queued++;
                continue;
            }
            // Bild-Story: erstes Bild, keine Caption/Alt (synchron)
            $pick = pickFirstJpeg($status);
            if (isset($pick['skip'])) {
                $skipReason = $pick['skip'];
            } else {
                if ($mediaCount > 1) {
                    p2iLog("Story ($id): nur erstes von $mediaCount Bildern wird gepostet");
                }
                p2iLog("Poste $id als Story …");
                $creationId = igCreateStoryContainer($pick['url']);
            }
        } elseif ($isPost || !$requireTag) {
            // Einzelvideo → Reel über NAS-Transkodierung (asynchron, s. processVideoPosts)
            $videoUrl = pickVideo($status);
            if ($videoUrl !== null) {
                enqueueTranscodeJob($id, $videoUrl, 'reel', 90);
                recordAttempt($id, $url, $createdAt, null, null, 'transcoding', null, 'reel');
                p2iLog("Video erkannt ($id) → Transkodier-Job angelegt (Reel folgt nach Transkodierung)");
                $queued++;
                continue;
            }
            $target = 'feed';
            if ($mediaCount > 1) {
                // Feed-Carousel: alle Bilder JPEG, max. 10
                $cpick = pickCarouselJpegs($status);
                if (isset($cpick['skip'])) {
                    $skipReason = $cpick['skip'];
                } else {
                    $imgs = $cpick['images'];
                    if (count($imgs) > 10) {
                        $imgs = array_slice($imgs, 0, 10);
                        p2iLog("Carousel ($id): mehr als 10 Bilder, nur die ersten 10");
                    }
                    $caption = stripControlHashtags(htmlToCaption((string) ($status['content'] ?? '')), $controlTags);
                    p2iLog("Poste $id als Carousel (" . count($imgs) . " Bilder) …");
                    $childIds = [];
                    foreach ($imgs as $img) {
                        $cid = igCreateCarouselChild($img['url'], $img['alt']);
                        if ($cid === null) {
                            break;   // ein Kind fehlgeschlagen → ganzer Post failed
                        }
                        $childIds[] = $cid;
                    }
                    if (count($childIds) === count($imgs)) {
                        $creationId = igCreateCarouselContainer($childIds, $caption);
                    }
                }
            } else {
                // Feed-Einzelbild
                $pick = pickSingleJpeg($status);
                if (isset($pick['skip'])) {
                    $skipReason = $pick['skip'];
                } else {
                    $caption = stripControlHashtags(htmlToCaption((string) ($status['content'] ?? '')), $controlTags);
                    p2iLog("Poste $id …");
                    $creationId = igCreateContainer($pick['url'], $caption, $pick['alt']);
                }
            }
        } else {
            // Kein Push-Hashtag und require_hashtag=true → ignorieren (als 'skipped' merken)
            recordAttempt($id, $url, $createdAt, null, null, 'skipped', 'kein Push-Hashtag', 'feed');
            $ignored++;
            continue;
        }

        if ($skipReason !== null) {
            recordAttempt($id, $url, $createdAt, null, null, 'skipped', "$target: $skipReason", $target);
            p2iLog("Übersprungen ($id, $target): $skipReason");
            $skipped++;
            continue;
        }

        if ($creationId === null) {
            recordAttempt($id, $url, $createdAt, null, null, 'failed', lastError() ?? 'Container-Erstellung fehlgeschlagen', $target);
            p2iLog("Fehlgeschlagen (Container): $id", 'ERROR');
            $failed++;
            continue;
        }

        $mediaId = igFinalize($creationId);
        if ($mediaId !== null) {
            recordAttempt($id, $url, $createdAt, $creationId, $mediaId, 'posted', null, $target);
            p2iLog("Gepostet ($target): $id → IG media $mediaId");
            $posted++;
            // Teil B: Control-Hashtags auf Pixelfed entfernen (braucht write-Token)
            if (!empty($config['remove_push_hashtags'])) {
                removePixelfedHashtags($status, $controlTags);
            }
        } else {
            recordAttempt($id, $url, $createdAt, $creationId, null, 'failed', lastError() ?? 'Publish/Container fehlgeschlagen', $target);
            p2iLog("Fehlgeschlagen (Publish): $id", 'ERROR');
            $failed++;
        }
    }

    // Lauf-Summary: im Leerlauf genau EINE Zeile (statt ~6 → Log bleibt lesbar)
    $tokenInfo = 'PF-Token ' . ($pfDays !== null ? "$pfDays T." : '?')
               . ', IG-Token ' . ($igDays !== null ? "$igDays T." : '?');
    if ($posted + $queued + $ignored + $skipped + $failed === 0) {
        p2iLog("Lauf ok – nichts Neues ($tokenInfo)");
    } else {
        p2iLog("Fertig: $posted gepostet, $queued Video(s) in Arbeit, $ignored ignoriert, "
             . "$skipped übersprungen, $failed fehlgeschlagen ($tokenInfo)");
    }

    // Video-Statusmaschine: fertige Transkodierungen → Reel-Container → publish
    processVideoPosts();

    // Rückrichtung: aktive Instagram-Stories als Pixelfed-Stories spiegeln
    importIgStories();

    // media/-Ablage aufräumen (konvertierte Bilder + verwaiste Videos)
    cleanupMediaFiles();

    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// ─── Run (nur wenn direkt aufgerufen) ──────────────────────────────
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    // CLI-Subcommand: IG-Stories sofort importieren (Verifizieren/Test).
    //   php push2ig.php import-stories
    if (php_sapi_name() === 'cli' && ($argv[1] ?? '') === 'import-stories') {
        try {
            ensureTables();
            getPixelfedToken();
            getIgToken();
            importIgStories(true);   // erzwingen, auch wenn import_ig_stories=false
        } catch (Throwable $e) {
            p2iLog("FATAL: " . $e->getMessage(), 'ERROR');
            exit(1);
        }
        exit(0);
    }

    // CLI-Subcommand: Pixelfed-Token sofort refreshen (Verifizieren/Wartung).
    //   php push2ig.php refresh-pixelfed
    if (php_sapi_name() === 'cli' && ($argv[1] ?? '') === 'refresh-pixelfed') {
        try {
            ensureTables();
            getPixelfedToken();              // ggf. aus Config seeden
            refreshPixelfedTokenIfNeeded(true);  // erzwingen
        } catch (Throwable $e) {
            p2iLog("FATAL: " . $e->getMessage(), 'ERROR');
            exit(1);
        }
        exit(0);
    }

    // CLI-Subcommand: neuen Instagram-Token in der DB setzen (nach Neu-Autorisierung).
    //   php push2ig.php set-ig-token            → nimmt config['instagram']['token']
    //   php push2ig.php set-ig-token "TOKEN…"   → nimmt das übergebene Token
    // Setzt 60 Tage Restlaufzeit (Dashboard-/Long-Lived-Token).
    if (php_sapi_name() === 'cli' && ($argv[1] ?? '') === 'set-ig-token') {
        try {
            ensureTables();
            $token  = $argv[2] ?? (string) ($config['instagram']['token'] ?? '');
            $source = isset($argv[2]) ? 'Argument' : 'config.php';
            if (trim($token) === '') {
                echo "Kein Token (weder als Argument noch in config['instagram']['token']).\n";
                exit(1);
            }
            // Token ERST gegen die API verifizieren – verhindert, dass ein
            // veralteter/widerrufener Token (z. B. aus der config) installiert wird
            $res = httpRequest('GET', igBase() . '/me', [
                'fields'       => 'username',
                'access_token' => $token,
            ]);
            if ($res['error'] !== null || empty($res['body']['username'])) {
                echo "ABBRUCH: Token (aus $source) ist UNGÜLTIG – nichts gespeichert.\n";
                echo "Fehler: " . (lastError() ?? 'unbekannt') . "\n";
                echo "→ Im Meta-Dashboard 'Token generieren' und frischen Token übergeben.\n";
                exit(1);
            }
            setSetting('ig_access_token', $token);
            setSetting('ig_token_expires_at', date('Y-m-d H:i:s', time() + 60 * 86400));
            p2iLog("IG-Token manuell gesetzt (aus $source, verifiziert für @{$res['body']['username']}, 60 Tage)");
            echo "Token verifiziert (@{$res['body']['username']}) und in der DB gesetzt, Ablauf in 60 Tagen.\n";
        } catch (Throwable $e) {
            p2iLog("FATAL: " . $e->getMessage(), 'ERROR');
            exit(1);
        }
        exit(0);
    }

    // CLI-Subcommand: Test-Alarm-Mail senden (End-to-End-Check der Zustellung).
    //   php push2ig.php test-alert
    if (php_sapi_name() === 'cli' && ($argv[1] ?? '') === 'test-alert') {
        try {
            ensureTables();
            clearAlert('test');   // Drossel umgehen, damit der Test immer sendet
            sendAlertOnce(
                'test',
                'push2ig: Test-Alarm',
                "Wenn du diese Mail liest, funktioniert der E-Mail-Alarm von push2ig.\n"
                . "Gesendet: " . date('Y-m-d H:i:s') . "\n"
                . "Absender laut config: " . ($config['alert_from'] ?? '(leer)') . "\n"
            );
            echo "Test-Alarm ausgelöst an " . ($config['alert_email'] ?? '(keine alert_email!)')
               . " – Posteingang UND Spam-Ordner prüfen.\n";
        } catch (Throwable $e) {
            p2iLog("FATAL: " . $e->getMessage(), 'ERROR');
            exit(1);
        }
        exit(0);
    }

    // CLI-Subcommand: neuen Pixelfed-Token aus der config in die DB setzen
    // (nach Neu-Autorisierung, z. B. read+write). Setzt 360 Tage Restlaufzeit.
    //   php push2ig.php set-pixelfed-token
    if (php_sapi_name() === 'cli' && ($argv[1] ?? '') === 'set-pixelfed-token') {
        try {
            ensureTables();
            $token = (string) ($config['pixelfed']['token'] ?? '');
            if (trim($token) === '') {
                echo "Kein Token in config['pixelfed']['token'].\n";
                exit(1);
            }
            // Token ERST verifizieren – verhindert, dass ein veralteter config-Token
            // installiert wird und dabei der ROTIERTE pf_refresh_token in der DB
            // mit dem alten (toten) config-Startwert überschrieben wird.
            $verify = httpRequest('GET',
                rtrim($config['pixelfed']['instance'], '/') . '/api/v1/accounts/verify_credentials',
                [], null, ['Authorization: Bearer ' . $token]);
            if ($verify['error'] !== null || empty($verify['body']['username'])) {
                echo "ABBRUCH: Token aus config.php ist UNGÜLTIG – nichts gespeichert.\n";
                echo "Fehler: " . (lastError() ?? 'unbekannt') . "\n";
                echo "→ OAuth-Flow neu durchlaufen und token+refresh_token in config.php aktualisieren.\n";
                exit(1);
            }
            setSetting('pf_access_token', $token);
            setSetting('pf_refresh_token', (string) ($config['pixelfed']['refresh_token'] ?? ''));
            setSetting('pf_token_expires_at', date('Y-m-d H:i:s', time() + 360 * 86400));
            p2iLog("Pixelfed-Token manuell gesetzt (aus config, verifiziert für @{$verify['body']['username']})");
            echo "Token verifiziert (@{$verify['body']['username']}) und in der DB gesetzt.\n";
        } catch (Throwable $e) {
            p2iLog("FATAL: " . $e->getMessage(), 'ERROR');
            exit(1);
        }
        exit(0);
    }

    // CLI-Subcommand: fehlgeschlagene Posts wieder in die Warteschlange (attempts=0).
    //   php push2ig.php requeue-failed
    if (php_sapi_name() === 'cli' && ($argv[1] ?? '') === 'requeue-failed') {
        try {
            ensureTables();
            $n = getDb()->exec("UPDATE push2ig_posts SET attempts = 0, last_error = NULL WHERE status = 'failed'");
            p2iLog("requeue-failed: $n Post(s) zurückgesetzt");
            echo "$n fehlgeschlagene Post(s) wieder in die Warteschlange gestellt.\n";
        } catch (Throwable $e) {
            p2iLog("FATAL: " . $e->getMessage(), 'ERROR');
            exit(1);
        }
        exit(0);
    }

    // CLI-Subcommand: einen Post erneut verarbeiten (löscht seinen DB-Eintrag,
    // damit er beim nächsten Lauf als neu erkannt wird). Für z. B. Posts, die
    // eine ältere Version als 'skipped' abgehakt hat.
    //   php push2ig.php reprocess <pixelfed-status-id>
    if (php_sapi_name() === 'cli' && ($argv[1] ?? '') === 'reprocess') {
        try {
            ensureTables();
            $srcId = (string) ($argv[2] ?? '');
            if ($srcId === '') {
                echo "Aufruf: php push2ig.php reprocess <pixelfed-status-id>\n";
                exit(1);
            }
            $st = getDb()->prepare("DELETE FROM push2ig_posts WHERE source_id = ?");
            $st->execute([$srcId]);
            $jb = getDb()->prepare("DELETE FROM push2ig_transcode_jobs WHERE source_id = ?");
            $jb->execute([$srcId]);
            p2iLog("reprocess: Post $srcId zurückgesetzt (" . $st->rowCount() . " Zeile[n])");
            echo "Post $srcId zurückgesetzt – wird beim nächsten Lauf neu verarbeitet.\n";
        } catch (Throwable $e) {
            p2iLog("FATAL: " . $e->getMessage(), 'ERROR');
            exit(1);
        }
        exit(0);
    }

    // Bei Web-Aufruf (URL-Cron) geheimen Schlüssel verlangen; CLI braucht keinen.
    if (php_sapi_name() !== 'cli') {
        $expected = (string) ($config['run_key'] ?? '');
        $given    = (string) ($_GET['key'] ?? '');
        if ($expected === '' || !hash_equals($expected, $given)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Forbidden";
            exit;
        }
    }

    // Web-Test der Alarm-Mail IM ECHTEN Web-Kontext (mail() verhält sich anders als CLI).
    //   https://…/push2ig.php?key=<run_key>&cmd=test-alert
    if (php_sapi_name() !== 'cli' && ($_GET['cmd'] ?? '') === 'test-alert') {
        header('Content-Type: text/plain; charset=utf-8');
        try {
            ensureTables();
            clearAlert('test');
            $ok = sendAlertOnce(
                'test',
                'push2ig: Test-Alarm (Web)',
                "Web-Kontext-Test des E-Mail-Alarms.\nGesendet: " . date('Y-m-d H:i:s') . "\n"
                . "Absender: " . ($config['alert_from'] ?? '(leer)') . "\n"
            );
            echo $ok
                ? "mail() OK – Test an " . ($config['alert_email'] ?? '?') . " ausgelöst. Posteingang + Spam prüfen.\n"
                : "mail() lieferte FALSE – Zustellung per PHP mail() klappt hier nicht (siehe Log).\n";
        } catch (Throwable $e) {
            http_response_code(500);
            echo "Fehler: " . $e->getMessage() . "\n";
        }
        exit;
    }

    try {
        main();
        if (php_sapi_name() !== 'cli') {
            header('Content-Type: text/plain; charset=utf-8');
            echo "OK";
        }
    } catch (Throwable $e) {
        p2iLog("FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString(), 'ERROR');
        if (php_sapi_name() !== 'cli') {
            http_response_code(500);
        }
        exit(1);
    }
}
