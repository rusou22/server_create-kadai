<?php

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/csrf.php';

session_start();

$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if ($name === '') $errors[] = 'ユーザー名を入力してください';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = '正しいメールアドレスを入力してください';
    if (strlen($pass) < 6) $errors[] = 'パスワードは6文字以上にしてください';

    if (!$errors) {
        try {
            $pdo = db();

            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
            $stmt->execute([':email' => $email]);

            if ($stmt->fetch()) {
                $errors[] = 'このメールアドレスは既に登録されています';
            } else {
                $stmt = $pdo->prepare('
                  INSERT INTO users (name, email, password_hash)
                  VALUES (:name, :email, :pass)
                ');
                $stmt->execute([
                    ':name'  => $name,
                    ':email' => $email,
                    ':pass'  => password_hash($pass, PASSWORD_DEFAULT),
                ]);

                $_SESSION['user_id'] = (int)$pdo->lastInsertId();
                $_SESSION['user_name'] = $name;

                header('Location: /');
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = '登録に失敗しました';
        }
    }
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <title>新規登録 | Drive Mapping</title>
    <style>
        body {
            font-family: system-ui;
            margin: 20px;
        }

        form {
            max-width: 420px;
        }

        label {
            display: block;
            margin-top: 12px;
        }

        input {
            width: 100%;
            padding: 8px;
            margin-top: 4px;
        }

        button {
            margin-top: 16px;
            padding: 10px;
        }

        .error {
            color: #c00;
        }
    </style>
</head>

<body>

    <h1>新規登録</h1>

    <?php if ($errors): ?>
        <ul class="error">
            <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

        <label>
            ユーザー名
            <input type="text" name="name" value="<?= h($name) ?>">
        </label>

        <label>
            メールアドレス
            <input type="email" name="email" value="<?= h($email) ?>">
        </label>

        <label>
            パスワード
            <input type="password" name="password">
        </label>

        <button type="submit">登録する</button>
    </form>

    <p>すでにアカウントをお持ちですか？ <a href="/login.php">ログイン</a></p>

</body>

</html>
