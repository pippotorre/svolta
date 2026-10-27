<?php
/** Rimuove un dispositivo dalle notifiche. */
require __DIR__ . '/inc/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'usa POST'], 405);
}
$endpoint = (string) (read_json_body()['endpoint'] ?? '');
if ($endpoint === '') {
    json_out(['error' => 'endpoint mancante'], 400);
}
$st = db()->prepare('DELETE FROM ' . t('subscriptions') . ' WHERE endpoint_hash = ?');
$st->execute([hash('sha256', $endpoint)]);
json_out(['ok' => true]);
