<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| index.php（投稿一覧ページ）
|--------------------------------------------------------------------------
| 目的：
|   - 全ユーザーの投稿（routes）を一覧表示する。
|   - 「キーワード検索」「都道府県絞り込み」で投稿を探せるようにする。
|   - 一覧ではサムネ画像（あれば）＋タイトル＋作成日時＋概要をカード形式で表示する。
|
| 仕様メモ：
|   - 未ログインでも閲覧可能（ログインの有無で上部ナビ表示を切り替える）
|   - サムネは route_photos.thumb_name から取得し、thumb.jpg を最優先にする
|   - 都道府県絞り込みは route_prefectures（メイン/サブ両対応）を参照する
*/

/* -----------------------------
 * 依存ファイル読み込み
 * -----------------------------
 * db.php         : DB接続（PDO）を返す db()
 * helpers.php    : h()（XSS対策のエスケープ）など共通関数
 * prefectures.php: prefecture_map()（都道府県コード→名称）
 *
 * ※ このページは閲覧専用なので auth.php は読み込んでいない
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/prefectures.php';

/* -----------------------------
 * セッション開始
 * - ログイン状態（$_SESSION['user_id']）を見て、
 *   「＋投稿する」 or 「ログイン」のリンク表示を切り替えるため
 * ----------------------------- */
session_start();

/* -----------------------------
 * 初期化：DB接続・都道府県マップ
 * ----------------------------- */
$pdo = db();
$prefMap = prefecture_map();

/* -----------------------------
 * 検索条件（GET）
 * - q    : キーワード（タイトル/概要/説明）
 * - pref : 都道府県コード（route_prefectures を参照して絞り込み）
 * ----------------------------- */
$q = trim((string)($_GET['q'] ?? ''));
$pref = filter_input(INPUT_GET, 'pref', FILTER_VALIDATE_INT);

/*
|--------------------------------------------------------------------------
| 一覧で表示するサムネ取得ルール（SQL側で決定）
|--------------------------------------------------------------------------
| - route_photos.thumb_name に 'thumb.jpg' が存在する場合はそれを優先
| - ない場合は thumb_name の中から1つ代表値を使う（ここでは MIN）
|
| 目的：
| - 一覧表示で必ず「1枚だけ」サムネ候補を返し、表示側を簡単にする
| - thumb.jpg を規約にしておけば、ユーザーが意図した代表画像を固定できる
*/

/* -----------------------------
 * SQL作成（動的に条件追加する方式）
 * -----------------------------
 * routes を起点に route_photos をLEFT JOIN
 * - 画像がない投稿も一覧に出したいので LEFT JOIN
 * - thumb は GROUP BY でまとめた上で集約関数で1つに絞る
 *
 * WHERE 1 は、後続で AND 条件を足しやすくする定番の書き方
 * ----------------------------- */
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

/* プレースホルダに渡す値をここに集約 */
$params = [];

/* -----------------------------
 * キーワード検索（部分一致）
 * - q が空でない時だけ条件追加
 * - title / summary / description を対象に LIKE 検索
 * ----------------------------- */
if ($q !== '') {
    $sql .= " AND (r.title LIKE :q OR r.summary LIKE :q OR r.description LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

/* -----------------------------
 * 都道府県絞り込み（メイン・サブ両対応）
 * - route_prefectures に対象コードが含まれる route_id のみ残す
 * - prefecture_map に存在する値のみ許可（想定外の値は無視）
 * ----------------------------- */
if ($pref && isset($prefMap[$pref])) {
    $sql .= " AND r.id IN (
        SELECT route_id FROM route_prefectures WHERE prefecture_code = :pref
    )";
    $params[':pref'] = $pref;
}

/* -----------------------------
 * 集約＆並び
 * - GROUP BY r.id で投稿単位に1行化（thumb を確定させるため）
 * - 新しい投稿が上に来るよう created_at DESC
 * ----------------------------- */
$sql .= " GROUP BY r.id ORDER BY r.created_at DESC";

/* -----------------------------
 * SQL実行 → 一覧取得
 * ----------------------------- */
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
        /* -----------------------------
         * 画面スタイル（一覧用）
         * - CSS変数で色を管理して、後でテーマ変更しやすくする
         * - カードUI＋レスポンシブ対応
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

        /* 上部ナビ：戻る/投稿/ログイン を横並び */
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

        /* 検索フォーム */
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

        /* 一覧リスト（カードを縦に並べる） */
        .list {
            margin-top: 14px;
            display: grid;
            gap: 12px;
        }

        /* 1投稿カード：左サムネ＋右本文 */
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 12px;
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 12px;
        }

        /* スマホでサムネ幅を少し縮める */
        @media (max-width: 700px) {
            .card {
                grid-template-columns: 110px 1fr;
            }
        }

        /* サムネ画像枠 */
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

        /* 検索結果が空のとき */
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

        <!-- 上部ナビ -->
        <div class="topnav">
            <a href="/">← トップ（日本地図）へ戻る</a>

            <!-- ログイン状態でリンクを切り替え -->
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="/create.php">＋ 投稿する</a>
            <?php else: ?>
                <a href="/login.php">ログイン</a>
            <?php endif; ?>
        </div>

        <h1>投稿一覧</h1>

        <!-- 検索フォーム（GET） -->
        <form class="search" method="get">
            <input type="text" name="q" placeholder="キーワード（タイトル/概要/説明）" value="<?= h($q) ?>">

            <select name="pref">
                <option value="">都道府県</option>
                <?php foreach ($prefMap as $c => $n): ?>
                    <option value="<?= (int)$c ?>" <?= ($pref === (int)$c) ? 'selected' : '' ?>>
                        <?= h($n) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">検索</button>
        </form>

        <!-- 一覧（0件ならメッセージ、あればカード表示） -->
        <?php if (!$routes): ?>
            <div class="empty">該当する投稿がありません。</div>
        <?php else: ?>
            <div class="list">
                <?php foreach ($routes as $r): ?>
                    <div class="card">

                        <!-- サムネがある場合のみ画像表示 -->
                        <?php if (!empty($r['thumb'])): ?>
                            <img class="thumb"
                                 src="/uploads/routes/<?= (int)$r['id'] ?>/thumbs/<?= h((string)$r['thumb']) ?>"
                                 alt="thumb"
                                 loading="lazy">
                        <?php else: ?>
                            <div class="thumb" aria-label="no image"></div>
                        <?php endif; ?>

                        <div>
                            <!-- タイトル（詳細へ） -->
                            <div style="font-size:16px; font-weight:700;">
                                <a href="/routes/show.php?id=<?= (int)$r['id'] ?>"><?= h((string)$r['title']) ?></a>
                            </div>

                            <!-- 投稿日時 -->
                            <div class="meta"><?= h((string)$r['created_at']) ?></div>

                            <!-- 概要（未入力ならプレースホルダー） -->
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
