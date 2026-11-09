<?php

// Home page of a multi-game static site (bin/jumphp pages): one card per game, from each game's
// dist/web/game.json (title, description, controls). Usage: php pages-index.php <site dir> <repo url> <game>...
[, $site, $repo] = $argv;
$games = array_slice($argv, 3);
$e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);

$cards = '';
foreach ($games as $id) {
    $m = json_decode((string)@file_get_contents("$site/$id/game.json"), true) ?: ['title' => $id];
    $thumb = !empty($m['thumbnail']) ? "<img src=\"{$e($id)}/{$e($m['thumbnail'])}\" alt=\"\" />" : '';
    $controls = !empty($m['controls']) ? "<small>{$e($m['controls'])}</small>" : '';
    $cards .= <<<HTML
	<a class="card" href="{$e($id)}/">{$thumb}<span><strong>{$e($m['title'] ?? $id)}</strong>
		{$e($m['description'] ?? '')}{$controls}</span></a>

HTML;
}
$source = $repo !== '' ? "<p>Source: <a href=\"{$e($repo)}\">{$e(preg_replace('#^https?://#', '', $repo))}</a>"
    . " · Make your own game: <a href=\"{$e($repo)}/blob/main/docs/tutorial.md\">tutorial</a>"
    . " (<a href=\"{$e($repo)}/blob/main/docs/tutoriel.md\">tutoriel en français</a>)</p>" : '';

file_put_contents("$site/index.html", <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>JumPHP games</title>
<style>
	:root { --bg: #0b0b18; --fg: #e6e6f0; --muted: #9a9ab4; --accent: #ffd23f; --card: #15152a; --border: #2a2a48; }
	html, body { margin: 0; background: var(--bg); color: var(--fg); font: 16px/1.5 system-ui, sans-serif; }
	main { max-width: 760px; margin: 0 auto; padding: 32px 16px; }
	h1 { font: bold 32px monospace; letter-spacing: 2px; color: var(--accent); margin: 0 0 4px; }
	p { color: var(--muted); }
	p a { color: var(--accent); }
	.card { display: flex; gap: 16px; align-items: center; background: var(--card); border: 1px solid var(--border);
		border-radius: 8px; padding: 16px 20px; margin: 14px 0; color: var(--fg); text-decoration: none; }
	.card:hover { border-color: var(--accent); }
	.card img { width: 128px; flex: none; image-rendering: pixelated; border-radius: 4px; }
	.card strong { display: block; font: bold 18px monospace; color: var(--accent); }
	.card small { display: block; margin-top: 6px; color: var(--muted); }
	@media (max-width: 480px) { .card { flex-direction: column; align-items: flex-start; } }
</style>
</head>
<body>
<main>
	<h1>JumPHP</h1>
	<p>Retro 2D games written in PHP, running in your browser (PHP compiled to WebAssembly).</p>
	{$source}
{$cards}</main>
</body>
</html>

HTML);
