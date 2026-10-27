<?php
/**
 * Notifica di prova: arriva solo al dispositivo che la chiede.
 * Limite: una prova al minuto per dispositivo.
 */
require __DIR__ . '/inc/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'usa POST'], 405);
}
$endpoint = (string) (read_json_body()['endpoint'] ?? '');
$st = db()->prepare('SELECT id FROM ' . t('subscriptions') . ' WHERE endpoint_hash = ?');
$st->execute([hash('sha256', $endpoint)]);
$id = $st->fetchColumn();
if ($id === false) {
    json_out(['error' => 'Questo dispositivo non risulta iscritto. Riattiva le notifiche.'], 404);
}

$key = 'test_' . (int) $id;
$last = (int) (setting_get($key) ?? 0);
if (time() - $last < 60) {
    json_out(['error' => 'Hai appena inviato una prova. Riprova tra un minuto.'], 429);
}
setting_set($key, (string) time());

$payload = json_encode([
    'title' => 'Notifiche attive',
    'body' => 'Così ti arriverà l\'avviso di ogni nuova circolare.',
    'guid' => '',
    'url' => cfg('app_url'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$r = push_to_subscribers($payload, [(int) $id]);
db()->prepare('DELETE FROM ' . t('settings') . ' WHERE k LIKE ? AND v < ?')
    ->execute(['test\\_%', (string) (time() - 3600)]);
json_out(['ok' => $r['sent'] === 1] + $r);
