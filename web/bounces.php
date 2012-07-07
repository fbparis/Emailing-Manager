<?php
include_once '/REPERTOIRE_DU_SCRIPT/include/config.php';

$cron_log_file = '/REPERTOIRE_DU_SCRIPT/logs/cron.php.' . date('Ym') . '.log';
$errors_log_file = '/REPERTOIRE_DU_SCRIPT/logs/Marketing.log';

$api = new Mailjet(MARKETING_MAILJET_API_KEY,MARKETING_MAILJET_API_SECRET);
$api->debug = 0;
$api->output = 'json';
@mb_internal_encoding('UTF-8');

$max_age = 3 * 24 * 3600;
$ts_from = time() - $max_age;
$bounces = array();
$start = 0;
$limit = 1000;

while ($response = $api->reportEmailBounce(array('start'=>$start,'limit'=>$limit))) {
	if (!@count($response->bounces)) break;
	foreach ($response->bounces as $k=>$v) if ($v->date_ts >= $ts_from) $bounces[] = $v;
	if (count($response->bounces) < $limit) break;
	$start += $limit;
}

usort($bounces,create_function('$a,$b','if ($a->date_ts == $b->date_ts) return 0; return $a->date_ts < $b->date_ts ? 1 : -1;'));

$filter = array('date_ts'=>create_function('$x','return date("d/m/Y@H:i:s",$x);'));

$cron_log = shell_exec("wc -l $cron_log_file") <= 100 ? file_get_contents($cron_log_file) : strstr(shell_exec("tail -n100 $cron_log_file"),"\n\n");
$errors_log = shell_exec("tail -n10 $errors_log_file");
$errors_log = preg_replace('#@[\w.-]+#s','@...',$errors_log);
$errors_log = preg_replace('#[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+#s','@...',$errors_log);

?>
<!DOCTYPE html>
<html lang="fr">
	<head>
		<meta charset="utf-8">
		<title>Last bounces</title>
		<style>
			body {font-family:sans-serif;}
			table { width:99%;margin: auto;border:1px solid black;}
			th {background-color:orange;color:white;}
			tr.even {background-color:white;}
			tr.odd {background-color:#efefef;}
			footer {text-align:center;font-size:small;color:#aaa;font-style:italic;margin-top:1ex;}
			h2 {border-bottom:1px solid black;}
			pre {white-space:pre-wrap;}
		</style>
	</head>
	<body>
		<h2>Last bounces</h2>
		<?php if (!count($bounces)):?>
		<p>No bounces ! :-)</p>
		<?php else:?>
		<table>
			<?php foreach ($bounces as $i=>$bounce): if ($i == 0):?>
			<thead>
				<tr>
					<?php foreach ($bounce as $k=>$v):?>
					<th><?php echo $k; ?></th>
					<?php endforeach;?>
				</tr>
			</thead>
			<tbody>
			<?php endif;?>
				<tr class="<?php echo $i % 2 ? 'even' : 'odd'; ?>" style="color:rgb(<?php echo round(255*pow($bounce->date_ts-$ts_from,1.5)/pow($max_age,1.5));?>,0,0)">
					<?php foreach ($bounce as $k=>$v):?>
					<td><?php echo array_key_exists($k,$filter) ? $filter[$k]($v) :  $v; ?></td>
					<?php endforeach;?>
				</tr>
			<?php endforeach;?>
			</tbody>
		</table>
		<?php endif; ?>
		<h2>Last cron log</h2>
		<pre><?php echo $cron_log; ?></pre>
		<h2>Last errors log</h2>
		<pre><?php echo $errors_log; ?></pre>
		<footer>Generated on <?php echo date('d/m/Y@H:i:s'); ?></footer>
	</body>
</html>