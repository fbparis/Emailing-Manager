-- 
-- Structure for table `clients`
-- 

CREATE TABLE `clients` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `site` varchar(50) NOT NULL,
  `cat` varchar(50) NOT NULL,
  `subcat` varchar(50) NOT NULL,
  `email` varchar(150) NOT NULL,
  `prenom` varchar(100) DEFAULT NULL,
  `status` int(10) NOT NULL DEFAULT '1',
  `emails_sent` int(10) unsigned NOT NULL DEFAULT '0',
  `data` blob,
  `date_created` int(14) unsigned NOT NULL,
  `date_lastemail` int(14) unsigned DEFAULT NULL,
  `date_lastnewsletter` int(14) unsigned NOT NULL,
  `previous_status` int(10) DEFAULT NULL,
  `bounce_info` blob,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_cat_subcat_email` (`site`,`cat`,`subcat`,`email`),
  KEY `status` (`status`),
  KEY `emails_sent` (`emails_sent`),
  KEY `date_created` (`date_created`),
  KEY `date_lastemail` (`date_lastemail`),
  KEY `date_lastnewsletter` (`date_lastnewsletter`)
) ENGINE=InnoDB AUTO_INCREMENT=6043 DEFAULT CHARSET=utf8;
