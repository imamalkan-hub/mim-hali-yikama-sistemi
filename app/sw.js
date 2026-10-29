/* Mim Halı Yıkama — servis çalışanı (service worker)
 *
 * Amaç: uygulama kabuğunu çevrimdışı açılabilir yapmak ve bağlantı koptuğunda
 * yapılan sipariş/fotoğraf gönderimlerinin kaybolmamasını sağlamak.
 *
 * GÜVENLİK KURALI — bu dosyanın en önemli kısmı:
 *   /data/ altındaki hiçbir şey (veritabanı, fotoğraflar, log) ASLA cache'e
 *   girmez. Sipariş ekranları da (HTML) cache'lenmez: cihaz paylaşılsa bile
 *   başka birinin verisi görünmez. Cache'e yalnızca statik kabuk dosyalar girer.
 *
 * Kuyruğun kendisi IndexedDB'de tutulur ve offline.js tarafından yönetilir.
 * Service worker yalnızca "bağlantı geri geldi" sinyalini iletir ve kuyruğu
 * boşaltmak için istemciye mesaj gönderir.
 */
'use strict';

/* ---------------------------------------------------------------- sürümleme
 * Yeni dosya dağıtıldığında buradaki tarihi güncelle: activate olayında eski
 * kasalar silinir, yeni dosyalar indirilir. */
var SURUM = 'mim-v2-2026-10-28';
var KASA = 'mim-v2-2026-10-28';

/* Çevrimdışı açılışta gereken statik kabuk. HTML/belge değil, yalnızca dosyalar. */
var KABUK = [
  'style.css',
  'app.js',
  'offline.js',
  'icon.svg',
  'manifest.webmanifest',
  'fotocompress.js',
  'qrgen.js'
];

/* KESİNLİKLE cache'e girmeyecek yollar. Veri ve fotoğraflar burada. */
var YASAK = [
  /\/data\//i,
  /\/data$/i,
  /\.(db|db-wal|db-shm|sqlite|sqlite3)$/i,
  /dbpath\.php$/i,
  /app\.log$/i,
  /[?&]p=foto(&|$)/i,     // korumalı fotoğraf akışı
  /[?&]p=yedek(&|$)/i,    // veritabanı yedeği
  /[?&]p=login(&|$)/i,
  /[?&]p=kurulum(&|$)/i
];

function yasakMi(url) {
  var u = String(url);
  for (var i = 0; i < YASAK.length; i++) {
    if (YASAK[i].test(u)) return true;
  }
  return false;
}

/* ---------------------------------------------------------------- yaşam döngüsü */

self.addEventListener('install', function (ev) {
  ev.waitUntil(
    caches.open(KASA).then(function (kasa) {
      // addAll tek dosya hatasında hepsini düşürür; dosya dosya ekliyoruz ki
      // eksik bir statik dosya uygulamayı tamamen çevrimdışı yapmasın.
      return Promise.all(KABUK.map(function (yol) {
        var istek = new Request(yol, { cache: 'reload' });
        return kasa.put(istek, fetch(istek)).catch(function () { return null; });
      }));
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (ev) {
  ev.waitUntil(
    caches.keys().then(function (anahtarlar) {
      return Promise.all(anahtarlar.map(function (a) {
        // yalnızca kendi sürümümüz kalsın; eski sürümün kasası düşsün
        if (a === KASA) return null;
        if (a.indexOf('mim-') !== 0) return null;
        return caches.delete(a);
      }));
    }).then(function () { return self.clients.claim(); })
  );
});

/* ---------------------------------------------------------------- ağ yakalama */

self.addEventListener('fetch', function (ev) {
  var istek = ev.request;

  // POST/PUT: gövdeyi elle göndermeyiz — bağlantı yoksa istemciye haber veririz,
  // kuyruğa yazma işini offline.js yapar (gövdeyi o elde).
  if (istek.method !== 'GET') {
    if (istek.method === 'POST') {
      ev.respondWith(gonderDene(istek));
    }
    return;
  }

  // Veri/fotoğraf/yoluk sayfalar: hiçbir zaman cache'e girmez.
  if (yasakMi(istek.url)) return;

  // HTML sayfaları: güncel veriyi göstermek için ağdan gider, cache'lenmez.
  // (Oturum açmış ekranların cihazda kalması veri sızıntısıdır.)
  if (istek.mode === 'navigate' || (istek.headers.get('accept') || '').indexOf('text/html') !== -1) {
    ev.respondWith(
      fetch(istek).catch(function () {
        return new Response(kapukSayfa(), {
          status: 503,
          headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' }
        });
      })
    );
    return;
  }

  // Yalnızca beyaz listedeki statik dosyalar cache'lenir.
  var ad = dosyaAdi(istek.url);
  if (KABUK.indexOf(ad) === -1) return;

  ev.respondWith(
    caches.open(KASA).then(function (kasa) {
      return kasa.match(istek).then(function (eski) {
        var tazelik = fetch(istek).then(function (yanit) {
          if (yanit && yanit.ok) kasa.put(istek, yanit.clone());
          return yanit;
        }).catch(function () { return eski || Response.error(); });
        return eski || tazelik;
      });
    })
  );
});

/* Bağlantı koptuğunda POST'u bir kez dene, başarısızsa istemciye bildir.
 * Yanıt gövdesi değiştirilmez — sunucunun davranışı bozulmaz. */
function gonderDene(istek) {
  return fetch(istek).catch(function () {
    istemcilereSozle({ tip: 'mim-cevrimdisi', url: istek.url });
    return new Response(JSON.stringify({
      ok: false, cevrimdisi: true,
      mesaj: 'Bağlantı yok — kayıt cihazınıza alındı, bağlantı gelince gönderilecek.'
    }), {
      status: 503,
      headers: { 'Content-Type': 'application/json; charset=utf-8' }
    });
  });
}

function dosyaAdi(url) {
  var parca = String(url).split('?')[0].split('#')[0];
  var dilim = parca.split('/');
  return dilim[dilim.length - 1] || '';
}

/* ---------------------------------------------------------------- kuyruk sinyali */

self.addEventListener('sync', function (ev) {
  if (ev.tag === 'mim-offline-sync') {
    ev.waitUntil(Promise.all([istemcilereSozle({ tip: 'mim-kuyruk-bosalt' })]));
  }
});

self.addEventListener('message', function (ev) {
  var veri = ev.data || {};
  if (veri.tip === 'mim-skip-waiting') { self.skipWaiting(); return; }
  if (veri.tip === 'mim-kuyruk-bosalt' || veri.tip === 'mim-sync-iste') {
    // Kayıt offline.js'in IndexedDB'sinde; service worker kuyruğu kendisi
    // göndermez, açık istemcilere "şimdi dene" der. Kapalı istemci yoksa
    // kuyruk kaydı cihazda kalır, sonraki açılışta gönderilir (veri kaybı yok).
    ev.waitUntil ? ev.waitUntil(istemcilereSozle({ tip: 'mim-kuyruk-bosalt' })) : null;
  }
});

function istemcilereSozle(mesaj) {
  return self.clients.matchAll({ includeUncontrolled: true, type: 'window' })
    .then(function (istemciler) {
      istemciler.forEach(function (c) { c.postMessage(mesaj); });
      return istemciler.length;
    });
}

/* ---------------------------------------------------------------- çevrimdışı sayfa
 * Ayrı bir offline.html dosyası yok (tek klasör kuralı); bu yüzden gömülü. */
function kapukSayfa() {
  return '<!doctype html><html lang="tr"><head><meta charset="utf-8">'
    + '<meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<meta name="theme-color" content="#10243A">'
    + '<title="Bağlantı yok — Mim Halı Yıkama</title><link rel="stylesheet" href="style.css"></head>'
    + '<body><main class="main">'
    + '<div class="card"><h2>📴 Bağlantı yok</h2>'
    + '<p>İnternet bağlantısı kurulamadı. Sayfa şu an görüntülenemiyor.</p>'
    + '<div class="alert warn"><b>Kaybettikleriniz bir şey yok.</b> Bağlantı yokken alınan '
    + 'sipariş ve fotoğraflar cihazınızda saklanır; bağlantı gelince kendiliğinden gönderilir. '
    + 'Kuyruğu <a href="?p=siparis-yeni">Sipariş</a> ekranından görebilirsiniz.</div>'
    + '<button class="btn btn-primary btn-block" onclick="location.reload()">Tekrar dene</button>'
    + '</div></main></body></html>';
}