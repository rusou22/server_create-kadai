<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| update.php（投稿更新処理）
|--------------------------------------------------------------------------
| 目的：
|   - edit.php から送信されたフォーム（POST）を受け取り、
|     routes / route_prefectures / route_points / route_photos を更新する。
|   - 写真の削除・追加もここで処理し、一覧用サムネを「必ず1枚目に統一」する。
|
| このファイルの役割：
|   - 表示はしない（処理専用）
|   - 失敗時はHTTP 400でエラーメッセージを返す
|   - 成功時は show.php にリダイレクトする
|
| 重要：仕様統一ルール
|   - routes.map_url は「目的地サイトURL」として扱う（create/edit/show/update で統一）
|   - route_points.url カラムは「住所」を格納する用途として運用中（カラム名はurlだが住所）
|
| セキュリティ：
|   - require_login() でログイン必須
|   - csrf_verify() でCSRFチェック
|   - 所有者確認（routes.user_id とセッション user_id が一致するか）
|   - 画像は MIME 判定で拡張子を許可（jpg/png/webp）
*/

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/prefectures.php';
require_once __DIR__ . '/../config/csrf.php';

/* -----------------------------
 * 認証・CSRF
 * ----------------------------- */
require_login();
csrf_verify();

/* -----------------------------
 * 初期化：DB接続・都道府県マップ
 * ----------------------------- */
$pdo = db();
$prefMap = prefecture_map();

/* ----------------------------------------------------------------------
 * 画像ヘルパー
 * ----------------------------------------------------------------------
 * safe_image_extension():
 *   - 一時ファイルのMIMEタイプから拡張子を決定（偽装拡張子対策）
 * create_thumbnail():
 *   - 画像を縮小して JPEG のサムネを生成する（最大 360x270）
 */

/* ===== 画像ヘルパ ===== */
function safe_image_extension(string $tmpPath): ?string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $tmpPath) : '';
    if ($finfo) finfo_close($finfo);

    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => null,
    };
}

function create_thumbnail(string $srcPath, string $dstPath, int $maxW = 360, int $maxH = 270): void
{
    $info = getimagesize($srcPath);
    if ($info === false) throw new RuntimeException('画像情報が取得できません');

    [$w, $h] = $info;
    $mime = $info['mime'] ?? '';

    $srcImg = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($srcPath),
        'image/png'  => imagecreatefrompng($srcPath),
        'image/webp' => imagecreatefromwebp($srcPath),
        default      => null,
    };
    if (!$srcImg) throw new RuntimeException('対応していない画像形式です');

    // 元画像が大きい場合のみ縮小（拡大はしない）
    $scale = min($maxW / $w, $maxH / $h, 1.0);
    $newW = (int)max(1, floor($w * $scale));
    $newH = (int)max(1, floor($h * $scale));

    // 背景を白で埋めたキャンバスに縮小画像を描画
    $dstImg = imagecreatetruecolor($newW, $newH);
    $white = imagecolorallocate($dstImg, 255, 255, 255);
    imagefilledrectangle($dstImg, 0, 0, $newW, $newH, $white);

    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $w, $h);

    // サムネは JPEG 固定（軽量化）
    if (!imagejpeg($dstImg, $dstPath, 82)) {
        imagedestroy($srcImg);
        imagedestroy($dstImg);
        throw new RuntimeException('サムネ生成に失敗しました');
    }

    imagedestroy($srcImg);
    imagedestroy($dstImg);
}

/**
 * ✅ サムネを「1枚目（sort_order最小）」に統一する
 *
 * 目的：
 *   - 一覧ページ（index.php など）で常に同じルールでサムネを表示できるようにするため。
 *
 * 仕様：
 *   - thumb_name='thumb.jpg' は常に「先頭写真（sort_order最小）」だけが持つ
 *   - もし先頭以外が誤って thumb.jpg を持っていたら、別名サムネに退避して矛盾を解消する
 *
 * 入力：
 *   - $uploadBase : 元画像が保存されているディレクトリ（.../uploads/routes/{id}）
 *   - $thumbDir   : サムネ保存ディレクトリ（.../uploads/routes/{id}/thumbs）
 */
function ensure_thumbnail_is_first(PDO $pdo, int $routeId, string $uploadBase, string $thumbDir): void
{
    // route_photos を表示順（sort_order）で取得
    $stmt = $pdo->prepare('
        SELECT id, file_name, thumb_name, sort_order
        FROM route_photos
        WHERE route_id = ?
        ORDER BY sort_order ASC, id ASC
    ');
    $stmt->execute([$routeId]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 写真が無ければ thumb.jpg を削除して終了（任意の整理）
    if (!$photos) {
        @unlink($thumbDir . '/thumb.jpg');
        return;
    }

    // 先頭写真（これを thumb.jpg にする）
    $first = $photos[0];
    $firstId = (int)$first['id'];

    // 現在 thumb.jpg を持っている写真IDを収集（複数あると困る）
    $thumbOwners = [];
    foreach ($photos as $p) {
        if ((string)($p['thumb_name'] ?? '') === 'thumb.jpg') {
            $thumbOwners[] = (int)$p['id'];
        }
    }

    // 先頭以外が thumb.jpg を持っていたら別名サムネへ退避
    if ($thumbOwners) {
        foreach ($thumbOwners as $ownerId) {
            if ($ownerId === $firstId) continue;

            // owner の file_name を探す
            foreach ($photos as $pp) {
                if ((int)$pp['id'] === $ownerId) {
                    $ownerFile = (string)$pp['file_name'];
                    break;
                }
            }
            if (!isset($ownerFile)) continue;

            // 別名サムネ（t_xxx.jpg）を作り、DBを更新
            $newThumbName = 't_' . bin2hex(random_bytes(16)) . '.jpg';
            $newThumbPath = $thumbDir . '/' . $newThumbName;
            $srcPath = $uploadBase . '/' . $ownerFile;

            if (is_file($srcPath)) {
                create_thumbnail($srcPath, $newThumbPath, 360, 270);
            }

            $upd = $pdo->prepare('UPDATE route_photos SET thumb_name=? WHERE id=? AND route_id=?');
            $upd->execute([$newThumbName, $ownerId, $routeId]);

            unset($ownerFile);
        }
    }

    // 先頭写真を thumb.jpg にする（ファイルも生成/上書き）
    $firstFile = (string)$first['file_name'];
    $firstSrcPath = $uploadBase . '/' . $firstFile;
    $thumbPath = $thumbDir . '/thumb.jpg';

    if (!is_file($firstSrcPath)) {
        // 元画像が無い（通常は起きない）→ 何もしない
        return;
    }

    // 先頭が別名サムネを持っていたら、古いサムネファイルを削除して整理
    $oldFirstThumbName = (string)($first['thumb_name'] ?? '');
    if ($oldFirstThumbName !== '' && $oldFirstThumbName !== 'thumb.jpg') {
        @unlink($thumbDir . '/' . $oldFirstThumbName);
    }

    // 先頭画像から thumb.jpg を生成
    create_thumbnail($firstSrcPath, $thumbPath, 360, 270);

    // 先頭写真の thumb_name を 'thumb.jpg' に更新
    $updFirst = $pdo->prepare('UPDATE route_photos SET thumb_name=? WHERE id=? AND route_id=?');
    $updFirst->execute(['thumb.jpg', $firstId, $routeId]);
}

/* ----------------------------------------------------------------------
 * 入力：更新対象ID（POST）
 * ---------------------------------------------------------------------- */
$routeId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$routeId) {
    http_response_code(400);
    exit('不正なIDです');
}

/* ----------------------------------------------------------------------
 * 所有者確認
 * - routes を取得し、存在チェック（404）
 * - routes.user_id と session user_id の一致チェック（403）
 * ---------------------------------------------------------------------- */
$stmt = $pdo->prepare('SELECT * FROM routes WHERE id = :id');
$stmt->execute([':id' => $routeId]);
$route = $stmt->fetch();
if (!$route) {
    http_response_code(404);
    exit('投稿が見つかりません');
}
if ((int)$route['user_id'] !== (int)$_SESSION['user_id']) {
    http_response_code(403);
    exit('権限がありません');
}

/* ----------------------------------------------------------------------
 * フィールド取得（仕様統一）
 * ----------------------------------------------------------------------
 * routes:
 *   - title / summary / description
 *   - prefecture_code（メイン）
 *   - address（住所）
 *   - map_url（目的地サイトURLとして統一）
 *
 * route_prefectures:
 *   - prefecture_code（メイン/サブ）を入れ替え方式で更新
 *
 * route_points:
 *   - start / middle[] / goal を入れ替え方式で更新
 *   - url カラムに住所（address）を入れる（互換設計）
 */
$title = trim($_POST['title'] ?? '');
$summary = trim($_POST['summary'] ?? '');
$description = trim($_POST['description'] ?? '');

$address = trim($_POST['address'] ?? '');   // routes.address
$site_url = trim($_POST['map_url'] ?? '');  // routes.map_url（=目的地サイトURL）

$mainPref = filter_input(INPUT_POST, 'prefecture_code', FILTER_VALIDATE_INT);
$sub_prefectures = $_POST['sub_prefectures'] ?? [];

$startLabel = trim($_POST['start_label'] ?? '');
$startAddress = trim($_POST['start_address'] ?? '');
$middleLabels = $_POST['middle_label'] ?? [];
$middleAddresses = $_POST['middle_address'] ?? [];
$goalLabel = trim($_POST['goal_label'] ?? '');
$goalAddress = trim($_POST['goal_address'] ?? '');

/* ----------------------------------------------------------------------
 * バリデーション
 * ----------------------------------------------------------------------
 * - 必須/文字数/都道府県コード/URL形式など
 * - エラーがあれば 400 でまとめて返す（画面側で表示するなら改善余地あり）
 */
$errors = [];
if ($title === '' || mb_strlen($title) > 100) $errors[] = 'タイトルは必須（100文字以内）です';
if ($summary !== '' && mb_strlen($summary) > 255) $errors[] = '概要は255文字以内にしてください';
if (!$mainPref || !isset($prefMap[$mainPref])) $errors[] = 'メイン都道府県が不正です';

if ($address !== '' && mb_strlen($address) > 255) $errors[] = '住所は255文字以内にしてください';
if ($site_url !== '' && !filter_var($site_url, FILTER_VALIDATE_URL)) $errors[] = '目的地のサイトは正しいURL形式で入力してください';

if ($errors) {
    http_response_code(400);
    exit(implode("\n", $errors));
}

/* ----------------------------------------------------------------------
 * 更新処理（トランザクション）
 * ----------------------------------------------------------------------
 * - DB更新とファイル処理をまとめて整合性を保つ
 * - 途中で失敗したら rollback して状態を戻す
 */
try {
    $pdo->beginTransaction();

    /* ----------------------------------------------------------
     * ① routes 更新（map_url=目的地サイトURLに統一）
     * ---------------------------------------------------------- */
    $up = $pdo->prepare('
      UPDATE routes
      SET title=:t, summary=:s, description=:d,
          prefecture_code=:p, address=:a, map_url=:m
      WHERE id=:id
    ');
    $up->execute([
        ':t' => $title,
        ':s' => $summary !== '' ? $summary : null,
        ':d' => $description !== '' ? $description : null,
        ':p' => $mainPref,
        ':a' => $address !== '' ? $address : null,
        ':m' => $site_url !== '' ? $site_url : null,
        ':id' => $routeId
    ]);

    /* ----------------------------------------------------------
     * ② route_prefectures 更新（入れ替え方式）
     * - 一旦DELETEしてから、メイン＋サブをINSERT
     * ---------------------------------------------------------- */
    $pdo->prepare('DELETE FROM route_prefectures WHERE route_id=:rid')->execute([':rid' => $routeId]);

    $rpStmt = $pdo->prepare('
      INSERT INTO route_prefectures (route_id, prefecture_code, is_main)
      VALUES (:rid, :code, :is_main)
    ');
    // メイン
    $rpStmt->execute([':rid' => $routeId, ':code' => $mainPref, ':is_main' => 1]);

    // サブ（重複排除・メインと同じものは除外）
    foreach (array_unique($sub_prefectures) as $code) {
        $code = (int)$code;
        if ($code === $mainPref || !isset($prefMap[$code])) continue;
        $rpStmt->execute([':rid' => $routeId, ':code' => $code, ':is_main' => 0]);
    }

    /* ----------------------------------------------------------
     * ③ route_points 更新（入れ替え方式：urlカラム=住所）
     * - 一旦DELETEしてから start / middle[] / goal を作り直す
     * - sort_order で表示順を管理する
     * ---------------------------------------------------------- */
    $pdo->prepare('DELETE FROM route_points WHERE route_id=:rid')->execute([':rid' => $routeId]);

    $ptStmt = $pdo->prepare('
      INSERT INTO route_points (route_id, point_type, label, url, sort_order)
      VALUES (:rid, :type, :label, :addr, :ord)
    ');

    // スタート（どちらか入力があれば登録）
    if ($startLabel !== '' || $startAddress !== '') {
        $ptStmt->execute([
            ':rid' => $routeId,
            ':type' => 'start',
            ':label' => ($startLabel !== '' ? $startLabel : 'スタート'),
            ':addr' => ($startAddress !== '' ? $startAddress : null),
            ':ord' => 0
        ]);
    }

    // 中間（配列で複数。空行はスキップ）
    foreach ($middleLabels as $i => $label) {
        $label = trim((string)$label);
        $addr = trim((string)($middleAddresses[$i] ?? ''));
        if ($label === '' && $addr === '') continue;

        $ptStmt->execute([
            ':rid' => $routeId,
            ':type' => 'middle',
            ':label' => ($label !== '' ? $label : '中間地点'),
            ':addr' => ($addr !== '' ? $addr : null),
            ':ord' => $i + 1
        ]);
    }

    // ゴール（どちらか入力があれば登録。最後扱いなので大きいsort_order）
    if ($goalLabel !== '' || $goalAddress !== '') {
        $ptStmt->execute([
            ':rid' => $routeId,
            ':type' => 'goal',
            ':label' => ($goalLabel !== '' ? $goalLabel : 'ゴール'),
            ':addr' => ($goalAddress !== '' ? $goalAddress : null),
            ':ord' => 999
        ]);
    }

    /* ----------------------------------------------------------
     * アップロード先フォルダ準備
     * - uploads/routes/{routeId}
     * - uploads/routes/{routeId}/thumbs
     * ---------------------------------------------------------- */
    $uploadBase = __DIR__ . '/../uploads/routes/' . $routeId;
    $thumbDir = $uploadBase . '/thumbs';

    if (!is_dir($uploadBase)) {
        if (!mkdir($uploadBase, 0777, true) && !is_dir($uploadBase)) {
            throw new RuntimeException('アップロードフォルダを作成できません');
        }
    }
    if (!is_dir($thumbDir)) {
        if (!mkdir($thumbDir, 0777, true) && !is_dir($thumbDir)) {
            throw new RuntimeException('サムネフォルダを作成できません');
        }
    }

    /* ----------------------------------------------------------
     * ④ 写真削除（ファイル＋DB）
     * - delete_photo_ids[] で指定された route_photos.id を削除
     * - 元画像ファイルとサムネファイル（thumb_name）があれば両方削除
     * ---------------------------------------------------------- */
    $deleteIds = $_POST['delete_photo_ids'] ?? [];
    if (is_array($deleteIds) && $deleteIds) {
        $deleteIds = array_values(array_filter(array_map('intval', $deleteIds), fn($v) => $v > 0));
        if ($deleteIds) {
            $in = implode(',', array_fill(0, count($deleteIds), '?'));

            // 削除対象のファイル名を先に取得
            $sel = $pdo->prepare("SELECT id, file_name, thumb_name FROM route_photos WHERE route_id=? AND id IN ($in)");
            $sel->execute(array_merge([$routeId], $deleteIds));
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

            // ファイル削除（失敗しても致命にしないため @ を付けている）
            foreach ($rows as $r) {
                @unlink($uploadBase . '/' . (string)$r['file_name']);
                $tn = (string)($r['thumb_name'] ?? '');
                if ($tn !== '') {
                    @unlink($thumbDir . '/' . $tn);
                }
            }

            // DB削除
            $del = $pdo->prepare("DELETE FROM route_photos WHERE route_id=? AND id IN ($in)");
            $del->execute(array_merge([$routeId], $deleteIds));
        }
    }

    /* ----------------------------------------------------------
     * ⑤ 写真追加（ファイル保存＋サムネ生成＋DB）
     * - photos[] を複数アップロード可能
     * - 合計10枚制限（現在枚数＋追加予定数で判定）
     * - sort_order は末尾に追加
     * - 追加時点では仮サムネ名（t_xxx.jpg）を作り、最後に統一処理へ
     * ---------------------------------------------------------- */
    if (!empty($_FILES['photos']) && isset($_FILES['photos']['tmp_name']) && is_array($_FILES['photos']['tmp_name'])) {

        // 現在の枚数
        $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM route_photos WHERE route_id=?');
        $cntStmt->execute([$routeId]);
        $currentCount = (int)$cntStmt->fetchColumn();

        // 追加予定数（UPLOAD_ERR_NO_FILE を除外）
        $errArr = $_FILES['photos']['error'] ?? [];
        $addCandidates = 0;
        foreach ($errArr as $e) {
            if ((int)$e !== UPLOAD_ERR_NO_FILE) $addCandidates++;
        }

        // 10枚制限
        if ($currentCount + $addCandidates > 10) {
            throw new RuntimeException('写真は合計で最大10枚までです（削除してから追加してください）');
        }

        // sort_order の開始値（既存最大+1）
        $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM route_photos WHERE route_id=?');
        $maxStmt->execute([$routeId]);
        $sort = (int)$maxStmt->fetchColumn() + 1;

        $photoInsert = $pdo->prepare('
          INSERT INTO route_photos (route_id, file_name, thumb_name, sort_order)
          VALUES (:rid, :file, :thumb, :ord)
        ');

        foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
            $err = (int)($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE);

            // ファイルが選ばれていない枠はスキップ
            if ($err === UPLOAD_ERR_NO_FILE) continue;

            // それ以外のエラーは中断
            if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('写真アップロードに失敗したファイルがあります');

            // PHPのアップロード経由かチェック（不正アップロード対策）
            if (!is_uploaded_file($tmp)) throw new RuntimeException('不正なアップロードが検出されました');

            // MIMEから拡張子を決定（許可形式のみ）
            $ext = safe_image_extension($tmp);
            if ($ext === null) throw new RuntimeException('対応していない画像形式があります（jpg/png/webpのみ）');

            // 新規ファイル名（推測困難なランダム）
            $newName = bin2hex(random_bytes(16)) . '.' . $ext;
            $dest = $uploadBase . '/' . $newName;

            // 元画像を保存
            if (!move_uploaded_file($tmp, $dest)) {
                throw new RuntimeException('写真の保存に失敗しました');
            }

            // 仮サムネ生成（最後に "thumb.jpg" を先頭へ統一する）
            $thumbName = 't_' . bin2hex(random_bytes(16)) . '.jpg';
            $thumbPath = $thumbDir . '/' . $thumbName;

            create_thumbnail($dest, $thumbPath, 360, 270);

            // DB登録
            $photoInsert->execute([
                ':rid' => $routeId,
                ':file' => $newName,
                ':thumb' => $thumbName,
                ':ord' => $sort++,
            ]);
        }
    }

    /* ----------------------------------------------------------
     * ✅ 最後に必ず「1枚目＝thumb.jpg」に揃える
     * - 削除/追加の結果を反映した上で統一するのがポイント
     * ---------------------------------------------------------- */
    ensure_thumbnail_is_first($pdo, $routeId, $uploadBase, $thumbDir);

    /* コミットして更新確定 */
    $pdo->commit();

    /* 更新後は詳細へ戻す */
    header('Location: /routes/show.php?id=' . $routeId);
    exit;

} catch (Throwable $e) {

    /* 失敗したらロールバック */
    if ($pdo->inTransaction()) $pdo->rollBack();

    http_response_code(400);
    exit('更新に失敗しました：' . $e->getMessage());
}
