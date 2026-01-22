<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/prefectures.php';
require_once __DIR__ . '/../config/csrf.php';

require_login();
csrf_verify();

$pdo = db();
$prefMap = prefecture_map();

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

    $scale = min($maxW / $w, $maxH / $h, 1.0);
    $newW = (int)max(1, floor($w * $scale));
    $newH = (int)max(1, floor($h * $scale));

    $dstImg = imagecreatetruecolor($newW, $newH);
    $white = imagecolorallocate($dstImg, 255, 255, 255);
    imagefilledrectangle($dstImg, 0, 0, $newW, $newH, $white);

    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $w, $h);

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
 * - thumb_name='thumb.jpg' は常に先頭写真に付与
 * - それ以外が誤って thumb.jpg を持っていたら別名へ退避
 */
function ensure_thumbnail_is_first(PDO $pdo, int $routeId, string $uploadBase, string $thumbDir): void
{
    $stmt = $pdo->prepare('
        SELECT id, file_name, thumb_name, sort_order
        FROM route_photos
        WHERE route_id = ?
        ORDER BY sort_order ASC, id ASC
    ');
    $stmt->execute([$routeId]);
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 写真ゼロなら thumb.jpg を消しておく（任意）
    if (!$photos) {
        @unlink($thumbDir . '/thumb.jpg');
        return;
    }

    $first = $photos[0];
    $firstId = (int)$first['id'];

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

            // owner の file_name を取得
            foreach ($photos as $pp) {
                if ((int)$pp['id'] === $ownerId) {
                    $ownerFile = (string)$pp['file_name'];
                    break;
                }
            }
            if (!isset($ownerFile)) continue;

            $newThumbName = 't_' . bin2hex(random_bytes(16)) . '.jpg';
            $newThumbPath = $thumbDir . '/' . $newThumbName;
            $srcPath = $uploadBase . '/' . $ownerFile;

            // サムネ生成（可能なら）
            if (is_file($srcPath)) {
                create_thumbnail($srcPath, $newThumbPath, 360, 270);
            }

            $upd = $pdo->prepare('UPDATE route_photos SET thumb_name=? WHERE id=? AND route_id=?');
            $upd->execute([$newThumbName, $ownerId, $routeId]);

            unset($ownerFile);
        }
    }

    // 先頭写真を thumb.jpg にする
    $firstFile = (string)$first['file_name'];
    $firstSrcPath = $uploadBase . '/' . $firstFile;
    $thumbPath = $thumbDir . '/thumb.jpg';

    if (!is_file($firstSrcPath)) {
        return;
    }

    $oldFirstThumbName = (string)($first['thumb_name'] ?? '');
    if ($oldFirstThumbName !== '' && $oldFirstThumbName !== 'thumb.jpg') {
        @unlink($thumbDir . '/' . $oldFirstThumbName);
    }

    create_thumbnail($firstSrcPath, $thumbPath, 360, 270);

    $updFirst = $pdo->prepare('UPDATE route_photos SET thumb_name=? WHERE id=? AND route_id=?');
    $updFirst->execute(['thumb.jpg', $firstId, $routeId]);
}

/* ===== 入力 ===== */
$routeId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$routeId) {
    http_response_code(400);
    exit('不正なIDです');
}

/* 所有者確認 */
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

/* ===== フィールド（仕様統一） ===== */
$title = trim($_POST['title'] ?? '');
$summary = trim($_POST['summary'] ?? '');
$description = trim($_POST['description'] ?? '');

$address = trim($_POST['address'] ?? '');        // routes.address
$site_url = trim($_POST['map_url'] ?? '');       // routes.map_url = 目的地サイトURL

$mainPref = filter_input(INPUT_POST, 'prefecture_code', FILTER_VALIDATE_INT);
$sub_prefectures = $_POST['sub_prefectures'] ?? [];

$startLabel = trim($_POST['start_label'] ?? '');
$startAddress = trim($_POST['start_address'] ?? '');
$middleLabels = $_POST['middle_label'] ?? [];
$middleAddresses = $_POST['middle_address'] ?? [];
$goalLabel = trim($_POST['goal_label'] ?? '');
$goalAddress = trim($_POST['goal_address'] ?? '');

/* ===== バリデーション ===== */
$errors = [];
if ($title === '' || mb_strlen($title) > 100) $errors[] = 'タイトルは必須（100文字以内）です';
if ($summary !== '' && mb_strlen($summary) > 255) $errors[] = '概要は250文字以内にしてください';
if (!$mainPref || !isset($prefMap[$mainPref])) $errors[] = 'メイン都道府県が不正です';

if ($address !== '' && mb_strlen($address) > 255) $errors[] = '住所は250文字以内にしてください';
if ($site_url !== '' && !filter_var($site_url, FILTER_VALIDATE_URL)) $errors[] = '目的地のサイトは正しいURL形式で入力してください';

if ($errors) {
    http_response_code(400);
    exit(implode("\n", $errors));
}

try {
    $pdo->beginTransaction();

    /* ① routes 更新（map_url=目的地サイトURLに統一） */
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

    /* ② route_prefectures 更新（入れ替え方式） */
    $pdo->prepare('DELETE FROM route_prefectures WHERE route_id=:rid')->execute([':rid' => $routeId]);

    $rpStmt = $pdo->prepare('
      INSERT INTO route_prefectures (route_id, prefecture_code, is_main)
      VALUES (:rid, :code, :is_main)
    ');
    $rpStmt->execute([':rid' => $routeId, ':code' => $mainPref, ':is_main' => 1]);

    foreach (array_unique($sub_prefectures) as $code) {
        $code = (int)$code;
        if ($code === $mainPref || !isset($prefMap[$code])) continue;
        $rpStmt->execute([':rid' => $routeId, ':code' => $code, ':is_main' => 0]);
    }

    /* ③ route_points 更新（入れ替え方式：urlカラム=住所） */
    $pdo->prepare('DELETE FROM route_points WHERE route_id=:rid')->execute([':rid' => $routeId]);

    $ptStmt = $pdo->prepare('
      INSERT INTO route_points (route_id, point_type, label, url, sort_order)
      VALUES (:rid, :type, :label, :addr, :ord)
    ');

    if ($startLabel !== '' || $startAddress !== '') {
        $ptStmt->execute([
            ':rid' => $routeId,
            ':type' => 'start',
            ':label' => ($startLabel !== '' ? $startLabel : 'スタート'),
            ':addr' => ($startAddress !== '' ? $startAddress : null),
            ':ord' => 0
        ]);
    }

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

    if ($goalLabel !== '' || $goalAddress !== '') {
        $ptStmt->execute([
            ':rid' => $routeId,
            ':type' => 'goal',
            ':label' => ($goalLabel !== '' ? $goalLabel : 'ゴール'),
            ':addr' => ($goalAddress !== '' ? $goalAddress : null),
            ':ord' => 999
        ]);
    }

    /* アップロード先 */
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

    $deleteIds = $_POST['delete_photo_ids'] ?? [];
    if (is_array($deleteIds) && $deleteIds) {
        $deleteIds = array_values(array_filter(array_map('intval', $deleteIds), fn($v) => $v > 0));
        if ($deleteIds) {
            $in = implode(',', array_fill(0, count($deleteIds), '?'));
            $sel = $pdo->prepare("SELECT id, file_name, thumb_name FROM route_photos WHERE route_id=? AND id IN ($in)");
            $sel->execute(array_merge([$routeId], $deleteIds));
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                @unlink($uploadBase . '/' . (string)$r['file_name']);
                $tn = (string)($r['thumb_name'] ?? '');
                if ($tn !== '') {
                    @unlink($thumbDir . '/' . $tn);
                }
            }

            $del = $pdo->prepare("DELETE FROM route_photos WHERE route_id=? AND id IN ($in)");
            $del->execute(array_merge([$routeId], $deleteIds));
        }
    }

    /* ⑤ 写真追加（thumbも生成） */
    if (!empty($_FILES['photos']) && isset($_FILES['photos']['tmp_name']) && is_array($_FILES['photos']['tmp_name'])) {

        // 現在の枚数
        $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM route_photos WHERE route_id=?');
        $cntStmt->execute([$routeId]);
        $currentCount = (int)$cntStmt->fetchColumn();

        // 追加予定数（NO_FILE除外）
        $errArr = $_FILES['photos']['error'] ?? [];
        $addCandidates = 0;
        foreach ($errArr as $e) {
            if ((int)$e !== UPLOAD_ERR_NO_FILE) $addCandidates++;
        }
        if ($currentCount + $addCandidates > 10) {
            throw new RuntimeException('写真は合計で最大10枚までです（削除してから追加してください）');
        }

        // sort_order を末尾に
        $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM route_photos WHERE route_id=?');
        $maxStmt->execute([$routeId]);
        $sort = (int)$maxStmt->fetchColumn() + 1;

        $photoInsert = $pdo->prepare('
          INSERT INTO route_photos (route_id, file_name, thumb_name, sort_order)
          VALUES (:rid, :file, :thumb, :ord)
        ');

        foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
            $err = (int)($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('写真アップロードに失敗したファイルがあります');
            if (!is_uploaded_file($tmp)) throw new RuntimeException('不正なアップロードが検出されました');

            $ext = safe_image_extension($tmp);
            if ($ext === null) throw new RuntimeException('対応していない画像形式があります（jpg/png/webpのみ）');

            $newName = bin2hex(random_bytes(16)) . '.' . $ext;
            $dest = $uploadBase . '/' . $newName;

            if (!move_uploaded_file($tmp, $dest)) {
                throw new RuntimeException('写真の保存に失敗しました');
            }

            // 追加時点では仮のサムネ名（最後に "1枚目=thumb.jpg" へ統一する）
            $thumbName = 't_' . bin2hex(random_bytes(16)) . '.jpg';
            $thumbPath = $thumbDir . '/' . $thumbName;

            create_thumbnail($dest, $thumbPath, 360, 270);

            $photoInsert->execute([
                ':rid' => $routeId,
                ':file' => $newName,
                ':thumb' => $thumbName,
                ':ord' => $sort++,
            ]);
        }
    }

    /*  最後に必ず「1枚目＝thumb.jpg」に揃える */
    ensure_thumbnail_is_first($pdo, $routeId, $uploadBase, $thumbDir);

    $pdo->commit();

    header('Location: /routes/show.php?id=' . $routeId);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    exit('更新に失敗しました：' . $e->getMessage());
}
