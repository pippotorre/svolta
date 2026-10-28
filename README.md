# sVolta – circolari del Liceo Volta

Web app (PWA) che mostra le circolari del Liceo Volta di Torino e manda una notifica sul telefono quando ne viene pubblicata una nuova. Nessuna registrazione: non si raccolgono nomi, numeri o email.

- App: https://www.entratadisicurezza.com/svolta/
- Fonte: https://api.liceovolta.eu/feed/circolari.rss

## Come funziona

```
cron-job.org (ogni 10 min) ──► check.php su Tophost ──► legge il feed RSS della scuola
                                      │
                                      ├─ circolare nuova? ──► notifica push a tutti i telefoni iscritti
                                      └─ salva in MySQL cosa ha già notificato

Telefono ──► app (index.html) ──► feed.php (elenco) / pdf.php (PDF nel lettore integrato)
```

Quando qualcuno apre l'app e il feed viene riletto, il server controlla anche le novità: se il controllo programmato salta un giro, le notifiche partono lo stesso.

Tophost non ha operazioni pianificate e rifiuta le chiamate in arrivo da GitHub Actions: per questo il controllo è affidato a cron-job.org. Il repository GitHub serve solo come archivio del codice.

## Struttura

```
www/                      ← va caricato su Tophost nella cartella /svolta
  index.html, style.css, app.js, sw.js, manifest.webmanifest, icons/
  api/
    feed.php              elenco circolari (JSON)
    check.php             controllo schedulato (protetto da token)
    install.php           installazione una tantum (protetto da token)
    subscribe.php         iscrizione anonima alle notifiche
    unsubscribe.php       disiscrizione
    test.php              notifica di prova al solo telefono che la chiede
    pdf.php               ponte per i PDF della scuola
    vapid.php             chiave pubblica per le notifiche
    status.php            pagina di stato per chi gestisce l'app (protetta da token)
    inc/config.sample.php modello di configurazione
    inc/lib.php           funzioni comuni
    inc/webpush.php       invio notifiche (senza librerie esterne)
sql/svolta_init.sql       creazione tabelle da importare in phpMyAdmin
circolari-app.md          diario di sviluppo
```

Requisiti server: PHP 8.0 o successivo con openssl, curl, pdo_mysql, simplexml; MySQL; HTTPS.

## Installazione

### 1. Prepara il token segreto
Serve una stringa lunga e casuale. Sul Mac apri Terminale e scrivi:
```
openssl rand -hex 24
```
Copia il risultato: lo userai al passo 3, al passo 5 e al passo 6.

### 2. Crea il database su Tophost
Dal pannello Tophost, sezione Database MySQL, annota: host, nome del database, utente e password.

Poi apri phpMyAdmin, seleziona il database a sinistra, vai su *Importa*, scegli `sql/svolta_init.sql` e premi *Esegui*. Vengono create tre tabelle, tutte con il prefisso `svolta_`:

| Tabella | Contenuto |
|---|---|
| `svolta_settings` | chiavi delle notifiche e impostazioni |
| `svolta_subscriptions` | telefoni iscritti (solo dati tecnici anonimi) |
| `svolta_circolari` | circolari già viste e quando sono state notificate |

Il database può ospitare anche altre app: ognuna userà un prefisso suo (per esempio `nomeapp_`), senza interferire con queste tabelle. Il prefisso di sVolta si cambia in `config.php` (`db_prefix`).

### 3. Crea il file di configurazione
Nella cartella `www/api/inc/` duplica `config.sample.php` e chiama la copia `config.php`.
Compila i dati del database e incolla il token in `cron_token`.
**Non caricare `config.php` su GitHub**: deve stare solo sul server.

### 4. Carica i file su Tophost
Con il programma FTP che usi, carica **il contenuto** della cartella `www/` dentro la cartella `svolta` del sito, così che `index.html` risponda a https://www.entratadisicurezza.com/svolta/.
Controlla che anche i file `.htaccess` siano stati caricati (sul Mac sono nascosti: nel Finder premi Cmd+Maiusc+punto per vederli).

### 5. Installa
Apri nel browser:
```
https://www.entratadisicurezza.com/svolta/api/install.php?token=IL_TUO_TOKEN
```
Deve comparire `"esito": "Installazione completata"`. Questo passo serve anche se hai importato lo script SQL: crea le chiavi per le notifiche, che in SQL non si possono generare. Le circolari già pubblicate vengono registrate senza mandare notifiche.
Se compare un errore sul database, ricontrolla i dati in `config.php`.

### 6. Attiva il controllo ogni 10 minuti (cron-job.org)
1. Accedi a https://cron-job.org e scegli *Create cronjob*.
2. **Title**: `sVolta – controllo circolari`.
3. **URL**: `https://www.entratadisicurezza.com/svolta/api/check.php?token=IL_TUO_TOKEN`
4. **Execution schedule**: *Every 10 minutes*.
5. In *Notifications* attiva l'avviso via email quando l'esecuzione fallisce (e quando torna a funzionare).
6. Salva, poi usa *Test run*: la risposta deve contenere `"ok":true`.

Il token compare nell'indirizzo, che resta visibile solo nel tuo account cron-job.org. Se cambi il token in `config.php`, aggiornalo anche lì.

### Codice su GitHub
Il codice è nel repository pubblico `pippotorre/svolta`. `www/api/inc/config.php` non c'è e non va mai aggiunto (il file `.gitignore` lo esclude).

### 7. Prova sul telefono
- **Android**: apri il link in Chrome, tocca *Attiva le notifiche*, poi *Prova*.
- **iPhone** (iOS 16.4 o successivo): apri il link in Safari, *Condividi › Aggiungi alla schermata Home*, apri l'app dall'icona, tocca *Attiva le notifiche*, poi *Prova*.

## Manutenzione

- **Pagina di stato**: `https://www.entratadisicurezza.com/svolta/api/status.php?token=IL_TUO_TOKEN`. Mostra l'ultimo controllo di cron-job.org, i telefoni iscritti (solo il tipo: Android o iPhone), l'esito di ogni invio (consegnate, fallite, rimosse, con il codice di risposta del servizio notifiche) e le ultime circolari con l'ora in cui sono state notificate. Non condividere l'indirizzo: contiene il token.
- Il registro degli invii (tabella `svolta_invii`) si crea da solo al primo invio e conserva gli ultimi 6 mesi.
- Anche la cronologia del job su cron-job.org riporta, per ogni esecuzione, circolari lette, dispositivi iscritti e notifiche inviate.
- I telefoni che disinstallano l'app o bloccano le notifiche vengono tolti automaticamente.
- Per forzare un controllo: *Test run* su cron-job.org, oppure apri nel browser l'indirizzo di `check.php` con il token.
- Le chiavi delle notifiche si creano una volta sola in `install.php`. Non cancellare la tabella `svolta_settings`: tutti i telefoni dovrebbero riattivare le notifiche.
