<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

csrf_verify();

$pdo = db();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    exit('不正なIDです');
}

$stmt = $pdo->prepare('SELECT user_id FROM routes WHERE id = :id');
$stmt->execute([':id' => $id]);
$owner = $stmt->fetch();

if (!$owner) {
    http_response_code(404);
    exit('投稿が見つかりません');
}
if ((int)$owner['user_id'] !== (int)$_SESSION['user_id']) {
    http_response_code(403);
    exit('この投稿は削除できません');
}

$stmt = $pdo->prepare('DELETE FROM routes WHERE id = :id');
$stmt->execute([':id' => $id]);

header('Location: /routes/index.php');
exit;
