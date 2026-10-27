<?php
/**
 * Web Push senza dipendenze esterne (solo estensioni openssl e curl).
 * Implementa VAPID (RFC 8292) e la cifratura aes128gcm (RFC 8291 / RFC 8188).
 * Compatibile con PHP 8.0+.
 */

function b64u_enc(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64u_dec(string $data): string
{
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    $out = base64_decode($data, true);
    if ($out === false) {
        throw new RuntimeException('base64url non valido');
    }
    return $out;
}

/** Genera una nuova coppia di chiavi VAPID (curva P-256). */
function vapid_generate_keys(): array
{
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if ($key === false) {
        throw new RuntimeException('Impossibile generare la chiave EC: ' . openssl_error_string());
    }
    openssl_pkey_export($key, $privatePem);
    $d = openssl_pkey_get_details($key);
    $public = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    return ['private_pem' => $privatePem, 'public' => b64u_enc($public)];
}

/** Converte una chiave pubblica P-256 "raw" (65 byte) in risorsa OpenSSL. */
function ec_public_from_raw(string $raw)
{
    if (strlen($raw) !== 65 || $raw[0] !== "\x04") {
        throw new RuntimeException('Chiave pubblica del dispositivo non valida');
    }
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $key = openssl_pkey_get_public($pem);
    if ($key === false) {
        throw new RuntimeException('Chiave pubblica non leggibile');
    }
    return $key;
}

/** Converte una firma ECDSA in DER nel formato raw r||s (64 byte) richiesto da JWT ES256. */
function ecdsa_der_to_raw(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) {
        $pos += ord($der[1]) & 0x7f;
    }
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $pos++; // tag INTEGER
        $len = ord($der[$pos]);
        $pos++;
        $int = substr($der, $pos, $len);
        $pos += $len;
        $int = ltrim($int, "\0");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
    }
    return $out;
}

/** Crea l'header Authorization VAPID per un endpoint. */
function vapid_auth_header(string $endpoint, string $privatePem, string $publicB64u, string $subject): string
{
    $parts = parse_url($endpoint);
    $aud = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $header = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = b64u_enc(json_encode([
        'aud' => $aud,
        'exp' => time() + 12 * 3600,
        'sub' => $subject,
    ], JSON_UNESCAPED_SLASHES));
    $signingInput = $header . '.' . $claims;
    if (!openssl_sign($signingInput, $sigDer, $privatePem, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Firma VAPID fallita');
    }
    $jwt = $signingInput . '.' . b64u_enc(ecdsa_der_to_raw($sigDer));
    return 'vapid t=' . $jwt . ', k=' . $publicB64u;
}

/** Cifra il payload per un dispositivo (aes128gcm, un solo record). */
function webpush_encrypt(string $payload, string $uaPublicB64u, string $authB64u): string
{
    $uaPublic = b64u_dec($uaPublicB64u);
    $authSecret = b64u_dec($authB64u);

    $local = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $ld = openssl_pkey_get_details($local);
    $asPublic = "\x04" . str_pad($ld['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ld['ec']['y'], 32, "\0", STR_PAD_LEFT);

    $shared = openssl_pkey_derive(ec_public_from_raw($uaPublic), $local, 32);
    if ($shared === false) {
        throw new RuntimeException('Derivazione ECDH fallita');
    }

    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    $plain = $payload . "\x02"; // delimitatore dell'ultimo record
    $cipher = openssl_encrypt($plain, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) {
        throw new RuntimeException('Cifratura fallita');
    }

    $rs = 4096;
    $header = $salt . pack('N', $rs) . chr(strlen($asPublic)) . $asPublic;
    return $header . $cipher . $tag;
}

/**
 * Invia lo stesso payload a più dispositivi in parallelo.
 * $subs: array di ['id'=>..., 'endpoint'=>..., 'p256dh'=>..., 'auth'=>...]
 * Restituisce [id => codice HTTP (0 = errore di rete)].
 */
function webpush_send_many(array $subs, string $payload, array $vapid, int $ttl = 259200, int $batch = 50): array
{
    $results = [];
    foreach (array_chunk($subs, $batch) as $chunk) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $s) {
            try {
                $body = webpush_encrypt($payload, $s['p256dh'], $s['auth']);
                $auth = vapid_auth_header($s['endpoint'], $vapid['private_pem'], $vapid['public'], $vapid['subject']);
            } catch (Throwable $e) {
                $results[$s['id']] = -1; // iscrizione non valida
                continue;
            }
            $ch = curl_init($s['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/octet-stream',
                    'Content-Encoding: aes128gcm',
                    'TTL: ' . $ttl,
                    'Urgency: high',
                    'Authorization: ' . $auth,
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[(int) $s['id']] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $id => $ch) {
            $results[$id] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $results;
}
