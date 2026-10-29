/* Mim Halı Yıkama — sipariş formu satır mantığı (vanilla JS, index kullanmaz)
 * ⚠ Bu dosya bazı sayfalarda İKİ KEZ yükleniyordu (satır içi + layout_foot);
 *   her kopya dinleyiciyi yeniden ekliyordu → tek submit iki kez işleniyordu.
 *   Tekrar kurumaya kapatıldı; çift include da kaldırıldı (mod/siparis.php). */
if (!window.__mimAppYuklu) {
window.__mimAppYuklu = true;
(function () {
  'use strict';
  // Bu blok yalnız sipariş formunda çalışır; diğer sayfalarda fiyat verisi yok.
  var pd = document.getElementById('priceData');
  if (!pd || !document.getElementById('items')) return;
  var DATA = JSON.parse(pd.textContent);
  var TYPES = DATA.types, PRICES = DATA.prices;
  var wrap = document.getElementById('items');

  function fmt(kurus) {
    return (kurus / 100).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';
  }
  function parseTL(s) {
    s = String(s || '').replace(/[₺\s]/g, '');
    if (s.indexOf(',') !== -1) { s = s.replace(/\./g, '').replace(',', '.'); }
    var f = parseFloat(s);
    return isNaN(f) ? 0 : Math.round(f * 100);
  }
  function rowOf(el) { return el.closest('.item-row'); }

  window.addItemRow = function () {
    var opts = '';
    for (var k in TYPES) {
      opts += '<option value="' + k + '">' + TYPES[k][0] + (TYPES[k][1] ? ' (₺/m²)' : '') + '</option>';
    }
    var div = document.createElement('div');
    div.className = 'item-row';
    div.innerHTML =
      '<label>Hizmet<select name="t_type[]" data-act="type">' + opts + '</select></label>' +
      '<div class="m2wrap" style="display:none">' +
      '<label>En (m)<input name="t_w[]" inputmode="decimal" data-act="calc"></label>' +
      '<label>Boy (m)<input name="t_h[]" inputmode="decimal" data-act="calc"></label>' +
      '<div class="muted small m2show"></div></div>' +
      '<label>Adet<input name="t_qty[]" type="number" min="1" value="1" data-act="calc"></label>' +
      '<label>Birim fiyat<input name="t_unit[]" inputmode="decimal" data-act="calc"></label>' +
      '<label>Tutar<input name="t_line[]" inputmode="decimal" data-act="manual"></label>' +
      '<label>Açıklama / not<input name="t_note[]" placeholder="örn. bordo, saçağı yıpranmış"></label>' +
      '<div class="btnrow"><button type="button" class="btn btn-sm" data-act="del">Satırı Sil</button></div>';
    wrap.appendChild(div);
    rowType(div.querySelector('select'));
  };

  // tek dinleyici: olay hedefinin data-act'ine bak
  document.addEventListener('input', function (ev) {
    var el = ev.target;
    if (el.closest('#items') || el.name === 'indirim') {
      if (el.dataset.act === 'calc') rowCalc(el);
      else if (el.dataset.act === 'manual') grandTotal();
      else if (el.name === 'indirim') grandTotal();
    }
  });
  document.addEventListener('change', function (ev) {
    var el = ev.target;
    if (el.dataset && el.dataset.act === 'type') rowType(el);
  });
  document.addEventListener('click', function (ev) {
    var el = ev.target;
    if (el.dataset && el.dataset.act === 'del') {
      rowOf(el).remove();
      grandTotal();
    }
  });

  function rowType(sel) {
    var row = rowOf(sel);
    var perM2 = TYPES[sel.value] && TYPES[sel.value][1];
    row.querySelector('.m2wrap').style.display = perM2 ? '' : 'none';
    var unit = row.querySelector('input[name="t_unit[]"]');
    if (PRICES[sel.value] > 0) {
      unit.value = (PRICES[sel.value] / 100).toLocaleString('tr-TR', { minimumFractionDigits: 2 });
    }
    rowCalc(unit);
  }

  function rowCalc(el) {
    var row = rowOf(el);
    var w = parseFloat(row.querySelector('input[name="t_w[]"]').value) || 0;
    var h = parseFloat(row.querySelector('input[name="t_h[]"]').value) || 0;
    var qty = parseInt(row.querySelector('input[name="t_qty[]"]').value || '1', 10) || 1;
    var sel = row.querySelector('select[name="t_type[]"]');
    var perM2 = TYPES[sel.value] && TYPES[sel.value][1];
    var m2 = (perM2 && w > 0 && h > 0) ? Math.round(w * h * 100) / 100 : 0;
    var show = row.querySelector('.m2show');
    if (show) show.textContent = m2 > 0 ? '= ' + String(m2).replace('.', ',') + ' m²' : '';
    var unit = parseTL(row.querySelector('input[name="t_unit[]"]').value);
    var line = unit * (m2 > 0 ? m2 : qty);
    var lineInp = row.querySelector('input[name="t_line[]"]');
    if (line > 0) {
      lineInp.value = (line / 100).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    grandTotal();
  }

  function grandTotal() {
    var total = 0;
    wrap.querySelectorAll('input[name="t_line[]"]').forEach(function (inp) {
      total += parseTL(inp.value);
    });
    total -= parseTL(document.querySelector('input[name="indirim"]').value);
    document.getElementById('grandTotal').textContent = fmt(total);
  }

  addItemRow();
})();

/* Mim Halı Yıkama — servis çalışanı kaydı (çevrimdışı destek)
 * Ayrı dosyada, mevcut sipariş formu mantığına dokunmadan eklenmiştir.
 * Kayıt başarısız olursa uygulama normal şekilde çalışmaya devam eder. */
(function () {
  'use strict';
  if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return;
  if (location.protocol === 'file:') return;
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('sw.js').catch(function () { /* sessiz */ });
  });
})();

/* Mim Halı Yıkama — çift gönderim koruması
 * Mobilde bağlantı yavaşken kullanıcı butona ikinci kez basıyor; sunucu
 * iki ödemeyi (denetimde kanıtlandı: aynı saniyede 2 kayıt) ve iki siparişi
 * de kaydediyordu. İlk gönderimde form işaretlenir; ikinci submit
 * preventDefault ile durdurulur.
 * ⚠ BUTON KİLİTLENMEZ (disabled): submit olayı içinde buton kapatılırsa
 *   Chromium gönderimi İPTAL ediyor (denetimde iki kez ölçüldü: form
 *   sunucuya hiç ulaşmıyor). Kilit yok; buton yalnız görsel soluklaşır. */
(function () {
  'use strict';
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form) return;
    // fotocompress.js sıkıştırmadan sonra formu requestSubmit() ile KENDİ
    // yeniden gönderir (__mimGonderiliyor=true). Bu meşru bir programatik
    // gönderimdir — denetimde ölçüldü: guard karışırsa fotoğraf HİÇ
    // yüklenmiyor (ikinci submit engelleniyor).
    if (form.__mimGonderiliyor) return;
    if (form.__mimGonderildi) { ev.preventDefault(); return; }
    form.__mimGonderildi = true;
    var btn = form.querySelector('button[type="submit"], button:not([type])');
    if (btn) btn.classList.add('btn-gonderildi');
  }, true);
})();

/* Mim Halı Yıkama — "Daha fazla" menü paneli (bottomnav ⋯)
 * DÜZELTME (19.10.2026): mimSheet çağrılıyordu ama HİÇ TANIMLI DEĞİLDİ —
 * tıklamada menü paneli açılmıyor, tarayıcı href yedeğine (?p=siparisler)
 * gidiyordu; Personel/Kasa/Giderler/Ayarlar ekranlarına menüden ulaşım
 * kopuyordu. showModal'ı olmayan eski tarayıcılar için open attribute yedeği. */
window.mimSheet = function (ev, id) {
  if (ev) ev.preventDefault();
  var d = document.getElementById(id);
  if (!d) return;
  if (typeof d.showModal === 'function') d.showModal();
  else d.setAttribute('open', '');
};
document.addEventListener('click', function (ev) {
  var el = ev.target;
  var btn = el.closest ? el.closest('[data-close]') : null;
  if (!btn) return;
  var d = btn.closest('dialog');
  if (d) d.close();
});

/* Mim Halı Yıkama — koyu/açık mod düğmesi (topbar 🌙). Seçim localStorage'da
 * tutulur; layout_head'teki satır-içi betik tema flaşını önlemek için HTML
 * basılmadan uygular. meta theme-color da uyumlu güncellenir. */
(function () {
  'use strict';
  var dugme = document.getElementById('temaDugme');
  if (!dugme) return;
  var meta = document.querySelector('meta[name="theme-color"]');
  var koyuMu = function () {
    var t = document.documentElement.dataset.theme;
    if (t === 'dark') return true;
    if (t === 'light') return false;
    return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  };
  var uygula = function () {
    dugme.textContent = koyuMu() ? '☀️' : '🌙';
    if (meta) meta.setAttribute('content', koyuMu() ? '#0b1220' : '#10243A');
  };
  dugme.addEventListener('click', function () {
    var simdi = koyuMu() ? 'light' : 'dark';
    document.documentElement.dataset.theme = simdi;
    try { localStorage.setItem('mimTema', simdi); } catch (e) {}
    uygula();
  });
  uygula();
})();
}
