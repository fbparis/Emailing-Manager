<?php
require_once dirname(__FILE__) . '/include/config.php';

class Marketing {
	const STATUS_BOUNCED = -1;
	const STATUS_UNSUB = 0;
	const STATUS_NEW = 1;
	const STATUS_VALID = 2;
	const STATUS_LISTED = 3;
	
	const NOTIFICATION_CAMPAIGN_ID_MASK = '%s_%s_%s_%d'; // site_cat_subcat_i
	const MARKETING_CAMPAIGN_ID_MASK = '%s_%d-%d-%d'; // LIST_LABEL_Y-m-d
	const LIST_LABEL_MASK = '%s_%s_%s'; // site_cat_subcat
	
	protected static $db = null;
	protected static $currentFilter = array();
	protected static $currentSelect = array();
	protected static $api = null;
	protected static $logfile = null;
	protected static $sqlfile = null;	
	protected static $apifile = null;
	protected static $notification_tplmask = null;
	protected static $marketing_tplmask = null;
	
	protected static $weekDay = array('dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi');
	
	public static $blocked_hosts = array(
		'justice.fr',
		'police.fr',
		'service-public.fr',
		'gouv.fr',
		'yopmail.com',
		'yopmail.fr',
		'jetable.org',
		'rtrtr.com'
	);
	
	public static $known_hosts = array(
		'gmail.com',
		'hotmail.fr',
		'hotmail.com',
		'laposte.net',
		'neuf.fr',
		'live.fr',
		'live.com',
		'orange.fr',
		'free.fr',
		'sfr.fr',
		'msn.com',
		'aol.com',
		'cegetel.net',
		'wanadoo.fr',
		'voila.fr',
		'caramail.com',
		'caramail.fr',
		'yahoo.fr',
		'yahoo.com',
		'bbox.fr',
		'videotron.ca',
		'gmx.fr',
		'aliceadsl.fr',
		'libertysurf.fr',
		'numericable.fr'
	);
	
	public static $max_distance = 3;
	public static $user_regex = '#^\w[-_.\w]*$#s';
	public static $host_regex = '#^\w[-.\w]*\.[a-z]{2,}$#s';
	
	public static $mailing_interval = MARKETING_MAILING_INTERVAL; 
		
	public static $api_lists = array(); // label => id

	protected static $checked_hosts = array(); // host => true | false | new_host
	
	static function init() {
		self::$logfile = dirname(__FILE__) . '/logs/Marketing.log';
		self::$sqlfile = dirname(__FILE__) . '/logs/failedSQL.log';
		self::$apifile = dirname(__FILE__) . '/logs/failedAPI.log';
		self::$notification_tplmask = dirname(__FILE__) . '/templates/notification/%s.html'; // notification_campaign_id.html
		self::$marketing_tplmask = dirname(__FILE__) . '/templates/marketing/%s.html'; // LIST_LABEL.html
		if (!self::$db = @mysql_connect(MARKETING_DB_HOST,MARKETING_DB_USER,MARKETING_DB_PASSWORD)) {
			self::log(@mysql_error());
		} else {
			if (!@mysql_select_db(MARKETING_DB_DATABASE,self::$db)) {
				self::log(@mysql_error());
				@mysql_close(self::$db);
				self::$db = null;
			} 
		}
		self::$api = new Mailjet(MARKETING_MAILJET_API_KEY,MARKETING_MAILJET_API_SECRET);
		self::$api->debug = 0;
		self::$api->output = 'json';
		if (@mb_internal_encoding('UTF-8') === false) self::log('unable to set internal encoding to UTF-8');
		if (!@mysql_set_charset('utf8',self::$db)) self::log('unable to set mysql charset to utf8');
	}
	
	public static function getClient($filter='1',$new=false,$ref='default') {
		if (!$filter) $filter = '1';
		if (!$ref) $ref = 'none';
		if ($new || ($filter != @self::$currentFilter[$ref])) {
			if (!self::$currentSelect[$ref] = self::sqlQuery(sprintf('SELECT * FROM %s WHERE %s',MARKETING_DB_TABLE,$filter),false)) {
				unset(self::$currentSelect[$ref]);
				if (@self::$currentFilter[$ref]) unset(self::$currentFilter[$ref]);
				return false;
			}
			self::$currentFilter[$ref] = $filter;
		}
		$ret = @mysql_fetch_object(self::$currentSelect[$ref]);
		if ($ret === false) {
			@mysql_free_result(self::$currentSelect[$ref]);
			unset(self::$currentFilter[$ref]);
			unset(self::$currentSelect[$ref]);
		}
		if (@property_exists($ret,'data')) $ret->data = self::unserialize($ret->data);
		if (@property_exists($ret,'bounce_info')) $ret->bounce_info = self::unserialize($ret->bounce_info);
		return $ret;
	}

	public static function addClient($site,$cat,$subcat,$email,$prenom=null,$status=null,$data=null) {
		$q = array(
			'site'=>$site,
			'cat'=>$cat,
			'subcat'=>$subcat,
			'status'=>$status === null ? self::STATUS_NEW : $status,
			'date_created' => time(),
			'date_lastnewsletter'=>time(),
			'emails_sent'=>0
		);
		if ($q['status'] == self::STATUS_NEW) {
			$email = self::cleanEmail($email,false);
			if ($email === false) return false;
		}
		$q['email'] = $email;
		if ($prenom) $q['prenom'] = $prenom;
		if (is_array($data)) $q['data'] = self::serialize($data);
		$query = sprintf('INSERT INTO %s (%s) VALUES ("%s")',MARKETING_DB_TABLE,implode(',',array_keys($q)),implode('","',array_values($q)));
		if (self::sqlQuery($query,true) === false) {
			if ($client = self::getClient(sprintf('site="%s" and cat="%s" and subcat="%s" and email="%s" and status IN ("%s","%s")',$site,$cat,$subcat,$email,self::STATUS_UNSUB,self::STATUS_BOUNCED),false,'duplicate')) {
				$q = array('status'=>in_array(@$client->previous_status,array(self::STATUS_VALID,self::STATUS_LISTED)) ? self::STATUS_VALID : self::STATUS_NEW);
				if ($prenom) $q['prenom'] = $prenom;
				if (is_array($data)) {
					foreach ($data as $data_key => $data_value) self::setData($client,$data_key,$data_value);
					$q['data'] = self::serialize($client->data);
				}
				return self::updateClient($client,$q) === false ? false : $client;
			}
			return false;
		}
		$q['id'] = @mysql_insert_id(self::$db);
		return (object) $q;
	}
	
	public static function updateClient(&$client,$fields) {
		$q = array();
		if (array_key_exists('status',$fields) && ($fields['previous_status'] != $client->status)) $fields['previous_status'] = $client->status;
		foreach ($fields as $k=>$v) {
			if (($k == 'data') && is_array($v)) foreach ($v as $data_key => $data_value) self::setData($client,$data_key,$data_value);
			if (($k == 'bounce_info') && is_object($v)) $v = self::serialize($v);
			$q[] = $v === null ? "$k=NULL" : "$k=\"$v\"";
		}
		if (is_array(@$client->data)) $q[] = sprintf('data="%s"',self::serialize($client->data));
		
		$query = sprintf('UPDATE %s SET %s WHERE id="%s"',MARKETING_DB_TABLE,implode(',',$q),$client->id);
		if (self::sqlQuery($query,true) === false) return false;
		foreach ($fields as $k=>$v) $client->$k = $v;
		return @mysql_affected_rows(self::$db);
	}
	
	public static function unsubClient(&$client,$data=null) {
		if ($client->status == self::STATUS_UNSUB) return 0;
		if (false === self::unrecordClient($client)) return false;
		if (false === self::updateClient($client,array('status'=>self::STATUS_UNSUB,'data'=>$data))) return false;
		$ret = @mysql_affected_rows(self::$db);
		return $ret;
	}
	
	public static function bounceClient(&$client,$bounce_info=null) {
		if ($client->status == self::STATUS_BOUNCED) return 0;
		if (!is_object($bounce_info)) return false;
		if (@$bounce_info->hard_bounce) {
			$new_host = '';
			if (in_array(trim(@$bounce_info->error),array('user unknown','no mail host','invalid domain'))) {
				list($email_user,$email_host) = split('@',$client->email);
				if (!in_array($email_host,self::$known_hosts) && ($new_host = self::suggestHost($email_host))) {
					if (false === self::unrecordClient($client)) return false;
					if (false !== self::updateClient($client,array('email'=>"$email_user@$new_host",'status'=>self::STATUS_VALID,'emails_sent'=>0,'bounce_info'=>$bounce_info))) {
					return 1;
					}		
				} 
			}
			if (@$bounce_info->blocked) {
				if (!$new_host) if (false === self::unrecordClient($client)) return false;
				if (false === self::updateClient($client,array('status'=>self::STATUS_BOUNCED,'bounce_info'=>$bounce_info))) return false;
				return 1;
			}
		}
		if (false === self::updateClient($client,array('bounce_info'=>$bounce_info))) return false;
		return 1;
	}

	public static function delClient(&$client) {
		if (false === self::unrecordClient($client)) return false;
		$query = sprintf('DELETE FROM %s WHERE id="%s"',MARKETING_DB_TABLE,$client->id);
		if (self::sqlQuery($query,false) === false) return false;
		$ret = @mysql_affected_rows(self::$db);
		$client = null;
		return $ret;
	}
	
	public static function recordClient(&$client) {
		if (in_array($client->status,array(self::STATUS_UNSUB,self::STATUS_BOUNCED))) return false;
		if ($client->status == self::STATUS_LISTED) return true;
		if ($client->status == self::STATUS_NEW) {
			$email = self::cleanEmail($client->email,true);
			if ($email === false) {
				self::updateClient($client,array('status'=>self::STATUS_UNSUB));
				return false;
			} else {
				$fields = array('status'=>self::STATUS_VALID);
				if ($email != $client->email) {
					$fields['email'] = $email;
				}
				if (self::updateClient($client,$fields) === false) {
					if (array_key_exists('email',$fields)) {
						self::delClient($client);
					}
					return false;
				}
			}
		}
		if ($client->status == self::STATUS_VALID) {
			if (false === self::getApiLists()) return false;
			$listLabel = sprintf(self::LIST_LABEL_MASK,$client->site,$client->cat,$client->subcat);
			if (!array_key_exists($listLabel,self::$api_lists)) {
				$result = self::apiQuery('listsCreate',array(array('method'=>'POST','label'=>$listLabel,'name'=>md5($listLabel))),true);
				if (($result === false) || !@$result->list_id) return false;
				self::$api_lists[$listLabel] = $result->list_id;
			}
			$result = self::apiQuery('listsAddcontact',array(array('method'=>'POST','contact'=>$client->email,'id'=>self::$api_lists[$listLabel],'force'=>true)),true);
			if (($result === false) || !@$result->contact_id) return false;
			if (self::updateClient($client,array('status'=>self::STATUS_LISTED))) {
				return true;
			}
		}
		return false;
	}
	
	public static function mailClient(&$client,$newsletter=false,$force=false) {
		if ($client->status == self::STATUS_UNSUB) return 0;
		if ($newsletter) {
			$listLabel = sprintf(self::LIST_LABEL_MASK,$client->site,$client->cat,$client->subcat);
			$template = sprintf(self::$marketing_tplmask,$listLabel);
			if (!file_exists($template)) return 0;
			$date = filectime($template);
			if (!$force && ($date <= $client->date_lastnewsletter)) return 0;
			$campaignID = sprintf(self::MARKETING_CAMPAIGN_ID_MASK,$listLabel,gmdate('Y',$date),gmdate('m',$date),gmdate('d',$date));
		} else {
			if (!$force && (($client->emails_sent > 0) && (time() - $client->date_lastemail < self::$mailing_interval))) return 0;
			$campaignID = sprintf(self::NOTIFICATION_CAMPAIGN_ID_MASK,$client->site,$client->cat,$client->subcat,$client->emails_sent);
			$template = sprintf(self::$notification_tplmask,$campaignID);
			if (!file_exists($template)) return 0;
		}
		$prenom = @$client->prenom ? mb_convert_case($client->prenom,MB_CASE_TITLE,'UTF-8') : ''; 
		$body = file_get_contents($template);
		$body = str_replace('[[PRENOM]]',$prenom,$body);
		$body = str_replace('[[EMAIL]]',$client->email,$body);
		$body = str_replace('[[BONJOUR]]',(date('G') <= 4) || (date('G') >= 18) ? 'Bonsoir' : 'Bonjour',$body);
		$body = str_replace('[[TRACKER]]','mailing_' . $campaignID,$body);
		$body = str_replace('[[JOUR]]',self::$weekDay[date('w')],$body);
		$body = preg_replace_callback('#\[\[([0-9]+)-([0-9]+)\]\]#s',create_function('$m','return mt_rand($m[1],$m[2]);'),$body);
		if (preg_match('#<title>(.*?)</title>#si',$body,$m)) $subject = $m[1]; else return 0;
		if (preg_match('#<meta name="author" content="([^" ]+) ([^"]+)"#si',$body,$m)) {
			$senderEmail = $m[1];
			$senderName = $m[2];
		} else return 0;
		$body = preg_replace('#<!--.*?-->#s','',$body);
		if (!$body) return 0; 

		$mail = new PHPMailer(true); 
		$mail->IsSMTP(); 
		
		try {
			$mail->Host       = MARKETING_SMTP_HOST; 
			$mail->SMTPAuth   = true;                
			$mail->Port       = MARKETING_SMTP_PORT; 
			$mail->Username   = MARKETING_SMTP_USER; 
			$mail->Password   = MARKETING_SMTP_PASSWORD;
			$mail->AddAddress($client->email,$prenom);
			$mail->SetFrom($senderEmail,$senderName);
			$mail->Subject = $subject;
			if (strip_tags($body) == $body) {
				$mail->Body = $body;
				$mail->WordWrap = 70;
			} else {
				$mail->MsgHTML($body);
			} 
			$mail->addCustomHeader("X-Mailjet-Campaign: $campaignID");
			if (!$force) $mail->addCustomHeader('X-Mailjet-DeduplicateCampaign: 1');
			$mail->CharSet = 'UTF-8';
			$mail->Send();
		} catch (phpmailerException $e) {
			self::log($e->errorMessage());
			return false;
		} catch (Exception $e) {
			self::log($e->getMessage());
			return false;
		}
		
		if ($newsletter) {
			$ret = self::updateClient($client,array('date_lastnewsletter'=>time()));			
		} else {
			$ret = self::updateClient($client,array('emails_sent'=>$client->emails_sent+1,'date_lastemail'=>time()));
		}
		return 1;
	}
	
	public static function cleanEmail($email,$dnsCheck=true) {
		$email = trim($email);
		list($user,$host) = split('@',$email,2);

		$user = trim(stripslashes($user));
		$user = str_replace('\\','',$user);
		$user = str_replace(';','.',$user);
		$user = preg_replace('#^[^\w]+#s','',$user);
		$user = preg_replace('#[^\w]+$#s','',$user);
		$user = preg_replace('#\.+#s','.',$user);
		$user = preg_replace('# +#s','_',$user);
		
		if (!preg_match(self::$user_regex,$user)) return false;
		
		$host = strtolower($host);
		$host = trim(stripslashes($host));
		$host = str_replace('\\','',$host);
		$host = str_replace(';','.',$host);
		$host = preg_replace('#^[^\w]+#s','',$host);
		$host = preg_replace('#[^\w]+$#s','',$host);
		$host = preg_replace('#\.+#s','.',$host);
		$host = preg_replace('# +#s','-',$host);
		if (strpos($host,'.') === false) $host .= '.com';
		
		foreach (self::$blocked_hosts as $blocked_host) {
			if (preg_match('#\.' . preg_quote($blocked_host,'#') . '$#si',".$host")) return false;
		}
	
		if (!preg_match(self::$host_regex,$host)) {
			$new_host = self::suggestHost($host);
			if (!$new_host) return false;
			$host = $new_host;
		}
		
		if (!in_array($host,self::$known_hosts)) { 
			if (array_key_exists($host,self::$checked_hosts)) {
				if (self::$checked_hosts[$host] === false) return false;
				if (!is_bool(self::$checked_hosts[$host])) {
					$host = self::$checked_hosts[$host];
				}
			} else {
				if ($dnsCheck && !checkdnsrr("$host.",'MX')) {
					$new_host = self::suggestHost($host);
					if (!$new_host) {
						self::$checked_hosts[$host] = false;
						return false;
					}
					self::$checked_hosts[$host] = $new_host;
					$host = $new_host;
				} elseif ($dnsCheck) {
					self::$checked_hosts[$host] = true;
				}
			}
		}
		
		return "$user@$host";		
	}
	
	public static function apiQuery($method,$params=array(),$saveOnFailure=false) {
		//printf("*** %s\n%s\n",$method,print_r($params,true));
		$result = call_user_func_array(array(self::$api,$method),$params);
		if ($result === false) {
			if ($saveOnFailure) self::apiSave($method,$params);
			self::log("API error in method $method");
		}
		return $result;
	}
	
	public static function sqlQuery($query,$saveOnFailure=false) {
		if (!self::$db) {
			if ($saveOnFailure) self::sqlSave($query);
			return false;		
		}
		if (!$result = @mysql_query($query,self::$db)) {
			self::log(sprintf("%s IN:\n  %s",mysql_error(),$query));
			return false;
		}
		return $result;
	}
	
	public static function getApiLists() {
		if (count(self::$api_lists)) return true;
		$result = self::apiQuery('listsAll');
		if (($result === false) || !is_array(@$result->lists)) return false;
		foreach ($result->lists as $list) {
			self::$api_lists[$list->label] = $list->id;
		}
		return true;
	}
	
	public static function suggestHost($host) {
		$min = self::$max_distance + 1;
		$new_host = '';
		foreach (self::$known_hosts as $known_host) {
			$d = levenshtein($host,$known_host);
			if ($d < $min) {
				$new_host = $known_host;
				if ($d < 2) break; 
				$min = $d;
			}
		}
		return $new_host;
	}
	
	protected static function unrecordClient(&$client) {
		if ($client->status == self::STATUS_LISTED) {
			if (self::getApiLists() === false) return false;
			$listLabel = sprintf(self::LIST_LABEL_MASK,$client->site,$client->cat,$client->subcat);
			if (!array_key_exists($listLabel,self::$api_lists)) return false; 
			if (false === self::apiQuery('listsRemovecontact',array(array('method'=>'POST','contact'=>$client->email,'id'=>self::$api_lists[$listLabel])),true)) return false;
		}
		return true;
	}
	
	protected static function serialize($data) {
		if (!self::$db) return false;
		return mysql_real_escape_string(serialize($data),self::$db);
	}

	protected static function unserialize($data) {
		return @unserialize($data);
	}
	
	protected static function getData(&$client,$key) {
		if (!property_exists($client,'data')) return null;
		return array_key_exists($key,$client->data) ? $client->data[$key] : false;
	}
	
	protected static function setData(&$client,$key,$value) {
		if (!property_exists($client,'data')) $client->data = array($key=>$value);
		else $client->data[$key] = $value;
		return true;
	}
	
	protected static function delData(&$client,$key) {
		if (property_exists($client,'data') && array_key_exists($key,$client->data)) unset($client->data[$key]);
		return true;
	}
	
	protected static function log($msg) {
		@file_put_contents(self::$logfile,sprintf("%s %s\n",date('d-m-Y H:i:s'),$msg),FILE_APPEND);
	}
	
	protected static function sqlSave($query) {
		@file_put_contents(self::$sqlfile,"$query\n",FILE_APPEND);
	}
	
	protected static function apiSave($method,$params) {
		@file_put_contents(self::$apifile,serialize(array($method,$params)) . "\n",FILE_APPEND);
	}
}

Marketing::init();
?>
						