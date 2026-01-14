<?php

// =============================
// 厳格な型チェックを有効化
// セキュリティ系コードでは特に重要
// =============================
declare(strict_types=1);


// =============================
// CSRFトークンを生成・取得する関数
// =============================
function csrf_token(): string
{
    // セッションが未開始なら開始
    // auth.php を通らずに使うケースにも対応
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // セッションにトークンが存在しない場合のみ生成
    if (empty($_SESSION['csrf_token'])) {
        // 暗号学的に安全な乱数を32バイト生成し、16進数文字列に変換
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // トークンを返す（フォームに埋め込む用）
    return $_SESSION['csrf_token'];
}


// =============================
// CSRFトークンを検証する関数
// =============================
function csrf_verify(): void
{
    // セッションが未開始なら開始
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // POST送信されたトークンを取得
    $token = $_POST['csrf_token'] ?? '';

    // トークンが存在しない、または一致しない場合は不正アクセス
    if (
        !$token ||
        !hash_equals($_SESSION['csrf_token'] ?? '', $token)
    ) {
        // 403 Forbidden を返す
        http_response_code(403);

        // 処理を即終了（非常に重要）
        exit('CSRF token mismatch');
    }
}
