<?php
/**
 * Registra (o aggiorna) un dispositivo per le notifiche.
 * Si salvano solo i dati tecnici dell'iscrizione push: nessun dato personale.
 */
require __DIR__ . '/inc/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'usa POST'], 405);
}
$b = read_json_body();
$endpoint = (string) ($b['endpoint'] ?? '');
$p256dh = (string) ($b['keys']['p256dh'] ?? '');
$auth = (string) ($b['keys']['auth'] ?? '');

if (!preg_match('#^https://[^\s]+$#', $endpoint) || strlen($endpoint) > 1000 || $p256dh === '' || $auth === '') {
    json_out(['error' => 'iscrizione non valida'], 400);
}
try {
    if (strlen(b64u_dec($p256dh)) !== 65 || strlen(b64u_dec($auth)) !== 16) {
        json_out(['error' => 'chiavi non valide'], 400);
    }
} catch (Throwable $e) {
    json_out(['error' => 'chiavi non valide'], 400);
}

$st = db()->prepare('INSERT INTO ' . t('subscriptions') . ' (endpoint_hash, endpoint, p256dh, auth, created_at)
    VALUES (?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), fail_count = 0');
$st->execute([hash('sha256', $endpoint), $endpoint, $p256dh, $auth]);

json_out(['ok' => true]);
