<?php

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/prefectures.php';

$pdo = db();
$prefMap = prefecture_map();

$code = filter_input(INPUT_GET, 'code', FILTER_VALIDATE_INT);
if (!$code || $code < 1 || $code > 49) {
    http_response_code(400);
    exit('都道府県コードが不正です（1〜49）');
}

$prefName = $prefMap[$code] ?? ('コード:' . $code);

$stmt = $pdo->prepare('
  SELECT id, title, summary, created_at
  FROM routes
  WHERE prefecture_code = :code
  ORDER BY created_at DESC
');
$stmt->execute([':code' => $code]);
$routes = $stmt->fetchAll();
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title><?= h($prefName) ?> | Drive Mapping</title>
    <style>
        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            margin: 20px;
        }

        .card {
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 14px;
            margin: 10px 0;
        }

        .meta {
            color: #666;
            font-size: 12px;
        }

        a {
            color: #0b57d0;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <p><a href="/">← トップへ</a> | <a href="/routes/index.php">投稿一覧へ</a></p>
    <h1><?= h($prefName) ?> の投稿</h1>

    <?php if (!$routes): ?>
        <p>この都道府県の投稿はまだありません。</p>
    <?php endif; ?>

    <?php foreach ($routes as $r): ?>
        <div class="card">
            <div class="meta">投稿日：<?= h($r['created_at']) ?></div>
            <h2 style="margin:8px 0;">
                <a href="/routes/show.php?id=<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a>
            </h2>
            <p style="margin:0;"><?= h($r['summary']) ?></p>
        </div>
    <?php endforeach; ?>
</body>

</html>
