<?php
/**
 * Copia questo file come config.php (nella stessa cartella) e compila i valori.
 * config.php NON va messo su GitHub: contiene le password.
 */
return [
    // Database MySQL (dati dal pannello Tophost)
    'db_host' => 'localhost',
    'db_name' => 'NOME_DATABASE',
    'db_user' => 'UTENTE_DATABASE',
    'db_pass' => 'PASSWORD_DATABASE',
    'db_prefix' => 'svolta_',

    // Token segreto per il controllo schedulato e per install.php.
    // Una stringa lunga e casuale; la stessa va messa nei "Secrets" di GitHub come CRON_TOKEN.
    'cron_token' => 'CAMBIAMI_CON_UNA_STRINGA_LUNGA_E_CASUALE',

    // Indirizzo pubblico dell'app (serve per le notifiche e come contatto VAPID)
    'app_url' => 'https://www.entratadisicurezza.com/svolta/',

    // Fonti
    'feed_url' => 'https://api.liceovolta.eu/feed/circolari.rss',
    'pdf_prefix' => 'https://demqube.s3.eu-central-1.amazonaws.com/circolari/',

    // Ogni quanti secondi l'app può rileggere il feed (cache)
    'feed_cache_seconds' => 300,
];
