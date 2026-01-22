<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/prefectures.php';

require_login();

$pdo = db();
$prefMap = prefecture_map();

/* 検索条件（お気に入り内検索） */
$q = trim($_GET['q'] ?? '');
$pref = filter_input(INPUT_GET, 'pref', FILTER_VALIDATE_INT);
$order = $_GET['order'] ?? 'new';

$userId = (int)$_SESSION['user_id'];

/* SQL */
$sql = "
SELECT
  r.*,
  MIN(p.thumb_name) AS thumb,
  MIN(p.file_name)  AS first_file,
  COALESCE(l.like_cnt, 0) AS like_cnt
FROM route_likes my
INNER JOIN routes r ON r.id = my.route_id
LEFT JOIN route_photos p ON p.route_id = r.id
LEFT JOIN (
  SELECT route_id, COUNT(*) AS like_cnt
  FROM route_likes
  GROUP BY route_id
) l ON l.route_id = r.id
";

$params = [':uid' => $userId];
$where = ["my.user_id = :uid"];

/* キーワード検索（title / summary / description） */
if ($q !== '') {
    $where[] = "(r.title LIKE :q OR r.summary LIKE :q OR r.description LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

/* 都道府県（メイン・サブ両対応） */
if ($pref && isset($prefMap[$pref])) {
    $sql .= "
    INNER JOIN route_prefectures rp
      ON rp.route_id = r.id
     AND rp.prefecture_code = :pref
    ";
    $params[':pref'] = $pref;
}

$sql .= " WHERE " . implode(' AND ', $where);
$sql .= " GROUP BY r.id ";

/* 並び替え（いいねした日時 or 投稿日）どちらでも良いが、ここは「お気に入り登録順」優先 */
if ($order === 'old') {
    $sql .= " ORDER BY my.created_at ASC ";
} else {
    $sql .= " ORDER BY my.created_at DESC ";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$routes = $stmt->fetchAll();
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>お気に入り | Drive Mapping</title>
    <style>
        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            margin: 20px;
        }

        h1 {
            margin-bottom: 10px;
        }

        .nav {
            margin-bottom: 12px;
            font-size: 14px;
        }

        .nav a {
            margin-right: 10px;
            color: #0b57d0;
            text-decoration: none;
        }

        .nav a:hover {
            text-decoration: underline;
        }

        form.search {
            margin-bottom: 14px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .meta {
            color: #666;
            font-size: 13px;
            margin-bottom: 12px;
        }

        .card {
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 14px;
            display: flex;
            gap: 12px;
        }

        .thumb {
            width: 120px;
            height: 90px;
            object-fit: cover;
            border-radius: 8px;
            background: #eee;
            flex: 0 0 auto;
        }

        a {
            color: #0b57d0;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .row-meta {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .badge {
            font-size: 12px;
            color: #444;
            background: #f3f3f3;
            border: 1px solid #e6e6e6;
            padding: 2px 8px;
            border-radius: 999px;
        }

        @media (max-width: 768px) {
            body {
                margin: 12px;
            }

            .card {
                flex-direction: column;
            }

            .thumb {
                width: 100%;
                height: auto;
            }
        }
    </style>
</head>

<body>

    <div class="nav">
        <a href="/">← トップへ</a>
        <a href="/routes/index.php">投稿一覧</a>
        <a href="/create.php">＋ 新規投稿</a>
    </div>

    <h1>お気に入り</h1>
    <div class="meta">ログイン中：<?= h($_SESSION['user_name']) ?> さん / <?= count($routes) ?> 件</div>

    <form method="get" class="search">
        <input type="text" name="q" placeholder="キーワード（タイトル・概要・説明）" value="<?= h($q) ?>">

        <select name="pref">
            <option value="">都道府県</option>
            <?php foreach ($prefMap as $c => $n): ?>
                <option value="<?= (int)$c ?>" <?= ($pref === (int)$c) ? 'selected' : '' ?>><?= h($n) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="order">
            <option value="new" <?= ($order === 'new') ? 'selected' : '' ?>>新しい順（お気に入り登録）</option>
            <option value="old" <?= ($order === 'old') ? 'selected' : '' ?>>古い順（お気に入り登録）</option>
        </select>

        <button type="submit">検索</button>
    </form>

    <?php if (!$routes): ?>
        <p>お気に入りはまだありません。</p>
    <?php endif; ?>

    <?php foreach ($routes as $r): ?>
        <?php
        $routeId = (int)$r['id'];

        $imgSrc = '';
        if (!empty($r['thumb'])) {
            $imgSrc = "/uploads/routes/$routeId/thumbs/" . $r['thumb'];
        } elseif (!empty($r['first_file'])) {
            $imgSrc = "/uploads/routes/$routeId/" . $r['first_file'];
        }
        ?>
        <div class="card">
            <?php if ($imgSrc !== ''): ?>
                <img class="thumb" src="<?= h($imgSrc) ?>" alt="thumb" loading="lazy">
            <?php else: ?>
                <div class="thumb"></div>
            <?php endif; ?>

            <div>
                <h3 style="margin:0 0 6px;">
                    <a href="/routes/show.php?id=<?= $routeId ?>"><?= h($r['title']) ?></a>
                </h3>

                <div class="row-meta">
                    <div class="meta"><?= h($r['created_at']) ?></div>
                    <div class="badge">いいね <?= (int)$r['like_cnt'] ?></div>
                </div>

                <p style="margin:8px 0 0;"><?= h((string)$r['summary']) ?></p>
            </div>
        </div>
    <?php endforeach; ?>

</body>

</html>
