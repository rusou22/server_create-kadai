<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| favorites.php（お気に入り一覧）
|--------------------------------------------------------------------------
| 目的：
|   - ログイン中ユーザーが「いいね（お気に入り）」した投稿一覧を表示する。
|   - お気に入り内で「キーワード検索」「都道府県絞り込み」「並び替え（お気に入り登録順）」ができる。
|
| 表示内容（カード形式）：
|   - サムネイル（thumbs があれば優先、なければ最初の画像）
|   - タイトル（詳細ページへのリンク）
|   - 投稿日時（routes.created_at）
|   - いいね数（全ユーザー合計）
|   - 概要（summary）
|
| 補足：
|   - route_likes を「お気に入り」として利用している。
|   - お気に入り登録順の表示を優先するため、my.created_at（自分がいいねした日時）で並べ替える。
*/

/* -----------------------------
 * 依存ファイル読み込み
 * -----------------------------
 * db.php         : DB接続（PDO）を返す db()
 * helpers.php    : h() などの共通関数
 * auth.php       : require_login()（未ログインのアクセス制御）
 * prefectures.php: prefecture_map()（都道府県コード→名称）
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/prefectures.php';

/* -----------------------------
 * 認証：ログイン必須
 * ----------------------------- */
require_login();

/* -----------------------------
 * 初期化：DB接続・都道府県マップ
 * ----------------------------- */
$pdo = db();
$prefMap = prefecture_map();

/* -----------------------------
 * 検索条件（GET）
 * - q     : キーワード（お気に入り内検索）
 * - pref  : 都道府県コード（メイン・サブ両対応）
 * - order : 並び（new/old）※お気に入り登録順
 * ----------------------------- */
$q = trim($_GET['q'] ?? '');
$pref = filter_input(INPUT_GET, 'pref', FILTER_VALIDATE_INT);
$order = $_GET['order'] ?? 'new';

/* -----------------------------
 * ログインユーザーID（お気に入り抽出に利用）
 * ----------------------------- */
$userId = (int)$_SESSION['user_id'];

/* -----------------------------
 * SQL（お気に入り一覧）
 * -----------------------------
 * my（route_likes）を起点にして、
 *   - 自分がいいねした route_id を routes と結合して投稿情報を取得
 *   - route_photos からサムネ候補（thumb_name / file_name）を取得
 *   - いいね総数をサブクエリ（l）で集計して付与
 *
 * thumb:
 *   - MIN(p.thumb_name) で1つ代表値を取る（複数枚あっても一覧用は1枚でOK）
 * first_file:
 *   - thumbがない場合の後方互換として file_name を使う
 *
 * like_cnt:
 *   - 全ユーザー合計のいいね数（COALESCEでNULL→0）
 */
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

/* -----------------------------
 * WHERE句を動的に組み立てる準備
 * - params : プレースホルダに渡す値
 * - where  : 条件式（ANDで連結）
 * ----------------------------- */
$params = [':uid' => $userId];
$where = ["my.user_id = :uid"];

/* -----------------------------
 * キーワード検索（title / summary / description）
 * - 部分一致LIKEで検索
 * - 空文字の場合は条件を追加しない
 * ----------------------------- */
if ($q !== '') {
    $where[] = "(r.title LIKE :q OR r.summary LIKE :q OR r.description LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

/* -----------------------------
 * 都道府県絞り込み（メイン・サブ両対応）
 * - route_prefectures に JOIN して一致するものだけ残す
 * - prefecture_map に存在するコードのみ許可（想定外の値を弾く）
 *
 * ※ INNER JOIN を追加するため、SQL文字列に追記している
 * ----------------------------- */
if ($pref && isset($prefMap[$pref])) {
    $sql .= "
    INNER JOIN route_prefectures rp
      ON rp.route_id = r.id
     AND rp.prefecture_code = :pref
    ";
    $params[':pref'] = $pref;
}

/* -----------------------------
 * WHERE適用 → route_id 単位で1行にまとめるため GROUP BY
 * ----------------------------- */
$sql .= " WHERE " . implode(' AND ', $where);
$sql .= " GROUP BY r.id ";

/* -----------------------------
 * 並び替え（お気に入り登録順）
 * - new（デフォルト）: 自分がいいねした日時の新しい順
 * - old              : 自分がいいねした日時の古い順
 *
 * ※ routes.created_at ではなく my.created_at を使うのがポイント
 * ----------------------------- */
if ($order === 'old') {
    $sql .= " ORDER BY my.created_at ASC ";
} else {
    $sql .= " ORDER BY my.created_at DESC ";
}

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
    <title>お気に入り | Drive Mapping</title>
    <style>
        /* -----------------------------
         * 画面スタイル（最小限）
         * ----------------------------- */
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

        /* 検索フォーム（キーワード・都道府県・並び替え） */
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

        /* カードUI */
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

        /* 投稿日・いいね数などの表示 */
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

        /* スマホ対応 */
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

    <!-- 主要ナビ -->
    <div class="nav">
        <a href="/">← トップへ</a>
        <a href="/routes/index.php">投稿一覧</a>
        <a href="/create.php">＋ 新規投稿</a>
    </div>

    <h1>お気に入り</h1>

    <!-- ログインユーザー名と件数 -->
    <div class="meta">
        ログイン中：<?= h($_SESSION['user_name']) ?> さん / <?= count($routes) ?> 件
    </div>

    <!-- 検索フォーム（GETで再表示） -->
    <form method="get" class="search">
        <input type="text" name="q" placeholder="キーワード（タイトル・概要・説明）" value="<?= h($q) ?>">

        <!-- 都道府県フィルタ -->
        <select name="pref">
            <option value="">都道府県</option>
            <?php foreach ($prefMap as $c => $n): ?>
                <option value="<?= (int)$c ?>" <?= ($pref === (int)$c) ? 'selected' : '' ?>>
                    <?= h($n) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- 並び替え（お気に入り登録順） -->
        <select name="order">
            <option value="new" <?= ($order === 'new') ? 'selected' : '' ?>>新しい順（お気に入り登録）</option>
            <option value="old" <?= ($order === 'old') ? 'selected' : '' ?>>古い順（お気に入り登録）</option>
        </select>

        <button type="submit">検索</button>
    </form>

    <!-- お気に入りが0件の場合 -->
    <?php if (!$routes): ?>
        <p>お気に入りはまだありません。</p>
    <?php endif; ?>

    <!-- お気に入り一覧 -->
    <?php foreach ($routes as $r): ?>
        <?php
        $routeId = (int)$r['id'];

        /*
         * 一覧サムネ画像の決定
         * - thumb があれば thumbnails を使う（軽い）
         * - なければ first_file（原寸）を使う（後方互換）
         * - どちらもなければプレースホルダー枠のみ表示
         */
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
                <!-- タイトル（詳細へ） -->
                <h3 style="margin:0 0 6px;">
                    <a href="/routes/show.php?id=<?= $routeId ?>"><?= h($r['title']) ?></a>
                </h3>

                <!-- 投稿日といいね数 -->
                <div class="row-meta">
                    <div class="meta"><?= h($r['created_at']) ?></div>
                    <div class="badge">いいね <?= (int)$r['like_cnt'] ?></div>
                </div>

                <!-- 概要 -->
                <p style="margin:8px 0 0;"><?= h((string)$r['summary']) ?></p>
            </div>
        </div>
    <?php endforeach; ?>

</body>

</html>
