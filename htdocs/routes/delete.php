<?php

// =============================
// 厳格な型チェックを有効化
// =============================
declare(strict_types=1);


// =============================
// 必要な設定ファイルを読み込み
// =============================
require_once __DIR__ . '/../config/db.php';    // DB接続
require_once __DIR__ . '/../config/auth.php';  // 認証（ログインチェック）
require_once __DIR__ . '/../config/csrf.php';  // CSRF対策


// =============================
// ログイン必須
// 未ログインユーザーはここで弾かれる
// =============================
require_login();


// =============================
// リクエストメソッドの確認
// POST以外（GETなど）は拒否
// =============================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    exit('Method Not Allowed');
}


// =============================
// CSRFトークン検証
// =============================
csrf_verify();


// =============================
// DB接続
// =============================
$pdo = db();


// =============================
// 削除対象IDの取得・検証
// =============================
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

// IDが不正な場合
if (!$id) {
    http_response_code(400); // Bad Request
    exit('不正なIDです');
}


// =============================
// 投稿の所有者を確認
// =============================
$stmt = $pdo->prepare(
    'SELECT user_id FROM routes WHERE id = :id'
);
$stmt->execute([':id' => $id]);

$owner = $stmt->fetch();

// 投稿が存在しない場合
if (!$owner) {
    http_response_code(404); // Not Found
    exit('投稿が見つかりません');
}

// ログインユーザーと投稿者が一致しない場合
if ((int)$owner['user_id'] !== (int)$_SESSION['user_id']) {
    http_response_code(403); // Forbidden
    exit('この投稿は削除できません');
}


// =============================
// 投稿削除処理
// =============================
$stmt = $pdo->prepare(
    'DELETE FROM routes WHERE id = :id'
);
$stmt->execute([':id' => $id]);


// =============================
// 削除後は一覧ページへリダイレクト
// =============================
header('Location: /routes/index.php');
exit;
