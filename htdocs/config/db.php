<?php

// =============================
// 厳格な型チェックを有効化
// DB接続は型ミス＝致命的エラーになりやすいため重要
// =============================
declare(strict_types=1);


// =============================
// DB接続用関数
// =============================
function db(): PDO
{
    // =============================
    // static 変数で PDO を保持
    // 同一リクエスト内で何度呼ばれても
    // 接続は1回だけにする（軽量化）
    // =============================
    static $pdo = null;

    // すでに接続済みならそれを返す
    if ($pdo) {
        return $pdo;
    }

    // =============================
    // DSN（接続情報）
    // =============================
    // host=db
    // → docker-compose の service 名「db」
    //    コンテナ間通信では localhost ではなく service 名を使う
    //
    // dbname=drive_mapping
    // → MYSQL_DATABASE で指定したDB名
    //
    // charset=utf8mb4
    // → 絵文字・日本語対応
    // =============================
    $dsn = 'mysql:host=db;dbname=drive_mapping;charset=utf8mb4';

    // =============================
    // DBユーザー情報
    // docker-compose.yml の環境変数と一致させる
    // =============================
    $user = 'appuser';
    $pass = 'apppass';

    // =============================
    // PDOインスタンス作成
    // =============================
    $pdo = new PDO(
        $dsn,
        $user,
        $pass,
        [
            // エラー時に例外を投げる（必須）
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            // fetch() のデフォルト形式を連想配列に
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // 接続済みPDOを返す
    return $pdo;
}
