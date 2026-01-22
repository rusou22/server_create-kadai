<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/prefectures.php';

session_start();

$pdo = db();
$prefMap = prefecture_map();

/* 検索条件 */
$q = trim((string)($_GET['q'] ?? ''));
$pref = filter_input(INPUT_GET, 'pref', FILTER_VALIDATE_INT);

/*
  ✅ サムネ取得ルール
  - thumb_name='thumb.jpg' があれば、それを優先
  - なければ thumb_name の中から適当に1つ（MIN）を使う
*/
$sql = "
SELECT
  r.*,
  COALESCE(
    MAX(CASE WHEN p.thumb_name = 'thumb.jpg' THEN p.thumb_name END),
    MIN(p.thumb_name)
  ) AS thumb
FROM routes r
LEFT JOIN route_photos p ON p.route_id = r.id
WHERE 1
";

$params = [];

if ($q !== '') {
    $sql .= " AND (r.title LIKE :q OR r.summary LIKE :q OR r.description LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

if ($pref && isset($prefMap[$pref])) {
    $sql .= " AND r.id IN (
        SELECT route_id FROM route_prefectures WHERE prefecture_code = :pref
    )";
    $params[':pref'] = $pref;
}

$sql .= " GROUP BY r.id ORDER BY r.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$routes = $stmt->fetchAll();
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>投稿一覧 | Drive Mapping</title>
    <style>
        :root {
            --border: #e6e6e6;
            --muted: #666;
            --bg: #fafafa;
            --card: #fff;
            --link: #0b57d0;
        }

        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            margin: 0;
            background: var(--bg);
            color: #111;
        }

        a {
            color: var(--link);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 18px;
        }

        .topnav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .topnav a {
            font-size: 14px;
        }

        h1 {
            margin: 6px 0 14px;
            font-size: 22px;
        }

        .search {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 12px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .search input,
        .search select {
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
            min-width: 180px;
        }

        .search button {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
            cursor: pointer;
        }

        .search button:hover {
            background: #f3f3f3;
        }

        .list {
            margin-top: 14px;
            display: grid;
            gap: 12px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 12px;
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 12px;
        }

        @media (max-width: 700px) {
            .card {
                grid-template-columns: 110px 1fr;
            }
        }

        .thumb {
            width: 140px;
            height: 105px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid #eee;
            background: #eee;
        }

        .meta {
            font-size: 12px;
            color: var(--muted);
            margin-top: 4px;
        }

        .summary {
            margin: 8px 0 0;
            font-size: 14px;
            color: #222;
            line-height: 1.6;
        }

        .empty {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px;
            color: var(--muted);
            margin-top: 14px;
        }
    </style>
</head>

<body>
    <div class="container">

        <div class="topnav">
            <a href="/">← トップ（日本地図）へ戻る</a>

            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="/create.php">＋ 投稿する</a>
            <?php else: ?>
                <a href="/login.php">ログイン</a>
            <?php endif; ?>
        </div>

        <h1>投稿一覧</h1>

        <form class="search" method="get">
            <input type="text" name="q" placeholder="キーワード（タイトル/概要/説明）" value="<?= h($q) ?>">
            <select name="pref">
                <option value="">都道府県</option>
                <?php foreach ($prefMap as $c => $n): ?>
                    <option value="<?= (int)$c ?>" <?= ($pref === (int)$c) ? 'selected' : '' ?>><?= h($n) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">検索</button>
        </form>

        <?php if (!$routes): ?>
            <div class="empty">該当する投稿がありません。</div>
        <?php else: ?>
            <div class="list">
                <?php foreach ($routes as $r): ?>
                    <div class="card">
                        <?php if (!empty($r['thumb'])): ?>
                            <img class="thumb" src="/uploads/routes/<?= (int)$r['id'] ?>/thumbs/<?= h((string)$r['thumb']) ?>" alt="thumb" loading="lazy">
                        <?php else: ?>
                            <div class="thumb" aria-label="no image"></div>
                        <?php endif; ?>

                        <div>
                            <div style="font-size:16px; font-weight:700;">
                                <a href="/routes/show.php?id=<?= (int)$r['id'] ?>"><?= h((string)$r['title']) ?></a>
                            </div>
                            <div class="meta"><?= h((string)$r['created_at']) ?></div>

                            <?php if (!empty($r['summary'])): ?>
                                <p class="summary"><?= h((string)$r['summary']) ?></p>
                            <?php else: ?>
                                <p class="summary" style="color:var(--muted);">概要は未入力です。</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</body>

</html>
