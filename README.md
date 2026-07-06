# push2ig — Zwei-Wege-Sync zwischen Pixelfed und Instagram

Spiegelt Inhalte zwischen einem Pixelfed-Konto und einem Instagram-Business-Konto —
in beide Richtungen, gesteuert über Hashtags. PHP + MySQL, läuft als Cronjob auf
Shared Hosting (entwickelt auf All-Inkl). Videos werden über einen kleinen
externen Worker (z. B. auf einem NAS) mit ffmpeg transkodiert.

## Was es kann

**Pixelfed → Instagram** (Opt-in per Hashtag; der Control-Hashtag wird nach dem
Push aus der Instagram-Caption *und* aus dem Pixelfed-Post entfernt):

| Pixelfed-Post | → Instagram |
|---|---|
| `#igpost` + 1 Bild | Feed-Post (Caption + Alt-Text) |
| `#igpost` + 2–10 Bilder | Carousel (Alt-Text je Bild) |
| `#igpost` + Video | **Reel** (async, NAS-Transkodierung, ≤ 90 s) |
| `#igstory` + Bild | Story |
| `#igstory` + Video | **Video-Story** (async, NAS-Transkodierung, ≤ 60 s) |
| ohne Hashtag | wird ignoriert (`require_hashtag`) |

Nicht-JPEG-Bilder (PNG/WebP/GIF) werden automatisch per GD nach JPEG konvertiert
(Instagram akzeptiert nur JPEG).

**Instagram → Pixelfed:**

| Instagram | → Pixelfed |
|---|---|
| aktive Story (Bild) | Pixelfed-Story (Anzeigedauer `ig_story_duration`) |
| aktive Story (Video) | Pixelfed-Story (30 s, damit nichts gekappt wird) |

## Bekannte Einschränkungen (API-bedingt, nicht behebbar)

- **Story-Reshares** (in die Story geteilte Reels/Posts) und Stories mit
  **Musik-Sticker oder interaktiven Elementen** liefert die Instagram-API nicht
  aus → sie können nicht gespiegelt werden. Nur original hochgeladene,
  „cleane" Stories kommen an.
- Instagram-Stories haben **keine Caption/Alt-Text** (API-Limit).
- Der **Ort** eines Pixelfed-Posts kann beim Hashtag-Entfernen nicht erhalten
  werden (die Mastodon-API transportiert ihn nicht); `sensitive`-Flag und
  Content-Warnung bleiben erhalten.
- Instagram-Captions werden bei 2200 Zeichen gekappt (IG-Limit).
- Video im Carousel wird noch nicht unterstützt (ganzer Post wird übersprungen).

## Architektur

```
Pixelfed ──(Mastodon-API, Bearer)──► push2ig.php  (Cron alle 5 Min, All-Inkl)
                                        │  Bilder: direkt durchreichen/konvertieren
                                        │  Videos: Job-Queue in MySQL
                                        ▼
                                   transcode.php ◄──(X-Api-Key)── NAS-Worker (ffmpeg)
                                        │   next / result / fail
                                        ▼
                              media/*.mp4 (öffentlich, IG holt ab)
                                        │
Instagram ◄──(Graph-API: Container→FINISHED→publish)──┘
Instagram ──(GET /stories)──► push2ig.php ──(stories/add+publish)──► Pixelfed
```

- **Statusmaschine für Videos:** `transcoding → reel_processing|story_processing
  → posted` über mehrere Cron-Läufe (nicht-blockierend, timeout-sicher im
  URL-Cron); Posts, die > 48 h feststecken, werden automatisch aufgegeben.
- **Token-Verwaltung:** Beide Tokens liegen in der DB und werden automatisch
  refreshed (Instagram ~60 Tage, Pixelfed ~1 Jahr, Laravel-Passport-Rotation).
  Jeder Lauf prüft den IG-Token aktiv; bei Widerruf (Error 190) gibt es
  **einmal pro 6 h eine Alarm-E-Mail** — ein IG-Ausfall löst keinen Fehlalarm aus.

## Dateien

| Datei | Zweck |
|---|---|
| `push2ig.php` | Hauptscript: kompletter Sync, Statusmaschine, Wartungs-CLI |
| `transcode.php` | Job-Queue-Endpunkt für den NAS-Transkodier-Worker |
| `data-deletion.php` | Meta-Datenlöschungs-Callback (für Live-Modus der App) |
| `seed.php` | Einmalig: Bestandsposts als „bereits verarbeitet" markieren |
| `test.php` | Dry-Run: zeigt je Post das Routing-Ziel, ohne zu posten |
| `tests.php` | Offline-Testsuite der reinen Funktionen (`php tests.php`) |
| `dbcheck.php` | Prüft nur die MySQL-Verbindung |
| `config.example.php` | Konfigurationsvorlage (alle Optionen kommentiert) |
| `config.php` | **Echte Zugangsdaten — nicht im Repo** (`.gitignore`) |
| `.htaccess` | Sperrt Config/Logs/Helfer gegen Web-Zugriff |
| `video-transcoding-nas.md` | Spezifikation für den NAS-Worker (ffmpeg-Befehle) |

## Setup

1. **Konten/Apps:**
   - Instagram: Meta-App („Instagram API with Instagram Login"), eigenes Konto
     als Instagram-Tester, Long-Lived Token generieren. Für dauerhafte Token-
     Stabilität die App in den **Live-Modus** schalten (braucht Datenschutz-URL
     + Datenlöschung — `data-deletion.php` als Rückruf-URL eintragen).
   - Pixelfed: App per `POST /api/v1/apps` registrieren (Scope `read write`),
     OAuth-Flow durchlaufen → `token` + `refresh_token`.
2. **`config.example.php` → `config.php`** kopieren und ausfüllen.
3. **Hochladen** (Web-Root-Unterordner, z. B. `/push2ig/`): `push2ig.php`,
   `transcode.php`, `data-deletion.php`, `config.php`, `seed.php`, `dbcheck.php`,
   **`.htaccess`** (Pflicht — sperrt die Config gegen Web-Zugriff!).
4. **Per SSH:** `php dbcheck.php` (Verbindung ok?) → `php seed.php` (markiert
   alle Bestandsposts, damit nichts rückwirkend gepusht wird).
5. **Cron einrichten** (alle 5 Min):
   - URL-Cron: `https://DOMAIN/push2ig/push2ig.php?key=<run_key>`
   - oder CLI: `*/5 * * * * /usr/bin/php /pfad/push2ig/push2ig.php >/dev/null 2>&1`
6. **NAS-Worker** nach `video-transcoding-nas.md` aufsetzen (nur für Video nötig;
   ohne Worker bleiben Video-Posts in der Queue, Bilder laufen unabhängig).
7. **Alarm testen:** `https://DOMAIN/push2ig/push2ig.php?key=<run_key>&cmd=test-alert`
   → Mail muss ankommen (Spam-Ordner prüfen).

## Wartungs-CLI (per SSH)

| Befehl | Zweck |
|---|---|
| `php push2ig.php` | Ein Lauf manuell (wie der Cron) |
| `php push2ig.php import-stories` | IG-Story-Import sofort ausführen (auch wenn deaktiviert) |
| `php push2ig.php set-ig-token "TOKEN"` | Neuen IG-Token setzen (wird **vorher verifiziert**) |
| `php push2ig.php set-pixelfed-token` | Pixelfed-Token aus config in DB (wird **vorher verifiziert**) |
| `php push2ig.php refresh-pixelfed` | Pixelfed-Token-Refresh erzwingen |
| `php push2ig.php requeue-failed` | Alle `failed`-Posts erneut versuchen |
| `php push2ig.php reprocess <id>` | Einen Post komplett neu verarbeiten (löscht seinen DB-Eintrag) |
| `php push2ig.php test-alert` | Alarm-Mail testen (⚠ `mail()` ist im CLI auf All-Inkl defekt — den Web-Test nutzen, s. Setup 7) |
| `php tests.php` | Offline-Testsuite |

## Wichtige Config-Optionen (vollständige Liste: `config.example.php`)

- `post_hashtag` / `story_hashtag` — die Control-Hashtags (Standard `igpost`/`igstory`)
- `require_hashtag` — `true`: nur getaggte Posts pushen
- `remove_push_hashtags` — Control-Tag nach Push aus dem Pixelfed-Post löschen (braucht write-Scope)
- `import_ig_stories` — IG-Stories → Pixelfed an/aus
- `public_base_url` — öffentliche Basis-URL des Ordners (für konvertierte/transkodierte Medien)
- `transcode_secret` — Shared Secret für den NAS-Worker (Header `X-Api-Key` oder `?key=`)
- `alert_email` / `alert_from` — Token-Alarm (Absender muss SPF-konform zur Hosting-Domain sein!)
- `run_key` — Schutz des URL-Crons

## Status-Werte (`push2ig_posts.status`)

| Status | Bedeutung |
|---|---|
| `seeded` | Bestandspost (vor dem ersten Lauf), wird nie gepusht |
| `posted` | Erfolgreich auf Instagram veröffentlicht |
| `transcoding` | Video wartet auf den NAS-Worker |
| `reel_processing` / `story_processing` | Instagram verarbeitet den Video-Container |
| `failed` | Fehler; wird bis `max_attempts` erneut versucht (Videos: 48-h-Timeout) |
| `skipped` | Bewusst übersprungen (kein Hashtag, kein brauchbares Medium …), kein Retry |

Die Spalte `target` (`feed`/`story`/`reel`) hält fest, wohin gepusht wurde.
Nachträglich getaggte Posts werden **nicht** automatisch erkannt (`skipped` ist
endgültig) — dafür gibt es `reprocess <id>`.

## Logging & Fehlersuche

- Alles läuft nach `push2ig.log` (rotiert ab `log_max_bytes`). Leerlauf-Läufe
  loggen genau **eine** Zeile inkl. Token-Restlaufzeiten.
- Bei Fehlern steht die konkrete API-Antwort (Pixelfed/Instagram) im Log;
  Tokens werden automatisch maskiert. Je Post zusätzlich `last_error` in der DB.
- `debug_log => true` schreibt jede API-Anfrage/-Antwort mit (nur zur Fehlersuche).
- Job-Queue einsehen: `transcode.php?action=list&key=<transcode_secret>`.

## Sicherheit

- `config.php` (alle Secrets) ist per `.htaccess` gegen Web-Zugriff gesperrt;
  Helfer-Scripte ebenso. `push2ig.php` verlangt im Web den `run_key`,
  `transcode.php` das `transcode_secret`, `data-deletion.php` verifiziert die
  Meta-Signatur.
- Der Mirror braucht Pixelfed-**write** nur für das Entfernen des Control-Hashtags
  und das Erstellen von Stories.
