<?php

$template = @$argv[1];

if (!file_exists($template)) {
	die("Template introuvable\n");
}

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

$clients = array(
	'email1'=>'prenom1',
	'email2'=>'prenom2' // etc
);

define('MARKETING_SMTP_HOST','in.mailjet.com');
define('MARKETING_SMTP_PORT',25);
define('MARKETING_SMTP_USER','');
define('MARKETING_SMTP_PASSWORD','');

$weekDay = array('dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi');

include_once dirname(__FILE__) . '/include/class.phpmailer.php';

$n = 0;

foreach ($clients as $email=>$prenom) {
	$prenom = mb_convert_case($prenom,MB_CASE_TITLE,'UTF-8');
	$body = file_get_contents($template);
	$body = str_replace('[[PRENOM]]',$prenom,$body);
	$body = str_replace('[[EMAIL]]',$email,$body);
	$body = str_replace('[[BONJOUR]]',(date('G') <= 4) || (date('G') >= 18) ? 'Bonsoir' : 'Bonjour',$body);
	$body = str_replace('[[TRACKER]]','test_' . basename($template),$body);
	$body = str_replace('[[JOUR]]',$weekDay[date('w')],$body);
	$body = preg_replace_callback('#\[\[([0-9]+)-([0-9]+)\]\]#s',create_function('$m','return mt_rand($m[1],$m[2]);'),$body);
	if (preg_match('#<title>(.*?)</title>#si',$body,$m)) $subject = $m[1]; else continue;
	if (preg_match('#<meta name="author" content="([^" ]+) ([^"]+)"#si',$body,$m)) {
		$senderEmail = $m[1];
		$senderName = $m[2];
	} else continue;
	$body = preg_replace('#<!--.*?-->#s','',$body);
	if (!$body) continue; 
	
	printf("From %s (%s) to %s (%s) => %s\n",$senderEmail,$senderName,$email,$prenom,$subject);

	$mail = new PHPMailer(true); 
	$mail->IsSMTP(); 
	$mail->SMTPDebug = false;
	
	try {
		$mail->Host       = MARKETING_SMTP_HOST; 
		$mail->SMTPAuth   = true;                
		$mail->Port       = MARKETING_SMTP_PORT; 
		$mail->Username   = MARKETING_SMTP_USER; 
		$mail->Password   = MARKETING_SMTP_PASSWORD;
		$mail->AddAddress($email,$prenom);
		$mail->SetFrom($senderEmail,$senderName);
		$mail->Subject = $subject;
		if (strip_tags($body) == $body) {
			$mail->Body = $body;
			$mail->WordWrap = 70;
		} else {
			$mail->MsgHTML($body);
		} 
		$mail->addCustomHeader("X-Mailjet-Campaign: test_" . basename($template));
		$mail->CharSet = 'UTF-8';
		$mail->Send();
	} catch (phpmailerException $e) {
		printf("%s\n",$e->errorMessage());
		continue;
	} catch (Exception $e) {
		printf("%s\n",$e->getMessage());
		continue;
	}
	$n++;
}

echo "$n emails envoyés\n";
?>
						