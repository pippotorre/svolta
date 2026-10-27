# sVolta – circolari del Liceo Volta

Web app (PWA) che mostra le circolari del Liceo Volta di Torino e manda una notifica sul telefono quando ne viene pubblicata una nuova. Nessuna registrazione: non si raccolgono nomi, numeri o email.

- App: https://www.entratadisicurezza.com/svolta/
- Fonte: https://api.liceovolta.eu/feed/circolari.rss

## Come funziona

```
GitHub Actions (ogni 10 min) ──► check.php su Tophost ──► legge il feed RSS della scuola
                                        │
                                        ├─ circolare nuova? ──► notifica push a tutti i telefoni iscritti
                                        └─ salva in MySQL cosa ha già notificato

Telefono ──► app (index.html) ──► feed.php (elenco) / pdf.php (PDF nel lettore integrato)
```

Quando qualcuno apre l'app e il feed viene riletto, il server controlla anche le novità: se GitHub è in ritardo, le notifiche partono lo stesso.

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
    inc/config.sample.php modello di configurazione
    inc/lib.php           funzioni comuni
    inc/webpush.php       invio notifiche (senza librerie esterne)
.github/workflows/        controllo ogni 10 minuti + mantenimento mensile
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
Copia il risultato: lo userai al passo 3 e al passo 6.

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

### 6. Attiva il controllo su GitHub
1. Il codice è già nel repository `pippotorre/svolta`, workflow compresi. `www/api/inc/config.php` non c'è e non va mai aggiunto: il repository è pubblico (il file `.gitignore` lo esclude).
2. Nel repository vai su *Settings › Secrets and variables › Actions › New repository secret*: nome `CRON_TOKEN`, valore il token del passo 1.
3. In *Settings › Actions › General › Workflow permissions* scegli *Read and write permissions* (serve al job mensile).
4. Vai su *Actions › Controllo circolari › Run workflow* per una prova: nel log deve comparire `"ok":true`.

**Repository pubblico o privato?** Il codice non contiene password, quindi può essere pubblico: in quel caso GitHub Actions è gratuito senza limiti. Con un repository privato i minuti gratuiti (2.000 al mese) non bastano per un controllo ogni 10 minuti: porta la frequenza a 30 minuti cambiando `*/10` in `*/30` in `controllo.yml`.

### 7. Prova sul telefono
- **Android**: apri il link in Chrome, tocca *Attiva le notifiche*, poi *Prova*.
- **iPhone** (iOS 16.4 o successivo): apri il link in Safari, *Condividi › Aggiungi alla schermata Home*, apri l'app dall'icona, tocca *Attiva le notifiche*, poi *Prova*.

## Manutenzione

- Lo stato del servizio si vede nel log di GitHub Actions: ogni esecuzione riporta circolari lette, dispositivi iscritti e notifiche inviate.
- I telefoni che disinstallano l'app o bloccano le notifiche vengono tolti automaticamente.
- Per forzare un controllo: *Actions › Controllo circolari › Run workflow*.
- Le chiavi delle notifiche si creano una volta sola in `install.php`. Non cancellare la tabella `svolta_settings`: tutti i telefoni dovrebbero riattivare le notifiche.
