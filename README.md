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
