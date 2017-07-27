CREATE TABLE IF NOT EXISTS `#__sfw_networks` (
  `network` int(11) unsigned NOT NULL,
  `mask` int(11) unsigned NOT NULL,
  KEY `network` (`network`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
CREATE TABLE IF NOT EXISTS `#__ct_apikey_status` (
	`id` int(11) unsigned NOT NULL auto_increment,
	`ct_status` varchar(1000) default NULL,
	`ct_changed` int(11) NOT NULL default '0',
	PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;
CREATE TABLE IF NOT EXISTS `#__ct_curr_server` (
	`id` int(11) unsigned NOT NULL auto_increment,
	`ct_work_url` varchar(100) default NULL,
	`ct_server_ttl` int(11) NOT NULL default '0',
	`ct_server_changed` int(11) NOT NULL default '0',
PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;