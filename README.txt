--- INSTALLATION

Copiez tout ça dans un répertoire non accessible au web, créez si possible base mysql, par exemple "marketing", et dedans une table, par exemple "clients" sur le modèle de marketing.sql.

Vous aurez également besoin des paramètres de SMTP et d'une clef API Mailjet ; vous pouvez vous créer un compte chez eux gratuitement pour cela.

Complétez et/ou modifiez ce qui doit l'être dans include/config.php et ajoutez une ligne à votre crontable pour que le fichier cron.php s'éxécute une fois par heure. Quelque chose de ce genre doit faire l'affaire :

	0 * * * * /usr/bin/php /REPERTOIRE_DU_SCRIPT/cron.php > /dev/null
	
Dans config.php, il y a 2 constantes qui nécessitent une petite explication :

	- MARKETING_MAILING_INTERVAL : 
		
		Une valeur en secondes. Empêche un email de notification d'être envoyé à un client tant qu'un certain temps ne s'est pas écoulé.
		Cette constante n'impacte pas l'envoi des emails marketing (newsletter) qui sont gérés différemment (voir plus loin).
		
	- DAILY_LIMIT : 
	
		Le nombre maximum d'emails à envoyer par jour.
		Attention : cette limite ne concerne que le fichier cron.php donc si vous envoyez des emails ailleurs, il est possible qu'elle soit dépassée.
		Une pseudo exception cependant : un email de notification envoyé à un nouveau client sera correctement comptabilisé dans cron.php l'heure suivante.
		Par ailleurs, l'API mailjet est également interrogée dans cron.php pour connaître le décompte actuel, mais cette méthode semble assez peu fiable.
		On ne peut pas mettre de limite infinie mais si vous avez un forfait à 1000 emails par jour, rien ne vous empêche d'en tolérer plus.
		
Dans cron.php à la ligne 9, vous pouvez modifier les heures d'envoi des emails. Par défaut 8h, 10h, 13h, 15h et 19h. La limite d'envoi par session est calculée dynamiquement donc c'est la seule chose à modifier. 

--- ORGANISATION

Quelques mots sur comment le truc est organisé avant d'aller plus loin.

J'ai codé ces quelques scripts pour pouvoir gérer de façon centralisée les clients de tous mes sites, et en l'état actuel en fait, seulement les mailing.

La clef unique de la table mysql est composée de 4 champs :

	- site		: j'y met le nom de domaine du site, en minuscules
	- cat		: la catégorie principale
	- subcat	: une categorie secondaire
	- email		: l'email du client
	
A vous de vous organiser en fonction de cela, si vous n'avez qu'une thématique par site, vous pouvez mettre cette thématique en catégorie et "indefini" en sous-categorie par exemple. Et si ce n'est pas suffisant, tant pis :)

Ces 4 champs sont requis lorsque vous ajoutez un client via l'api, avec la méthode Marketing::addClient().

Pour l'emailing qui est notre principal sujet de préoccupation donc, les fichiers html correspondant aux messages à envoyer devront être nommés de façon très stricte en fonction des paramètres site, cat et subcat :

	- les newsletters seront enregistrées sous le nom site_cat_subcat.html dans le dossier /templates/marketing/
	- les notifications seront enregistrées sous le nom site_cat_subcat_i.html dans le dossier /templates/notification/, avec i débutant à 0 pour le premier mail.
	
Chaque nouvelle newsletter écrase donc la précédente, à vous de déplacer l'ancienne ailleurs si vous souhaitez la conserver. Et modifier la newsletter actuelle ne suffit pas, le fichier doit être recréé pour que le système comprenne qu'il s'agit d'une nouvelle newsletter (la date de création du fichier est regardée, et pas celle de modification afin de ne pas envoyer dix fois le mail quand on corrige une faute d'orthographe après coup etc).

Les notifications commencent à 0, vous pouvez en créer autant que vous voulez. A chaque lancement de cron.php, le script s'occupera d'envoyer le mail correspondant au client (en respectant un minimum de MARKETING_MAILING_INTERVAL secondes entre deux messages) en commençant par le mail 0, puis 1, 2, etc.

Les mails en question, qu'il s'agisse de newsletter comme de notification, sont de simples fichiers HTML. Je vous ai mis un modèle dans /templates/dev/.

Il y a deux trois choses à savoir :

	- le sujet du message correspond à la balise <title> du document
	- le nom et l'email de l'expéditeur correspondent aux informations dans ma balise <meta name="author"> du document
	- en plus des variables reconnues par Mailjet (yen a peu), vous disposez des variables suivantes :
	
		- [[PRENOM]]	: sera remplacé par le prénom du client
		- [[EMAIL]]		: sera remplacé par l'email du client
		- [[BONJOUR]]	: sera remplacé par "Bonjour" entre 5h et 17h, et par "Bonsoir" entre 18h et et 4h (toutes les heures s'entendent au format "et des bananes").
		- [[TRACKER]]	: sera remplacé par "mailing_" + ID de la campagne, l'ID de la campagne étant le nom du fichier HTML utilisé sans l'extension pour les notifications, et le nom du fichier utilisé sans l'extension mais avec en plus _AAAAMMJJ selon la date de création du fichier pour les newsletters.
		- [[JOUR]]		: sera remplacé par le nom complet du jour courant (lundi à dimanche)
		- [[X-Y]]		: avec X et Y des nombres entiers, sera remplacé par un nombre aléatoire entre X et Y (ou Y et X si Y est plus grand).
		
Le mieux est de créer vos brouillons dans templates/dev/ et de les déplacer au bon endroit lorsque tout est OK. J'ai inclu un fichier test-mailing.php qui vous permet en ligne de commande de tester n'importe quel template (pour cela modifier d'abord les emails et prenoms des destinataires à la ligne 12 du script) avec la commande suivante par exemple :

	test-mailing.php dev/ma-campagne.html
	
Ensuite, vous n'avez qu'à checker vos boîtes aux lettres.

--- L'API

J'ai déjà consacré trop de temps à cette doc de merde donc on va faire minimaliste, pour le reste je vous invite à mater le code de api.php :)

En gros, quand un client s'inscrit sur un de vos sites :

	$prenom = trim(stripslashes(strip_tags(@$_REQUEST['prenom'])));
	$email = trim(stripslashes(strip_tags(@$_REQUEST['email'])));
	if ($prenom && preg_match('#^[^@ ]+@[^@ ]+$#si',$email)) {
		include_once '/REPERTOIRE_DU_SCRIPT/api.php';
		if ($client = Marketing::addClient('exemple.fr','categorie','sous-categorie',$email,$prenom)) {
			Marketing::mailClient($client);
		}
	}

Cela va le mettre en base si l'email semble valide et lui envoyer le premier message de notification si il y en a. Dans ce cas classique, un nombre minimal de requêtes est fait donc il n'y a pas de verification dns pour l'email, mais cela sera fait plus tard dans cron.php si besoin est.

Notez aussi que l'api utilise une fonction spéciale pour valider les emails qui permet quand cela est possible de corriger les adresses erronées (vous pouvez ajouter des domaines courants directement ligne 27 de api.php...).

--- A VENIR

Une admin online pour gérer tout ça.
