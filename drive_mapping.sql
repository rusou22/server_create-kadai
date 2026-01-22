-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- ホスト: db
-- 生成日時: 2026 年 1 月 22 日 12:43
-- サーバのバージョン： 8.0.44
-- PHP のバージョン: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- データベース: `drive_mapping`
--

-- --------------------------------------------------------

--
-- テーブルの構造 `routes`
--

CREATE TABLE `routes` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `title` varchar(100) NOT NULL,
  `summary` varchar(255) DEFAULT NULL,
  `description` text,
  `address` varchar(255) DEFAULT NULL,
  `prefecture_code` tinyint UNSIGNED NOT NULL,
  `map_url` text,
  `site_url` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- テーブルのデータのダンプ `routes`
--

INSERT INTO `routes` (`id`, `user_id`, `title`, `summary`, `description`, `address`, `prefecture_code`, `map_url`, `site_url`, `created_at`, `updated_at`) VALUES
(2, 1, 'aaa', 'aaa', 'aa', NULL, 46, NULL, NULL, '2025-12-19 13:14:30', '2025-12-19 13:14:30'),
(3, 2, 'ｔｔｔｔ', 'ｔｔｔｔｔ', 'ｓふぇあｓふぁｆｆさｆせ\r\nさふぇふぁｓ\r\n\r\n\r\nあｄｓｆｆｆ', NULL, 8, NULL, NULL, '2025-12-19 13:34:02', '2025-12-19 13:34:02'),
(4, 2, 'ｖｖｖｖ', 'あ', 'ｓ', NULL, 5, NULL, NULL, '2025-12-19 13:40:58', '2025-12-19 13:40:58'),
(5, 1, '淡路島', '淡路島へ行ってきました', 'ああああああああああああああああああああああああああああああああああああああああああああああああああ\r\n\r\nええええええええええええええええええええええ\r\nｔｔｔｔｔｔｔｔｔｔｔｔｔｔｔｔｔｔｔｔ\r\n\r\nああああああああああああああああああああああああああああああああああああああああああああああああ', NULL, 48, 'https://maps.app.goo.gl/VzZBsQNzS585q5uq8', NULL, '2025-12-19 16:02:28', '2025-12-19 16:02:28'),
(6, 3, 'テスト投稿', 'テストのための投稿', 'これはテスト投稿です\r\n二行目\r\n三行目\r\n\r\n改行後の五行目', NULL, 26, 'https://kyoto-tech.ac.jp/?gad_source=1&gad_campaignid=23147824056&gbraid=0AAAABBwJvsOaXqh-gpRSWsuWf_h019G4i&gclid=CjwKCAiA64LLBhBhEiwA-PxguyI6Fs3LNmsh559wRq5oA7NPrMFe70SLmCiPn9Qoaqu1oYmFsv1brRoCkYsQAvD_BwE', NULL, '2026-01-10 14:05:13', '2026-01-10 14:05:13'),
(7, 3, '京都TECH テスト投稿', 'テストです', 'ああああああああああああああああああああああああああああ\r\n\r\nいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいいい\r\n\r\nｙｙｙｙｙｙｙｙｙｙｙ\r\n\r\n\r\nうううううううううううう\r\n\r\n\r\n\r\n\r\n\r\nｇｙｇ\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\nせｆｆ\r\n\r\n\r\n\r\n\r\nえｆ', '〒600-8357 京都府京都市下京区柿本町５９６', 26, 'https://kyoto-tech.ac.jp/', NULL, '2026-01-10 15:58:01', '2026-01-10 15:58:01');

-- --------------------------------------------------------

--
-- テーブルの構造 `route_likes`
--

CREATE TABLE `route_likes` (
  `id` bigint UNSIGNED NOT NULL,
  `route_id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- テーブルのデータのダンプ `route_likes`
--

INSERT INTO `route_likes` (`id`, `route_id`, `user_id`, `created_at`) VALUES
(3, 4, 1, '2025-12-20 00:06:14');

-- --------------------------------------------------------

--
-- テーブルの構造 `route_photos`
--

CREATE TABLE `route_photos` (
  `id` bigint UNSIGNED NOT NULL,
  `route_id` bigint UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `thumb_name` varchar(255) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- テーブルのデータのダンプ `route_photos`
--

INSERT INTO `route_photos` (`id`, `route_id`, `file_name`, `thumb_name`, `sort_order`, `created_at`) VALUES
(1, 4, 'dcb1fa6205705b2b363e482b8a8aa547.png', '', 0, '2025-12-19 14:49:24'),
(2, 4, 'fa35f047829da2fb1c522fa7a4e809d6.png', '', 1, '2025-12-19 14:49:24'),
(3, 4, '19057b914c5cdd410044096cb2f533de.png', '', 2, '2025-12-19 14:49:24'),
(4, 4, '1c0e35116fc5c90619c11b1f488687c9.png', '', 3, '2025-12-19 14:49:24'),
(5, 4, 'c9282f9ef230ddb6cccb3c8109637cc0.png', '', 4, '2025-12-19 14:49:24'),
(6, 4, '4406c854c888589bcc3bf0d8e49efb77.png', '', 5, '2025-12-19 14:49:24'),
(7, 4, 'caa08a6f16c87fb7a40f07e7b5a7b370.png', '', 6, '2025-12-19 14:49:24'),
(8, 4, '82eb8dc98361cfc788d41235f1740c47.png', '', 7, '2025-12-19 14:49:24'),
(9, 5, 'd958bd03951025d44127f44d0c30b7d0.png', 't_e8bdbc4aa1cc28db750175485ae13f77.jpg', 0, '2025-12-19 16:02:29'),
(10, 5, '55f5acb05b656d150ba15b14b9f39e6a.png', 't_03c22951336e1d31b104119f2af0375e.jpg', 1, '2025-12-19 16:02:29'),
(11, 6, 'e582552a5bfda584dd7c72c80b4705ae.png', 't_4c7d8dfc0c3e211bf261c48d0e521664.jpg', 0, '2026-01-10 14:05:13'),
(12, 7, '52d8169f0da260cb8510acb4255fd6e8.png', 'thumb.jpg', 0, '2026-01-10 15:58:01'),
(13, 7, '73b72f03194670f00a96e169aa9a900d.png', 't_f66550fd7a755ebcac6a00eb98175a3a.jpg', 1, '2026-01-10 15:58:03'),
(14, 7, '3b2b3740e8b50b14e79973b61ced95b4.png', 't_7230b05989177fcee23238dda30aef64.jpg', 2, '2026-01-10 15:58:04'),
(15, 7, '8ccb4ea8ba10b60660ede91b8cab51ee.png', 't_d270ece9afcf3bd6e2f6f4ea410b3d3c.jpg', 3, '2026-01-10 15:58:05'),
(16, 7, '6409a7b479a103a587a8bea23e5db9ad.png', 't_5a1ede4ab0c29c61990400b4086d5e5d.jpg', 4, '2026-01-10 15:58:06');

-- --------------------------------------------------------

--
-- テーブルの構造 `route_points`
--

CREATE TABLE `route_points` (
  `id` bigint UNSIGNED NOT NULL,
  `route_id` bigint UNSIGNED NOT NULL,
  `point_type` enum('start','middle','goal') NOT NULL,
  `label` varchar(100) NOT NULL,
  `url` text,
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- テーブルのデータのダンプ `route_points`
--

INSERT INTO `route_points` (`id`, `route_id`, `point_type`, `label`, `url`, `sort_order`) VALUES
(4, 4, 'start', 'ｓｓ', NULL, 0),
(5, 4, 'middle', 'ｓｆ', NULL, 1),
(6, 4, 'goal', 'せｆ', NULL, 999),
(7, 5, 'start', '自宅', NULL, 0),
(8, 5, 'middle', 'コンビニ', NULL, 1),
(9, 5, 'middle', 'じｂｂ￥￥', NULL, 2),
(10, 5, 'middle', 'konnbini', NULL, 3),
(11, 5, 'goal', '淡路島', NULL, 999),
(12, 6, 'start', '自宅', NULL, 0),
(13, 6, 'middle', '休憩所', NULL, 1),
(14, 6, 'goal', '学校近くの駐車場', NULL, 999),
(15, 7, 'start', '自宅', '〒600-8357 京都府京都市下京区柿本町５９６', 0),
(16, 7, 'middle', 'コンビニ', '〒600-8357 京都府京都市下京区柿本町５９６', 1),
(17, 7, 'middle', 'コンビニ2', '〒600-8357 京都府京都市下京区柿本町５９６', 2),
(18, 7, 'goal', 'TECH', '〒600-8357 京都府京都市下京区柿本町５９６', 999);

-- --------------------------------------------------------

--
-- テーブルの構造 `route_prefectures`
--

CREATE TABLE `route_prefectures` (
  `id` bigint UNSIGNED NOT NULL,
  `route_id` bigint UNSIGNED NOT NULL,
  `prefecture_code` tinyint UNSIGNED NOT NULL,
  `is_main` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- テーブルのデータのダンプ `route_prefectures`
--

INSERT INTO `route_prefectures` (`id`, `route_id`, `prefecture_code`, `is_main`) VALUES
(5, 4, 5, 1),
(6, 4, 25, 0),
(7, 4, 26, 0),
(8, 4, 27, 0),
(9, 5, 48, 1),
(10, 5, 2, 0),
(11, 5, 3, 0),
(12, 5, 4, 0),
(13, 5, 6, 0),
(14, 6, 26, 1),
(15, 7, 26, 1),
(16, 7, 24, 0),
(17, 7, 25, 0),
(18, 7, 27, 0),
(19, 7, 28, 0);

-- --------------------------------------------------------

--
-- テーブルの構造 `users`
--

CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(50) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- テーブルのデータのダンプ `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `created_at`, `updated_at`) VALUES
(1, 'test_user', 'test@user.com', '$2y$10$ZRpA8eYGgpj7wpOHwsXjP.nBViWUA0R2N/VEKR0V4TXdsh8gjfebi', '2025-12-19 11:27:16', '2025-12-19 11:27:16'),
(2, 'test_user2', 'test@user2.com', '$2y$10$YRtM174iDLjZ.IFc8PR4jORdy/3MNf/NihORYlsfgT0lDHU/9da6C', '2025-12-19 13:15:20', '2025-12-19 13:15:20'),
(3, 'admin', 'admin@admin.com', '$2y$10$274y4pYm6/xsnMLBTM.HKunHLQEd0mH5iYW9Wse197.wK5ikk6d7W', '2026-01-10 13:58:19', '2026-01-10 13:58:19');

--
-- ダンプしたテーブルのインデックス
--

--
-- テーブルのインデックス `routes`
--
ALTER TABLE `routes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_routes_user` (`user_id`),
  ADD KEY `idx_routes_pref` (`prefecture_code`),
  ADD KEY `idx_routes_created` (`created_at`);

--
-- テーブルのインデックス `route_likes`
--
ALTER TABLE `route_likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_route_user` (`route_id`,`user_id`),
  ADD KEY `idx_route` (`route_id`),
  ADD KEY `fk_likes_user` (`user_id`);

--
-- テーブルのインデックス `route_photos`
--
ALTER TABLE `route_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_route_photos_route` (`route_id`);

--
-- テーブルのインデックス `route_points`
--
ALTER TABLE `route_points`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_points_route` (`route_id`);

--
-- テーブルのインデックス `route_prefectures`
--
ALTER TABLE `route_prefectures`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rp_route` (`route_id`),
  ADD KEY `idx_rp_pref` (`prefecture_code`);

--
-- テーブルのインデックス `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- ダンプしたテーブルの AUTO_INCREMENT
--

--
-- テーブルの AUTO_INCREMENT `routes`
--
ALTER TABLE `routes`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- テーブルの AUTO_INCREMENT `route_likes`
--
ALTER TABLE `route_likes`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- テーブルの AUTO_INCREMENT `route_photos`
--
ALTER TABLE `route_photos`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- テーブルの AUTO_INCREMENT `route_points`
--
ALTER TABLE `route_points`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- テーブルの AUTO_INCREMENT `route_prefectures`
--
ALTER TABLE `route_prefectures`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- テーブルの AUTO_INCREMENT `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- ダンプしたテーブルの制約
--

--
-- テーブルの制約 `routes`
--
ALTER TABLE `routes`
  ADD CONSTRAINT `fk_routes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- テーブルの制約 `route_likes`
--
ALTER TABLE `route_likes`
  ADD CONSTRAINT `fk_likes_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_likes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- テーブルの制約 `route_photos`
--
ALTER TABLE `route_photos`
  ADD CONSTRAINT `fk_route_photos_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`) ON DELETE CASCADE;

--
-- テーブルの制約 `route_points`
--
ALTER TABLE `route_points`
  ADD CONSTRAINT `fk_route_points_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`) ON DELETE CASCADE;

--
-- テーブルの制約 `route_prefectures`
--
ALTER TABLE `route_prefectures`
  ADD CONSTRAINT `fk_route_prefectures_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
