<?php
include_once '/REPERTOIRE_DU_SCRIPT/include/config.php';

$api = new Mailjet(MARKETING_MAILJET_API_KEY,MARKETING_MAILJET_API_SECRET);
$api->debug = 0;
$api->output = 'json';
@mb_internal_encoding('UTF-8');

$ts_from = time() - 3 * 24 * 3600; // 3 derniers jours
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
		</style>
	</head>
	<body>
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
				<tr class="<?php echo $i % 2 ? 'even' : 'odd'; ?>">
					<?php foreach ($bounce as $k=>$v):?>
					<td><?php echo array_key_exists($k,$filter) ? $filter[$k]($v) :  $v; ?></td>
					<?php endforeach;?>
				</tr>
			<?php endforeach;?>
			</tbody>
		</table>
		<?php endif; ?>
		<footer><?php echo date('d/m/Y@H:i:s'); ?></footer>
	</body>
</html>