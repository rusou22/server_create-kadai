# 環境構築手順書（Docker 利用）家でやる用

## 1. Docker Desktop のインストール  
以下の手順を参考にインストールしてください。  
👉 [Docker Desktop インストール手順（Qiita）](https://qiita.com/zembutsu/items/a98f6f25ef47c04893b3)  

インストールが完了すると、`Docker` と `Docker Compose` が利用可能になります。  

---

## 2. バージョン確認  
ターミナルまたはコマンドプロンプトで以下を実行し、バージョンが表示されればOKです。  

```bash
docker -v
docker compose version
```

---

## 3. プロジェクトフォルダ構成  

Docker Compose を使う場合、以下のようなフォルダ構成にします。  

```
Drive_Mapping/
├── docker-compose.yml        ← Docker Compose 設定ファイル
├── htdocs/                   ← Webアプリのソースコードを置く
│   ├── config/               ← 共通設定ファイル
│   │   ├── auth.php          ← 認証関連
│   │   ├── csrf.php          ← CSRF対策
│   │   ├── db.php            ← DB接続設定
│   │   ├── helpers.php       ← 共通ヘルパー関数
│   │   └── prefectures.php  ← 都道府県マスタ
│   │
│   ├── routes/               ← 各種処理用PHP
│   │   ├── delete.php
│   │   ├── edit.php
│   │   ├── favorites.php
│   │   ├── index.php
│   │   ├── like.php
│   │   ├── show.php
│   │   └── update.php
│   │
│   ├── uploads/              ← 投稿画像保存用ディレクトリ
│   │   └── routes/
│   │
│   ├── create.php            ← 記録登録画面
│   ├── index.php             ← トップページ
│   ├── japan_map.php         ← 日本地図表示
│   ├── login.php             ← ログイン画面
│   ├── logout.php            ← ログアウト処理
│   ├── prefecture.php        ← 都道府県別ページ
│   ├── register.php          ← 新規登録画面
│   └── map.jpg               ← 日本地図画像
│
├── php/                      ← PHP用コンテナ設定
│   ├── Dockerfile
│   └── php.ini

```



## 4. コンテナのビルド & 起動  

### ビルド（初回のみ必要）  
```bash
docker-compose build
```

📌 解説：  
- 設定ファイル（Dockerfile）に基づいてイメージを作成します。  
- 作成されたイメージはキャッシュされるため、次回以降は高速に起動できます。  

---

### 起動  
```bash
docker-compose up -d
```

📌 解説：  
- 作成済みのイメージからコンテナを起動します。  
- `-d` オプションを付けるとバックグラウンド実行になります。  

✅ ビルドと起動をまとめて行う場合は以下でもOKです。  
```bash
docker-compose up -d --build
```

---

## 5. 起動確認  

以下の方法でコンテナが起動しているか確認できます。  

### Docker Desktop で確認  
- `Containers` タブを開き、`docker-kadai` 内に以下が表示されていることを確認してください。  
  - `mysql-1`  
  - `phpmyadmin`  
  - `php-apache-1`  

### コマンドで確認  
```bash
docker-compose ps
```

---

## 7. Git 関連（ソースコード管理）  
リポジトリのクローンやコミット方法については、授業資料「3章・4章」を参照してください。  


8. データベース設計（使用SQL）

本プロジェクトでは、ユーザー情報・ツーリング／ドライブ記録・関連データを管理するため、以下のテーブル構成を採用しています。

users テーブル

ユーザーアカウント情報を管理するテーブルです。
ログイン認証や投稿データの所有者管理に使用します。

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

routes テーブル

ツーリング・ドライブのメインとなる記録情報を管理するテーブルです。

CREATE TABLE routes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(100) NOT NULL,
  summary VARCHAR(255) NULL,
  description TEXT NULL,
  prefecture_code TINYINT UNSIGNED NOT NULL,
  map_url TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_routes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_routes_pref (prefecture_code),
  INDEX idx_routes_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


投稿タイトル・概要・詳細説明を管理

都道府県コードを用いた地域別表示に対応

ユーザー削除時に投稿も削除されるよう外部キー制約を設定

routes テーブルの user_id 制約変更

将来的な仕様変更を考慮し、user_id を NULL 許可に変更しています。

ALTER TABLE routes
MODIFY user_id BIGINT UNSIGNED NULL;

route_prefectures テーブル

1つのルートが複数の都道府県を跨ぐ場合に対応するための中間テーブルです。

CREATE TABLE route_prefectures (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  route_id BIGINT UNSIGNED NOT NULL,
  prefecture_code TINYINT UNSIGNED NOT NULL,
  is_main TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_route_prefectures_route
    FOREIGN KEY (route_id)
    REFERENCES routes(id)
    ON DELETE CASCADE,
  INDEX idx_rp_route (route_id),
  INDEX idx_rp_pref (prefecture_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

route_points テーブル

ルート内の立ち寄り地点（開始・経由・終了）を管理するテーブルです。

CREATE TABLE route_points (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  route_id BIGINT UNSIGNED NOT NULL,
  point_type ENUM('start','middle','goal') NOT NULL,
  label VARCHAR(100) NOT NULL,
  url TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_route_points_route
    FOREIGN KEY (route_id)
    REFERENCES routes(id)
    ON DELETE CASCADE,
  INDEX idx_points_route (route_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

route_photos テーブル

ルートに紐づく写真データを管理するテーブルです。

CREATE TABLE route_photos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  route_id BIGINT UNSIGNED NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_route_photos_route
    FOREIGN KEY (route_id)
    REFERENCES routes(id)
    ON DELETE CASCADE,
  INDEX idx_route_photos_route (route_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

routes テーブルへのカラム追加

ルート情報拡張のため、住所情報と外部サイトURLを追加しています。

ALTER TABLE routes
  ADD COLUMN address VARCHAR(255) NULL AFTER description,
  ADD COLUMN site_url TEXT NULL AFTER map_url;


address：目的地や走行エリアの補足情報

site_url：観光地・立ち寄り先の公式サイトなど
