-- Позначка властивості «Індексувати в парі з брендом»: сторінки
-- /catalog/x/brand-y/feature-value стають посадковими, якщо пройшли поріг товарів
-- (BrandFeatureLandingsHelper). Типово 0 — поведінка сайту не змінюється.

ALTER TABLE `__features` ADD COLUMN IF NOT EXISTS `index_with_brand` TINYINT(1) NOT NULL DEFAULT 0 AFTER `to_index_new_value`;
