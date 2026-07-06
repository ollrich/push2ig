<?php
/**
 * test.php – Dry-Run OHNE auf Instagram zu posten.
 *
 *   php test.php
 *
 * Holt die neuesten Pixelfed-Media-Posts und zeigt für jeden,
 *  - das ZIEL (Feed-Post oder Story, je nach Story-Hashtag),
 *  - ob er gepostet würde oder warum er übersprungen wird,
 *  - die fertige Instagram-Caption (nur Feed), Bild-URL und Alt-Text.
 * Prüft außerdem, ob der Instagram-Token grundsätzlich gültig ist.
 *
 * Hinweis: Nutzt die DB-gestützten Tokens (wie der echte Lauf) und braucht
 * daher eine DB-Verbindung → auf dem Server (per SSH) ausführen.
 */

declare(strict_types=1);
error_reporting(E_ALL);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/push2ig.php';

ensureTables();
getPixelfedToken();   // ggf. aus Config seeden

echo "══ Pixelfed: neueste Media-Posts ══\n";
$posts = fetchRecentStatuses(10);
echo "Gefunden: " . count($posts) . "\n\n";

foreach ($posts as $status) {
    $id = (string) ($status['id'] ?? '');
    echo "── Post $id  ({$status['created_at']}, {$status['visibility']})\n";

    if (!empty($status['in_reply_to_id']) || !empty($status['reblog'])) {
        echo "   → wird ignoriert (Reply/Reblog)\n\n";
        continue;
    }

    $isStory    = isStoryTagged($status);
    $isPost     = isPostTagged($status);
    $requireTag = !empty($config['require_hashtag']);
    $mediaCount = count($status['media_attachments'] ?? []);

    if (!$isStory && !$isPost && $requireTag) {
        echo "   ZIEL: — ignoriert (kein #{$config['post_hashtag']}/#{$config['story_hashtag']})\n\n";
        continue;
    }

    if ($isStory) {
        // Video-Story → NAS-Transkodierung (kind=story, ≤60 s)
        if (($v = pickVideo($status)) !== null) {
            echo "   ZIEL: VIDEO-STORY (→ Transkodier-Job kind=story ≤60 s)\n     Video: $v\n\n";
            continue;
        }
        echo "   ZIEL: STORY\n";
        $pick = pickFirstJpeg($status);
        if (isset($pick['skip'])) { echo "   → SKIP: {$pick['skip']}\n\n"; continue; }
        echo "   → würde gesendet:\n     Bild:  {$pick['url']}\n";
        if ($mediaCount > 1) {
            echo "     (Story: nur erstes von $mediaCount Bildern)\n";
        }
        echo "\n";
        continue;
    }

    // Video im Feed → Reel über NAS-Transkodierung (kind=reel, ≤90 s)
    if (($v = pickVideo($status)) !== null) {
        echo "   ZIEL: REEL (→ Transkodier-Job kind=reel ≤90 s)\n     Video: $v\n\n";
        continue;
    }

    if ($mediaCount > 1) {
        echo "   ZIEL: FEED (Carousel)\n";
        $cpick = pickCarouselJpegs($status);
        if (isset($cpick['skip'])) { echo "   → SKIP: {$cpick['skip']}\n\n"; continue; }
        echo "   → würde gesendet: " . count($cpick['images']) . " Bilder:\n";
        foreach ($cpick['images'] as $i => $img) {
            echo "     [$i] {$img['url']}  (Alt: " . ($img['alt'] ?? '–') . ")\n";
        }
        $caption = stripControlHashtags(htmlToCaption((string) ($status['content'] ?? '')), [(string)($config['post_hashtag'] ?? ''), (string)($config['story_hashtag'] ?? '')]);
        echo "     Caption:\n";
        foreach (explode("\n", $caption) as $line) { echo "       | $line\n"; }
        echo "\n";
        continue;
    }

    echo "   ZIEL: FEED\n";
    $pick = pickSingleJpeg($status);
    if (isset($pick['skip'])) { echo "   → SKIP: {$pick['skip']}\n\n"; continue; }
    echo "   → würde gesendet:\n";
    echo "     Bild:  {$pick['url']}\n";
    echo "     Alt:   " . ($pick['alt'] ?? '(keiner)') . "\n";
    $caption = stripControlHashtags(htmlToCaption((string) ($status['content'] ?? '')), [(string)($config['post_hashtag'] ?? ''), (string)($config['story_hashtag'] ?? '')]);
    echo "     Caption:\n";
    foreach (explode("\n", $caption) as $line) { echo "       | $line\n"; }
    echo "\n";
}

echo "══ Instagram: Token-Check ══\n";
$res = httpRequest('GET', igBase() . '/' . $config['instagram']['user_id'], [
    'fields'       => 'user_id,username',
    'access_token' => getIgToken(),
]);
if ($res['error'] === null && !empty($res['body'])) {
    echo "   OK – " . json_encode($res['body']) . "\n";
} else {
    echo "   FEHLER – " . ($res['error'] ?? 'unbekannt') . "\n";
    echo "   Body: " . json_encode($res['body']) . "\n";
}
