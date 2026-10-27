/* sVolta – circolari. Nessun framework, nessun dato personale. */
(() => {
  'use strict';

  const PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
  const $ = (s) => document.querySelector(s);

  const els = {
    notify: $('#notify'),
    list: $('#lista'),
    status: $('#status'),
    q: $('#q'),
    refresh: $('#refresh'),
    markAll: $('#mark-all'),
    viewer: $('#viewer'),
    pages: $('#pages'),
    vTitle: $('#viewer-title'),
    vSub: $('#viewer-sub'),
    vDownload: $('#viewer-download'),
  };

  let items = [];
  let swReg = null;

  /* ---------------- memoria locale (solo su questo telefono) ---------------- */

  const store = {
    get(k, d) {
      try { const v = localStorage.getItem('svolta:' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; }
    },
    set(k, v) {
      try { localStorage.setItem('svolta:' + k, JSON.stringify(v)); } catch (e) { /* storage non disponibile */ }
    },
  };
  let read = new Set(store.get('read', []));
  let initialized = store.get('init', false);

  function saveRead() {
    // conservo solo le circolari ancora presenti nel feed
    const alive = new Set(items.map((c) => c.guid));
    store.set('read', [...read].filter((g) => alive.has(g)));
  }

  /* ---------------- ambiente ---------------- */

  const ua = navigator.userAgent;
  const isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

  /* ---------------- elenco circolari ---------------- */

  const fmtDay = new Intl.DateTimeFormat('it-IT', { day: 'numeric', month: 'long' });
  const fmtDayYear = new Intl.DateTimeFormat('it-IT', { day: 'numeric', month: 'long', year: 'numeric' });
  const fmtTime = new Intl.DateTimeFormat('it-IT', { hour: '2-digit', minute: '2-digit' });

  function when(iso) {
    const d = new Date(iso);
    const now = new Date();
    const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const diffDays = Math.floor((startToday - new Date(d.getFullYear(), d.getMonth(), d.getDate())) / 86400000);
    if (diffDays === 0) return 'Oggi alle ' + fmtTime.format(d);
    if (diffDays === 1) return 'Ieri alle ' + fmtTime.format(d);
    if (d.getFullYear() === now.getFullYear()) return fmtDay.format(d);
    return fmtDayYear.format(d);
  }

  function isNew(c) { return !read.has(c.guid); }

  function render() {
    const q = els.q.value.trim().toLowerCase();
    const list = q
      ? items.filter((c) => String(c.numero ?? '') === q.replace(/^n\.?\s*/, '') || c.oggetto.toLowerCase().includes(q))
      : items;

    els.list.replaceChildren(...list.map((c) => {
      const li = document.createElement('li');
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'item' + (isNew(c) ? ' is-new' : '');
      b.dataset.guid = c.guid;

      const num = document.createElement('span');
      num.className = 'num' + (c.numero === null ? ' small' : '');
      num.textContent = c.numero ?? '—';
      num.setAttribute('aria-label', c.numero === null ? 'Circolare' : 'Circolare numero ' + c.numero);

      const body = document.createElement('span');
      body.className = 'body';
      const ogg = document.createElement('span');
      ogg.className = 'ogg';
      ogg.textContent = c.oggetto;
      const meta = document.createElement('span');
      meta.className = 'meta';
      if (isNew(c)) {
        const tag = document.createElement('span');
        tag.className = 'new-tag';
        tag.textContent = 'Da leggere';
        meta.append(tag, ' · ');
      }
      meta.append(when(c.data));
      body.append(ogg, meta);
      b.append(num, body);
      li.append(b);
      return li;
    }));

    if (!list.length && items.length) {
      const li = document.createElement('li');
      li.className = 'empty';
      li.textContent = 'Nessuna circolare contiene “' + els.q.value.trim() + '”. Prova con un\'altra parola o con il numero.';
      els.list.append(li);
    }

    const newCount = items.filter(isNew).length;
    els.markAll.hidden = newCount === 0;
    if ('setAppBadge' in navigator) {
      (newCount ? navigator.setAppBadge(newCount) : navigator.clearAppBadge()).catch(() => {});
    }
  }

  function skeleton() {
    els.list.replaceChildren(...Array.from({ length: 5 }, () => {
      const li = document.createElement('li');
      li.innerHTML = '<div class="item skeleton" aria-hidden="true"><span class="num"></span><span class="body"><span class="ogg"></span><span class="meta"></span></span></div>';
      return li;
    }));
  }

  async function loadFeed({ quiet = false } = {}) {
    if (!items.length) skeleton();
    els.refresh.classList.add('spin');
    try {
      const r = await fetch('api/feed.php', { cache: 'no-store' });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const data = await r.json();
      if (!Array.isArray(data.items) || !data.items.length) throw new Error('vuoto');
      items = data.items;

      if (!initialized) {
        // primo avvio: "da leggere" solo le circolari degli ultimi 3 giorni
        const limit = Date.now() - 3 * 86400000;
        items.forEach((c) => { if (new Date(c.data).getTime() < limit) read.add(c.guid); });
        initialized = true;
        store.set('init', true);
      }
      saveRead();
      store.set('cache', items);
      els.status.textContent = data.stale ? 'Il sito della scuola non risponde: mostro l\'ultimo elenco salvato.' : '';
    } catch (e) {
      const cached = store.get('cache', []);
      if (cached.length) {
        items = cached;
        els.status.textContent = 'Sei offline o il sito non risponde: mostro l\'ultimo elenco salvato.';
      } else {
        items = [];
        els.list.replaceChildren();
        els.status.innerHTML = '<span class="err">Non riesco a caricare le circolari. Controlla la connessione e tocca Aggiorna in alto.</span>';
      }
    } finally {
      els.refresh.classList.remove('spin');
    }
    render();
    if (!quiet) openFromUrl();
  }

  /* ---------------- lettore PDF ---------------- */

  let pdfjsReady = null;
  let currentDoc = null;
  let zoom = 1;
  let renderToken = 0;
  let openGuid = null;

  function loadPdfJs() {
    if (!pdfjsReady) {
      pdfjsReady = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = PDFJS + 'pdf.min.js';
        s.onload = () => {
          window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js';
          resolve(window.pdfjsLib);
        };
        s.onerror = () => { pdfjsReady = null; reject(new Error('pdf.js non caricato')); };
        document.head.append(s);
      });
    }
    return pdfjsReady;
  }

  function note(html) {
    els.pages.innerHTML = '<p class="note">' + html + '</p>';
  }

  async function openCircolare(guid, { push = true } = {}) {
    const c = items.find((x) => x.guid === guid);
    if (!c) return;

    openGuid = guid;
    read.add(guid);
    saveRead();
    render();

    els.vTitle.textContent = c.numero !== null ? 'Circolare ' + c.numero : 'Circolare';
    els.vSub.textContent = c.oggetto;
    els.vDownload.href = c.link;
    els.viewer.hidden = false;
    document.body.style.overflow = 'hidden';
    if (push) history.pushState({ viewer: guid }, '', '?c=' + encodeURIComponent(guid));
    els.pages.scrollTop = 0;
    els.pages.focus({ preventScroll: true });
    note('Apro la circolare…');

    zoom = 1;
    const token = ++renderToken;
    try {
      const pdfjs = await loadPdfJs();
      const src = 'api/pdf.php?u=' + encodeURIComponent(c.link);
      const doc = await pdfjs.getDocument({ url: src, isEvalSupported: false }).promise;
      if (token !== renderToken) return;
      currentDoc = doc;
      await drawPages(token);
    } catch (e) {
      if (token !== renderToken) return;
      note('Non riesco a mostrare il PDF qui.<br><a href="' + c.link + '" target="_blank" rel="noopener">Apri la circolare in una nuova finestra</a>');
    }
  }

  async function drawPages(token) {
    const doc = currentDoc;
    const width = Math.min(els.pages.clientWidth - 16, 900) * zoom;
    const dpr = Math.min(window.devicePixelRatio || 1, 3);
    els.pages.replaceChildren();

    for (let n = 1; n <= doc.numPages; n++) {
      const page = await doc.getPage(n);
      if (token !== renderToken) return;
      const base = page.getViewport({ scale: 1 });
      const scale = width / base.width;
      const vp = page.getViewport({ scale: scale * dpr });

      const wrap = document.createElement('div');
      wrap.className = 'page';
      wrap.style.width = Math.round(width) + 'px';
      const canvas = document.createElement('canvas');
      canvas.width = Math.floor(vp.width);
      canvas.height = Math.floor(vp.height);
      canvas.setAttribute('role', 'img');
      canvas.setAttribute('aria-label', 'Pagina ' + n + ' di ' + doc.numPages);
      wrap.append(canvas);
      els.pages.append(wrap);
      await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
    }
  }

  async function setZoom(z) {
    if (!currentDoc) return;
    const old = zoom;
    zoom = Math.max(1, Math.min(3, z));
    if (zoom === old) return;
    const ratio = els.pages.scrollTop / Math.max(1, els.pages.scrollHeight);
    await drawPages(++renderToken);
    els.pages.scrollTop = ratio * els.pages.scrollHeight;
  }

  function closeViewer() {
    renderToken++;
    els.viewer.hidden = true;
    els.pages.replaceChildren();
    document.body.style.overflow = '';
    if (currentDoc) { currentDoc.destroy(); currentDoc = null; }
    const last = [...els.list.querySelectorAll('.item[data-guid]')].find((b) => b.dataset.guid === openGuid);
    if (last) last.focus({ preventScroll: true });
  }

  function openFromUrl() {
    const guid = new URLSearchParams(location.search).get('c');
    if (guid && items.some((c) => c.guid === guid) && els.viewer.hidden) {
      history.replaceState({}, '', location.pathname);
      history.pushState({ viewer: guid }, '', '?c=' + encodeURIComponent(guid));
      openCircolare(guid, { push: false });
    }
  }

  /* ---------------- notifiche ---------------- */

  function urlB64ToUint8Array(b64) {
    const pad = '='.repeat((4 - (b64.length % 4)) % 4);
    const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (ch) => ch.charCodeAt(0));
  }

  async function postJSON(url, data) {
    const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
    const body = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(body.error || 'Errore ' + r.status);
    return body;
  }

  async function currentSubscription() {
    if (!swReg) return null;
    return swReg.pushManager.getSubscription();
  }

  // limite di tempo per ogni passaggio, così il pulsante non resta bloccato in silenzio
  function withTimeout(promise, ms, msg) {
    let t;
    return Promise.race([promise, new Promise((_, rej) => { t = setTimeout(() => rej(new Error(msg)), ms); })])
      .finally(() => clearTimeout(t));
  }

  async function subscribe(onStep = () => {}) {
    onStep('Chiedo il permesso…');
    const perm = Notification.permission === 'granted'
      ? 'granted'
      : await withTimeout(Notification.requestPermission(), 30000,
          'il telefono non ha mostrato la richiesta di permesso. Se in alto vedi l\'icona di una campanella barrata, toccala e scegli Consenti. Oppure installa l\'app e riprova da lì');
    if (perm === 'denied') throw new Error('hai scelto di bloccare le notifiche');
    if (perm !== 'granted') throw new Error('la richiesta è stata chiusa senza scegliere. Tocca di nuovo e scegli Consenti');

    onStep('Preparo il collegamento…');
    const kr = await withTimeout(fetch('api/vapid.php', { cache: 'no-store' }), 15000, 'il server non risponde');
    const kj = await kr.json().catch(() => ({}));
    if (!kr.ok || !kj.publicKey) throw new Error('il server non è pronto (' + (kj.error || 'errore ' + kr.status) + ')');

    onStep('Collego il telefono…');
    let sub = await swReg.pushManager.getSubscription();
    if (!sub) {
      sub = await withTimeout(
        swReg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8Array(kj.publicKey) }),
        20000, 'il servizio notifiche del telefono non risponde. Controlla la connessione e che Chrome sia aggiornato');
    }

    onStep('Registro…');
    await postJSON('api/subscribe.php', sub.toJSON());
    store.set('subscribed', true);
  }

  async function unsubscribe() {
    const sub = await currentSubscription();
    if (sub) {
      await postJSON('api/unsubscribe.php', { endpoint: sub.endpoint }).catch(() => {});
      await sub.unsubscribe();
    }
    store.set('subscribed', false);
  }

  const shareIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M8 7l4-4 4 4M6 11H5v10h14V11h-1" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  function panel(html, compact = false) {
    els.notify.className = 'notify' + (compact ? ' compact' : '');
    els.notify.innerHTML = html;
    els.notify.hidden = false;
  }

  function message(text, isError) {
    let m = els.notify.querySelector('.msg');
    if (!m) {
      m = document.createElement('p');
      els.notify.append(m);
    }
    m.className = 'msg' + (isError ? ' err' : '');
    m.textContent = text;
  }

  async function drawNotify() {
    // iPhone: le notifiche funzionano solo dall'app aggiunta alla schermata Home
    if (isIOS && !isStandalone) {
      panel(
        '<h2>Ricevi un avviso per ogni nuova circolare</h2>' +
        '<p>Su iPhone le notifiche arrivano solo se aggiungi l\'app alla schermata Home. Serve un minuto, una volta sola.</p>' +
        '<ol class="steps">' +
        '<li><span>In Safari tocca Condividi ' + shareIcon + '. Con iOS 26 tocca prima ··· e poi Condividi.</span></li>' +
        '<li><span>Scorri e scegli <strong>Aggiungi alla schermata Home</strong>, poi Aggiungi.</span></li>' +
        '<li><span>Apri sVolta dall\'icona sulla Home, tocca <strong>Attiva le notifiche</strong> e poi <strong>Consenti</strong>.</span></li>' +
        '</ol>'
      );
      return;
    }
    if (!pushSupported || !swReg) {
      panel('<h2>Notifiche non disponibili</h2><p>Questo browser non riceve notifiche. Puoi comunque consultare le circolari qui. Su Android usa Chrome; su iPhone serve iOS 16.4 o successivo.</p>');
      return;
    }

    const perm = Notification.permission;
    const sub = await currentSubscription();

    if (perm === 'denied') {
      panel(
        '<h2>Le notifiche sono bloccate</h2>' +
        '<p>' + (isIOS
          ? 'Apri Impostazioni › Notifiche › sVolta e attiva Consenti notifiche.'
          : 'Tocca il lucchetto accanto all\'indirizzo, oppure le impostazioni dell\'app, e consenti le notifiche. Poi torna qui.') +
        '</p>'
      );
      return;
    }

    if (perm === 'granted' && sub) {
      panel(
        '<p><span class="dot" aria-hidden="true"></span>Notifiche attive su questo telefono</p>' +
        '<div class="row">' +
        ((installEvent && !isStandalone) ? '<button class="text-btn" id="n-install" type="button">Installa</button>' : '') +
        '<button class="text-btn" id="n-test" type="button">Prova</button>' +
        '<button class="text-btn" id="n-off" type="button">Disattiva</button></div>',
        true
      );
      bindInstall();
      $('#n-test').onclick = async (ev) => {
        ev.target.disabled = true;
        try {
          await postJSON('api/test.php', { endpoint: sub.endpoint });
          panelFlash('Notifica di prova inviata. Dovrebbe arrivare tra pochi secondi.');
        } catch (e) {
          panelFlash(e.message, true);
        } finally {
          ev.target.disabled = false;
        }
      };
      $('#n-off').onclick = async () => {
        await unsubscribe();
        drawNotify();
      };
      return;
    }

    panel(
      '<h2>Ricevi un avviso per ogni nuova circolare</h2>' +
      '<p>Una notifica sul telefono, con numero e oggetto, appena la scuola pubblica una circolare. Non serve registrarsi.</p>' +
      '<div class="row"><button class="btn" id="n-on" type="button">Attiva le notifiche</button>' +
      installButtonHtml() + '</div>' + installHintHtml()
    );
    bindInstall();
    const btn = $('#n-on');
    btn.onclick = async () => {
      btn.disabled = true;
      const step = (t) => { btn.textContent = t; };
      try {
        await subscribe(step);
        await drawNotify();
        panelFlash('Fatto. Riceverai un avviso per ogni nuova circolare.');
      } catch (e) {
        await drawNotify();
        message('Notifiche non attivate: ' + e.message + '.', true);
      }
    };
  }

  /* ---------------- installazione su Android ---------------- */

  // Statistiche (GoatCounter): eventi anonimi sull'installazione. Se lo script non è caricato (offline, blocco pubblicità) non fa nulla.
  const conta = (nome) => { try { if (window.goatcounter && window.goatcounter.count) window.goatcounter.count({ path: 'svolta-' + nome, title: 'svolta: ' + nome, event: true }); } catch (e) {} };
  let installEvent = null;
  const isAndroid = /Android/i.test(ua);

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    installEvent = e;
    if (!els.notify.hidden) drawNotify();
  });
  window.addEventListener('appinstalled', () => {
    installEvent = null;
    store.set('installed', true);
    conta('installata');
    drawNotify();
    panelFlash('App installata. La trovi tra le tue app con il nome sVolta.');
  });

  function installButtonHtml() {
    return (installEvent && !isStandalone) ? '<button class="btn secondary" id="n-install" type="button">Installa l\'app</button>' : '';
  }

  function installHintHtml() {
    if (installEvent || isStandalone || !isAndroid || store.get('installed', false)) return '';
    return '<p class="hint">Per averla tra le app: menu <strong>⋮</strong> di Chrome e poi <strong>Installa app</strong> o <strong>Aggiungi a schermata Home</strong>.</p>';
  }

  function bindInstall() {
    const b = $('#n-install');
    if (!b) return;
    b.onclick = async () => {
      const ev = installEvent;
      if (!ev) return;
      conta('installa-clic');
      ev.prompt();
      const choice = await ev.userChoice.catch(() => null);
      if (choice && choice.outcome === 'dismissed') conta('installa-rifiutata');
      if (choice && choice.outcome === 'accepted') installEvent = null;
      drawNotify();
    };
  }

  function panelFlash(text, isError = false) {
    els.status.innerHTML = '';
    const span = document.createElement('span');
    if (isError) span.className = 'err';
    span.textContent = text;
    els.status.append(span);
    clearTimeout(panelFlash.t);
    panelFlash.t = setTimeout(() => { if (els.status.firstChild === span) els.status.textContent = ''; }, 6000);
  }

  // se il telefono ha rinnovato l'iscrizione, la riallineo con il server
  async function resyncSubscription() {
    if (Notification.permission !== 'granted') return;
    const sub = await currentSubscription();
    if (sub) {
      postJSON('api/subscribe.php', sub.toJSON()).catch(() => {});
    } else if (store.get('subscribed', false)) {
      subscribe().catch(() => {}).then(drawNotify);
    }
  }

  /* ---------------- eventi ---------------- */

  els.list.addEventListener('click', (e) => {
    const b = e.target.closest('.item[data-guid]');
    if (b) openCircolare(b.dataset.guid);
  });
  els.q.addEventListener('input', render);
  els.refresh.addEventListener('click', () => loadFeed({ quiet: true }));
  els.markAll.addEventListener('click', () => {
    items.forEach((c) => read.add(c.guid));
    saveRead();
    render();
  });

  $('#viewer-close').addEventListener('click', () => history.back());
  $('#zoom-in').addEventListener('click', () => setZoom(zoom + 0.5));
  $('#zoom-out').addEventListener('click', () => setZoom(zoom - 0.5));
  window.addEventListener('popstate', () => {
    if (!els.viewer.hidden) closeViewer();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !els.viewer.hidden) history.back();
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') loadFeed({ quiet: true });
  });

  /* ---------------- avvio ---------------- */

  async function start() {
    if ('serviceWorker' in navigator) {
      try {
        swReg = await navigator.serviceWorker.register('sw.js');
        await navigator.serviceWorker.ready;
        navigator.serviceWorker.addEventListener('message', (e) => {
          if (e.data && e.data.type === 'open' && e.data.guid) {
            loadFeed({ quiet: true }).then(() => {
              if (!els.viewer.hidden) closeViewer();
              openCircolare(e.data.guid);
            });
          } else if (e.data && e.data.type === 'refresh') {
            loadFeed({ quiet: true });
          }
        });
      } catch (e) {
        swReg = null;
      }
    }
    await drawNotify();
    resyncSubscription();
  }

  loadFeed();
  start();
})();
