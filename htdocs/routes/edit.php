<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| edit.php（投稿編集画面）
|--------------------------------------------------------------------------
| 目的：
|   - ログイン中のユーザーが、自分の投稿（routes）を編集できる画面を表示する。
|   - ルート（start/middle/goal）・通過都道府県（メイン/サブ）・写真（削除/追加）の編集UIを提供する。
|
| 前提：
|   - update.php でDB更新を行う（このファイルは「表示と入力フォーム」担当）。
|   - map_url は「目的地サイトURL」として統一して扱う（create / edit / update で同じ仕様）。
*/

/* -----------------------------
 * 依存ファイル読み込み
 * -----------------------------
 * db.php         : DB接続（PDO）を返す db() を提供
 * helpers.php    : h() など汎用関数
 * auth.php       : require_login() など認証処理
 * prefectures.php: prefecture_map()（都道府県コード→名称の配列）
 * csrf.php       : csrf_token() などCSRF対策
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/prefectures.php';
require_once __DIR__ . '/../config/csrf.php';

/* -----------------------------
 * 認証：未ログインならログイン画面へ（または停止）
 * ----------------------------- */
require_login();

/* -----------------------------
 * DB接続・都道府県マップ取得
 * ----------------------------- */
$pdo = db();
$prefMap = prefecture_map();

/* -----------------------------
 * GETパラメータ id を検証
 * - routes/show.php?id=xx → 編集リンク → edit.php?id=xx を想定
 * - 不正な場合は400
 * ----------------------------- */
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    exit('不正なIDです');
}

/* -----------------------------
 * 投稿本体を取得（routes）
 * - 存在しなければ404
 * ----------------------------- */
$stmt = $pdo->prepare('SELECT * FROM routes WHERE id = :id');
$stmt->execute([':id' => $id]);
$route = $stmt->fetch();
if (!$route) {
    http_response_code(404);
    exit('投稿が見つかりません');
}

/* -----------------------------
 * 本人確認（編集権限）
 * - routes.user_id と セッション user_id が一致しない場合は403
 * ----------------------------- */
if ((int)$route['user_id'] !== (int)$_SESSION['user_id']) {
    http_response_code(403);
    exit('この投稿は編集できません');
}

/* -----------------------------
 * 通過都道府県（メイン/サブ）を取得
 * - route_prefectures：route_id ごとに複数行を持つ
 * - is_main = 1 がメイン、それ以外がサブ
 * - 表示の都合で is_main DESC でメインが先に来るように
 * ----------------------------- */
$stmt = $pdo->prepare('
  SELECT prefecture_code, is_main
  FROM route_prefectures
  WHERE route_id = :rid
  ORDER BY is_main DESC
');
$stmt->execute([':rid' => $id]);
$prefRows = $stmt->fetchAll();

/* メイン都道府県とサブ都道府県（複数）に振り分け */
$mainPref = null;
$subPrefs = [];
foreach ($prefRows as $r) {
    $code = (int)$r['prefecture_code'];
    if ((int)$r['is_main'] === 1) $mainPref = $code;
    else $subPrefs[] = $code;
}

/*
 * 後方互換：
 * - 古い投稿では route_prefectures を持っていない可能性があるため、
 *   routes.prefecture_code をメインとして採用する
 */
if ($mainPref === null) {
    $mainPref = (int)$route['prefecture_code'];
}

/* -----------------------------
 * 地点（start/middle/goal）を取得
 * - route_points から route_id で取得
 * - point_type：start / middle / goal
 * - sort_order 順で並べて、表示順を安定させる
 *
 * 重要（後方互換の設計）：
 * - route_points.url カラムを「住所」として運用中
 *   （カラム名は url だが、住所文字列を入れている）
 * ----------------------------- */
$stmt = $pdo->prepare('
  SELECT point_type, label, url, sort_order
  FROM route_points
  WHERE route_id = :rid
  ORDER BY sort_order ASC
');
$stmt->execute([':rid' => $id]);
$points = $stmt->fetchAll();

/* フォーム初期値に展開するための変数を用意 */
$startLabel = '';
$startAddress = '';
$goalLabel = '';
$goalAddress = '';
$middles = []; // [ ['label'=>..., 'address'=>...], ... ]

/* DBのpointsを start/middle/goal に振り分け（フォームへ反映） */
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

/* -----------------------------
 * 写真一覧を取得
 * - route_photos から route_id で取得
 * - thumb_name があればサムネを優先表示（なければ後方互換で原寸を表示）
 * - sort_order → id の順で表示を安定化
 * ----------------------------- */
$stmt = $pdo->prepare('
  SELECT id, file_name, thumb_name, sort_order, created_at
  FROM route_photos
  WHERE route_id = :rid
  ORDER BY sort_order ASC, id ASC
');
$stmt->execute([':rid' => $id]);
$photos = $stmt->fetchAll();

/* routesテーブルの基本項目をフォーム初期値にセット */
$title = (string)$route['title'];
$summary = (string)($route['summary'] ?? '');
$description = (string)($route['description'] ?? '');

/*
 * ✅ 住所＆目的地サイトURL
 * - address は routes.address（住所テキスト）
 * - map_url は routes.map_url（目的地サイトURL）として統一運用
 */
$address = (string)($route['address'] ?? '');
$site_url = (string)($route['map_url'] ?? ''); // ← 目的地サイトURL

/* 画像の公開URL（表示用）ベースパス：/uploads/routes/{route_id} */
$uploadUrlBase = '/uploads/routes/' . (int)$id;

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>投稿編集 | Drive Mapping</title>
    <style>
        /* -----------------------------
         * 画面デザイン（最小限）
         * - GitHubに上げる想定なので、現状はCSSを同一ファイル内で完結
         * ----------------------------- */
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

    <!-- 詳細ページへ戻る導線（編集対象 id を維持） -->
    <div class="topbar">
        <a href="/routes/show.php?id=<?= (int)$id ?>">← 詳細へ戻る</a>
    </div>

    <h1>投稿編集</h1>

    <!--
      編集フォーム
      - action は update.php（POST）に送信
      - 写真追加があるので enctype="multipart/form-data"
    -->
    <form method="post" action="/routes/update.php" enctype="multipart/form-data">
        <!-- CSRF対策：トークンを埋め込む -->
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <!-- 更新対象 id -->
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <!-- タイトル -->
        <label>
            タイトル（必須）
            <input type="text" name="title" maxlength="100" value="<?= h($title) ?>" required>
        </label>

        <!-- 概要 -->
        <label>
            概要（任意・255文字まで）
            <input type="text" name="summary" maxlength="255" value="<?= h($summary) ?>">
        </label>

        <!-- メイン都道府県 -->
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

        <!-- サブ都道府県（複数選択） -->
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

        <!-- 説明 -->
        <label>
            説明（任意）
            <textarea name="description"><?= h($description) ?></textarea>
        </label>

        <!-- 住所 -->
        <label>
            住所（任意）
            <input type="text" name="address" value="<?= h($address) ?>" placeholder="例：東京都千代田区〇〇...">
        </label>

        <!-- 目的地のサイトURL（routes.map_url として保存する前提） -->
        <label>
            目的地のサイト（任意）
            <input type="text" name="map_url" value="<?= h($site_url) ?>" placeholder="https://example.com">
            <small>※ URL形式で入力してください</small>
        </label>

        <hr>

        <!-- ルート（地点） -->
        <h3>ルート</h3>

        <!-- スタート -->
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

        <!-- 中間地点（複数） -->
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

                    <!-- 既存の中間地点を画面上から削除（※DB反映はupdate.php側で配列を見て再構成する想定） -->
                    <button type="button" onclick="this.parentNode.remove()">削除</button>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- JSで中間地点フォームを追加 -->
        <button type="button" onclick="addMiddlePoint()">＋ 中間地点を追加</button>

        <!-- ゴール -->
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

        <!-- 写真セクション -->
        <h3>写真（最大10枚）</h3>
        <small>現在 <?= count($photos) ?> 枚登録されています。削除した分だけ追加できます（最大10枚）。</small>

        <!-- 既存写真がある場合：サムネ一覧＋削除チェック -->
        <?php if ($photos): ?>
            <div class="photos">
                <?php foreach ($photos as $p): ?>
                    <?php
                    /*
                     * 表示用URL組み立て
                     * - thumb_name がある場合：/uploads/routes/{id}/thumbs/{thumb}
                     * - ない場合：後方互換として原寸 file_name を使用
                     */
                    $fullSrc = $uploadUrlBase . '/' . $p['file_name'];
                    $thumbSrc = (!empty($p['thumb_name']))
                        ? ($uploadUrlBase . '/thumbs/' . $p['thumb_name'])
                        : $fullSrc;
                    ?>
                    <div class="photo-card">
                        <img class="thumb" src="<?= h($thumbSrc) ?>" alt="photo" loading="lazy">
                        <label style="margin-top:6px;">
                            <!-- 削除対象の route_photos.id を送る -->
                            <input type="checkbox" name="delete_photo_ids[]" value="<?= (int)$p['id'] ?>">
                            この写真を削除
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>写真はまだありません。</p>
        <?php endif; ?>

        <!-- 写真追加（複数） -->
        <label>
            写真を追加（最大10枚まで）
            <input type="file" name="photos[]" accept="image/*" multiple>
            <small>※ まとめて選択できます</small>
        </label>

        <!-- 送信 -->
        <button type="submit">更新する</button>
    </form>

    <script>
        /*
         * 中間地点フォームを追加する
         * - middle_label[] / middle_address[] の配列としてPOSTされる
         * - 追加したブロックは「削除」ボタンでDOMから取り除ける
         */
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
