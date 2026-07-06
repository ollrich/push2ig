# push2ig — Video-Transkodierung per NAS: Anforderungen

Handoff-Spezifikation für den separaten Task „ffmpeg auf dem NAS bereitstellen".

## 1. Warum das nötig ist

Das Video-Feature von push2ig braucht **ffmpeg**, um Videos ins strikte
Instagram-Reels-Format umzuwandeln. push2ig läuft auf **All-Inkl Shared Hosting**,
das **kein ffmpeg** anbietet (kein `exec`, kein Binary). Daher übernimmt das
**NAS** die Transkodierung.

Hauptanwendungsfall: **Pixelfed-Video-Post → Instagram-Reel**. Instagram nimmt
Feed-Video nur noch als Reel, mit harten Format-Vorgaben (s. u.). Pixelfeds
Video-Datei trifft diese Spec nicht garantiert → muss neu enkodiert werden.

(Nebenfall „IG-Video-Story → Pixelfed-Story" braucht meist **keine** volle
Transkodierung — Pixelfed akzeptiert MP4 direkt —, evtl. nur `ffprobe` zum
Auslesen der Videolänge. Siehe Abschnitt 6.)

## 2. Architektur (empfohlen): NAS holt Jobs ab

```
  Pixelfed ──(Video-Post)──► push2ig (All-Inkl, immer online)
                                 │  legt Transkodier-Job in DB an, wartet
                                 ▼
                          transcode.php  (Job-Queue + Ergebnis-Ablage)
                                 ▲   ▲
              (1) GET next job   │   │  (2) POST Ergebnis (MP4 + Dauer)
                                 │   │
                            NAS-Worker (ffmpeg)   ◄─ läuft, WENN das NAS an ist
                                 │
                     lädt Quellvideo, transkodiert, lädt Ergebnis hoch
                                 ▼
  push2ig ──(nächster Lauf)──► Instagram (Reel mit öffentlicher Ergebnis-URL)
```

**Warum dieses Modell (auch wenn das NAS aus dem Internet erreichbar ist):**
- **Der eigentliche Grund — PHP-Zeitlimit:** push2ig läuft per URL-Cron im
  **Web-Kontext** mit begrenzter `max_execution_time`. Ein langer, blockierender
  Transkodier-Call (Video-Encoding dauert Sekunden bis Minuten) würde dort ins
  **Timeout** laufen. Beim Pull-Modell macht push2ig **nie** einen langen Call —
  es legt nur einen Job ab; die Rechenzeit passiert komplett auf dem NAS.
- **Einfacher & sicherer am NAS:** nur ein **Worker-Skript + Scheduler**, kein
  öffentlicher Web-Service auf dem NAS, den man absichern/mit Zertifikat betreiben
  müsste.
- Das NAS muss **nicht durchgehend laufen** — offene Jobs warten und werden
  abgearbeitet, sobald das NAS wieder läuft.
- Das immer-online All-Inkl-Webspace hostet das Ergebnis-Video öffentlich →
  Instagram kann es jederzeit abrufen, auch wenn das NAS danach wieder aus ist.

**Alternative (NAS als HTTP-Service, da erreichbar):** push2ig ruft das NAS direkt
auf. Dann aber zwingend **asynchron** (Job einreichen → Status pollen → Ergebnis
holen), wegen des PHP-Zeitlimits — was Job-Verwaltung *auf dem NAS* erfordert und
unterm Strich mehr Aufwand ist als das Pull-Skript. Möglich, aber nicht empfohlen.
Die Erreichbarkeit des NAS könnte man alternativ dafür nutzen, das Ergebnis direkt
vom NAS hosten zu lassen — davon rät der intermittierende Betrieb aber ab (wenn das
NAS beim Instagram-Abruf gerade aus ist, schlägt der Abruf fehl).

## 3. HTTP-Schnittstelle (baue ich auf der push2ig-Seite; das NAS muss sie bedienen)

Basis-URL (Beispiel): `https://eichhof.co/push2ig/transcode.php`
Alle Aufrufe über **HTTPS** mit gemeinsamem Geheimnis `key=<SHARED_SECRET>`.

**a) Nächsten Job abholen**
```
GET  transcode.php?action=next&key=<SHARED_SECRET>
→ 200 JSON:  {"job_id":"123","source_url":"https://pixelfed.de/…/video.mp4",
              "kind":"reel","max_duration":90}
→ 204 (kein offener Job)
```

**b) Ergebnis abliefern** (nach erfolgreicher Transkodierung)
```
POST transcode.php?action=result&key=<SHARED_SECRET>
     multipart/form-data:
       job_id   = 123
       duration = 27          (Sekunden, ganzzahlig – aus ffprobe)
       file     = @out.mp4    (die transkodierte Datei)
→ 200 {"ok":true}
```

**c) Fehler melden** (Transkodierung fehlgeschlagen)
```
POST transcode.php?action=fail&key=<SHARED_SECRET>
     job_id = 123
     error  = "kurze Fehlerbeschreibung"
→ 200 {"ok":true}
```

Der Worker-Ablauf ist also: `next` → (Datei laden, ffmpeg) → `result` bzw. `fail`.
Idealerweise pro Durchlauf so lange `next` abfragen, bis 204 kommt (alle Jobs
leeren), immer nur ein Job gleichzeitig.

## 4. Ziel-Format (Instagram Reels)

Die transkodierte Datei MUSS erfüllen:

| Eigenschaft | Vorgabe |
|---|---|
| Container | MP4, **`moov`-Atom vorne** (faststart), keine edit lists |
| Video-Codec | H.264 (High Profile), `yuv420p` |
| Audio-Codec | AAC, 48 kHz, Stereo |
| Seitenverhältnis | **9:16**, Ziel 1080×1920 (Rest schwarz padden) |
| Framerate | 23–60 fps (30 ok) |
| Dauer | **≤ 90 s** (harte API-Grenze; länger → kürzen oder Job als „fail") |

## 5. ffmpeg-Referenzbefehl (Reel)

```bash
ffmpeg -y -i "$INPUT" \
  -t 90 \
  -vf "scale='trunc(iw*sar/2)*2':ih,\
scale=1080:1920:force_original_aspect_ratio=decrease,\
pad=1080:1920:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1,fps=30" \
  -c:v libx264 -profile:v high -level:v 4.1 -pix_fmt yuv420p \
  -b:v 5M -maxrate 6M -bufsize 8M \
  -c:a aac -b:a 128k -ar 48000 -ac 2 \
  -movflags +faststart \
  "$OUTPUT"
```

- **`scale='trunc(iw*sar/2)*2':ih` (erster Filter) ist entscheidend:** viele
  Handy-/Pixelfed-Videos sind **anamorph** gespeichert (z. B. 1080×720 kodiert mit
  SAR 3:8 = Anzeige 9:16). ffmpegs `scale` wendet die SAR **nicht** automatisch an
  → ohne diesen Schritt wird das Bild **gequetscht**. Der Filter rechnet die SAR in
  echte (quadratische) Pixel um. Bei SAR 1:1 ist es ein No-op, schadet also nie.
- Zweites `scale` skaliert das (jetzt korrekte) Bild größtmöglich in 1080×1920 und
  padded den Rest schwarz → sauberes 9:16, egal welches Quellformat.
- `-t 90` kappt hart bei 90 s (bei `kind=story` stattdessen `max_duration`=60).
- `-movflags +faststart` schiebt den `moov`-Atom nach vorne (Pflicht für IG).

## 5b. Remux-first: nur bei Bedarf neu enkodieren (Rechenzeit sparen)

Der volle Befehl aus Abschnitt 5 **decodiert und enkodiert neu** — langsam und mit
Qualitätsverlust. Das ist oft **unnötig**: Ist das Quellvideo bereits konform,
genügt ein **Remux** (Container umpacken, `moov`-Atom nach vorn) **ohne** Decode —
in Sekunden und verlustfrei.

**Vorgehen: erst mit `ffprobe` prüfen, dann entscheiden.**

Konform ist ein Video, wenn **alles** zutrifft:
- Video-Codec `h264`, Audio-Codec `aac`
- **SAR (sample_aspect_ratio) = 1:1** — sonst ist das Video anamorph gespeichert
  und würde beim reinen Remux gequetscht → dann **immer** neu enkodieren (Abschnitt 5)!
- Seitenverhältnis (DAR) ≈ **9:16** (0,5625; kleine Toleranz ok)
- Dauer **≤ max_duration** des Jobs (Reel 90 s / Story 60 s)
- `pix_fmt yuv420p`

Prüf-Werte auslesen:
```bash
# Codec Video / Audio
ffprobe -v error -select_streams v:0 -show_entries stream=codec_name,width,height,pix_fmt \
  -of default=nw=1 "$INPUT"
ffprobe -v error -select_streams a:0 -show_entries stream=codec_name -of default=nw=1 "$INPUT"
# Dauer
ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 "$INPUT"
```

**Fall A — konform → nur Remux (kein Decode):**
```bash
ffmpeg -y -i "$INPUT" -c copy -movflags +faststart "$OUTPUT"
```

**Fall B — nicht konform → volles Re-Encode** mit dem Befehl aus Abschnitt 5.

So läuft nur durch den teuren Encoder, was wirklich muss. Wenn dir die Prüflogik
zu fummelig ist: der Befehl aus Abschnitt 5 funktioniert **immer** (ist nur
langsamer) — Remux-first ist eine **Optimierung**, keine Pflicht.

## 6. Videolänge auslesen (für Story-Videos)

Für den Story-Fall (und um Reels > 90 s zu erkennen):
```bash
ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 "$FILE"
```
Gibt die Dauer in Sekunden zurück (dann auf ganze Sekunden runden).

## 7. Anforderungen an das NAS

- **ffmpeg + ffprobe**, aktuelle Version (Docker-Image z. B. `linuxserver/ffmpeg`
  oder `jrottenberg/ffmpeg`, oder natives Paket via Entware/Synology).
- **curl** (oder gleichwertig) für die HTTPS-Calls aus Abschnitt 3.
- Ein **Scheduler**, der den Worker regelmäßig startet, wenn das NAS läuft
  (Synology Aufgabenplaner / QNAP Cron / crontab), z. B. alle 5–15 Min.
- **Temp-Speicher** für Quell- und Zieldatei + **Aufräumen** nach jedem Job.
- **Ausgehendes Internet** (HTTPS zu All-Inkl und zu den Medien-URLs).
- Sprache des Workers beliebig (Bash+curl, Python, …) — solange sie die drei
  HTTP-Endpunkte bedient.

## 8. Sicherheit

- Gemeinsames Geheimnis `key` (langer Zufallsstring), nur über **HTTPS**.
- Keine offenen Ports am NAS (Modell „NAS holt ab").
- Größen- und Zeitlimits am Worker (z. B. max. Quellvideo 500 MB,
  ffmpeg-Timeout), damit ein Ausreißer den Worker nicht blockiert.

## 9. Verfügbarkeit / Fehlerbehandlung

- **NAS aus:** Jobs bleiben `pending` und werden verarbeitet, sobald das NAS
  wieder läuft. Reels erscheinen also verzögert — bewusst akzeptiert.
- **Transkodierung schlägt fehl:** Worker meldet `action=fail`; push2ig markiert
  den Post als fehlgeschlagen (mit Retry-Limit) und überspringt ihn danach.
- **Ergebnis-URL:** liegt auf All-Inkl (immer online), damit Instagram das Video
  jederzeit abrufen kann.

## 10. Was ich (push2ig-Seite) dazu baue — NICHT Teil des NAS-Tasks

- `transcode.php` mit Job-Queue-Tabelle und den Endpunkten aus Abschnitt 3.
- Öffentliche Ablage der Ergebnis-Videos (z. B. `…/push2ig/media/<id>.mp4`).
- Video-Erkennung im Haupt-Flow: Pixelfed-Post mit Video + `#igpost` → Reel-Job
  anlegen statt sofort zu posten; nach Ergebnis via `media_type=REELS`
  veröffentlichen.
- Config: `transcode_secret` (= der `key`), Basis-URL.

## 11. Offene Entscheidungen (für den NAS-Task)

- Worker-Sprache & Poll-Intervall.
- Umgang mit Videos > 90 s: hart auf 90 s kürzen (Referenzbefehl) **oder** als
  `fail` melden? (Vorschlag: kürzen.)
- Ob der Worker mehrere Jobs pro Lauf abarbeitet (empfohlen: ja, bis `204`).

---

**Kurzfassung für den NAS-Task:** Ein kleiner Worker, der regelmäßig bei
`transcode.php?action=next` einen Job abholt, das verlinkte Video herunterlädt,
mit dem ffmpeg-Befehl aus Abschnitt 5 ins Reels-Format wandelt und das Ergebnis
per `action=result` zurücklädt. Kein offener Port, kein Dauerbetrieb nötig.
