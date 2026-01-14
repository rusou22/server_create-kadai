<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| show.php（投稿詳細ページ）
|--------------------------------------------------------------------------
| 目的：
|   - 指定された投稿（routes）を詳細表示する。
|   - 写真・説明・ルート地点（start/middle/goal）・通過都道府県（メイン/サブ）を見せる。
|   - ログイン時は「いいね（お気に入り）」の追加/解除ができる。
|   - 投稿者本人は「編集」「削除」ができる。
|
| 重要な互換方針（本プロジェクトの統一ルール）：
|   - routes.map_url は「目的地サイトURL」として扱う（site_url ではない）
|   - route_points.url カラムは「住所文字列」を入れる用途として運用中（カラム名は url だが住所）
*/

/* -----------------------------
 * 依存ファイル読み込み
 * -----------------------------
 * db.php         : DB接続（PDO）を返す db()
 * helpers.php    : h() など共通関数（XSS対策）
 * prefectures.php: prefecture_map()（都道府県コード→名称）
 * csrf.php       : csrf_token()（いいね/削除などPOSTフォームのCSRF対策）
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/prefectures.php';
require_once __DIR__ . '/../config/csrf.php';

/* -----------------------------
 * セッション開始
 * - ログイン状態、ユーザーID/ユーザー名を参照するため
 * ----------------------------- */
session_start();

/* -----------------------------
 * 初期化：DB接続・都道府県マップ
 * ----------------------------- */
$pdo = db();
$prefMap = prefecture_map();

/* -----------------------------
 * 投稿ID取得（GET）
 * - /routes/show.php?id=xx を想定
 * - 不正な場合は400
 * ----------------------------- */
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    exit('不正なIDです');
}

/* -----------------------------
 * 投稿本体取得（routes）
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
 * 投稿者本人判定
 * - ログイン中かつ routes.user_id と一致する場合のみ true
 * - true のとき「編集」「削除」を表示する
 * ----------------------------- */
$isOwner = isset($_SESSION['user_id']) && ((int)$_SESSION['user_id'] === (int)$route['user_id']);

/* ----------------------------------------------------------------------
 * いいね（お気に入り）
 * ----------------------------------------------------------------------
 * - likeCount : この投稿の総いいね数（全ユーザー合計）
 * - isLiked   : ログインユーザーが既にいいね済みか
 *
 * ※ いいねの追加/解除は routes/like.php にPOSTする
 */

/* いいね数 */
$likeCountStmt = $pdo->prepare('SELECT COUNT(*) FROM route_likes WHERE route_id = :id');
$likeCountStmt->execute([':id' => $id]);
$likeCount = (int)$likeCountStmt->fetchColumn();

/* 自分がいいね済みか */
$isLiked = false;
if (isset($_SESSION['user_id'])) {
    $chk = $pdo->prepare('SELECT 1 FROM route_likes WHERE route_id = :rid AND user_id = :uid');
    $chk->execute([':rid' => $id, ':uid' => (int)$_SESSION['user_id']]);
    $isLiked = (bool)$chk->fetchColumn();
}

/* ----------------------------------------------------------------------
 * 通過都道府県（メイン／サブ）
 * ----------------------------------------------------------------------
 * - route_prefectures から取得し、is_main=1 をメインとして表示
 * - 取れない場合は後方互換で routes.prefecture_code を使う
 */
$stmt = $pdo->prepare('
  SELECT prefecture_code, is_main
  FROM route_prefectures
  WHERE route_id = :id
  ORDER BY is_main DESC, prefecture_code ASC
');
$stmt->execute([':id' => $id]);
$prefRows = $stmt->fetchAll();

$mainPrefName = null;
$subPrefNames = [];

foreach ($prefRows as $r) {
    $code = (int)$r['prefecture_code'];
    $name = $prefMap[$code] ?? ('コード:' . $code);
    if ((int)$r['is_main'] === 1) {
        $mainPrefName = $name;
    } else {
        $subPrefNames[] = $name;
    }
}

/* 後方互換：route_prefectures が無い古い投稿用 */
if ($mainPrefName === null) {
    $code = (int)$route['prefecture_code'];
    $mainPrefName = $prefMap[$code] ?? ('コード:' . $code);
}

/* ----------------------------------------------------------------------
 * ルート地点（start/middle/goal）
 * ----------------------------------------------------------------------
 * - route_points を sort_order で並べて表示
 * - url カラムは「住所」として利用（重要）
 */
$stmt = $pdo->prepare('
  SELECT point_type, label, url
  FROM route_points
  WHERE route_id = :id
  ORDER BY sort_order ASC
');
$stmt->execute([':id' => $id]);
$points = $stmt->fetchAll();

/* ----------------------------------------------------------------------
 * 写真
 * ----------------------------------------------------------------------
 * - route_photos を sort_order → id の順で取得
 * - 表示は /uploads/routes/{id}/{file_name}
 */
$stmt = $pdo->prepare('
  SELECT id, file_name, sort_order
  FROM route_photos
  WHERE route_id = :id
  ORDER BY sort_order ASC, id ASC
');
$stmt->execute([':id' => $id]);
$photos = $stmt->fetchAll();

$uploadUrlBase = '/uploads/routes/' . (int)$id;

/*
|--------------------------------------------------------------------------
| 住所＆目的地サイトURL（統一仕様）
|--------------------------------------------------------------------------
| - routes.address : 住所（テキスト）
| - routes.map_url : 目的地サイトURL（site_url ではない）
| - 表示時はURL形式かどうかを検証して「サイトを開く」を出し分ける
|
| ※ このファイルには target="_blank" が入っているが、
|    「タブを開かずアプリ内で完結したい」場合は後で調整可能（要件に合わせる）
*/
$address = trim((string)($route['address'] ?? ''));
$siteUrl = trim((string)($route['map_url'] ?? ''));
$siteUrlValid = ($siteUrl !== '' && filter_var($siteUrl, FILTER_VALIDATE_URL));

/* ルート表示用：point_type のラベル */
$pointLabelMap = [
    'start' => 'スタート',
    'middle' => '中間',
    'goal' => 'ゴール',
];

/* 表示用テキスト（未入力判定に利用） */
$summary = trim((string)($route['summary'] ?? ''));
$desc = trim((string)($route['description'] ?? ''));

?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title><?= h($route['title']) ?> | Drive Mapping</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        /* -----------------------------
         * 画面スタイル（詳細ページ）
         * - 左：写真/説明/ルート
         * - 右：概要/都道府県/住所/目的地サイト
         * ----------------------------- */
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

        .breadcrumb a {
            font-size: 14px;
        }

        .header {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px;
        }

        .title-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        h1 {
            margin: 0;
            font-size: 24px;
            line-height: 1.25;
        }

        .meta {
            color: var(--muted);
            font-size: 13px;
            margin-top: 6px;
        }

        .actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        button {
            padding: 8px 12px;
            cursor: pointer;
            border: 1px solid var(--border);
            background: #fff;
            border-radius: 10px;
        }

        button:hover {
            background: #f3f3f3;
        }

        .pill {
            font-size: 13px;
            color: #333;
            background: #f2f2f2;
            border: 1px solid #ededed;
            padding: 6px 10px;
            border-radius: 999px;
        }

        .grid {
            margin-top: 14px;
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 14px;
            align-items: start;
        }

        @media (max-width: 980px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px;
        }

        .card h3 {
            margin: 0 0 10px;
            font-size: 16px;
        }

        .kv {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 8px 12px;
            font-size: 14px;
        }

        .kv .k {
            color: var(--muted);
        }

        .desc {
            font-size: 14px;
            line-height: 1.7;
            white-space: pre-wrap;
            margin: 0;
        }

        ul {
            padding-left: 18px;
            margin: 8px 0 0;
        }

        .route-list {
            margin: 0;
            padding-left: 18px;
        }

        .route-list li {
            margin: 10px 0;
        }

        .route-tag {
            font-weight: 700;
        }

        .route-addr {
            color: #222;
            margin-top: 2px;
        }

        .route-addr.muted {
            color: var(--muted);
        }

        /* --- 写真スライダー（横スクロール） --- */
        .photo-slider {
            display: flex;
            overflow-x: auto;
            gap: 10px;
            padding-bottom: 6px;
            scroll-snap-type: x mandatory;
        }

        .photo-slider::-webkit-scrollbar {
            height: 10px;
        }

        .slide-photo {
            width: 190px;
            height: 140px;
            object-fit: cover;
            border-radius: 12px;
            cursor: pointer;
            flex: 0 0 auto;
            scroll-snap-align: start;
            border: 1px solid #eee;
            background: #eee;
        }

        .photo-hint {
            color: var(--muted);
            font-size: 12px;
            margin-top: 6px;
        }

        /* --- 写真モーダル --- */
        #photo-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.82);
            z-index: 9999;
            padding: 20px;
        }

        #photo-modal-inner {
            max-width: 1100px;
            margin: 0 auto;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #photo-modal img {
            max-width: 100%;
            max-height: 92vh;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
        }

        #photo-modal-close {
            position: fixed;
            top: 14px;
            right: 14px;
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 10px;
            padding: 10px 12px;
            cursor: pointer;
            backdrop-filter: blur(6px);
        }

        .muted-text {
            color: var(--muted);
        }
    </style>
</head>

<body>
    <div class="container">

        <!-- 戻る導線 -->
        <div class="topnav">
            <div class="breadcrumb">
                <a href="/routes/index.php">← 投稿一覧へ戻る</a>
            </div>
        </div>

        <!-- ヘッダー（タイトル・投稿日・アクション） -->
        <div class="header">
            <div class="title-row">
                <div>
                    <h1><?= h($route['title']) ?></h1>
                    <div class="meta">投稿日：<?= h($route['created_at']) ?></div>
                </div>
            </div>

            <div class="actions">
                <span class="pill">いいね：<?= (int)$likeCount ?> 件</span>

                <!-- ログイン中：いいねボタン（追加/解除）＋お気に入り一覧 -->
                <?php if (isset($_SESSION['user_id'])): ?>
                    <form method="post" action="/routes/like.php" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="route_id" value="<?= (int)$route['id'] ?>">
                        <button type="submit"><?= $isLiked ? 'いいね解除' : 'いいね' ?></button>
                    </form>

                    <a class="pill" href="/routes/favorites.php">★ お気に入り一覧</a>
                <?php else: ?>
                    <a class="pill" href="/login.php">ログインしていいね</a>
                <?php endif; ?>

                <!-- 投稿者本人：編集/削除 -->
                <?php if ($isOwner): ?>
                    <a class="pill" href="/routes/edit.php?id=<?= (int)$route['id'] ?>">編集</a>
                    <form method="post" action="/routes/delete.php" style="display:inline;"
                        onsubmit="return confirm('本当に削除しますか？');">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int)$route['id'] ?>">
                        <button type="submit">削除</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- 2カラム（左：本文 / 右：情報） -->
        <div class="grid">
            <!-- 左：メイン -->
            <div>

                <!-- 写真（横スクロール＋クリックで拡大） -->
                <div class="card">
                    <h3>写真</h3>

                    <?php if (!$photos): ?>
                        <p class="meta" style="margin:0;">写真は登録されていません。</p>
                    <?php else: ?>
                        <div class="photo-slider" aria-label="写真一覧（横スクロール）">
                            <?php foreach ($photos as $p): ?>
                                <?php $src = $uploadUrlBase . '/' . $p['file_name']; ?>
                                <img
                                    src="<?= h($src) ?>"
                                    class="slide-photo"
                                    alt="photo"
                                    loading="lazy"
                                    onclick="openPhotoModal('<?= h($src) ?>')">
                            <?php endforeach; ?>
                        </div>
                        <div class="photo-hint">※ 横にスクロールできます。クリックで拡大表示します。</div>
                    <?php endif; ?>
                </div>

                <!-- 説明（description） -->
                <div class="card">
                    <h3>説明</h3>
                    <?php if ($desc === ''): ?>
                        <p class="meta" style="margin:0;">説明は未入力です。</p>
                    <?php else: ?>
                        <p class="desc"><?= h($desc) ?></p>
                    <?php endif; ?>
                </div>

                <!-- ルート（地点一覧） -->
                <div class="card">
                    <h3>ルート</h3>

                    <?php if (!$points): ?>
                        <p class="meta" style="margin:0;">ルート情報は登録されていません。</p>
                    <?php else: ?>
                        <ol class="route-list">
                            <?php foreach ($points as $p): ?>
                                <?php
                                $type = (string)$p['point_type'];
                                $label = trim((string)($p['label'] ?? ''));
                                $addr = trim((string)($p['url'] ?? '')); // urlカラム＝住所
                                $tag = $pointLabelMap[$type] ?? '地点';
                                ?>
                                <li>
                                    <span class="route-tag"><?= h($tag) ?></span>
                                    ：<?= h($label !== '' ? $label : $tag) ?>
                                    <div class="route-addr <?= ($addr === '' ? 'muted' : '') ?>">
                                        <?= $addr !== '' ? h($addr) : '（住所未入力）' ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>

            </div>

            <!-- 右：サイド（概要など） -->
            <div>

                <div class="card">
                    <h3>情報</h3>

                    <div class="kv">
                        <div class="k">概要</div>
                        <div>
                            <?php if ($summary !== ''): ?>
                                <?= h($summary) ?>
                            <?php else: ?>
                                <span class="muted-text">未入力</span>
                            <?php endif; ?>
                        </div>

                        <div class="k">メイン都道府県</div>
                        <div><?= h($mainPrefName ?? '未設定') ?></div>

                        <?php if ($subPrefNames): ?>
                            <div class="k">サブ都道府県</div>
                            <div>
                                <ul>
                                    <?php foreach ($subPrefNames as $name): ?>
                                        <li><?= h($name) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <div class="k">住所</div>
                        <div>
                            <?php if ($address !== ''): ?>
                                <?= h($address) ?>
                            <?php else: ?>
                                <span class="muted-text">未入力</span>
                            <?php endif; ?>
                        </div>

                        <!-- 目的地サイトURL（routes.map_url） -->
                        <div class="k">目的地サイト</div>
                        <div>
                            <?php if ($siteUrl === ''): ?>
                                <span class="muted-text">未入力</span>
                            <?php elseif ($siteUrlValid): ?>
                                <!-- 現状：新規タブで開く（必要なら要件に合わせて変更可能） -->
                                <a href="<?= h($siteUrl) ?>" target="_blank" rel="noopener">サイトを開く</a>
                            <?php else: ?>
                                <span class="muted-text">（URL形式ではありません）</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- 写真拡大モーダル（クリックで開く／Escで閉じる） -->
    <div id="photo-modal" role="dialog" aria-modal="true" aria-label="写真拡大表示">
        <button id="photo-modal-close" type="button" onclick="closePhotoModal()">閉じる（Esc）</button>
        <div id="photo-modal-inner" onclick="closePhotoModal()">
            <img id="photo-modal-img" src="" alt="拡大写真">
        </div>
    </div>

    <script>
        /*
         * 写真モーダルを開く
         * - body のスクロールを止めて、写真を拡大表示する
         */
        function openPhotoModal(src) {
            const modal = document.getElementById('photo-modal');
            const img = document.getElementById('photo-modal-img');
            img.src = src;
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        /*
         * 写真モーダルを閉じる
         * - 表示を消し、画像srcをクリアしてメモリ消費を抑える
         */
        function closePhotoModal() {
            const modal = document.getElementById('photo-modal');
            const img = document.getElementById('photo-modal-img');
            modal.style.display = 'none';
            img.src = '';
            document.body.style.overflow = '';
        }

        /* Escキーで閉じる */
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closePhotoModal();
        });
    </script>

</body>

</html>
