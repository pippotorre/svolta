<?php
/**
 * Installazione: crea le tabelle se mancano (in alternativa importa sql/svolta_init.sql
 * da phpMyAdmin), genera le chiavi per le notifiche
 * e registra le circolari già pubblicate (senza notificarle).
 * Si apre una volta dal browser: install.php?token=IL_TUO_CRON_TOKEN
 * Si può rieseguire senza danni.
 */
require __DIR__ . '/inc/lib.php';
require_token();

$p = cfg('db_prefix');
$sql = [
    "CREATE TABLE IF NOT EXISTS {$p}settings (
        k VARCHAR(64) NOT NULL PRIMARY KEY,
        v TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS {$p}subscriptions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        endpoint_hash CHAR(64) NOT NULL,
        endpoint TEXT NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        last_ok_at DATETIME NULL,
        fail_count INT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_endpoint_hash (endpoint_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS {$p}circolari (
        guid VARCHAR(64) NOT NULL PRIMARY KEY,
        numero INT NULL,
        oggetto TEXT NOT NULL,
        link TEXT NOT NULL,
        pub_date DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        notified_at DATETIME NULL,
        KEY idx_pending (notified_at, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

$out = ['tabelle' => 'ok'];
try {
    foreach ($sql as $q) {
        db()->exec($q);
    }
    ensure_log_table();
} catch (Throwable $e) {
    json_out(['error' => 'Database: ' . $e->getMessage()], 500);
}

if (!setting_get('vapid_public')) {
    $k = vapid_generate_keys();
    setting_set('vapid_private', $k['private_pem']);
    setting_set('vapid_public', $k['public']);
    $out['chiavi_notifiche'] = 'create';
} else {
    $out['chiavi_notifiche'] = 'già presenti (non toccate)';
}

$feed = load_feed(true);
if (!$feed['items']) {
    $out['feed'] = 'ATTENZIONE: feed non raggiungibile dal server';
} else {
    $r = process_new_circolari($feed['items']);
    $out['feed'] = count($feed['items']) . ' circolari lette';
    $out['primo_avvio'] = $r['first_run'] ? 'circolari esistenti registrate senza notifiche' : 'no';
}

$cache = __DIR__ . '/cache';
$out['cartella_cache'] = (is_dir($cache) && is_writable($cache)) ? 'scrivibile' : 'ATTENZIONE: api/cache non scrivibile (funziona lo stesso, ma più lento)';
$out['estensioni'] = [
    'openssl' => extension_loaded('openssl'),
    'curl' => extension_loaded('curl'),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'simplexml' => extension_loaded('simplexml'),
];
$out['php'] = PHP_VERSION;
$out['esito'] = 'Installazione completata';
json_out($out);
