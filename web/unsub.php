<?php
$unsub_version = 1.1;

header('X-Robots-Tag: noindex,noarchive,nofollow',true);
header('Content-Type: text/html; charset=utf-8',true);

include_once '/REPERTOIRE_DU_SCRIPT/api.php'; // à modifier
$admin_email = 'your@email.com'; // à modifier

$id = intval(@$_GET['id']);
$chk = $_GET['chk'];

if (!$client = Marketing::getClient("id=$id",true,"unsub-$id")) die("Aucun email trouvé pour l'ID $id.\n");
if ($chk != md5($client->id . MARKETING_UNSUB_SECRET . $client->email)) die("Le lien de désabonnement est invalide.\n");

$confirm_unsub_link = sprintf(MARKETING_UNSUB_LINK_MASK,$id,$chk) . '&confirm=1';
$prenom = mb_convert_case($client->prenom, MB_CASE_TITLE, 'UTF-8');

if ($_GET['confirm'] == 1) {
	if (false === Marketing::unsubClient($client,array('unsub_version'=>$unsub_version,'unsub_ip'=>$_SERVER['REMOTE_ADDR'],'unsub_date'=>$_SERVER['REQUEST_TIME']))) die("Erreur lors du désabonnement, vous pouvez m'envoyer un message en <a href='mailto:<?php echo $admin_email; ?>?subject=UNSUB'>cliquant ici</a> pour que je vous désabonne manuellement.");
	else die("L'adresse $client->email a bien été supprimée de notre liste de diffusion.");
}
?>
<!DOCTYPE html>
<html lang="fr">
	<head>
		<meta charset="utf-8">
		<title>Désabonnement</title>
	</head>
	<body>	
		<div class="container">
			<h1>Nous sommes désolés de vous voir partir <?php echo $prenom; ?></h1>
			<blockquote>
				<p>Il vous est encore possible de renoncer à vous désabonner de notre newsletter, nous avons encore tant de choses à vous offrir...</p>
				<p>Si malgré tout vous préférez vous désabonner, c'est votre choix et nous le respectons.</p>
				<p>Avant de nous séparer, laissez nous juste vous présenter toutes nos excuses si nous n'avons pas su vous aider comme nous l'aurions du. Nous vous souhaitons de tout notre coeur un avenir plein de bonheur et de réussite <?php echo $prenom; ?>.</p>
				<small>L'équipe de SPAM Corp.</small>
			</blockquote>
			<p align="center"><a class="btn" href="<?php echo $confirm_unsub_link; ?>">Je confirme ma désinscription de votre newsletter</a></p>
		</div>
	</body>
</html>