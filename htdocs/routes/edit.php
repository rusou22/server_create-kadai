<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/prefectures.php';
require_once __DIR__ . '/../config/csrf.php';

require_login();

$pdo = db();
$prefMap = prefecture_map();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    exit('不正なIDです');
}

/* 投稿本体 */
$stmt = $pdo->prepare('SELECT * FROM routes WHERE id = :id');
$stmt->execute([':id' => $id]);
$route = $stmt->fetch();
if (!$route) {
    http_response_code(404);
    exit('投稿が見つかりません');
}

/* 本人確認 */
if ((int)$route['user_id'] !== (int)$_SESSION['user_id']) {
    http_response_code(403);
    exit('この投稿は編集できません');
}

/* 通過都道府県（メイン/サブ） */
$stmt = $pdo->prepare('
  SELECT prefecture_code, is_main
  FROM route_prefectures
  WHERE route_id = :rid
  ORDER BY is_main DESC
');
$stmt->execute([':rid' => $id]);
$prefRows = $stmt->fetchAll();

$mainPref = null;
$subPrefs = [];
foreach ($prefRows as $r) {
    $code = (int)$r['prefecture_code'];
    if ((int)$r['is_main'] === 1) $mainPref = $code;
    else $subPrefs[] = $code;
}
if ($mainPref === null) {
    // 後方互換（古い投稿は routes.prefecture_code を採用）
    $mainPref = (int)$route['prefecture_code'];
}

/* 地点（start/middle/goal）※ urlカラムを住所として使う */
$stmt = $pdo->prepare('
  SELECT point_type, label, url, sort_order
  FROM route_points
  WHERE route_id = :rid
  ORDER BY sort_order ASC
');
$stmt->execute([':rid' => $id]);
$points = $stmt->fetchAll();

$startLabel = '';
$startAddress = '';
$goalLabel = '';
$goalAddress = '';
$middles = []; // [ ['label'=>..., 'address'=>...], ...]

foreach ($points as $p) {
    if ($p['point_type'] === 'start') {
        $startLabel = (string)($p['label'] ?? '');
        $startAddress = (string)($p['url'] ?? '');
    } elseif ($p['point_type'] === 'goal') {
        $goalLabel = (string)($p['label'] ?? '');
        $goalAddress = (string)($p['url'] ?? '');
    } elseif ($p['point_type'] === 'middle') {
        $middles[] = [
            'label' => (string)($p['label'] ?? ''),
            'address' => (string)($p['url'] ?? ''),
        ];
    }
}

/* 写真（thumb_name も取得。NULLでもOK） */
$stmt = $pdo->prepare('
  SELECT id, file_name, thumb_name, sort_order, created_at
  FROM route_photos
  WHERE route_id = :rid
  ORDER BY sort_order ASC, id ASC
');
$stmt->execute([':rid' => $id]);
$photos = $stmt->fetchAll();

$title = (string)$route['title'];
$summary = (string)($route['summary'] ?? '');
$description = (string)($route['description'] ?? '');

/* ✅ 住所＆目的地サイト（map_urlを目的地サイトとして統一） */
$address = (string)($route['address'] ?? '');
$site_url = (string)($route['map_url'] ?? ''); // ← 目的地サイトURL

$uploadUrlBase = '/uploads/routes/' . (int)$id;
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>投稿編集 | Drive Mapping</title>
    <style>
        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            margin: 20px;
        }

        form {
            max-width: 820px;
        }

        label {
            display: block;
            margin-top: 14px;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 8px;
            margin-top: 4px;
        }

        textarea {
            min-height: 120px;
        }

        button {
            margin-top: 10px;
            padding: 8px 12px;
            cursor: pointer;
        }

        .topbar {
            margin-bottom: 12px;
            font-size: 14px;
        }

        .photos {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .photo-card {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 8px;
            width: 180px;
        }

        .thumb {
            width: 160px;
            height: 120px;
            object-fit: cover;
            border-radius: 8px;
            display: block;
            border: 1px solid #eee;
            background: #f3f3f3;
        }

        small {
            color: #555;
        }

        hr {
            margin: 18px 0;
        }

        .point-block {
            border: 1px solid #eee;
            border-radius: 10px;
            padding: 10px;
            margin-top: 10px;
        }

        .point-title {
            font-weight: 700;
            margin-bottom: 6px;
        }
    </style>
</head>

<body>

    <div class="topbar">
        <a href="/routes/show.php?id=<?= (int)$id ?>">← 詳細へ戻る</a>
    </div>

    <h1>投稿編集</h1>

    <form method="post" action="/routes/update.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <label>
            タイトル（必須）
            <input type="text" name="title" maxlength="100" value="<?= h($title) ?>" required>
        </label>

        <label>
            概要（任意・255文字まで）
            <input type="text" name="summary" maxlength="255" value="<?= h($summary) ?>">
        </label>

        <label>
            メインで通った都道府県（必須）
            <select name="prefecture_code" required>
                <?php foreach ($prefMap as $code => $name): ?>
                    <option value="<?= (int)$code ?>" <?= ((int)$code === (int)$mainPref) ? 'selected' : '' ?>>
                        <?= h($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            サブで通った都道府県（任意・複数）
            <select name="sub_prefectures[]" multiple size="6">
                <?php foreach ($prefMap as $code => $name): ?>
                    <option value="<?= (int)$code ?>" <?= in_array((int)$code, $subPrefs, true) ? 'selected' : '' ?>>
                        <?= h($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small>
                ※ Windows：<strong>Ctrl</strong> / Mac：<strong>⌘</strong> キーを押しながらクリックで複数選択できます。
            </small>
        </label>

        <label>
            説明（任意）
            <textarea name="description"><?= h($description) ?></textarea>
        </label>

        <label>
            住所（任意）
            <input type="text" name="address" value="<?= h($address) ?>" placeholder="例：東京都千代田区〇〇...">
        </label>

        <label>
            目的地のサイト（任意）
            <input type="text" name="map_url" value="<?= h($site_url) ?>" placeholder="https://example.com">
            <small>※ URL形式で入力してください</small>
        </label>

        <hr>

        <h3>ルート</h3>

        <div class="point-block">
            <div class="point-title">スタート</div>
            <label>
                地点名（任意）
                <input type="text" name="start_label" value="<?= h($startLabel) ?>" placeholder="例：自宅・駅・IC名など">
            </label>
            <label>
                住所（任意）
                <input type="text" name="start_address" value="<?= h($startAddress) ?>" placeholder="例：〇〇県〇〇市...">
            </label>
        </div>

        <h4 style="margin-top:16px;">中間地点</h4>
        <div id="middle-points">
            <?php foreach ($middles as $m): ?>
                <div class="point-block">
                    <div class="point-title">中間</div>
                    <label>
                        地点名（任意）
                        <input type="text" name="middle_label[]" value="<?= h($m['label']) ?>" placeholder="中間地点名">
                    </label>
                    <label>
                        住所（任意）
                        <input type="text" name="middle_address[]" value="<?= h($m['address']) ?>" placeholder="例：〇〇県〇〇市...">
                    </label>
                    <button type="button" onclick="this.parentNode.remove()">削除</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" onclick="addMiddlePoint()">＋ 中間地点を追加</button>

        <div class="point-block">
            <div class="point-title">ゴール</div>
            <label>
                地点名（任意）
                <input type="text" name="goal_label" value="<?= h($goalLabel) ?>" placeholder="例：宿泊地・観光地など">
            </label>
            <label>
                住所（任意）
                <input type="text" name="goal_address" value="<?= h($goalAddress) ?>" placeholder="例：〇〇県〇〇市...">
            </label>
        </div>

        <hr>

        <h3>写真（最大10枚）</h3>
        <small>現在 <?= count($photos) ?> 枚登録されています。削除した分だけ追加できます（最大10枚）。</small>

        <?php if ($photos): ?>
            <div class="photos">
                <?php foreach ($photos as $p): ?>
                    <?php
                    $fullSrc = $uploadUrlBase . '/' . $p['file_name'];
                    $thumbSrc = (!empty($p['thumb_name']))
                        ? ($uploadUrlBase . '/thumbs/' . $p['thumb_name'])
                        : $fullSrc; // 後方互換
                    ?>
                    <div class="photo-card">
                        <img class="thumb" src="<?= h($thumbSrc) ?>" alt="photo" loading="lazy">
                        <label style="margin-top:6px;">
                            <input type="checkbox" name="delete_photo_ids[]" value="<?= (int)$p['id'] ?>">
                            この写真を削除
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>写真はまだありません。</p>
        <?php endif; ?>

        <label>
            写真を追加（最大10枚まで）
            <input type="file" name="photos[]" accept="image/*" multiple>
            <small>※ まとめて選択できます</small>
        </label>

        <button type="submit">更新する</button>
    </form>

    <script>
        function addMiddlePoint() {
            const wrap = document.getElementById('middle-points');
            const div = document.createElement('div');
            div.className = 'point-block';
            div.innerHTML = `
                <div class="point-title">中間</div>
                <label>地点名（任意）
                    <input type="text" name="middle_label[]" placeholder="中間地点名">
                </label>
                <label>住所（任意）
                    <input type="text" name="middle_address[]" placeholder="例：〇〇県〇〇市...">
                </label>
                <button type="button" onclick="this.parentNode.remove()">削除</button>
            `;
            wrap.appendChild(div);
        }
    </script>

</body>

</html>
