<?php
// bin/jumphp site:new <game>: a first web site for the game (AGENTS.md §13) in games/<game>/site/, from its game.yaml
// and real screenshots (bin/jumphp shot, taken before): index.html (SEO, Open Graph, JSON-LD VideoGame, play/ link,
// phones), images/ (the shots scaled by whole factors, og.png 1200 x 630, favicon.png), robots.txt, sitemap.xml.
// Absolute addresses are https://{{DOMAIN}}/... (deploy and the playground replace it). Edit it afterwards.
//   php tools/new-site.php <game folder> <folder of shots> [--force]
[, $game, $shots] = $argv + [null, null, null];
$force = in_array('--force', $argv, true);
if ($game === null || $shots === null) {
    fwrite(STDERR, "usage: php tools/new-site.php <game folder> <folder of shots> [--force]\n");
    exit(2);
}
$site = "$game/site";
if (is_file("$site/index.html") && !$force) {
    fwrite(STDERR, "$site/index.html exists (--force to write it again)\n");
    exit(1);
}

// what the game says about itself
$yaml = (string)@file_get_contents(is_file("$game/config/project.yaml") ? "$game/config/project.yaml" : "$game/config/game.yaml");
$get = static function (string $key) use ($yaml): ?string {
    return preg_match('/^\s{2,4}' . $key . ':\s*(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\']|\'\')*)\'|([^#\n]*?))\s*(?:#.*)?$/m', $yaml, $m)
        ? trim(stripcslashes($m[1] ?? '') . str_replace("''", "'", $m[2] ?? '') . ($m[3] ?? '')) : null;
};
$id = $get('id') ?: basename($game);
$title = $get('title') ?: strtoupper($id);
$lang = substr($get('lang') ?: 'en', 0, 2);
$descriptions = [];
if (preg_match('/^\s{2}descriptions:\s*\{([^}]*)\}/m', $yaml, $m)) {
    foreach (preg_split('/,\s*(?=[a-z]{2}:)/', $m[1]) ?: [] as $pair) {
        if (preg_match('/^\s*([a-z]{2}):\s*["\']?(.*?)["\']?\s*$/', $pair, $p)) {
            $descriptions[$p[1]] = $p[2];
        }
    }
}
$description = $descriptions[$lang] ?? $get('description') ?: "$title, a retro game made in PHP.";
$controls = $get('controls') ?: '';
$width = (int)($get('width') ?: 320);

$fr = $lang === 'fr';
$t = $fr ? [
    'play' => 'Jouer', 'free' => 'jouer gratuitement en ligne', 'screens' => 'Captures d\'écran', 'how' => 'Comment jouer',
    'keyboard' => 'Clavier', 'pad' => 'Manette : le stick ou la croix pour bouger, A pour l\'action principale, Start pour la pause.',
    'phone' => 'Téléphone : les boutons à l\'écran.', 'about' => 'Le jeu', 'browser' => 'Dans le navigateur, sur ordinateur et sur téléphone : rien à installer.',
    'made' => 'Fait en PHP avec JumPHP.', 'again' => 'Lancer le jeu', 'shot' => 'Capture du jeu', 'genre' => 'Jeu rétro',
] : [
    'play' => 'Play', 'free' => 'play free online', 'screens' => 'Screenshots', 'how' => 'How to play',
    'keyboard' => 'Keyboard', 'pad' => 'Gamepad: the stick or the d-pad to move, A for the main action, Start to pause.',
    'phone' => 'Phone: the buttons on the screen.', 'about' => 'The game', 'browser' => 'In the browser, on computers and phones: nothing to install.',
    'made' => 'Made in PHP with JumPHP.', 'again' => 'Start the game', 'shot' => 'Screenshot of the game', 'genre' => 'Retro game',
];

// pictures: the shots scaled by a whole factor (pixel art stays sharp), the sharing picture, the icon
@mkdir("$site/images", 0777, true);
$files = glob(rtrim($shots, '/') . '/*.png') ?: [];
sort($files, SORT_NATURAL);
$files = array_values(array_filter($files, static function (string $f): bool {
    $img = @imagecreatefrompng($f);
    if (!$img) {
        return false;
    }
    // a frame of one colour (black: a fade) says nothing about the game
    $w = imagesx($img);
    $h = imagesy($img);
    $colors = [];
    for ($y = 0; $y < $h; $y += 2) {
        for ($x = 0; $x < $w; $x += 2) {
            $colors[imagecolorat($img, $x, $y)] = true;
            if (count($colors) > 1) {
                return true;
            }
        }
    }
    return false;
}));
$files = array_slice($files, -4);
if ($files === []) {
    fwrite(STDERR, "no usable screenshot in $shots\n");
    exit(1);
}
$scale = static function (\GdImage $img, int $factor): \GdImage {
    $out = imagecreatetruecolor(imagesx($img) * $factor, imagesy($img) * $factor);
    imagecopyresized($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), imagesx($img), imagesy($img));
    return $out;
};
$shotNames = [];
$size = [0, 0];
foreach ($files as $i => $file) {
    $img = imagecreatefrompng($file);
    $factor = max(1, intdiv(960, max(1, imagesx($img))));
    $big = $scale($img, $factor);
    $name = 'shot-' . ($i + 1) . '.png';
    imagepng($big, "$site/images/$name", 9);
    $shotNames[] = $name;
    $size = [imagesx($big), imagesy($big)];
}
// og.png: the last shot, cover-fitted into 1200 x 630
$last = imagecreatefrompng($files[count($files) - 1]);
$og = imagecreatetruecolor(1200, 630);
$ratio = max(1200 / imagesx($last), 630 / imagesy($last));
$w = (int)ceil(imagesx($last) * $ratio);
$h = (int)ceil(imagesy($last) * $ratio);
imagecopyresized($og, $last, (int)((1200 - $w) / 2), (int)((630 - $h) / 2), 0, 0, $w, $h, imagesx($last), imagesy($last));
imagepng($og, "$site/images/og.png", 9);
// favicon: the game's icon, else the middle of a shot
$icon = is_file("$game/assets/icon.png") ? @imagecreatefrompng("$game/assets/icon.png") : null;
if (!$icon) {
    $side = min(imagesx($last), imagesy($last));
    $icon = imagecrop($last, ['x' => (int)((imagesx($last) - $side) / 2), 'y' => (int)((imagesy($last) - $side) / 2), 'width' => $side, 'height' => $side]);
}
$fav = imagecreatetruecolor(64, 64);
imagealphablending($fav, false);
imagesavealpha($fav, true);
imagecopyresized($fav, $icon, 0, 0, 0, 0, 64, 64, imagesx($icon), imagesy($icon));
imagepng($fav, "$site/images/favicon.png", 9);

// the page
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5);
$url = 'https://{{DOMAIN}}/';
$pageTitle = "$title – {$t['genre']}, {$t['free']}";
$jsonLd = json_encode([
    '@context' => 'https://schema.org', '@type' => 'VideoGame', 'name' => $title, 'description' => $description, 'url' => $url,
    'genre' => $t['genre'], 'gamePlatform' => ['Web browser', 'PC', 'Mobile'], 'applicationCategory' => 'Game', 'operatingSystem' => 'Any',
    'image' => $url . 'images/og.png', 'screenshot' => array_map(fn ($n) => $url . "images/$n", $shotNames), 'isAccessibleForFree' => true,
    'inLanguage' => $lang, 'potentialAction' => ['@type' => 'PlayAction', 'target' => $url . 'play/'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$gallery = '';
foreach ($shotNames as $i => $name) {
    $gallery .= "      <img src=\"images/$name\" alt=\"" . $e($t['shot'] . ' ' . $title . ' ' . ($i + 1)) . "\" width=\"$size[0]\" height=\"$size[1]\" loading=\"lazy\">\n";
}
$controlsHtml = ($controls !== '' ? '<li><b>' . $e($t['keyboard']) . '</b>' . ($fr ? ' : ' : ': ') . $e($controls) . '</li>' : '') . '<li>' . $e($t['pad']) . '</li><li>' . $e($t['phone']) . '</li>';
$html = <<<HTML
<!doctype html>
<html lang="$lang">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$e($pageTitle)}</title>
  <meta name="description" content="{$e($description)}">
  <link rel="canonical" href="$url">
  <link rel="icon" href="images/favicon.png">
  <meta property="og:type" content="website">
  <meta property="og:title" content="{$e($title)}">
  <meta property="og:description" content="{$e($description)}">
  <meta property="og:url" content="$url">
  <meta property="og:image" content="{$url}images/og.png">
  <meta property="og:locale" content="$lang">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="{$e($title)}">
  <meta name="twitter:description" content="{$e($description)}">
  <meta name="twitter:image" content="{$url}images/og.png">
  <script type="application/ld+json">
$jsonLd
  </script>
  <style>
    :root { --bg: #0d0f1a; --panel: #171a2b; --text: #eef0ff; --muted: #a7acc9; --accent: #ffcc33; }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--text); font: 17px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
    main { max-width: 1000px; margin: 0 auto; padding: 32px 16px 64px; }
    header { text-align: center; padding: 24px 0 8px; }
    h1 { font-size: clamp(32px, 7vw, 56px); margin: 0 0 8px; letter-spacing: 2px; }
    h2 { margin: 48px 0 12px; font-size: 24px; }
    p, li { color: var(--muted); }
    .play { display: inline-block; margin: 20px 0; padding: 16px 40px; border-radius: 12px; background: var(--accent); color: #111;
      font-weight: 800; font-size: 22px; text-decoration: none; box-shadow: 0 6px 0 #b38b00; }
    .play:active { transform: translateY(4px); box-shadow: 0 2px 0 #b38b00; }
    .hero img, .gallery img { width: 100%; height: auto; image-rendering: pixelated; border-radius: 10px; display: block; background: #000; }
    .hero { margin-top: 16px; }
    .gallery { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
    footer { text-align: center; color: var(--muted); font-size: 14px; margin-top: 56px; }
    a { color: var(--accent); }
  </style>
</head>
<body>
  <main>
    <header>
      <h1>{$e($title)}</h1>
      <p>{$e($description)}</p>
      <a class="play" href="play/">▶ {$e($t['play'])}</a>
    </header>
    <div class="hero"><img src="images/{$shotNames[count($shotNames) - 1]}" alt="{$e($t['shot'] . ' ' . $title)}" width="$size[0]" height="$size[1]"></div>
    <section>
      <h2>{$e($t['about'])}</h2>
      <p>{$e($description)} {$e($t['browser'])}</p>
    </section>
    <section>
      <h2>{$e($t['how'])}</h2>
      <ul>$controlsHtml</ul>
    </section>
    <section>
      <h2>{$e($t['screens'])}</h2>
      <div class="gallery">
$gallery      </div>
    </section>
    <p style="text-align: center"><a class="play" href="play/">▶ {$e($t['again'])}</a></p>
    <footer>{$e($t['made'])}</footer>
  </main>
</body>
</html>

HTML;
file_put_contents("$site/index.html", $html);
file_put_contents("$site/robots.txt", "User-agent: *\nAllow: /\nSitemap: {$url}sitemap.xml\n");
file_put_contents("$site/sitemap.xml", "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
    . "  <url><loc>$url</loc></url>\n  <url><loc>{$url}play/</loc></url>\n</urlset>\n");
echo "$site/index.html (" . count($shotNames) . " screenshots, $width px game). Check it: bin/jumphp build:site $id && bin/jumphp siteshot $id\n";
