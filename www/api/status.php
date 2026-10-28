<?php
/**
 * Pagina di stato (solo per chi gestisce l'app): ultimo controllo, telefoni iscritti,
 * esito degli ultimi invii, ultime circolari.
 * Si apre dal browser: status.php?token=IL_TUO_CRON_TOKEN
 */
require __DIR__ . '/inc/lib.php';
require_token();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function quando(?string $dt, string $adesso): string
{
    if (!$dt) {
        return '—';
    }
    $sec = strtotime($adesso) - strtotime($dt);
    if ($sec < 60) $rel = 'meno di un minuto fa';
    elseif ($sec < 3600) $rel = floor($sec / 60) . ' min fa';
    elseif ($sec < 86400) $rel = floor($sec / 3600) . ' h fa';
    else $rel = floor($sec / 86400) . ' g fa';
    return date('d/m H:i', strtotime($dt)) . ' <span class="soft">· ' . $rel . '</span>';
}

function servizio(string $endpoint): string
{
    $host = (string) parse_url($endpoint, PHP_URL_HOST);
    if (str_contains($host, 'fcm.googleapis.com')) return 'Android / Chrome';
    if (str_contains($host, 'push.apple.com')) return 'iPhone / Safari';
    if (str_contains($host, 'mozilla.com')) return 'Firefox';
    if (str_contains($host, 'notify.windows.com')) return 'Edge / Windows';
    return $host;
}

function codici_html(?string $json): string
{
    $codes = json_decode((string) $json, true);
    if (!$codes) {
        return '—';
    }
    $out = [];
    foreach ($codes as $code => $n) {
        $code = (int) $code;
        $cls = ($code >= 200 && $code < 300) ? 'ok' : 'ko';
        $out[] = '<span class="' . $cls . '" title="' . h(push_code_meaning($code)) . '">' . $n . '× ' . ($code === 0 ? 'nessuna risposta' : ($code === -1 ? 'non valida' : $code)) . '</span>';
    }
    return implode(' ', $out);
}

ensure_log_table();
$adesso = (string) db()->query('SELECT NOW()')->fetchColumn();
$ultimo = json_decode((string) setting_get('ultimo_controllo'), true);
$subs = db()->query('SELECT id, endpoint, created_at, last_ok_at, fail_count FROM ' . t('subscriptions') . ' ORDER BY created_at DESC')->fetchAll();
$invii = db()->query('SELECT * FROM ' . t('invii') . ' ORDER BY id DESC LIMIT 40')->fetchAll();
$circ = db()->query('SELECT guid, numero, oggetto, pub_date, created_at, notified_at FROM ' . t('circolari') . ' ORDER BY pub_date DESC LIMIT 15')->fetchAll();

$ritardo = $ultimo ? (time() - strtotime($ultimo['ora'])) : null;
$controlloOk = $ultimo && ($ultimo['esito'] ?? '') === 'ok' && $ritardo !== null && $ritardo < 30 * 60;
$nonNotificate = array_filter($circ, fn($c) => $c['notified_at'] === null);
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>sVolta · stato</title>
<style>
  :root { --paper:#eef2f6; --sheet:#fff; --ink:#13294b; --soft:#4b5b74; --rule:#d6dee8; --ok:#1d7a46; --ko:#a3261b; --warn:#8a5a00; --band:#13294b; }
  @media (prefers-color-scheme: dark) { :root { --paper:#0d1726; --sheet:#15223a; --ink:#e7edf6; --soft:#9fb0c8; --rule:#243651; --ok:#6fd39b; --ko:#ff8a7a; --warn:#f0c060; --band:#0a1a33; } }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--paper); color:var(--ink); font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
  header { background:var(--band); color:#eef2f6; padding:18px 16px; }
  header h1 { margin:0; font-size:22px; }
  header p { margin:4px 0 0; opacity:.8; font-size:13px; }
  main { max-width:900px; margin:0 auto; padding:0 16px 40px; }
  section { background:var(--sheet); border-radius:12px; padding:14px 16px; margin-top:16px; }
  h2 { font-size:16px; margin:0 0 10px; }
  .big { font-size:17px; font-weight:600; }
  .ok { color:var(--ok); } .ko { color:var(--ko); } .warn { color:var(--warn); } .soft { color:var(--soft); font-size:13px; }
  .tw { overflow-x:auto; -webkit-overflow-scrolling:touch; }
  table { border-collapse:collapse; width:100%; font-size:13.5px; }
  th, td { text-align:left; padding:7px 8px; border-bottom:1px solid var(--rule); vertical-align:top; }
  th { color:var(--soft); font-weight:600; white-space:nowrap; }
  td.n { text-align:right; font-variant-numeric:tabular-nums; }
  .rows { list-style:none; margin:0; padding:0; }
  .rows li { padding:9px 0; border-bottom:1px solid var(--rule); }
  .rows li:last-child { border-bottom:0; }
  .rows .l1 { font-weight:600; }
  .rows .l2 { font-size:13.5px; margin-top:2px; }
  .rows .l3 { font-size:13px; margin-top:2px; }
  .legend { margin:10px 0 0; padding-left:18px; color:var(--soft); font-size:13px; }
</style>
</head>
<body>
<header>
  <h1>sVolta · stato del servizio</h1>
  <p>Ora del database: <?= h(date('d/m/Y H:i', strtotime($adesso))) ?> · pagina riservata, non condividere l'indirizzo</p>
</header>
<main>

<section>
  <h2>Controllo automatico</h2>
  <?php if (!$ultimo): ?>
    <p class="big warn">Nessun controllo registrato finora.</p>
    <p class="soft">Il registro parte da questo aggiornamento: entro 10 minuti dovrebbe comparire la prima esecuzione di cron-job.org.</p>
  <?php else: ?>
    <p class="big <?= $controlloOk ? 'ok' : 'ko' ?>">
      <?= $controlloOk ? 'Funziona' : 'Attenzione' ?>: ultimo controllo <?= quando(date('Y-m-d H:i:s', strtotime($ultimo['ora'])), date('Y-m-d H:i:s')) ?>
    </p>
    <p class="soft">Esito: <?= h($ultimo['esito'] ?? '') ?><?php if (isset($ultimo['circolari_nel_feed'])): ?> · <?= (int) $ultimo['circolari_nel_feed'] ?> circolari nel feed · <?= (int) $ultimo['nuove'] ?> nuove in quel giro<?php endif; ?></p>
    <?php if (!$controlloOk && $ritardo !== null && $ritardo >= 30 * 60): ?>
      <p class="ko">Da più di 30 minuti non arrivano controlli: verifica il job su cron-job.org.</p>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($nonNotificate): ?>
    <p class="ko">Circolari registrate ma non ancora notificate: <?= count($nonNotificate) ?> (vedi sotto).</p>
  <?php endif; ?>
</section>

<section>
  <h2>Telefoni iscritti: <?= count($subs) ?></h2>
  <?php if (!$subs): ?>
    <p class="warn">Nessun telefono iscritto. Le notifiche non hanno destinatari.</p>
  <?php else: ?>
  <ul class="rows">
    <?php foreach ($subs as $s): ?>
    <li>
      <div class="l1">#<?= (int) $s['id'] ?> · <?= h(servizio($s['endpoint'])) ?><?php if ($s['fail_count'] > 0): ?> · <span class="ko"><?= (int) $s['fail_count'] ?> errori di fila</span><?php endif; ?></div>
      <div class="l2">Ultima consegna: <?= $s['last_ok_at'] ? quando($s['last_ok_at'], $adesso) : '<span class="soft">mai</span>' ?></div>
      <div class="l3 soft">Iscritto <?= quando($s['created_at'], $adesso) ?></div>
    </li>
    <?php endforeach; ?>
  </ul>
  <p class="soft">Nessun dato personale: per ogni telefono si conosce solo il tipo di servizio notifiche. Un iscritto con errori di fila viene tolto dopo 20 invii falliti; se il telefono ha disinstallato l'app viene tolto subito.</p>
  <?php endif; ?>
</section>

<section>
  <h2>Ultimi invii</h2>
  <?php if (!$invii): ?>
    <p class="soft">Ancora nessun invio registrato. Da ora compaiono qui le notifiche delle circolari e le prove fatte dal pulsante «Prova».</p>
  <?php else: ?>
  <ul class="rows">
    <?php foreach ($invii as $i): ?>
    <li>
      <div class="l1"><?= $i['tipo'] === 'prova' ? 'Prova' : 'Circolare ' . h($i['numero'] ?? '') ?> <span class="soft">· <?= h(['controllo' => 'da cron-job', 'app' => "dall'apertura dell'app", 'prova' => 'pulsante Prova'][$i['origine']] ?? $i['origine']) ?></span></div>
      <?php if ($i['tipo'] !== 'prova'): ?><div class="l3 soft"><?= h(mb_strimwidth((string) $i['oggetto'], 0, 80, '…')) ?></div><?php endif; ?>
      <div class="l2"><?= quando($i['created_at'], $adesso) ?></div>
      <div class="l2"><?= (int) $i['iscritti'] ?> iscritti · <span class="ok"><?= (int) $i['consegnate'] ?> consegnate</span> · <span class="<?= $i['fallite'] ? 'ko' : '' ?>"><?= (int) $i['fallite'] ?> fallite</span> · <?= (int) $i['rimosse'] ?> rimosse</div>
      <div class="l3">Risposte: <?= codici_html($i['codici']) ?></div>
      <?php if ($i['errore']): ?><div class="l3 ko">Errore: <?= h($i['errore']) ?></div><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <ul class="legend">
    <li><b>201</b>: consegnata al servizio notifiche (Google o Apple), che la recapita al telefono.</li>
    <li><b>404 / 410</b>: telefono non più iscritto (app disinstallata o notifiche revocate), iscrizione rimossa.</li>
    <li><b>403</b>: chiavi non corrispondenti, il telefono deve disattivare e riattivare le notifiche.</li>
    <li><b>Nessuna risposta</b> o <b>5xx</b>: problema temporaneo di rete o del servizio.</li>
  </ul>
  <?php endif; ?>
</section>

<section>
  <h2>Ultime circolari</h2>
  <ul class="rows">
    <?php foreach ($circ as $c): ?>
    <li>
      <div class="l1"><?= h($c['numero'] ?? '—') ?> · <?= h(mb_strimwidth((string) $c['oggetto'], 0, 80, '…')) ?></div>
      <div class="l3">Pubblicata <?= h(date('d/m H:i', strtotime($c['pub_date'] . ' UTC'))) ?> · vista dal server <?= h(date('d/m H:i', strtotime($c['created_at']))) ?> · <?= $c['notified_at'] ? 'notificata ' . h(date('d/m H:i', strtotime($c['notified_at']))) : '<span class="ko">non notificata</span>' ?></div>
    </li>
    <?php endforeach; ?>
  </ul>
  <p class="soft">Le circolari già presenti al momento dell'installazione risultano «notificate» senza che sia partito alcun avviso: è voluto, per non mandare decine di notifiche il primo giorno.</p>
</section>

</main>
</body>
</html>
