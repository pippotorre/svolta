<?php
/** Elenco delle circolari per l'app (JSON). */
require __DIR__ . '/inc/lib.php';

$feed = load_feed(false);

// Quando il feed è stato appena riletto, controllo anche le novità:
// così le notifiche partono anche se il controllo schedulato è in ritardo.
if (!empty($feed['fetched']) && $feed['items']) {
    try {
        process_new_circolari($feed['items']);
    } catch (Throwable $e) {
        // l'elenco va mostrato comunque
    }
}

header('Cache-Control: no-store');
json_out([
    'items' => $feed['items'],
    'stale' => !empty($feed['stale']),
    'error' => !empty($feed['error']),
]);
