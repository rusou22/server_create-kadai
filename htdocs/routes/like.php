<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

require_login();
csrf_verify();

$pdo = db();

$routeId = filter_input(INPUT_POST, 'route_id', FILTER_VALIDATE_INT);
if (!$routeId) {
    http_response_code(400);
    exit('不正なIDです');
}

$userId = (int)$_SESSION['user_id'];

try {
    $pdo->beginTransaction();

    // すでにいいね済み？
    $chk = $pdo->prepare('SELECT id FROM route_likes WHERE route_id = :rid AND user_id = :uid');
    $chk->execute([':rid' => $routeId, ':uid' => $userId]);
    $liked = (bool)$chk->fetchColumn();

    if ($liked) {
        // 解除
        $del = $pdo->prepare('DELETE FROM route_likes WHERE route_id = :rid AND user_id = :uid');
        $del->execute([':rid' => $routeId, ':uid' => $userId]);
    } else {
        // 追加（uniqueで二重登録防止）
        $ins = $pdo->prepare('INSERT INTO route_likes (route_id, user_id) VALUES (:rid, :uid)');
        $ins->execute([':rid' => $routeId, ':uid' => $userId]);
    }

    $pdo->commit();

    // 元のページへ戻る
    $back = $_SERVER['HTTP_REFERER'] ?? ('/routes/show.php?id=' . $routeId);
    header('Location: ' . $back);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    exit('いいね処理に失敗しました');
}
