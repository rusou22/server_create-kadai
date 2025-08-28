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
[任意のプロジェクトフォルダ]
├── docker-compose.yml
├── htdocs/            ← Webアプリのソースコードを置く
├── php/               ← PHP用コンテナ設定
│   ├── Dockerfile
│   └── php.ini
```

---

## 4. フォルダ作成手順  

1. 任意の場所に作業用フォルダを作成します（例：`ドキュメント/Projects/docker-kadai`）。  
2. `docker-kadai` フォルダ内に以下を作成します。  
   - `htdocs` フォルダ（アプリのソース格納用）  
   - `php` フォルダ（PHP コンテナ用設定ファイル置き場）  
     - `Dockerfile`（リポジトリ内の内容をコピー）  
     - `php.ini`（リポジトリ内の内容をコピー）  
3. `docker-kadai` の直下に `docker-compose.yml` を配置します（リポジトリ内の内容をコピー）。  

---

## 5. コンテナのビルド & 起動  

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

## 6. 起動確認  

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
