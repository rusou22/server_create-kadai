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

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/prefectures.php';
require_once __DIR__ . '/config/csrf.php';

require_login();

$errors = [];

// 入力保持用
$title = '';
$summary = '';
$description = '';
$address = '';
$site_url = ''; // フォームは site_url、DB保存は map_url に入れる
$prefecture_code = '';
$sub_prefectures = [];

$startLabel = '';
$startAddress = '';
$middleLabels = [];
$middleAddresses = [];
$goalLabel = '';
$goalAddress = '';

$prefMap = prefecture_map();

/* ========= 写真アップロード用ヘルパ ========= */
function normalize_files_array(array $files): array
{
    $result = [];
    if (!isset($files['name']) || !is_array($files['name'])) return $result;

    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        $result[] = [
            'name' => $files['name'][$i] ?? '',
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
    }
    return $result;
}

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

/* ========= サムネ生成 ========= */
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

    // 背景白（PNG透過等の簡易対策）
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // 基本項目
    $title = trim($_POST['title'] ?? '');
    $summary = trim($_POST['summary'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // 住所＆目的地サイト
    $address = trim($_POST['address'] ?? '');
    $site_url = trim($_POST['site_url'] ?? '');

    // 都道府県
    $mainPref = filter_input(INPUT_POST, 'prefecture_code', FILTER_VALIDATE_INT);
    $sub_prefectures = $_POST['sub_prefectures'] ?? [];

    // ルート（住所）
    $startLabel = trim($_POST['start_label'] ?? '');
    $startAddress = trim($_POST['start_address'] ?? '');
    $middleLabels = $_POST['middle_label'] ?? [];
    $middleAddresses = $_POST['middle_address'] ?? [];
    $goalLabel = trim($_POST['goal_label'] ?? '');
    $goalAddress = trim($_POST['goal_address'] ?? '');

    // 写真
    $photoFiles = [];
    if (!empty($_FILES['photos'])) {
        $photoFiles = normalize_files_array($_FILES['photos']);
    }

    /* ---------- バリデーション ---------- */
    if ($title === '' || mb_strlen($title) > 100) $errors[] = 'タイトルは必須（100文字以内）です';
    if ($summary !== '' && mb_strlen($summary) > 255) $errors[] = '概要は255文字以内にしてください';
    if (!$mainPref || !isset($prefMap[$mainPref])) $errors[] = 'メインで通った都道府県を選択してください';

    if ($address !== '' && mb_strlen($address) > 255) $errors[] = '住所は255文字以内にしてください';

    if ($site_url !== '' && !filter_var($site_url, FILTER_VALIDATE_URL)) {
        $errors[] = '目的地のサイトは正しいURL形式で入力してください';
    }

    // 写真最大10枚
    $validPhotoCandidates = array_filter(
        $photoFiles,
        fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
    );
    if (count($validPhotoCandidates) > 10) {
        $errors[] = '写真は最大10枚までです';
    }

    if (!$errors) {
        $pdo = db();

        try {
            $pdo->beginTransaction();

            /* ① routes
               ✅ address は address
               ✅ 目的地サイトURLは map_url に入れる（互換維持）
            */
            $stmt = $pdo->prepare('
              INSERT INTO routes (user_id, title, summary, description, address, map_url, prefecture_code)
              VALUES (:user_id, :title, :summary, :description, :address, :map_url, :prefecture_code)
            ');
            $stmt->execute([
                ':user_id' => (int)$_SESSION['user_id'],
                ':title' => $title,
                ':summary' => $summary !== '' ? $summary : null,
                ':description' => $description !== '' ? $description : null,
                ':address' => $address !== '' ? $address : null,
                ':map_url' => $site_url !== '' ? $site_url : null,
                ':prefecture_code' => $mainPref, // 後方互換
            ]);

            $routeId = (int)$pdo->lastInsertId();

            /* ② route_prefectures（メイン／サブ） */
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

            /* ③ route_points（地点）※ urlカラムを「住所保存」として利用 */
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

            /* ④ 写真保存（uploads + route_photos） */
            $uploadBase = __DIR__ . '/uploads/routes/' . $routeId;
            $thumbDir = $uploadBase . '/thumbs';

            // ✅ base と thumbs を確実に作る
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

            $photoInsert = $pdo->prepare('
              INSERT INTO route_photos (route_id, file_name, thumb_name, sort_order)
              VALUES (:rid, :file, :thumb, :ord)
            ');

            $sort = 0;
            foreach ($validPhotoCandidates as $file) {
                if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
                    $errors[] = '写真アップロードに失敗したファイルがあります';
                    break;
                }
                if (!is_uploaded_file($file['tmp_name'])) {
                    $errors[] = '不正なアップロードが検出されました';
                    break;
                }

                $ext = safe_image_extension($file['tmp_name']);
                if ($ext === null) {
                    $errors[] = '対応していない画像形式があります（jpg/png/webpのみ）';
                    break;
                }

                // 元画像ファイル名
                $newName = bin2hex(random_bytes(16)) . '.' . $ext;
                $dest = $uploadBase . '/' . $newName;

                if (!move_uploaded_file($file['tmp_name'], $dest)) {
                    $errors[] = '写真の保存に失敗しました';
                    break;
                }

                // ✅ 1枚目は thumb.jpg に固定（一覧側が拾える）
                $thumbName = ($sort === 0) ? 'thumb.jpg' : ('t_' . bin2hex(random_bytes(16)) . '.jpg');
                $thumbPath = $thumbDir . '/' . $thumbName;

                create_thumbnail($dest, $thumbPath, 360, 270);

                $photoInsert->execute([
                    ':rid' => $routeId,
                    ':file' => $newName,
                    ':thumb' => $thumbName,
                    ':ord' => $sort++,
                ]);
            }

            if ($errors) {
                throw new RuntimeException('写真保存エラー');
            }

            $pdo->commit();

            header('Location: /routes/show.php?id=' . $routeId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (!$errors) $errors[] = '保存中にエラーが発生しました';
        }
    }

    // POST失敗時の再表示用
    $prefecture_code = (string)($mainPref ?? '');
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>投稿作成 | Drive Mapping</title>
    <style>
        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            margin: 20px;
        }

        form {
            max-width: 760px;
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
        }

        .error {
            color: #c00;
        }

        .topbar {
            margin-bottom: 12px;
            font-size: 14px;
        }

        hr {
            margin: 18px 0;
        }

        small {
            color: #555;
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
        ログイン中：<?= h($_SESSION['user_name']) ?> さん |
        <a href="/">トップ</a> |
        <a href="/routes/index.php">投稿一覧</a> |
        <a href="/logout.php">ログアウト</a>
    </div>

    <h1>投稿作成</h1>

    <?php if ($errors): ?>
        <ul class="error">
            <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

        <label>
            タイトル（必須）
            <input type="text" name="title" maxlength="100" value="<?= h($title) ?>">
        </label>

        <label>
            概要（任意）
            <input type="text" name="summary" maxlength="255" value="<?= h($summary) ?>">
        </label>

        <label>
            メインで通った都道府県（必須）
            <select name="prefecture_code" required>
                <option value="">選択してください</option>
                <?php foreach ($prefMap as $code => $name): ?>
                    <option value="<?= (int)$code ?>" <?= ((string)$code === (string)$prefecture_code) ? 'selected' : '' ?>>
                        <?= h($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            サブで通った都道府県（任意・複数）
            <select name="sub_prefectures[]" multiple size="6">
                <?php foreach ($prefMap as $code => $name): ?>
                    <option value="<?= (int)$code ?>" <?= in_array((string)$code, (array)$sub_prefectures, true) ? 'selected' : '' ?>>
                        <?= h($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small>
                ※ 複数の都道府県を通った場合は、<br>
                ・Windows：<strong>Ctrl</strong> キーを押しながらクリック<br>
                ・Mac：<strong>⌘（Command）</strong> キーを押しながらクリック<br>
                すると、複数選択できます。
            </small>
        </label>

        <label>
            説明（任意）
            <textarea name="description"><?= h($description) ?></textarea>
        </label>

        <label>
            住所（任意）
            <input type="text" name="address" value="<?= h($address) ?>" placeholder="例：東京都千代田区〇〇...">
            <small>※ Google Maps のURLではなく、住所を書けます。</small>
        </label>

        <label>
            目的地のサイト（任意）
            <input type="text" name="site_url" value="<?= h($site_url) ?>" placeholder="https://example.com">
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
        <div id="middle-points"></div>
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

        <h3>写真</h3>
        <label>
            写真（最大10枚まで）
            <input type="file" name="photos[]" accept="image/*" multiple>
            <small>
                ※ まとめて選択できます（最大10枚）。<br>
                ✅ <strong>1枚目に選択した写真がサムネになります。</strong><br>
                ・Windows：<strong>Ctrl</strong> / ・Mac：<strong>⌘</strong> キーで複数選択
            </small>
        </label>

        <button type="submit">投稿する</button>
    </form>

    <script>
        function addMiddlePoint() {
            const wrap = document.getElementById('middle-points');
            const div = document.createElement('div');
            div.className = 'point-block';
            div.innerHTML = `
        <div class="point-title">中間</div>
        <label>
            地点名（任意）
            <input type="text" name="middle_label[]" placeholder="中間地点名">
        </label>
        <label>
            住所（任意）
            <input type="text" name="middle_address[]" placeholder="例：〇〇県〇〇市...">
        </label>
        <button type="button" onclick="this.parentNode.remove()">削除</button>
    `;
            wrap.appendChild(div);
        }
    </script>

</body>

</html>
