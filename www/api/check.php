<?php
/**
 * Controllo schedulato: legge il feed e invia le notifiche per le circolari nuove.
 * Chiamato da GitHub Actions (o da qualunque servizio cron) con il token:
 *   header  X-Cron-Token: ...   oppure   check.php?token=...
 */
require __DIR__ . '/inc/lib.php';
require_token();

$feed = load_feed(true);
if (!$feed['items']) {
    json_out(['ok' => false, 'error' => 'feed non raggiungibile o vuoto'], 502);
}
$r = process_new_circolari($feed['items']);
$subs = (int) db()->query('SELECT COUNT(*) FROM ' . t('subscriptions'))->fetchColumn();

json_out([
    'ok' => true,
    'ora' => date('c'),
    'circolari_nel_feed' => count($feed['items']),
    'dispositivi_iscritti' => $subs,
    'nuove' => $r['new'],
]);
