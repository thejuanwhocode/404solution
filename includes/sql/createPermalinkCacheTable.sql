
CREATE TABLE `{wp_abj404_permalink_cache}` (
    `id` bigint(20) NOT NULL COMMENT 'corresponds to the wp_posts.id column',
    `url` varchar(2048) NOT NULL COMMENT 'a sometimes updated column that holds URLs for pages',
    `structure` varchar(256) NOT NULL COMMENT 'e.g. /%postname%/ or /%year%/%monthnum%/%postname%/',
PRIMARY KEY (`id`),
index (`structure`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='404 Solution Plugin Permalinks Cache Table'
