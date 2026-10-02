<?php
/**
 * AI-радар — автономна версія (без CMS сайту студії).
 * Запуск: php -S localhost:8000 -t public public/index.php
 */
declare(strict_types=1);
require __DIR__ . '/../src/AiRadar.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false; // статика (assets/*) — віддає вбудований сервер PHP
}

if ($path === '/api') {
    header('Content-Type: application/json; charset=utf-8');
    $result = AiRadar::search($_GET);
    if (empty($result['ok'])) {
        http_response_code(502);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

if ($path !== '/') {
    http_response_code(404);
    echo 'Not found';
    return;
}

$aiRadarEndpoint = '/api';
$v = static fn(string $f): int => (int) @filemtime(__DIR__ . $f);
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AI-радар — нові AI-сайти та на чому їх роблять</title>
  <meta name="description" content="Каталог свіжих AI-сайтів на даних FreeSerp: фільтри за нішею, датою запуску, DR і стеком, порівняння сайтів.">
  <link rel="stylesheet" href="/assets/ai-radar.css?v=<?= $v('/assets/ai-radar.css') ?>">
  <style>
    body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #F7FBFC; color: #0B1F26; line-height: 1.5; }
    .wrap { max-width: 1240px; margin: 0 auto; padding: 32px 20px 64px; }
    h1 { font-size: clamp(28px, 4vw, 40px); line-height: 1.15; margin: 6px 0 12px; }
    .eyebrow { color: #007C93; font-weight: 700; font-size: 13px; letter-spacing: .08em; text-transform: uppercase; }
    .lead { color: #4B6570; max-width: 720px; margin: 0 0 24px; }
  </style>
</head>
<body>
  <div class="wrap">
    <span class="eyebrow">AI-радар</span>
    <h1>Нові AI-сайти та на чому їх роблять</h1>
    <p class="lead">Свіжі AI-стартапи з відкритого індексу FreeSerp: шукайте за нішею, датою запуску й авторитетністю домену, дивіться стек і порівнюйте до трьох сервісів поруч.</p>
    <?php require __DIR__ . '/../src/ai-radar-app.php'; ?>
  </div>
  <script src="/assets/ai-radar.js?v=<?= $v('/assets/ai-radar.js') ?>" defer></script>
</body>
</html>
