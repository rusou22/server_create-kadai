

services:
  db:
    image: mysql:8.0
    container_name: drive_mapping_db
    restart: always
    environment:
      MYSQL_ROOT_PASSWORD: rootpassword
      MYSQL_DATABASE: drive_mapping
      MYSQL_USER: appuser
      MYSQL_PASSWORD: apppass
      TZ: Asia/Tokyo
    command: >
      --default-authentication-plugin=mysql_native_password
      --character-set-server=utf8mb4
      --collation-server=utf8mb4_unicode_ci
    volumes:
      - db_data:/var/lib/mysql
      # （任意）初期SQLを自動実行したい場合は下を有効化（あとで追加でOK）
      # - ./db/init:/docker-entrypoint-initdb.d
    ports:
      - "3306:3306"

  phpmyadmin:
    image: phpmyadmin/phpmyadmin:latest
    container_name: drive_mapping_phpmyadmin
    restart: always
    depends_on:
      - db
    environment:
      PMA_HOST: db
      PMA_USER: root
      PMA_PASSWORD: rootpassword
      TZ: Asia/Tokyo
    ports:
      - "8081:80"

  web:
    build:
      context: .
      dockerfile: ./php/Dockerfile
    container_name: drive_mapping_web
    restart: always
    depends_on:
      - db
    volumes:
      - ./htdocs:/var/www/html
      - ./php/php.ini:/usr/local/etc/php/php.ini
    ports:
      - "8080:80"
    environment:
      TZ: Asia/Tokyo

volumes:
  db_data:
