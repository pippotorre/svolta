# App Circolari – Liceo Volta (sVolta)

Diario di sviluppo del progetto. Ogni decisione, modifica o problema va aggiunto qui.

## Obiettivo

Avvisare i genitori quando la scuola pubblica una nuova circolare, con notifica push sul telefono, senza raccogliere numeri di telefono, email o iscrizioni a canali.

Prima fase: genitori di una classe. In seguito, se possibile, estensione a tutta la scuola.

## Fonti

- Pagina circolari: https://liceovolta.eu/istituto/circolari
- Feed RSS: https://api.liceovolta.eu/feed/circolari.rss
- PDF delle circolari: https://demqube.s3.eu-central-1.amazonaws.com/circolari

### Struttura del feed (verificata il 26/10/2026)

- RSS 2.0, generato da DEM QUBE, `ttl` 30 minuti.
- Ogni `item` ha: `title`, `link`, `guid`, `pubDate`, `description`, `content:encoded`, `author`.
- Il numero della circolare sta solo nel titolo: `Circolare n. 51 - Oggetto`.
- `guid` è un identificativo interno (es. 4048), diverso dal numero della circolare: si usa per riconoscere le circolari nuove.
- `link` punta direttamente al PDF su S3 (`.../circolari/2026/51-....pdf`).
- `description` è un estratto troncato: il testo completo è solo nel PDF.

## Decisioni

- **Tipo di app**: PWA (web app installabile), niente App Store / Play Store.
- **Nome**: sVolta. Indirizzo: https://www.entratadisicurezza.com/svolta/
- **Telefoni**: misti iPhone e Android. Su iPhone serve "Aggiungi alla schermata Home" (iOS 16.4+): la guida passo passo è dentro l'app.
- **Privacy**: nessun dato personale. Si salva solo l'iscrizione push anonima del dispositivo. Le circolari lette sono ricordate solo sul telefono.
- **Testo della notifica**: titolo "CIRCOLARE 50", testo = oggetto della circolare. Toccandola si apre direttamente il PDF nell'app.
- **Filtro**: nessun filtro per classe in partenza. Eventuale filtro per anno di corso ("classi terze") da valutare dopo.
- **Gestione**: personale, per ora senza coinvolgimento ufficiale della scuola. Niente logo del liceo.
- **Hosting**: Tophost (PHP 8, MySQL), cartella /svolta. Tophost non ha operazioni pianificate.
- **Schedulazione**: GitHub Actions (repo pippotorre/svolta) chiama `check.php` ogni 10 minuti con un token segreto. Un secondo job mensile tiene attivo il repository (GitHub sospende le azioni pianificate dopo 60 giorni di inattività nei repo pubblici).
- **Rete di sicurezza**: anche `feed.php`, quando rilegge il feed, controlla le novità e notifica. Se GitHub è in ritardo, basta che un genitore apra l'app.
- **Notifiche push senza Composer**: VAPID e cifratura aes128gcm scritte in PHP puro (solo openssl e curl), per non dover caricare librerie su Tophost e funzionare anche senza l'estensione gmp.
- **Lettore PDF**: PDF.js 3.11.174 da cdnjs (la versione 4 richiede Safari 17.4+ ed escluderebbe iPhone con iOS 16.4–17.3). Esecuzione di codice nei PDF disattivata (`isEvalSupported: false`).
- **PDF via ponte**: `pdf.php` scarica i PDF dalla cartella delle circolari su S3 e li serve dall'app (evita problemi CORS; accetta solo URL della cartella circolari; cache degli ultimi 200 PDF).
- **Grafica**: carta fredda e inchiostro blu; numero della circolare grande come protagonista; le circolari da leggere hanno un tratto di evidenziatore giallo. Caratteri Atkinson Hyperlegible (testo, pensato per la leggibilità) e Barlow Condensed (numeri). Tema scuro automatico.
- **"Da leggere"**: al primo avvio solo le circolari degli ultimi 3 giorni risultano da leggere.

## Architettura

```
GitHub Actions (ogni 10 min) ──► api/check.php ──► feed RSS della scuola
                                       ├─ nuova circolare ──► push a tutti i dispositivi
                                       └─ MySQL: svolta_circolari, svolta_subscriptions, svolta_settings

Telefono ──► index.html + app.js + sw.js ──► api/feed.php, api/pdf.php, api/subscribe.php
```

Tabelle MySQL (prefisso `svolta_`):
- `settings`: chiavi VAPID, limiti delle notifiche di prova
- `subscriptions`: endpoint push + chiavi del dispositivo, contatore errori
- `circolari`: guid, numero, oggetto, link, data, quando è stata notificata

Protezione dai doppioni: ogni circolare viene "prenotata" con un UPDATE condizionato (`notified_at IS NULL`) prima dell'invio, quindi due controlli contemporanei non mandano la stessa notifica due volte.

## Verifiche fatte

- Cifratura e firma delle notifiche: decifrate correttamente da un'implementazione indipendente (Python `cryptography`), firma JWT valida, invio in parallelo a 5 dispositivi + scarto di un'iscrizione rotta.
- Parsing del feed su campione con la struttura reale, incluse varianti ("n.48" senza spazio, avvisi senza numero).
- Interfaccia su Chromium simulando iPhone e Android, tema chiaro e scuro: elenco, ricerca, guida iPhone, pannello notifiche, apertura lettore e messaggio di riserva. Nessun errore JavaScript.
- Sintassi PHP verificata (`php -l`) su tutti i file.

## Punti aperti

- [ ] Prima installazione su Tophost e verifica di install.php
- [ ] Verifica del lettore PDF su telefono reale (da qui la CDN di PDF.js non era raggiungibile)
- [ ] Prova di notifica reale su Android e su iPhone
- [x] Volantino per i genitori (PDF con link cliccabile + testo per WhatsApp)
- [ ] Eventuale filtro per anno di corso
- [ ] Eventuale coinvolgimento della scuola per l'estensione a tutto l'istituto

## Log di sviluppo

- **26/10/2026** – Avvio progetto. Definiti requisiti e architettura.
- **26/10/2026** – Letta la struttura del feed. Scelte definitive: PHP 8 su Tophost in /svolta, GitHub Actions per la schedulazione, nessuna libreria esterna lato server.
- **26/10/2026** – Prima versione completa (v1): PWA con elenco, ricerca, lettore PDF, notifiche, guida iPhone; backend PHP; workflow GitHub; README di installazione. Corretto in corsa il tema scuro (testata e evidenziatore sul numero).
- **26/10/2026** – Repository GitHub reso pubblico (Actions gratuito senza limiti). Creato `config.php` con token generato; restano da inserire i dati del database Tophost. `config.php` resta fuori dal repo (.gitignore).
- **26/10/2026** – Deciso di restare su MySQL (meglio di file JSON in vista dell'estensione a tutta la scuola). Il database è condiviso con eventuali app future: ogni app usa un proprio prefisso, sVolta usa `svolta_`. Aggiunto `sql/svolta_init.sql` da importare in phpMyAdmin (rieseguibile, non cancella nulla); `install.php` resta necessario per generare le chiavi VAPID. Definizioni delle tabelle allineate tra script SQL e install.php (utf8mb4_unicode_ci).
- **26/10/2026** – Creato il volantino `flyer/sVolta-circolari.pdf` (sorgente `flyer/flyer.html`): formato verticale da telefono (110×218 mm), link cliccabile all'app, anteprima della notifica, istruzioni iPhone e Android, nessuna firma. Scelto il PDF perché su WhatsApp le immagini non hanno link cliccabili; il link è ripetuto anche nel testo del messaggio. Carattere Inter (quelli dell'app non erano disponibili per l'incorporamento).
- **26/10/2026** – Segnalato: su Android "Attiva le notifiche" non fa nulla. Il server non è raggiungibile dall'ambiente di Claude, quindi causa ancora da confermare (ipotesi: install.php non eseguito, oppure Chrome che nasconde la richiesta di permesso con l'icona della campanella). Modifiche v2: ogni passaggio dell'attivazione mostra il suo stato e, in caso di problema, un messaggio con la causa (permesso, server, servizio push, registrazione), con limiti di tempo; aggiunto il pulsante "Installa l'app" su Android (evento beforeinstallprompt, come per turet) e un suggerimento manuale se Chrome non lo offre. Cache del service worker portata a svolta-v2.
- **26/10/2026** – Android: attivazione notifiche e installazione confermate funzionanti dopo la v2. Prova su iPhone prevista in serata.
- **26/10/2026** – Schermata di avvio blu inchiostro (#13294b) con icona: su Android cambiando `background_color` nel manifest; su iPhone con 11 immagini `apple-touch-startup-image` (una per misura di schermo, da iPhone SE a 16 Pro Max) generate da `tools/splash.py`. Cache del service worker svolta-v3. Nota: Android aggiorna l'app installata in modo automatico ma non immediato; per vedere subito il nuovo avvio si può disinstallare e reinstallare.
- **26/10/2026** – Pacchetti separati: `svolta.zip` = solo la cartella `svolta/` pronta per Tophost (contenuto di `www/`, senza config.php, da aggiungere a mano in `svolta/api/inc/`); `svolta-progetto.zip` = progetto completo per GitHub (README, sql, flyer, tools, workflow).
- **26/10/2026** – Volantino rivisto: titolo "appena pubblicate", tolta la didascalia sotto la notifica, istruzioni allineate all'app (Android: prima "Installa l'app", poi notifiche e Consenti; iPhone: indicazione per iOS 26 dove Condividi sta nel menu ···, Consenti finale). Stessa indicazione iOS 26 aggiunta alla guida dentro l'app (app.js).
- **26/10/2026** – Seconda versione del volantino per la stampa: `flyer/sVolta-circolari-A5-QR.pdf` (sorgente `flyer/flyer-a5-qr.html`), formato A5 su fondo bianco, QR code vettoriale da 48 mm (`flyer/qr-svolta.svg`, correzione d'errore livello Q, generato e verificato con OpenCV: si legge anche dalla pagina renderizzata), indirizzo scritto per esteso, istruzioni iPhone e Android affiancate con il primo passo adattato al QR (fotocamera).
- **27/10/2026** – Primo commit sul repository pubblico https://github.com/pippotorre/svolta (branch `main`): app (`www/`), script SQL, workflow GitHub Actions, volantini, strumenti e diario. Escluso `config.php` (verificato che token e password non compaiono in nessun file pubblicato). Da fare su GitHub: secret `CRON_TOKEN` e permessi di scrittura per le Actions.
