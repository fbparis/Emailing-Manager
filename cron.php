<?php

ob_start();

$sendingHours = range(8,19); //array('8','10','13','15','19');

include_once dirname(__FILE__) . '/api.php';

$NOW = time();
$YESTERDAY = $NOW - 24 * 3600;
$hour = date('G',$NOW);
$today = date('Ymd',$NOW);
$dataFile = sprintf('%s.data.inc',__FILE__);
$logFile = dirname(__FILE__) . sprintf('/logs/%s.%s.log',basename(__FILE__),date('Ym'));
$templates_dir = dirname(__FILE__) . '/templates';

$timer = microtime(true);

$overdue = (object) array('marketing'=>0,'notification'=>0);

function debug($msg) {
	global $timer;
	printf("%010.5f: %s\n",microtime(true)-$timer,$msg);
}

function num_rows($query) {
	if ($q = Marketing::sqlQuery(sprintf("SELECT COUNT(id) FROM %s WHERE %s",MARKETING_DB_TABLE,$query))) {
		$result = mysql_fetch_array($q);
		return $result[0];
	}
	return 0;
}

printf("%s\n",date('d/m/Y @ H:i:s',$NOW));

debug('--- Infos diverses');
debug(' Internal Encoding: ' . mb_internal_encoding());
$cron = @unserialize(file_get_contents($dataFile));
if (!$cron) {
	$cron = (object) array('today'=>$today,'daily_emails_sent'=>(object) array('marketing'=>0,'notification'=>0,'total'=>0),'bounces_cnt'=>0,'daily_stats'=>array(),'monthly_stats'=>array());
	debug(' Pas de fichier de conf trouvé');
} else {
	debug(' Fichier de conf chargé');
}
if (@$cron->today != $today) {
	$previously_emails_sent = $cron->daily_emails_sent;
	$cron->daily_emails_sent = (object) array('marketing'=>0,'notification'=>0,'total'=>0);
}
debug(sprintf(" %d emails déjà envoyés aujourd'hui (dont %d marketing et %d notifications)",$cron->daily_emails_sent->total,$cron->daily_emails_sent->marketing,$cron->daily_emails_sent->notification));

debug('--- Récupération des quotas');

$ts_from = mktime(0,0,0);

if (!$report = Marketing::apiQuery('reportEmailstatistics',array(array('ts_from'=>$ts_from)),false)) {
	debug(' Erreur lors de la récupération du rapport');
} else {
	debug(sprintf(' %d emails envoyés depuis le %s',$report->stats->total,date('d-m-Y H:i:s',$ts_from)));
	$cron->daily_emails_sent->total = max($cron->daily_emails_sent->total,$report->stats->total);
}

$sendingLimit = !in_array($hour,$sendingHours) ? 0 : floor( max( 0,DAILY_LIMIT - $cron->daily_emails_sent->total ) / ( 1 + array_search($hour, array_reverse($sendingHours))));

debug(" Limite d'envois pour la session : $sendingLimit");

debug('--- Gestion des Bounces');

$start = $cnt = 0;
$limit = 100;

while ($bounces = Marketing::apiQuery('reportEmailBounce',array(array('limit'=>$limit,'start'=>$start)),false)) {
	if (!@$bounces->cnt || ($bounces->cnt == $cron->bounces_cnt)) break;
	if ($bounces->cnt < $cron->bounces_cnt) {
		debug(" $bounces->cnt bounces contre $cron->bounces_cnt précédemment, remise à zéro");
		$cron->bounces_cnt = 0;
	}
	if (!$cnt) {
		$cnt = $bounces->cnt;
		debug(sprintf(' %d contacts à bouncer',$cnt - $cron->bounces_cnt));
	}
	foreach ($bounces->bounces as $i=>$bounce) {
		if (($cnt + $i) < $bounces->cnt) continue;
		$start++;
		if ($bounce->hard_bounce && $bounce->blocked) {
			$q = array(sprintf('email="%s"',$bounce->email));
			list($site,$cat,$subcat) = split('_',$bounce->customcampaign);
			if ($site && $cat && $subcat) $q[] = sprintf('site="%s" AND cat="%s" AND subcat="%s"',$site,$cat,$subcat);
			$q = implode(' AND ',$q);
			while ($client = Marketing::getClient($q)) {
				if (false === Marketing::bounceClient($client,$bounce)) debug("  Erreur en bouncant $client->email");
			}
			
		}
		if (($start + $cron->bounces_cnt) >= $bounces->cnt) break(2);
	}
	$cnt = $bounces->cnt;
}

$cron->bounces_cnt += $start;

debug(" $start nouveaux bounces ($cron->bounces_cnt au total)");

debug('--- Synchronisation des nouveaux clients avec Mailjet');
$n_total = $n_done = 0;

while ($client = Marketing::getClient(sprintf('status IN ("%s","%s")',Marketing::STATUS_NEW,Marketing::STATUS_VALID))) {
	$n_total++;
	$email = $client->email;
	if (Marketing::recordClient($client) === false) {
		if ($client === null) debug(" Client $email supprimé");
		else debug(" Erreur en synchronisant le client $client->email");
	} else {
		$n_done++;
		// Envoi du premier email si pas déjà fait
		if ($client->emails_sent == 0) {
			// Sauf si la limite d'envoi de la session est déjà dépassée
			if ($sendingLimit > 0) {
				$ret = Marketing::mailClient($client);
				if ($ret === false) {
					debug(" Erreur lors de l'envoi de l'email de bienvenue à $client->email");
				} elseif ($ret) {
					$cron->daily_emails_sent->notification++;
					$cron->daily_emails_sent->total++;
					$sendingLimit--;
				}
			}
		} else {
			if ($hour == 0) {
				$previously_emails_sent->notification++;				
				$previously_emails_sent->total++;				
			} else {
				$cron->daily_emails_sent->notification++;
				$cron->daily_emails_sent->total++;
				$sendingLimit--;
			}
		}
	}
}

debug(" $n_done clients synchronisés sur $n_total");

debug('--- Suppression des Unsub Mailjet et synchronisation avec la base de données');

if (!count(Marketing::$api_lists) && (Marketing::getApiLists() === false)) {
	debug(' Erreur lors de la récupération des listes de contact Mailjet');
} else {
	foreach (Marketing::$api_lists as $label=>$id) {
		debug(" Analyse de la liste $label...");
		list($site,$cat,$subcat) = split('_',$label,3);
		$lastTotal = 0;
		while ($contacts = Marketing::apiQuery('listsContacts',array(array('id'=>$id,'status'=>'unsub')))) {
			if (!$contacts->total_cnt) break;
			if ($contacts->total_cnt == $lastTotal) {
				debug('  Possible boucle infinie détectée, abandon...');
				break;
			}
			$lastTotal = $contacts->total_cnt;
			debug("  $lastTotal contacts à supprimer");
			$removeEmails = array();
			foreach ($contacts->result as $contact) {
				$removeEmails[$contact->email] = $contact->email; 
			}
			if (Marketing::sqlQuery(sprintf('UPDATE %s SET previous_status=status,status="%s" WHERE site="%s" AND cat="%s" AND subcat="%s" AND email in ("%s")',MARKETING_DB_TABLE,Marketing::STATUS_UNSUB,$site,$cat,$subcat,implode('","',array_keys($removeEmails))),false) === false) {
				debug('  Impossible de mettre à jour la base de données, abandon...');
				break;
			}
			if (Marketing::apiQuery('listsRemovemanycontacts',array(array('method'=>'POST','contacts'=>implode(',',array_keys($removeEmails)),'id'=>$id)),false) === false) {
				debug('  Erreur lors de la suppression des contacts via Mailjet, abandon...');
				break;
			}			
		}
		if ($contacts === false) debug("  Erreur lors de la récupération des contacts");
	}
}

if (($hour == 23) || ($sendingLimit > 0)) {
	$marketing_templates = $notification_templates = array();
	$todo = glob($templates_dir . '/marketing/*_*_*.html');
	foreach ($todo as $file) {
		list($site,$cat,$subcat) = preg_match('#^([^_]+_[^_]+_.+)\.html$#si',basename($file),$m) ? split('_',$m[1]) : null;
		if (!$site || !$cat || !$subcat) continue;
		$marketing_templates[] = (object) array('site'=>$site,'cat'=>$cat,'subcat'=>$subcat,'lastmod'=>filectime($file));
	}
	shuffle($marketing_templates);
	$todo = glob($templates_dir . '/notification/*_*_*_*.html');
	foreach ($todo as $file) {
		list($site,$cat,$subcat,$n) = preg_match('#^([^_]+_[^_]+_[^_]+_[0-9]+)\.html$#si',basename($file),$m) ? split('_',$m[1]) : null;
		if (!$site || !$cat || !$subcat || !is_numeric($n)) continue;
		if (!array_key_exists("$site $cat $subcat",$todo)) $notification_templates["$site $cat $subcat"] = (object) array('site'=>$site,'cat'=>$cat,'subcat'=>$subcat,'last'=>$n);
		else $notification_templates["$site $cat $subcat"]->last = max($n,$notification_templates["$site $cat $subcat"]->last);
	}
	shuffle($notification_templates);
}

if (count($marketing_templates) && ($sendingLimit > 0)) {
	debug('--- Envois de newsletters');
	foreach ($marketing_templates as $m) {
		$n = 0;
		while ($client = Marketing::getClient("site='$m->site' AND cat='$m->cat' AND subcat='$m->subcat' AND status=" . Marketing::STATUS_LISTED . " AND date_lastnewsletter<$m->lastmod ORDER BY RAND()")) {
			if (Marketing::mailClient($client,true)) {
				$cron->daily_emails_sent->marketing++;
				$cron->daily_emails_sent->total++;				
				$sendingLimit--;
				$n++;
				if ($sendingLimit <= 0) break;
			}
		}		
		if ($n) {
			debug(" $n emails envoyés pour la newsletter $m->site-$m->cat-$m->subcat");
		}
		if ($sendingLimit <= 0) break;
	}
}

if (count($notification_templates) && ($sendingLimit > 0)) {
	debug('--- Envois de notifications');
	foreach ($notification_templates as $m) {
		$n = 0;
		while ($client = Marketing::getClient("site='$m->site' AND cat='$m->cat' AND subcat='$m->subcat' AND status=" . Marketing::STATUS_LISTED . " AND emails_sent<=$m->last AND date_lastemail<" . ($NOW - MARKETING_MAILING_INTERVAL) . " ORDER BY emails_sent ASC,RAND()")) {
			if (Marketing::mailClient($client,false)) {
				$cron->daily_emails_sent->notification++;
				$cron->daily_emails_sent->total++;				
				$sendingLimit--;
				$n++;
				if ($sendingLimit <= 0) break;
			}
		}
		if ($n) {
			debug(" $n notifications envoyées pour la campagne $m->site-$m->cat-$m->subcat");
		}
		if ($sendingLimit <= 0) break;
	}
}

if (@$cron->today != $today) {
	debug('--- Bilan quotidien');
	$cron->daily_stats[date('j',$YESTERDAY)]->sent = $previously_emails_sent;
	for ($i = min(2,date('j',$YESTERDAY) - 1); $i >= 0; $i--) {
		$ts = $YESTERDAY - $i * 24 * 3600;
		debug(sprintf(' %s : %d emails envoyés (%d marketing, %d notifications), %d emails en attente (%d marketing, %d notifications)',date('d/m/Y',$ts),$cron->daily_stats[date('j',$ts)]->sent->total,$cron->daily_stats[date('j',$ts)]->sent->marketing,$cron->daily_stats[date('j',$ts)]->sent->notification,$cron->daily_stats[date('j',$ts)]->overdue->marketing+$cron->daily_stats[date('j',$ts)]->overdue->notification,$cron->daily_stats[date('j',$ts)]->overdue->marketing,$cron->daily_stats[date('j',$ts)]->overdue->notification));
	}

	if (date('j',$NOW) == 1) {
		debug('--- Bilan mensuel');
		$lastDay = array_pop($cron->daily_stats);
		$cron->monthly_stats[date('M',$YESTERDAY)] = (object) array(
			'sent'=>(object) array(
				'marketing'=>$lastDay->sent->marketing + array_reduce($cron->daily_stats,create_function('$T,$x','return $T + $x->sent->marketing;'),0),
				'notification'=>$lastDay->sent->notification + array_reduce($cron->daily_stats,create_function('$T,$x','return $T + $x->sent->notification;'),0),
				'total'=>$lastDay->sent->total + array_reduce($cron->daily_stats,create_function('$T,$x','return $T + $x->sent->total;'),0)
			),
			'overdue'=>$lastDay->overdue
		);
		$cron->daily_stats = array();
		for ($i = 2; $i >= 0; $i--) {
			$ts = mktime(12,0,0,date('n',$YESTERDAY) - $i,1);
			if (array_key_exists(date('M',$ts),$cron->monthly_stats)) debug(sprintf(' %s : %d emails envoyés (%d marketing, %d notifications), %d emails en attente (%d marketing, %d notifications)',date('M',$ts),$cron->monthly_stats[date('M',$ts)]->sent->total,$cron->monthly_stats[date('M',$ts)]->sent->marketing,$cron->monthly_stats[date('M',$ts)]->sent->notification,$cron->monthly_stats[date('M',$ts)]->overdue->marketing+$cron->monthly_stats[date('M',$ts)]->overdue->notification,$cron->monthly_stats[date('M',$ts)]->overdue->marketing,$cron->monthly_stats[date('M',$ts)]->overdue->notification));
		}
	}
}

if ($hour == 23) {
	debug("--- Nombre d'envois en attente en fin de journée");
	foreach ($marketing_templates as $m) {
		$overdue->marketing += num_rows("site='$m->site' AND cat='$m->cat' AND subcat='$m->subcat' AND status=" . Marketing::STATUS_LISTED . " AND date_lastnewsletter<$m->lastmod");
	}
	foreach($notification_templates as $m) {
		$overdue->notification += num_rows("site='$m->site' AND cat='$m->cat' AND subcat='$m->subcat' AND status=" . Marketing::STATUS_LISTED . " AND emails_sent<=$m->last AND date_lastemail<" . ($NOW - MARKETING_MAILING_INTERVAL));
	}
	$cron->daily_stats[date('j',$NOW)] = (object) array('sent'=>null,'overdue'=>$overdue);
	debug(" $overdue->marketing newsletters et $overdue->notification notifications en attente d'envoi");
}

debug('--- Sauvegarde des paramètres et des stats');

$cron->today = $today;

if (!@file_put_contents($dataFile,serialize($cron))) debug(' Erreur en sauvegardant les paramètres');

debug('--- Terminé !');
echo "\n";

file_put_contents($logFile,ob_get_contents(),FILE_APPEND);
?>
						