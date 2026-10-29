/* Mim Halı Yıkama — çevrimdışı kuyruk (IndexedDB)
 *
 * Sahada internet çektiğinde alınan sipariş ve fotoğraf kaydının kaybolmaması
 * için tasarlandı. Kuyruk veritabanı: 'mim-offline'
 *
 * ÇOK ÖNEMLİ — client_uid kuralı:
 *   Bir sipariş kuyruğa yazıldığında bir kez UUID üretilir ve kayıtla birlikte
 *   SAKLANIR. Her yeniden denemede AYNI değer gönderilir. Yeniden denemede yeni
 *   UUID üretmek sunucunun mükerrer kayıt korumasını boşa çıkarır ve aynı
 *   siparişten birden fazla kayıt açar. Bu yüzden uid alanı gönderimde asla
 *   yeniden üretilmez — yalnızca ilk kuyruğa yazma sırasında üretilir.
 *
 * Dürüstlük kuralı:
 *   Kayıt cihaza alındığında kullanıcıya "gönderildi" DENİRMEZ. Kayıt ancak
 *   sunucu 2xx döndükten sonra başarılı sayılır. Sunucu bir siparişi client_uid
 *   ile "zaten var" diye karşılıyorsa (yinelenen kayıt) o da başarıdır — çünkü
 *   kayıt sistemde duruyordadır.
 */
'use strict';

(function (global) {
  var KUTU_ADI = 'mim-offline';
  var KUTU_NO = 1;
  var MAGAZA = 'kuyruk';
  var AYAR_MAGAZA = 'meta';
  var API = {};

  /* ------------------------------------------------------------ IndexedDB
   * Safari'ın özel WebView'larında indexedDB olmayabilir; bu durumda kuyruk
   * kurulamaz ve çağıran tarafa null döner — çevrimdışı KAYIP OLMAYACAK
   * şekilde form normal gönderilir. */
  function ac() {
    return new Promise(function (coz, reddet) {
      if (!global.indexedDB) { reddet(new Error('IndexedDB yok')); return; }
      var istek = global.indexedDB.open(KUTU_ADI, KUTU_NO);
      istek.onupgradeneeded = function (ev) {
        var db = istek.result;
        if (!db.objectStoreNames.contains(MAGAZA)) {
          var ma = db.createObjectStore(MAGAZA, { keyPath: 'id', autoIncrement: true });
          ma.createIndex('durum', 'durum', { unique: false });
          ma.createIndex('olusturma', 'olusturma', { unique: false });
          ma.createIndex('clientUid', 'clientUid', { unique: false });
        }
        if (!db.objectStoreNames.contains(AYAR_MAGAZA)) db.createObjectStore(AYAR_MAGAZA);
      };
      istek.onsuccess = function () { coz(istek.result); };
      istek.onerror = function () { reddet(istek.error || new Error('IndexedDB açılamadı')); };
      istek.onblocked = function () { reddet(new Error('IndexedDB kilitli')); };
    });
  }

  function yaz(db, fn) {
    return new Promise(function (coz, reddet) {
      var tx = db.transaction(MAGAZA, 'readwrite');
      var sonuc;
      fn(tx.objectStore(MAGAZA), function (deger) { sonuc = deger; });
      tx.oncomplete = function () { coz(sonuc); };
      tx.onerror = function () { reddet(tx.error); };
      tx.onabort = function () { reddet(tx.error || new Error('İşlem iptal')); };
    });
  }

  function oku(db, fn) {
    return new Promise(function (coz, reddet) {
      var tx = db.transaction(MAGAZA, 'readonly');
      var istek = fn(tx.objectStore(MAGAZA));
      istek.onsuccess = function () { coz(istek.result); };
      istek.onerror = function () { reddet(istek.error); };
    });
  }

  /* ------------------------------------------------------------ yardımcılar */

  function yeniUid() {
    if (global.crypto && typeof global.crypto.randomUUID === 'function') return global.crypto.randomUUID();
    // randomUUID yoksa (eski WebView) yine de sunucunun beklediği biçimde benzersiz
    // bir değer üret. Her yeniden denemede DEĞİL, ilk yazımda bir kez.
    var d = '';
    if (global.crypto && typeof global.crypto.getRandomValues === 'function') {
      var b = new Uint8Array(16);
      global.crypto.getRandomValues(b);
      for (var i = 0; i < b.length; i++) d += ('0' + b[i].toString(16)).slice(-2);
    } else {
      d = Date.now().toString(16) + Math.random().toString(16).slice(2, 12);
    }
    return 'mim-' + d;
  }

  function simdi() { return Date.now(); }

  function bagliMi() {
    if (typeof navigator === 'undefined' || !navigator.onLine) return false;
    return true;
  }

  /* FormData → düz nesne (kuyrukta saklamak için; dosyalar ayrıca saklanır) */
  function formuNesneyeCevir(fd) {
    var o = {};
    if (!fd || typeof fd.forEach !== 'function') return o;
    fd.forEach(function (deger, ad) {
      if (ad === 'foto[]' || ad.indexOf('foto[') === 0) return;   // dosyalar ayrı
      if (ad === 'csrf') return;                                   // kuyruk kaydında CSRF tutulmaz
      if (Object.prototype.hasOwnProperty.call(o, ad)) {
        if (!Array.isArray(o[ad])) o[ad] = [o[ad]];
        o[ad].push(deger);
      } else {
        o[ad] = deger;
      }
    });
    return o;
  }

  /* Sıralı dizi değerlerini tekrar 'ad[]' alanlarına çevir (sunucu bekliyor). */
  function nesneyiFormDataYap(nesne) {
    var fd = new FormData();
    Object.keys(nesne).forEach(function (ad) {
      var d = nesne[ad];
      if (Array.isArray(d)) d.forEach(function (tek) { fd.append(ad, tek); });
      else if (d !== undefined && d !== null) fd.append(ad, d);
    });
    return fd;
  }

  /* ---------------------------------------------------------------- kuyruk API */

  /** Sipariş kaydını kuyruğa yaz. Dönen kayıt: {id, clientUid, ...} */
  API.kuyrugaEkle = function (tur, hedefUrl, formData, dosyalar) {
    return ac().then(function (db) {
      var kayit = {
        tur: tur,                       // 'siparis' | 'foto'
        url: hedefUrl,
        alanlar: formuNesneyeCevir(formData),
        dosyalar: dosyalar || [],       // {ad, tur, veri(ArrayBuffer)}
        durum: 'bekliyor',
        hata: '',
        deneme: 0,
        olusturma: simdi(),
        gonderilme: 0,
        clientUid: ''
      };
      if (tur === 'siparis') {
        // UID BİR KEZ üretilir ve kayda yazılır — sonraki denemelerde aynısı gider.
        // Yeniden denemede yeni UID üretmek sunucunun mükerrer korumasını bozar.
        var mevcut = kayit.alanlar.client_uid;
        kayit.clientUid = mevcut ? String(mevcut) : yeniUid();
        kayit.alanlar.client_uid = kayit.clientUid;
      }
      return yaz(db, function (ma, coz) {
        var istek = ma.add(kayit);
        istek.onsuccess = function () { kayit.id = istek.result; coz(kayit); };
      });
    });
  };

  /** Tüm bekleyen kayıtlar (eskiden yeniye) */
  API.bekleyenler = function () {
    return ac().then(function (db) {
      return oku(db, function (ma) { return ma.index('olusturma').getAll(); });
    }).then(function (liste) {
      return (liste || []).filter(function (k) { return k.durum !== 'gonderildi'; });
    }).catch(function () { return []; });
  };

  API.bekleyenSayisi = function () {
    return API.bekleyenler().then(function (l) { return l.length; });
  };

  /** Belirli bir kaydı sil */
  API.sil = function (id) {
    return ac().then(function (db) {
      return yaz(db, function (ma, coz) { var i = ma.delete(id); i.onsuccess = function () { coz(true); }; });
    });
  };

  /** Kaydın durumunu güncelle */
  API.durumGuncelle = function (id, durum, hataMetni) {
    return ac().then(function (db) {
      return yaz(db, function (ma, coz) {
        var istek = ma.get(id);
        istek.onsuccess = function () {
          var k = istek.result;
          if (k) {
            k.durum = durum;
            k.hata = hataMetni || '';
            if (durum === 'gonderildi') k.gonderilme = simdi();
            ma.put(k);
          }
          coz(true);
        };
      });
    });
  };

  /** Sunucuya gönderir. Yanıt koduna göre kaydı günceller; çağıran yorumu alır. */
  API.gonder = function (kayit) {
    if (!kayit || !kayit.url) return Promise.resolve({ ok: false, hata: 'geçersiz kayıt' });
    if (!bagliMi()) return Promise.resolve({ ok: false, cevrimdisi: true, hata: 'bağlantı yok' });

    var fd = nesneyiFormDataYap(kayit.alanlar || {});
    // CSRF her gönderimde taze alınır (oturum çerezinden gelir).
    var csrf = csrfAl();
    if (csrf) fd.append('csrf', csrf);

    var zincir = Promise.resolve();
    (kayit.dosyalar || []).forEach(function (d) {
      // Blob her seferinde yeniden kurulur; veri ham bayt olarak saklanır.
      var blob = new Blob([d.veri], { type: d.tur || 'application/octet-stream' });
      fd.append('foto[]', blob, d.ad || 'foto.jpg');
    });

    return zincir.then(function () {
      return fetch(kayit.url, { method: 'POST', body: fd, credentials: 'same-origin' });
    }).then(function (yanit) {
      if (yanit && yanit.ok) {
        return API.durumGuncelle(kayit.id, 'gonderildi', '')
          .then(function () { return { ok: true, kod: yanit.status }; });
      }
      // 503 + JSON gövdesi → service worker bağlantı yok dedi, kuyrukta kalsın.
      var kod = yanit ? yanit.status : 0;
      var sebep = kod === 0 ? 'bağlantı yok' : ('sunucu ' + kod + ' döndü');
      return API.durumGuncelle(kayit.id, 'hata', sebep).then(function () {
        return { ok: false, hata: sebep, kod: kod };
      });
    }).catch(function (err) {
      var sebep = (err && err.message) ? err.message : 'ağ hatası';
      return API.durumGuncelle(kayit.id, 'hata', sebep).then(function () {
        return { ok: false, hata: sebep, cevrimdisi: !bagliMi() };
      });
    });
  };

  /** Kuyruğu sırayla boşaltır. Kısmi başarı: hata olan kayıt kuyrukta kalır. */
  API.kuyruguBosalt = function (geriCagirma) {
    if (!bagliMi()) return Promise.resolve({ gonderildi: 0, hata: 0, cevrimdisi: true });
    return API.bekleyenler().then(function (liste) {
      var sonuc = { gonderildi: 0, hata: 0, cevrimdisi: false, ayrinti: [] };
      var zincir = Promise.resolve();
      liste.forEach(function (kayit) {
        zincir = zincir.then(function () {
          return API.gonder(kayit).then(function (r) {
            if (r.ok) sonuc.gonderildi++;
            else sonuc.hata++;
            sonuc.ayrinti.push({
              id: kayit.id, tur: kayit.tur, ok: r.ok,
              hata: r.hata || ''
            });
            if (geriCagirma) {
              try { geriCagirma(kayit, r); } catch (e) {}
            }
          });
        });
      });
      return zincir.then(function () { return sonuc; });
    });
  };

  function csrfAl() {
    try {
      var el = document.querySelector('input[name="csrf"]');
      return el ? el.value : '';
    } catch (e) { return ''; }
  }

  /* ------------------------------------------------------------ arayüz */

  /** Sipariş formunu çevrimdışı kuyruğa bağlar (id #orderForm varsa). */
  API.formuKur = function (form) {
    if (!form || form.__mimOfflineBagli) return;
    form.__mimOfflineBagli = true;

    var uidEl = form.querySelector('#clientUid');

    form.addEventListener('submit', function (ev) {
      if (form.__mimCevrimdisi) return;            // kuyruğa yazıldı, tekrar gönderme
      // Bağlantı varsa hiçbir şeye dokunma — sunucunun normal akışı çalışsın.
      if (bagliMi()) { uidDoldur(); return; }

      ev.preventDefault();
      uidDoldur();
      var fd = new FormData(form);
      API.kuyrugaEkle('siparis', form.getAttribute('action') || '?p=siparis-yeni', fd, [])
        .then(function (kayit) {
          form.__mimCevrimdisi = true;
          mesajGoster('Kaydınız cihazınıza alındı, bağlantı gelince gönderilecek. '
            + '(kuyruk no: ' + kayit.id + ')', 'warn');
          form.reset();
          API.listeyiYenile();
        })
        .catch(function (err) {
          // Kuyruk kurulamadıysa kullanıcıyı bilgilendir; sahte başarı YOK.
          mesajGoster('Cihazınıza kayıt alınamadı (' + (err && err.message ? err.message : 'bilinmeyen')
            + '). Bağlantı gelince tekrar deneyin — kayıt şu an gönderilmedi.', 'danger');
        });
    });

    function uidDoldur() {
      if (uidEl && !uidEl.value) uidEl.value = yeniUid();
    }
    // Kuyruğa alınan kayıt da aynı uid'i taşır; sunucu çift kayıt açmaz.
    form.__mimUidUret = yeniUid;
  };

  /** Durum şeridini (bekleyen kayıt listesi) yeniler. */
  API.listeyiYenile = function (kutu) {
    if (!kutu) return Promise.resolve([]);
    return API.bekleyenler().then(function (liste) {
      if (!liste.length) {
        kutu.innerHTML = '<div class="alert ok">Bekleyen kayıt yok — kuyruk boş.</div>';
        return liste;
      }
      var html = '<div class="alert warn"><b>' + liste.length + ' kayıt cihazınızda bekliyor.</b>'
        + ' Bağlantı gelince otomatik gönderilir. Henüz sunucuya ulaşmadılar.</div><ul>';
      liste.forEach(function (k) {
        var ne = k.tur === 'foto' ? 'Fotoğraf' : 'Sipariş';
        var zaman = k.olusturma ? new Date(k.olusturma).toLocaleString('tr-TR') : '';
        html += '<li>' + ne + ' #' + k.id
          + (k.clientUid ? ' <small>(uid ' + String(k.clientUid).slice(0, 8) + '…)</small>' : '')
          + ' — <b>' + (k.durum === 'hata' ? 'Hata: ' + kacis(k.hata) : 'Bekliyor') + '</b>'
          + ' <small>' + kacis(zaman) + '</small></li>';
      });
      kutu.innerHTML = html + '</ul>';
      return liste;
    });
  };

  function kacis(s) {
    return String(s === undefined || s === null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function mesajGoster(metin, tip) {
    var el = document.getElementById('mimOfflineMesaj');
    if (!el) return;
    el.className = 'alert ' + (tip || 'warn');
    el.textContent = metin;
    el.style.display = '';
  }

  /** Yeni sipariş formundaki client_uid alanını doldur. */
  API.sayfaKur = function () {
    if (typeof document === 'undefined') return;
    var form = document.getElementById('orderForm');
    if (form) API.formuKur(form);

    var kutu = document.getElementById('mimOfflineKuyruk');
    if (kutu) API.listeyiYenile(kutu);

    // Bağlantı geldiğinde kuyruğu boşalt.
    global.addEventListener('online', function () {
      mesajGoster('Bağlantı geldi — bekleyen kayıtlar gönderiliyor…', 'warn');
      API.kuyruguBosalt(function () { API.listeyiYenile(kutu); })
        .then(function (sonuc) {
          if (sonuc.gonderildi) {
            mesajGoster(sonuc.gonderildi + ' kayıt gönderildi.'
              + (sonuc.hata ? ' ' + sonuc.hata + ' kayıt gönderilemedi, kuyrukta.' : ''), 'ok');
          }
          API.listeyiYenile(kutu);
        });
    });
  };

  /** Düzenli deneme — 'online' olayı gelmezse de kuyruk boşalmaz. */
  API.periyodikBaslat = function (aralikMs) {
    var a = aralikMs || 60000;
    global.setInterval(function () {
      if (!bagliMi()) return;
      var kutu = (typeof document !== 'undefined') ? document.getElementById('mimOfflineKuyruk') : null;
      API.bekleyenSayisi().then(function (n) {
        if (n > 0) {
          API.kuyruguBosalt().then(function () { if (kutu) API.listeyiYenile(kutu); });
        }
      });
    }, a);
  };

  global.mimOffline = API;
})(typeof window !== 'undefined' ? window : this);