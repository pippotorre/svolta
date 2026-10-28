<?php
/** Funzioni comuni: configurazione, database, feed, invio notifiche. */

require_once __DIR__ . '/webpush.php';

function cfg(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            json_out(['error' => 'config.php mancante: copia config.sample.php e compilalo'], 500);
        }
        $cfg = require $file;
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function t(string $name): string
{
    return cfg('db_prefix') . $name;
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

function require_token(): void
{
    $given = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
    $expected = (string) cfg('cron_token');
    if ($expected === '' || str_starts_with($expected, 'CAMBIAMI') || !hash_equals($expected, (string) $given)) {
        json_out(['error' => 'token non valido'], 403);
    }
}

function setting_get(string $k): ?string
{
    $st = db()->prepare('SELECT v FROM ' . t('settings') . ' WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

function setting_set(string $k, string $v): void
{
    $st = db()->prepare('INSERT INTO ' . t('settings') . ' (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $st->execute([$k, $v]);
}

function vapid(): array
{
    $priv = setting_get('vapid_private');
    $pub = setting_get('vapid_public');
    if (!$priv || !$pub) {
        json_out(['error' => 'chiavi VAPID mancanti: esegui install.php'], 500);
    }
    return ['private_pem' => $priv, 'public' => $pub, 'subject' => cfg('app_url')];
}

/* ---------------- Feed ---------------- */

function http_get(string $url, int $timeout = 20): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'sVolta/1.0 (+notifiche circolari)',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
    }
    $body = @file_get_contents($url);
    return $body === false ? null : $body;
}

/** Estrae numero e oggetto da "Circolare n. 51 - Oggetto". */
function parse_title(string $title): array
{
    $title = trim(preg_replace('/\s+/u', ' ', $title));
    if (preg_match('/^circolare\s*n\.?\s*(\d+)\s*(?:[-–—:]\s*)?(.*)$/iu', $title, $m)) {
        return [(int) $m[1], trim($m[2]) !== '' ? trim($m[2]) : $title];
    }
    return [null, $title];
}

/** Converte l'XML del feed in un elenco di circolari. */
function parse_feed(string $xml): array
{
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    if ($doc === false || !isset($doc->channel)) {
        return [];
    }
    $items = [];
    foreach ($doc->channel->item as $it) {
        $title = (string) $it->title;
        [$num, $oggetto] = parse_title($title);
        $guid = trim((string) $it->guid);
        $link = trim((string) $it->link);
        if ($guid === '') {
            $guid = substr(sha1($link . $title), 0, 32);
        }
        $ts = strtotime((string) $it->pubDate) ?: time();
        $desc = trim(html_entity_decode(strip_tags((string) $it->description), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $items[] = [
            'guid' => $guid,
            'numero' => $num,
            'oggetto' => $oggetto,
            'titolo' => $title,
            'link' => $link,
            'data' => gmdate('c', $ts),
            'estratto' => $desc,
        ];
    }
    return $items;
}

/** Legge il feed, con una piccola cache su file. */
function load_feed(bool $force = false): array
{
    $cacheDir = __DIR__ . '/../cache';
    $cacheFile = $cacheDir . '/feed.xml';
    $maxAge = (int) (cfg('feed_cache_seconds') ?: 300);
    $fresh = is_file($cacheFile) && (time() - filemtime($cacheFile) < $maxAge);

    if (!$force && $fresh) {
        return ['items' => parse_feed((string) file_get_contents($cacheFile)), 'fetched' => false];
    }
    $xml = http_get(cfg('feed_url'));
    if ($xml !== null && parse_feed($xml)) {
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        @file_put_contents($cacheFile, $xml);
        return ['items' => parse_feed($xml), 'fetched' => true];
    }
    // Feed non raggiungibile: uso l'ultima copia salvata, se c'è
    if (is_file($cacheFile)) {
        return ['items' => parse_feed((string) file_get_contents($cacheFile)), 'fetched' => false, 'stale' => true];
    }
    return ['items' => [], 'fetched' => false, 'error' => true];
}

/* ---------------- Notifiche ---------------- */

function notification_payload(array $c): string
{
    $title = $c['numero'] !== null ? 'CIRCOLARE ' . $c['numero'] : 'Nuova circolare';
    return json_encode([
        'title' => $title,
        'body' => $c['oggetto'],
        'guid' => $c['guid'],
        'url' => cfg('app_url') . '?c=' . rawurlencode($c['guid']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Invia un payload a tutte le iscrizioni (o a quelle indicate) e pulisce quelle scadute. */
function push_to_subscribers(string $payload, ?array $onlyIds = null): array
{
    $sql = 'SELECT id, endpoint, p256dh, auth FROM ' . t('subscriptions');
    $params = [];
    if ($onlyIds !== null) {
        if (!$onlyIds) {
            return ['sent' => 0, 'failed' => 0, 'removed' => 0];
        }
        $sql .= ' WHERE id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
        $params = $onlyIds;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    $subs = $st->fetchAll();

    $res = webpush_send_many($subs, $payload, vapid());
    $ok = $failed = $removed = 0;
    $codes = [];
    foreach ($res as $code) {
        $codes[(string) $code] = ($codes[(string) $code] ?? 0) + 1;
    }
    $del = db()->prepare('DELETE FROM ' . t('subscriptions') . ' WHERE id = ?');
    $good = db()->prepare('UPDATE ' . t('subscriptions') . ' SET last_ok_at = NOW(), fail_count = 0 WHERE id = ?');
    $bad = db()->prepare('UPDATE ' . t('subscriptions') . ' SET fail_count = fail_count + 1 WHERE id = ?');
    foreach ($res as $id => $code) {
        if ($code >= 200 && $code < 300) {
            $ok++;
            $good->execute([$id]);
        } elseif (in_array($code, [-1, 404, 410], true)) {
            // il telefono ha disattivato le notifiche o disinstallato l'app
            $removed++;
            $del->execute([$id]);
        } else {
            $failed++;
            $bad->execute([$id]);
        }
    }
    // dopo molti errori consecutivi l'iscrizione viene eliminata
    db()->exec('DELETE FROM ' . t('subscriptions') . ' WHERE fail_count >= 20');
    ksort($codes);
    return ['iscritti' => count($subs), 'sent' => $ok, 'failed' => $failed, 'removed' => $removed, 'codes' => $codes];
}

/** Spiegazione in italiano dei codici di risposta del servizio push. */
function push_code_meaning(int $code): string
{
    if ($code >= 200 && $code < 300) return 'consegnata al servizio notifiche del telefono';
    switch ($code) {
        case -1: return 'iscrizione non valida: è stata rimossa, riattiva le notifiche';
        case 0: return 'il servizio notifiche non ha risposto (rete o timeout)';
        case 400: return 'richiesta rifiutata dal servizio notifiche';
        case 401:
        case 403: return 'le chiavi del server non corrispondono a quelle dell\'iscrizione: disattiva e riattiva le notifiche';
        case 404:
        case 410: return 'il telefono non è più iscritto (app disinstallata o notifiche revocate): l\'iscrizione è stata rimossa, riattiva le notifiche';
        case 413: return 'messaggio troppo lungo';
        case 429: return 'troppi invii ravvicinati, il servizio chiede di aspettare';
        default: return $code >= 500 ? 'il servizio notifiche ha un problema temporaneo' : 'risposta inattesa (codice ' . $code . ')';
    }
}

/* ---------------- Registro degli invii ---------------- */

function ensure_log_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec('CREATE TABLE IF NOT EXISTS ' . t('invii') . ' (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL,
        tipo VARCHAR(16) NOT NULL,
        origine VARCHAR(16) NOT NULL,
        guid VARCHAR(64) NULL,
        numero INT NULL,
        oggetto TEXT NULL,
        iscritti INT NOT NULL DEFAULT 0,
        consegnate INT NOT NULL DEFAULT 0,
        fallite INT NOT NULL DEFAULT 0,
        rimosse INT NOT NULL DEFAULT 0,
        codici VARCHAR(255) NULL,
        errore TEXT NULL,
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $done = true;
}

/**
 * Salva l'esito di un invio. Non deve mai bloccare le notifiche:
 * se il registro non è scrivibile, l'errore viene ignorato.
 */
function log_invio(string $tipo, string $origine, ?array $c, ?array $r, ?string $errore = null): void
{
    try {
        ensure_log_table();
        $st = db()->prepare('INSERT INTO ' . t('invii') . '
            (created_at, tipo, origine, guid, numero, oggetto, iscritti, consegnate, fallite, rimosse, codici, errore)
            VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([
            $tipo, $origine,
            $c['guid'] ?? null, $c['numero'] ?? null, $c['oggetto'] ?? null,
            (int) ($r['iscritti'] ?? 0), (int) ($r['sent'] ?? 0), (int) ($r['failed'] ?? 0), (int) ($r['removed'] ?? 0),
            $r ? json_encode($r['codes'] ?? []) : null,
            $errore,
        ]);
        // tengo gli ultimi 6 mesi
        if (random_int(1, 50) === 1) {
            db()->exec('DELETE FROM ' . t('invii') . ' WHERE created_at < NOW() - INTERVAL 6 MONTH');
        }
    } catch (Throwable $e) {
        // il registro è solo diagnostica
    }
}

/**
 * Confronta il feed con le circolari già viste e notifica quelle nuove.
 * Al primo avvio registra le circolari esistenti senza notificarle.
 */
function process_new_circolari(array $items, string $origine = 'controllo'): array
{
    $tbl = t('circolari');
    $isFirstRun = (int) db()->query("SELECT COUNT(*) FROM $tbl")->fetchColumn() === 0;
    $ins = db()->prepare("INSERT IGNORE INTO $tbl (guid, numero, oggetto, link, pub_date, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    // "prenota" la notifica: solo un processo alla volta può riuscirci
    $claim = db()->prepare("UPDATE $tbl SET notified_at = NOW() WHERE guid = ? AND notified_at IS NULL");

    // dal più vecchio al più recente, così le notifiche arrivano in ordine
    foreach (array_reverse($items) as $c) {
        $ins->execute([$c['guid'], $c['numero'], $c['oggetto'], $c['link'], gmdate('Y-m-d H:i:s', strtotime($c['data']))]);
        if ($isFirstRun && $ins->rowCount() === 1) {
            $claim->execute([$c['guid']]);
        }
    }

    // circolari registrate ma non ancora notificate (anche da un giro precedente interrotto)
    $pending = db()->query("SELECT guid, numero, oggetto, link FROM $tbl
        WHERE notified_at IS NULL AND created_at > NOW() - INTERVAL 2 DAY
        ORDER BY pub_date ASC")->fetchAll();

    $report = [];
    foreach ($pending as $c) {
        $claim->execute([$c['guid']]);
        if ($claim->rowCount() !== 1) {
            continue; // già presa da un altro processo
        }
        $c['numero'] = $c['numero'] === null ? null : (int) $c['numero'];
        try {
            $r = push_to_subscribers(notification_payload($c));
        } catch (Throwable $e) {
            // invio non riuscito: la circolare torna "da notificare" e si riprova al giro successivo
            db()->prepare("UPDATE $tbl SET notified_at = NULL WHERE guid = ?")->execute([$c['guid']]);
            log_invio('circolare', $origine, $c, null, $e->getMessage());
            throw $e;
        }
        log_invio('circolare', $origine, $c, $r);
        $report[] = ['guid' => $c['guid'], 'numero' => $c['numero'], 'oggetto' => $c['oggetto']] + $r;
    }
    return ['first_run' => $isFirstRun, 'new' => $report];
}
