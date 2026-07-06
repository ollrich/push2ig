<?php
/**
 * data-deletion.php — Data Deletion Request Callback für die Meta-App.
 *
 * Meta ruft diese URL per POST auf, wenn ein Nutzer die Löschung seiner Daten
 * verlangt (z. B. die App bei Instagram entfernt). Der Callback prüft die
 * Signatur (falls App-Geheimcode hinterlegt), protokolliert die Anfrage und
 * antwortet mit dem von Meta geforderten JSON: { url, confirmation_code }.
 *
 * Im Meta-Dashboard unter „Löschung der Userdaten" → Dropdown „Rückruf-URL"
 * eintragen:  <public_base_url>/data-deletion.php
 *
 * Hinweis: Der Callback hat KEINE Seiteneffekte auf den laufenden Betrieb
 * (er löscht keine Live-Tokens), damit ein Test-/Fehlaufruf den Mirror nicht
 * lahmlegt. push2ig verarbeitet ohnehin nur die eigenen Daten des Betreibers.
 */
declare(strict_types=1);
ini_set('display_errors', '0');

// Config: bevorzugt außerhalb des Web-Roots, Fallback lokal (s. push2ig.php)
$configFile = dirname(__DIR__, 2) . '/push2ig-config.php';
if (!is_file($configFile)) {
    $configFile = __DIR__ . '/config.php';
}
$config  = @include $configFile;
$secret  = is_array($config) ? (string) ($config['meta_app_secret'] ?? '') : '';
$contact = is_array($config) ? trim((string) ($config['contact_email'] ?? '')) : '';
$pubBase = is_array($config) ? rtrim((string) ($config['public_base_url'] ?? ''), '/') : '';

function b64url_decode(string $s): string {
    return base64_decode(strtr($s, '-_', '+/'));
}

/** Signed-Request von Meta parsen und (falls Secret vorhanden) verifizieren. */
function parse_signed_request(string $signed, string $secret): ?array {
    if (strpos($signed, '.') === false) {
        return null;
    }
    [$encSig, $payload] = explode('.', $signed, 2);
    $data = json_decode(b64url_decode($payload), true);
    if (!is_array($data)) {
        return null;
    }
    if ($secret !== '') {
        $expected = hash_hmac('sha256', $payload, $secret, true);
        if (!hash_equals($expected, b64url_decode($encSig))) {
            return null;   // ungültige Signatur
        }
    }
    return $data;
}

// Kanonische eigene URL (bevorzugt aus config public_base_url)
$self = $pubBase !== ''
    ? $pubBase . '/data-deletion.php'
    : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
      . strtok($_SERVER['REQUEST_URI'] ?? '/data-deletion.php', '?');

// ── Statusseite (GET, optional mit ?id=CODE) ───────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $code = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($_GET['id'] ?? ''));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><meta charset="utf-8">'
       . '<meta name="robots" content="noindex"><title>Datenlöschung – push2ig</title>'
       . '<body style="font-family:sans-serif;max-width:640px;margin:40px auto;padding:0 20px;line-height:1.6;color:#222">'
       . '<h1>Datenlöschung</h1>';
    echo $code !== ''
        ? '<p>Deine Datenlöschungsanfrage wurde bearbeitet.<br>Bestätigungscode: <code>'
          . htmlspecialchars($code) . '</code></p>'
          . '<p>Von der Anwendung „push2ig" gespeicherte Zugriffstokens und Statusdaten '
          . 'werden gelöscht bzw. sind ungültig.</p>'
        : '<p>Diese Seite bestätigt Datenlöschungsanfragen der Anwendung „push2ig".'
          . ($contact !== ''
              ? '<br>Anfragen können auch per E-Mail an <a href="mailto:'
                . htmlspecialchars($contact) . '">' . htmlspecialchars($contact) . '</a> gestellt werden.'
              : '')
          . '</p>';
    echo '</body></html>';
    exit;
}

// ── Callback (POST mit signed_request) ─────────────────────────────
$signed = (string) ($_POST['signed_request'] ?? '');
$data   = $signed !== '' ? parse_signed_request($signed, $secret) : [];

if ($signed !== '' && $data === null) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid signed_request']);
    exit;
}

$userId = (string) ($data['user_id'] ?? 'unknown');
$code   = bin2hex(random_bytes(8));

@file_put_contents(
    __DIR__ . '/push2ig.log',
    date('Y-m-d H:i:s') . " [INFO] Data-Deletion-Request user=$userId code=$code\n",
    FILE_APPEND | LOCK_EX
);

header('Content-Type: application/json');
echo json_encode([
    'url'               => $self . '?id=' . $code,
    'confirmation_code' => $code,
]);
