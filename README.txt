--- INSTALLATION

Copiez tout �a dans un r�pertoire non accessible au web, cr�ez si possible base mysql, par exemple "marketing", et dedans une table, par exemple "clients" sur le mod�le de marketing.sql.

Vous aurez �galement besoin des param�tres de SMTP et d'une clef API Mailjet ; vous pouvez vous cr�er un compte chez eux gratuitement pour cela.

Compl�tez et/ou modifiez ce qui doit l'�tre dans include/config.php et ajoutez une ligne � votre crontable pour que le fichier cron.php s'�x�cute une fois par heure. Quelque chose de ce genre doit faire l'affaire :

	0 * * * * /usr/bin/php /REPERTOIRE_DU_SCRIPT/cron.php > /dev/null
	
Dans config.php, il y a 2 constantes qui n�cessitent une petite explication :

	- MARKETING_MAILING_INTERVAL : 
		
		Une valeur en secondes. Emp�che un email de notification d'�tre envoy� � un client tant qu'un certain temps ne s'est pas �coul�.
		Cette constante n'impacte pas l'envoi des emails marketing (newsletter) qui sont g�r�s diff�remment (voir plus loin).
		
	- DAILY_LIMIT : 
	
		Le nombre maximum d'emails � envoyer par jour.
		Attention : cette limite ne concerne que le fichier cron.php donc si vous envoyez des emails ailleurs, il est possible qu'elle soit d�pass�e.
		Une pseudo exception cependant : un email de notification envoy� � un nouveau client sera correctement comptabilis� dans cron.php l'heure suivante.
		Par ailleurs, l'API mailjet est �galement interrog�e dans cron.php pour conna�tre le d�compte actuel, mais cette m�thode semble assez peu fiable.
		On ne peut pas mettre de limite infinie mais si vous avez un forfait � 1000 emails par jour, rien ne vous emp�che d'en tol�rer plus.
		
Dans cron.php � la ligne 9, vous pouvez modifier les heures d'envoi des emails. Par d�faut 8h, 10h, 13h, 15h et 19h. La limite d'envoi par session est calcul�e dynamiquement donc c'est la seule chose � modifier. 

--- ORGANISATION

Quelques mots sur comment le truc est organis� avant d'aller plus loin.

J'ai cod� ces quelques scripts pour pouvoir g�rer de fa�on centralis�e les clients de tous mes sites, et en l'�tat actuel en fait, seulement les mailing.

La clef unique de la table mysql est compos�e de 4 champs :

	- site		: j'y met le nom de domaine du site, en minuscules
	- cat		: la cat�gorie principale
	- subcat	: une categorie secondaire
	- email		: l'email du client
	
A vous de vous organiser en fonction de cela, si vous n'avez qu'une th�matique par site, vous pouvez mettre cette th�matique en cat�gorie et "indefini" en sous-categorie par exemple. Et si ce n'est pas suffisant, tant pis :)

Ces 4 champs sont requis lorsque vous ajoutez un client via l'api, avec la m�thode Marketing::addClient().

Pour l'emailing qui est notre principal sujet de pr�occupation donc, les fichiers html correspondant aux messages � envoyer devront �tre nomm�s de fa�on tr�s stricte en fonction des param�tres site, cat et subcat :

	- les newsletters seront enregistr�es sous le nom site_cat_subcat.html dans le dossier /templates/marketing/
	- les notifications seront enregistr�es sous le nom site_cat_subcat_i.html dans le dossier /templates/notification/, avec i d�butant � 0 pour le premier mail.
	
Chaque nouvelle newsletter �crase donc la pr�c�dente, � vous de d�placer l'ancienne ailleurs si vous souhaitez la conserver. Et modifier la newsletter actuelle ne suffit pas, le fichier doit �tre recr�� pour que le syst�me comprenne qu'il s'agit d'une nouvelle newsletter (la date de cr�ation du fichier est regard�e, et pas celle de modification afin de ne pas envoyer dix fois le mail quand on corrige une faute d'orthographe apr�s coup etc).

Les notifications commencent � 0, vous pouvez en cr�er autant que vous voulez. A chaque lancement de cron.php, le script s'occupera d'envoyer le mail correspondant au client (en respectant un minimum de MARKETING_MAILING_INTERVAL secondes entre deux messages) en commen�ant par le mail 0, puis 1, 2, etc.

Les mails en question, qu'il s'agisse de newsletter comme de notification, sont de simples fichiers HTML. Je vous ai mis un mod�le dans /templates/dev/.

Il y a deux trois choses � savoir :

	- le sujet du message correspond � la balise <title> du document
	- le nom et l'email de l'exp�diteur correspondent aux informations dans ma balise <meta name="author"> du document
	- en plus des variables reconnues par Mailjet (yen a peu), vous disposez des variables suivantes :
	
		- [[PRENOM]]	: sera remplac� par le pr�nom du client
		- [[EMAIL]]		: sera remplac� par l'email du client
		- [[BONJOUR]]	: sera remplac� par "Bonjour" entre 5h et 17h, et par "Bonsoir" entre 18h et et 4h (toutes les heures s'entendent au format "et des bananes").
		- [[TRACKER]]	: sera remplac� par "mailing_" + ID de la campagne, l'ID de la campagne �tant le nom du fichier HTML utilis� sans l'extension pour les notifications, et le nom du fichier utilis� sans l'extension mais avec en plus _AAAAMMJJ selon la date de cr�ation du fichier pour les newsletters.
		- [[JOUR]]		: sera remplac� par le nom complet du jour courant (lundi � dimanche)
		- [[X-Y]]		: avec X et Y des nombres entiers, sera remplac� par un nombre al�atoire entre X et Y (ou Y et X si Y est plus grand).
		
Le mieux est de cr�er vos brouillons dans templates/dev/ et de les d�placer au bon endroit lorsque tout est OK. J'ai inclu un fichier test-mailing.php qui vous permet en ligne de commande de tester n'importe quel template (pour cela modifier d'abord les emails et prenoms des destinataires � la ligne 12 du script) avec la commande suivante par exemple :

	test-mailing.php dev/ma-campagne.html
	
Ensuite, vous n'avez qu'� checker vos bo�tes aux lettres.

--- L'API

A venir...