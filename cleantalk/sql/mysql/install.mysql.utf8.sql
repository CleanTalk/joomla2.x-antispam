CREATE TABLE `#__sfw_networks` (
  `network` int(11) unsigned NOT NULL,
  `mask` int(11) unsigned NOT NULL,
  KEY `network` (`network`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
