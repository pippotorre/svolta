<?php
/** Chiave pubblica per attivare le notifiche sul telefono. */
require __DIR__ . '/inc/lib.php';
json_out(['publicKey' => vapid()['public']]);
