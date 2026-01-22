<?php

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/prefectures.php';

session_start();

$pdo = db();
$prefMap = prefecture_map();

/* 投稿数 */
$stmt = $pdo->query('
  SELECT prefecture_code, COUNT(*) AS cnt
  FROM routes
  GROUP BY prefecture_code
');
$rows = $stmt->fetchAll();

$counts = [];
foreach ($rows as $r) {
    $counts[(int)$r['prefecture_code']] = (int)$r['cnt'];
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>Drive Mapping</title>
    <style>
        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            margin: 20px;
        }

        .wrap {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 20px;
            align-items: start;
        }

        .card {
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 14px;
        }

        .auth {
            margin-bottom: 14px;
            font-size: 14px;
        }

        a {
            color: #0b57d0;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        /* ===== 地図UI改善 ===== */
        .map-wrap {
            max-width: 530px;
            /* ← 地図サイズ調整はここ */
            margin: 0 auto;
        }

        .map-wrap img {
            width: 100%;
            height: auto;
            display: block;
        }

        /* ===== スマホ対応 ===== */
        @media (max-width: 900px) {
            .wrap {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            body {
                margin: 12px;
            }

            .map-wrap {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

    <div class="auth">
        <?php if (isset($_SESSION['user_id'])): ?>
            ログイン中：<?= htmlspecialchars($_SESSION['user_name'], ENT_QUOTES, 'UTF-8') ?> さん |
            <a href="/create.php">投稿する</a> |
            <a href="/logout.php">ログアウト</a>
        <?php else: ?>
            <a href="/login.php">ログイン</a> /
            <a href="/register.php">新規登録</a>
        <?php endif; ?>
    </div>

    <h1>Drive Mapping</h1>
    <p>都道府県をクリックすると投稿一覧へ移動します。</p>

    <div class="wrap">

        <!-- 日本地図 -->
        <div class="card">
            <div class="map-wrap">
                <?php include __DIR__ . '/japan_map.php'; ?>
            </div>
        </div>

        <!-- サイドメニュー -->
        <div class="card">
            <h3>メニュー</h3>
            <ul>
                <li><a href="/routes/index.php">投稿一覧</a></li>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <li><a href="/create.php">投稿する</a></li>
                    <li><a href="/routes/favorites.php">お気に入り</a></li>
                <?php endif; ?>
            </ul>

            <hr>

            <h3>投稿数</h3>
            <?php if (!$counts): ?>
                <p>まだ投稿がありません。</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($counts as $code => $cnt): ?>
                        <?php $name = $prefMap[$code] ?? ('コード:' . $code); ?>
                        <li>
                            <a href="/prefecture.php?code=<?= (int)$code ?>">
                                <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            ：<?= (int)$cnt ?> 件
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

    </div>

    <!-- ホバー：都道府県名＋件数 -->
    <script>
        const prefectureCounts = <?= json_encode($counts, JSON_UNESCAPED_UNICODE) ?>;
        const prefectureNames = <?= json_encode($prefMap, JSON_UNESCAPED_UNICODE) ?>;

        document.querySelectorAll('area[data-pref]').forEach(area => {
            const code = Number(area.dataset.pref);
            const cnt = prefectureCounts[code] ?? 0;
            const name = prefectureNames[code] ?? `コード:${code}`;
            area.title = `${name} / 投稿 ${cnt} 件`;
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/image-map-resizer@1.0.10/js/imageMapResizer.min.js"></script>
    <script>
        imageMapResize();
    </script>

</body>

</html>
