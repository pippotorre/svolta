<?php
/**
 * Controllo schedulato: legge il feed e invia le notifiche per le circolari nuove.
 * Chiamato da cron-job.org ogni 10 minuti (o da qualunque servizio cron) con il token:
 *   header  X-Cron-Token: ...   oppure   check.php?token=...
 */
require __DIR__ . '/inc/lib.php';
require_token();

$feed = load_feed(true);
if (!$feed['items']) {
    setting_set('ultimo_controllo', json_encode(['ora' => date('c'), 'esito' => 'feed non raggiungibile o vuoto']));
    json_out(['ok' => false, 'error' => 'feed non raggiungibile o vuoto'], 502);
}
try {
    $r = process_new_circolari($feed['items'], 'controllo');
} catch (Throwable $e) {
    setting_set('ultimo_controllo', json_encode(['ora' => date('c'), 'esito' => 'errore: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
$subs = (int) db()->query('SELECT COUNT(*) FROM ' . t('subscriptions'))->fetchColumn();
setting_set('ultimo_controllo', json_encode([
    'ora' => date('c'),
    'esito' => 'ok',
    'circolari_nel_feed' => count($feed['items']),
    'nuove' => count($r['new']),
], JSON_UNESCAPED_UNICODE));

json_out([
    'ok' => true,
    'ora' => date('c'),
    'circolari_nel_feed' => count($feed['items']),
    'dispositivi_iscritti' => $subs,
    'nuove' => $r['new'],
]);
