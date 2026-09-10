<?php
/**
 * config.php
 * Réglages PHP à charger en tout premier (avant tout autre require).
 *
 * ATTENTION : upload_max_filesize et post_max_size NE PEUVENT PAS être
 * changés ici avec ini_set() — PHP applique déjà ces limites AVANT
 * d'exécuter le moindre code de ce fichier. Ils sont désormais réglés
 * dans le fichier .htaccess à la racine du projet (php_value ...),
 * ce qui fonctionne réellement avec Apache + mod_php (XAMPP par défaut).
 * Si l'upload reste bloqué malgré ça, voir la note en bas de ce fichier.
 */

@ini_set('max_execution_time', '120');
@ini_set('max_input_time', '120');
@ini_set('memory_limit', '256M');

/*
 * -----------------------------------------------------------------
 * Si l'upload d'un fichier échoue toujours (message d'erreur générique
 * "pièce jointe obligatoire" alors qu'un fichier a bien été choisi),
 * la cause est presque toujours une des deux :
 *
 * 1) Fichier trop volumineux pour les limites du serveur.
 *    -> Vérifiez le message d'erreur affiché (le site l'indique
 *       maintenant précisément) et/ou ouvrez C:\xampp\php\php.ini,
 *       cherchez ces lignes et augmentez les valeurs :
 *           upload_max_filesize = 20M
 *           post_max_size = 25M
 *       Puis redémarrez Apache dans le panneau XAMPP.
 *
 * 2) Le dossier assets/uploads/ n'est pas accessible en écriture par
 *    Apache (rare sous Windows, mais possible si le dossier a été
 *    copié avec des droits restreints). Clic droit sur le dossier
 *    -> Propriétés -> Sécurité -> autoriser l'écriture pour tous.
 * -----------------------------------------------------------------
 *
 * RAPIDITÉ D'ACCÈS DEPUIS LES AUTRES POSTES DU RÉSEAU :
 *
 * 1) Activez OPcache (accélère fortement PHP) : dans php.ini,
 *    décommentez/ajoutez :
 *        zend_extension=opcache
 *        opcache.enable=1
 *        opcache.memory_consumption=128
 *
 * 2) Dans C:\xampp\apache\conf\httpd.conf, décommentez ces lignes
 *    (retirez le # devant) pour activer la compression et le cache
 *    navigateur utilisés par le fichier .htaccess du projet :
 *        LoadModule deflate_module modules/mod_deflate.so
 *        LoadModule expires_module modules/mod_expires.so
 *        LoadModule headers_module modules/mod_headers.so
 *    Puis vérifiez que la ligne suivante est bien "Off" (accélère
 *    chaque requête en évitant une résolution DNS inverse) :
 *        HostnameLookups Off
 *
 * 3) Sur les postes clients, préférez accéder via l'adresse IP fixe
 *    du serveur (ex: http://192.168.1.10/gestion de suivi/) plutôt
 *    qu'un nom d'ordinateur, ce qui évite les délais de résolution
 *    NetBIOS sur certains réseaux Windows.
 *
 * Redémarrez Apache après chaque modification.
 * -----------------------------------------------------------------
 */
