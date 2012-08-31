<?php
date_default_timezone_set('Europe/Paris');

define('MARKETING_DB_HOST','localhost');
define('MARKETING_DB_DATABASE','marketing');
define('MARKETING_DB_TABLE','clients');
define('MARKETING_DB_USER','marketing');
define('MARKETING_DB_PASSWORD','');
define('MARKETING_SMTP_HOST','in.mailjet.com');
define('MARKETING_SMTP_PORT',25);
define('MARKETING_SMTP_USER','');
define('MARKETING_SMTP_PASSWORD','');
define('MARKETING_MAILJET_API_KEY','');
define('MARKETING_MAILJET_API_SECRET','');
define('MARKETING_MAILING_INTERVAL',28800); // 8h
define('MARKETING_UNSUB_LINK_MASK','http://example.com/unsub.php?id=%s&chk=%s'); // Ajustez selon votre domaine, répertoire
define('MARKETING_UNSUB_SECRET',''); // Un mot de passe pour la somme de contrôle des désinscriptions
define('DAILY_LIMIT',200); // Valeur par défaut pour un compte gratuite Mailjet

require_once dirname(__FILE__) . '/class.phpmailer.php';
require_once dirname(__FILE__) . '/php-mailjet.class-mailjet-0.1.php';

?>
						