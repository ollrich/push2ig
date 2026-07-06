<?php
/**
 * tests.php – Mini-Testsuite für die reinen Funktionen von push2ig.
 *
 *   php tests.php        → Exit-Code 0 (alles grün) oder 1 (Fehlschläge)
 *
 * Läuft offline: keine DB-Verbindung, keine API-Calls. Getestet wird nur
 * Logik, die keine Seiteneffekte hat (Caption, Hashtags, Medienauswahl …).
 * Vor jedem Deploy/Commit einmal laufen lassen.
 */

declare(strict_types=1);
error_reporting(E_ALL);

require __DIR__ . '/push2ig.php';

$pass = 0;
$fail = 0;

function t(string $name, bool $cond): void {
    global $pass, $fail;
    echo ($cond ? '  ✓ ' : '✗ FAIL ') . $name . "\n";
    $cond ? $pass++ : $fail++;
}

echo "── htmlToCaption\n";
t('br → Zeilenumbruch, Tags weg, Entities dekodiert',
    htmlToCaption('Hallo <br /> <a href="x">#test</a> &amp; mehr') === "Hallo \n #test & mehr");
t('p-Absätze → Doppel-Umbruch',
    htmlToCaption('<p>Eins</p><p>Zwei</p>') === "Eins\n\nZwei");
t('3+ Leerzeilen werden auf eine reduziert',
    htmlToCaption("A<br/><br/><br/><br/>B") === "A\n\n\nB" || htmlToCaption("A<br/><br/><br/><br/>B") === "A\n\nB");

echo "── stripControlHashtags\n";
t('Control-Tag raus, Themen-Tag bleibt',
    stripControlHashtags('Strand #urlaub #igpost', ['igpost', 'igstory']) === 'Strand #urlaub');
t('mitten im Text',
    stripControlHashtags('Test #igstory mitten und #igpost', ['igpost', 'igstory']) === 'Test mitten und');
t('Wortgrenze: #igposting bleibt erhalten',
    stripControlHashtags('#igposting bleibt', ['igpost', 'igstory']) === '#igposting bleibt');
t('case-insensitiv',
    stripControlHashtags('Hi #IgPost', ['igpost']) === 'Hi');

echo "── igCaption (Instagram-Limit 2200)\n";
t('2500 → 2200 gekappt', mb_strlen(igCaption(str_repeat('ä', 2500))) === 2200);
t('kurze Caption unverändert', igCaption('Hallo #welt') === 'Hallo #welt');

echo "── hasHashtag\n";
t('exakter Match', hasHashtag(['tags' => [['name' => 'igpost']]], 'igpost') === true);
t('kein Substring-Match', hasHashtag(['tags' => [['name' => 'igposting']]], 'igpost') === false);
t('case-insensitiv', hasHashtag(['tags' => [['name' => 'IgStory']]], 'igstory') === true);
t('leerer Trigger → false', hasHashtag(['tags' => [['name' => 'x']]], '') === false);

echo "── pickVideo\n";
$vid = ['type' => 'video', 'url' => 'https://x/v.mp4'];
$img = ['type' => 'image', 'url' => 'https://x/b.jpg', 'description' => 'Alt'];
t('Einzelvideo erkannt', pickVideo(['media_attachments' => [$vid]]) === 'https://x/v.mp4');
t('gifv erkannt', pickVideo(['media_attachments' => [['type' => 'gifv', 'url' => 'u']]]) === 'u');
t('Bild → null', pickVideo(['media_attachments' => [$img]]) === null);
t('Mischpost → null (kein Reel)', pickVideo(['media_attachments' => [$vid, $img]]) === null);

echo "── pickFirstJpeg (Story-Bildauswahl)\n";
$r = pickFirstJpeg(['media_attachments' => [$vid, $img]]);
t('überspringt Video, nimmt Bild dahinter', ($r['url'] ?? '') === 'https://x/b.jpg' && ($r['alt'] ?? '') === 'Alt');
$r = pickFirstJpeg(['media_attachments' => [$vid]]);
t('nur Video → skip', isset($r['skip']));
$r = pickFirstJpeg(['media_attachments' => []]);
t('keine Medien → skip', isset($r['skip']));

echo "── pickSingleJpeg (Feed-Einzelbild)\n";
$r = pickSingleJpeg(['media_attachments' => [$img]]);
t('ein JPEG → url+alt', ($r['url'] ?? '') === 'https://x/b.jpg');
$r = pickSingleJpeg(['media_attachments' => [$img, $img]]);
t('zwei Bilder → skip (Carousel-Pfad)', isset($r['skip']));

echo "── pickCarouselJpegs\n";
$r = pickCarouselJpegs(['media_attachments' => [$img, ['type' => 'image', 'url' => 'https://x/c.jpg']]]);
t('2 JPEGs → images[2]', count($r['images'] ?? []) === 2);
$r = pickCarouselJpegs(['media_attachments' => [$img, $vid]]);
t('Video im Carousel → skip', isset($r['skip']));
$r = pickCarouselJpegs(['media_attachments' => [$img]]);
t('nur 1 Bild → skip', isset($r['skip']));

echo "── isJpegUrl (nur Endungs-Zweige, kein Netz)\n";
t('.jpg → true', isJpegUrl('https://x/a.jpg') === true);
t('.jpeg → true', isJpegUrl('https://x/a.JPEG') === true);
t('.png → false', isJpegUrl('https://x/a.png') === false);
t('.webp → false', isJpegUrl('https://x/a.webp') === false);

echo "── redactSecrets\n";
t('Query-Token maskiert', str_contains(redactSecrets('a?access_token=GEHEIM&b=1'), 'access_token=***'));
t('JSON-Token maskiert', str_contains(redactSecrets('{"refresh_token":"GEHEIM"}'), '"refresh_token":"***'));
t('client_secret maskiert', !str_contains(redactSecrets('client_secret=abc123'), 'abc123'));
t('grant_type bleibt lesbar', str_contains(redactSecrets('grant_type=refresh_token&client_secret=x'), 'grant_type=refresh_token'));

echo "── normalizeDatetime\n";
t('ISO-8601 mit ms', normalizeDatetime('2026-06-20T16:53:24.000Z') !== '' && strlen(normalizeDatetime('2026-06-20T16:53:24.000Z')) === 19);
t('Müll → fällt auf jetzt zurück (kein Crash)', strlen(normalizeDatetime('kein-datum')) === 19);

echo "\n═══ $pass bestanden, $fail fehlgeschlagen ═══\n";
exit($fail > 0 ? 1 : 0);
