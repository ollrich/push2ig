<?php
/**
 * push2ig – Pixelfed → Instagram Mirror – Konfiguration
 *
 * Kopiere diese Datei nach config.php und trage deine Zugangsdaten ein.
 * config.php NICHT ins Repo committen (steht in .gitignore)!
 */

return [
    // ── Quelle: Pixelfed (Mastodon-kompatible API) ──────────────────
    'pixelfed' => [
        'instance'   => 'https://pixelfed.de',
        'account_id' => '000000000000000000',   // numerische ID, via /api/v1/accounts/lookup?acct=HANDLE
        'handle'     => 'deinhandle',            // nur für Logs/Lesbarkeit
        // Bearer-Token (read-Scope). Gilt bei Pixelfed lange (~1 Jahr).
        // Wird beim ersten Lauf in die DB übernommen und danach automatisch
        // refreshed. Hier nur der Start-Token.
        'token'      => 'PIXELFED_ACCESS_TOKEN',
        // Für den Auto-Refresh (OAuth/Laravel Passport, grant_type=refresh_token):
        // client_id/secret stammen aus der App-Registrierung (POST /api/v1/apps),
        // refresh_token aus dem initialen Token-Tausch (/oauth/token).
        'client_id'     => 'OAUTH_CLIENT_ID',
        'client_secret' => 'OAUTH_CLIENT_SECRET',
        'refresh_token' => 'PIXELFED_REFRESH_TOKEN',
    ],

    // ── Ziel: Instagram (Instagram API with Instagram Login) ────────
    'instagram' => [
        'app_id'     => '0000000000000000',
        // App Secret NUR nötig für komplette Neu-Autorisierung (OAuth),
        // NICHT für den laufenden Token-Refresh. Darf leer bleiben.
        'app_secret' => '',
        'user_id'    => '00000000000000000',
        'handle'     => 'deinhandle',            // nur für Logs
        // Long-Lived Token. Wird beim ersten Lauf in die DB übernommen
        // und danach automatisch refreshed. Hier nur der Start-Token.
        'token'      => 'INSTAGRAM_LONG_LIVED_TOKEN',
        // Graph-API-Version
        'api_version' => 'v25.0',
    ],

    // ── MySQL (All-Inkl) ────────────────────────────────────────────
    'db' => [
        'host' => 'localhost',
        'name' => 'db_XXXXXX',
        'user' => 'db_XXXXXX',
        'pass' => 'DEIN_DB_PASSWORT',
    ],

    // ── Verhalten ───────────────────────────────────────────────────
    // Wie viele Posts pro API-Seite holen
    'batch_size' => 20,
    // Wie viele Seiten pro Lauf maximal durchblättern (Burst-Schutz).
    // batch_size * max_pages = max. erfassbare neue Posts pro Lauf.
    'max_pages'  => 10,
    // Wie oft ein fehlgeschlagener Post erneut versucht wird, bevor
    // er endgültig als 'failed' aufgegeben wird (transiente Fehler)
    'max_attempts' => 5,

    // Opt-in-Push per Hashtag (ohne #). Posts MIT diesem Tag werden als …
    'story_hashtag' => 'igstory',   // … Instagram-STORY gepostet
    'post_hashtag'  => 'igpost',    // … Instagram-FEED-Post gepostet
    // true  = NUR getaggte Posts pushen (alles ohne Tag wird ignoriert)
    // false = alte Logik (jeder Post → Feed, story_hashtag → Story)
    'require_hashtag' => true,
    // Control-Hashtags nach erfolgreichem Push aus dem Pixelfed-Post löschen.
    // Braucht einen Pixelfed-Token mit write-Scope (Neu-Autorisierung)!
    'remove_push_hashtags' => false,

    // Rückrichtung: aktive Instagram-Stories als Pixelfed-Stories spiegeln.
    // Braucht ebenfalls einen Pixelfed-Token mit write-Scope.
    'import_ig_stories'  => false,   // true = an
    'ig_story_duration'  => 10,      // Anzeigedauer für BILD-Stories (Sek., 0–30).
                                     // Video-Stories nutzen fix 30 s, damit nichts gekappt wird.
    'ig_story_can_reply' => true,
    'ig_story_can_react' => true,

    // E-Mail-Alarm, wenn der Instagram-Token ungültig ist (Neu-Autorisierung nötig).
    // Leer lassen ('') = kein Alarm. Max. 1 Mail pro 6 h.
    'alert_email' => '',
    // Absender der Alarm-Mails. MUSS zu einer Domain gehören, für die der
    // Server senden darf (SPF!), z. B. deine eigene Hosting-Domain.
    'alert_from'  => 'noreply@example.org',
    // Kontaktadresse für Datenschutz-/Löschanfragen (data-deletion.php).
    'contact_email' => '',

    // App-Geheimcode der Meta-App (Basic Settings → „Anzeigen") – verifiziert die
    // Signatur des Datenlöschungs-Callbacks (data-deletion.php).
    'meta_app_secret' => '',

    // Öffentliche Basis-URL des push2ig-Ordners – für konvertierte Bilder/Medien,
    // die Instagram abrufen können muss. Ohne abschließenden Slash.
    'public_base_url' => 'https://DEINE-DOMAIN/push2ig',

    // Gemeinsames Geheimnis zwischen push2ig und dem NAS-Transkodier-Worker
    // (transcode.php). Im NAS-Worker identisch hinterlegen. Eigenen Wert setzen:
    //   php -r 'echo bin2hex(random_bytes(24));'
    'transcode_secret' => '',
    'transcode_max_bytes' => 314572800,   // 300 MB max. Ergebnis-Video

    // Instagram-Token refreshen, wenn die Restlaufzeit darunter fällt (Tage)
    'token_refresh_threshold_days' => 7,
    // Pixelfed-Token refreshen, wenn die Restlaufzeit darunter fällt (Tage).
    // Pixelfed-Token gilt ~1 Jahr, daher größerer Puffer.
    'pixelfed_token_refresh_threshold_days' => 30,

    // Instagram Container-Status pollen: max. Versuche und Pause (Sek.)
    // zwischen den Checks, bis status_code = FINISHED
    'container_poll_attempts' => 20,
    'container_poll_delay'    => 3,

    // HTTP-Timeout (Sek.)
    'http_timeout' => 30,
    // Sofort-Retries bei transienten Fehlern (HTTP 429 / Verbindungsfehler)
    'retry_count'  => 2,    // zusätzliche Versuche pro Request
    'retry_delay'  => 3,    // Sekunden Pause zwischen den Versuchen

    // Lockdatei – verhindert, dass sich zwei Cron-Läufe überlappen
    'lock_file' => __DIR__ . '/push2ig.lock',
    // Log-Datei (relativ zum Script-Verzeichnis)
    'log_file'  => __DIR__ . '/push2ig.log',
    // Log ab dieser Größe (Bytes) rotieren; 0 = nie rotieren
    'log_max_bytes' => 5000000,
    // Debug-Logging: schreibt JEDE Pixelfed-/Instagram-Anfrage samt Antwort
    // (Token wird redacted) ins Log. Für Fehlersuche einschalten, sonst false.
    'debug_log' => false,

    // Geheimer Schlüssel für den URL-Cron-Aufruf. Erforderlich, wenn der Cron
    // push2ig.php per HTTP aufruft (z. B. All-Inkl). Eigenen Wert eintragen, z. B.
    //   php -r 'echo bin2hex(random_bytes(16));'
    // Cron-URL: https://DEINE-DOMAIN/pfad/push2ig.php?key=<run_key>
    // Bei CLI-Aufruf (php push2ig.php) wird der Key nicht gebraucht.
    'run_key' => 'HIER_EIGENEN_ZUFALLSWERT_EINTRAGEN',
];
