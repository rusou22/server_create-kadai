# =============================
# PHP + Apache の公式イメージをベースにする
# PHPのバージョンは 8.2
# Webサーバー（Apache）が最初から入っているため、すぐにWeb公開できる
# =============================
FROM php:8.2-apache


# =============================
# 必要なライブラリのインストール
# =============================
RUN apt-get update && apt-get install -y --no-install-recommends \
    # zip 関連（画像アップロードや圧縮ファイル操作用）
    libzip-dev zip unzip \
    \
    # GDライブラリ用（画像処理）
    # JPEG / PNG / WebP を扱えるようにする
    libjpeg62-turbo-dev libpng-dev libwebp-dev \
  \
  # =============================
  # PHP拡張 gd の設定
  # --with-jpeg / --with-webp を指定しないと
  # 画像形式が使えないことがあるため明示的に指定
  # =============================
  && docker-php-ext-configure gd --with-jpeg --with-webp \
  \
  # =============================
  # PHP拡張モジュールのインストール
  # =============================
  && docker-php-ext-install \
     # MySQL（PDO）用：安全なDBアクセス（推奨）
     pdo_mysql \
     # mysqli：既存コード・簡易用途向け
     mysqli \
     # gd：画像リサイズやサムネイル生成などに使用
     gd \
  \
  # =============================
  # apt のキャッシュを削除
  # イメージサイズを小さくするための後処理
  # =============================
  && rm -rf /var/lib/apt/lists/*


# =============================
# 作業ディレクトリの指定
# Apacheのドキュメントルート
# docker exec で入ったときの初期位置にもなる
# =============================
WORKDIR /var/www/html
