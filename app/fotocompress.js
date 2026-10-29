/* Mim Halı Yıkama — istem tarafı fotoğraf sıkıştırma (bağımlılık yok, CDN yok)
 *
 * NEDEN: Sunucuda GD yok, sunucu tarafında yeniden boyutlandırma YAPILAMAZ.
 * PNG ~5,5 MB vs JPEG q78 ~450 KB (ölçüldü) — PNG seçmek yer kazandırmaz,
 * tersine kaybettirir. Sıkıştırma tek yolu TARAYICIDA, yüklemeden önce.
 *
 * DİSİPLİN: başarısızlık ASLA sahte başarı üretmez. JPEG'e çevrilemeyen
 * (HEIC, bozuk, canvas desteklemeyen) dosyada ORİJİNALİ döndürürüz; sunucu
 * kendi doğrulamasını yapsın. Boş/bozuk dosya üretmektense hiç sıkıştırmamak
 * yeğdir — kullanıcının fotoğrafı kaybolmaz.
 */
(function (global) {
  'use strict';

  // Sunucunun kabul ettiği türler (foto.php PHOTO_MIME) — dışarıda bırakıyoruz
  var KABUL_EDILEN = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

  var VARSAYILAN = {
    maxYon: 1600,        // maksimum kenar uzunluğu (px)
    kalite: 0.78,        // JPEG kalitesi (0..1)
    minKazanc: 1.02,     // bu orandan az küçülme fayda değil → orijinal kalsın
    atla: 220 * 1024,    // zaten küçük ve küçük boyutlu → dokunma (yeniden kodlama kazancı artmaz)
    sunucuSinir: 8192    // sunucunun kabul ettiği en büyük kenar (foto.php PHOTO_MAX_EDGE)
  };

  /** Tek bir fotoğrafın sıkıştırılması bu süreyi aşarsa vazgeçilir: özgün
   *  dosya gönderilir (kullanıcının verisi kaybolmaz), form yine de gönderilir.
   *  Ölçülen hata: canvas/Image yüklemesi takılınca "Sıkıştırılıyor… 0%"
   *  ekranında kalıcı kalıyor ve form hiç gönderilmiyordu. */
  var SIKISTIRMA_ASIMI_MS = 15000;

  function ayarlar(opts) {
    opts = opts || {};
    var o = {};
    for (var k in VARSAYILAN) if (Object.prototype.hasOwnProperty.call(VARSAYILAN, k)) o[k] = VARSAYILAN[k];
    // hem Türkçe hem İngilizce seçenek adlarını kabul et
    if (opts.maxYon != null || opts.maxEdge != null) o.maxYon = Number(opts.maxYon != null ? opts.maxYon : opts.maxEdge);
    if (opts.kalite != null || opts.quality != null) o.kalite = Number(opts.kalite != null ? opts.kalite : opts.quality);
    if (!isFinite(o.maxYon) || o.maxYon < 1) o.maxYon = VARSAYILAN.maxYon;
    if (!isFinite(o.kalite) || o.kalite <= 0 || o.kalite > 1) o.kalite = VARSAYILAN.kalite;
    return o;
  }

  function FileReaderOku(file) {
    return new Promise(function (resolve, reject) {
      var fr = new FileReader();
      fr.onload = function () { resolve(fr.result); };
      fr.onerror = function () { reject(fr.error || new Error('dosya okunamadı')); };
      fr.onabort = function () { reject(new Error('dosya okuma iptal')); };
      fr.readAsArrayBuffer(file);
    });
  }

  /* EXIF orientation (1..8). createImageBitmap 'from-image' destekliyorsa
   * gerekmez; <img> yolu modern tarayıcılarda zaten 'from-image' varsayılan.
   * Yine de hesaplarız: eski Android WebView'de telefon fotoğrafı 90° yan
   * çıkması kritik bir hata, bu satırlar onu kapatır. */
  function exifOrientation(buf) {
    try {
      if (!buf || buf.byteLength < 4) return 1;
      var dv = new DataView(buf);
      if (dv.getUint16(0, false) !== 0xFFD8) return 1;              // JPEG imzası yok → EXIF yok
      var off = 2;
      while (off + 4 <= dv.byteLength) {
        if (dv.getUint8(off) !== 0xFF) break;
        var marker = dv.getUint8(off + 1);
        if (marker === 0xD8 || marker === 0x01 || (marker >= 0xD0 && marker <= 0xD7)) { off += 2; continue; }
        if (marker === 0xDA || marker === 0xD9) break;               // görüntü verisi başladı
        var len = dv.getUint16(off + 2, false);
        if (len < 2 || off + 2 + len > dv.byteLength) break;
        if (marker === 0xE1 && dv.getUint32(off + 4, false) === 0x45786966) { // "Exif"
          var tiff = off + 10;
          var le = dv.getUint16(tiff, false) === 0x4949;
          if (dv.getUint16(tiff + 2, le) !== 0x002A) return 1;
          var ifd0 = tiff + dv.getUint32(tiff + 4, le);
          if (ifd0 + 2 > dv.byteLength) return 1;
          var n = dv.getUint16(ifd0, le);
          for (var i = 0; i < n; i++) {
            var e = ifd0 + 2 + i * 12;
            if (e + 12 > dv.byteLength) break;
            if (dv.getUint16(e, le) === 0x0112) {                     // Orientation
              var o = dv.getUint16(e + 8, le);
              return (o >= 1 && o <= 8) ? o : 1;
            }
          }
          return 1;
        }
        off += 2 + len;
      }
      return 1;
    } catch (err) { return 1; }
  }

  function bitmapDestekli() {
    return typeof global.createImageBitmap === 'function';
  }

  /* EXIF yönlendirmesini ve boyutlandırmayı tek seferde canvas'a uygular.
   * dönüş: [a,b,c,d,e,f] — ctx.transform için. */
  function yonMatrisi(orient, sw, sh, r) {
    var takas = orient >= 5 && orient <= 8;
    var DW = Math.max(1, Math.round(takas ? sh * r : sw * r));
    var DH = Math.max(1, Math.round(takas ? sw * r : sh * r));
    switch (orient) {
      case 2: return [r, 0, 0, r, DW, 0];
      case 3: return [-r, 0, 0, -r, DW, DH];
      case 4: return [r, 0, 0, -r, 0, DH];
      case 5: return [0, r, r, 0, 0, 0];
      case 6: return [0, r, -r, 0, DW, 0];
      case 7: return [0, -r, -r, 0, DW, DH];
      case 8: return [0, -r, r, 0, 0, DH];
      default: return [r, 0, 0, r, 0, 0];
    }
  }

  function tuvalCiz(img, sw, sh, maxYon, orient) {
    var r = Math.min(1, maxYon / Math.max(sw, sh));
    var takas = orient >= 5 && orient <= 8;
    var cv = document.createElement('canvas');
    cv.width = Math.max(1, Math.round(takas ? sh * r : sw * r));
    cv.height = Math.max(1, Math.round(takas ? sw * r : sh * r));
    var ctx = cv.getContext('2d');
    if (!ctx) return null;
    // JPEG şeffaflığı desteklemez → arka planı beyaz doldur (yoksa PNG'deki
    // saydam bölgeler siyaha çöker ve görsel bozuk görünür)
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, cv.width, cv.height);
    ctx.imageSmoothingEnabled = true;
    if ('imageSmoothingQuality' in ctx) ctx.imageSmoothingQuality = 'high';
    var m = yonMatrisi(orient, sw, sh, r);
    ctx.transform(m[0], m[1], m[2], m[3], m[4], m[5]);
    ctx.drawImage(img, 0, 0, sw, sh);
    return cv;
  }

  function tuvalSerbestBirak(cv) {
    if (!cv) return;
    try { cv.width = 0; cv.height = 0; } catch (e) { /* yoksay */ }
  }

  function tuvalBlob(cv, kalite) {
    return new Promise(function (resolve, reject) {
      if (typeof cv.toBlob !== 'function') { reject(new Error('canvas.toBlob yok')); return; }
      try {
        cv.toBlob(function (b) { b ? resolve(b) : reject(new Error('toBlob boş döndü')); },
          'image/jpeg', kalite);
      } catch (e) { reject(e); }
    });
  }

  function adUret(file) {
    var ad = (file && file.name ? file.name : 'foto').replace(/\.[^.]*$/, '');
    ad = ad.replace(/[^\w\-.]+/g, '_').slice(0, 60) || 'foto';
    return ad + '.jpg';
  }

  function skalerVar(srcW, srcH, maxYon) {
    return Math.max(srcW, srcH) > maxYon;
  }

  /* Kaynak görüntüyü açar: createImageBitmap (hızlı, EXIF'i doğru uygular)
   * yoksa <img> + FileReader + elle EXIF düzeltmesi. */
  function kaynakAc(file, opts) {
    if (bitmapDestekli()) {
      var so = { imageOrientation: 'from-image' };
      return global.createImageBitmap(file, so)
        .catch(function () { return global.createImageBitmap(file); })  // seçenek desteklenmiyorsa düz aç
        .then(function (bmp) { return { src: bmp, sw: bmp.width, sh: bmp.height, orient: 1, kapat: function () { if (bmp.close) bmp.close(); } }; });
    }
    return FileReaderOku(file).then(function (buf) {
      var orient = exifOrientation(buf);
      return new Promise(function (resolve, reject) {
        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function () {
          URL.revokeObjectURL(url);
          resolve({ src: img, sw: img.naturalWidth || img.width, sh: img.naturalHeight || img.height,
            orient: orient, kapat: function () {} });
        };
        img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('görüntü açılamadı')); };
        img.src = url;
      });
    });
  }

  /* Ayrıntılı sürüm: istatistik döndürür (ilerleme göstergesi bunu kullanır).
   * { file, sikistirildi, eskiBayt, yeniBayt, neden } */
  function ayrintiliSikistir(file, opts) {
    opts = ayarlar(opts);
    var sonuc = { file: file, sikistirildi: false, eskiBayt: file ? file.size : 0, yeniBayt: file ? file.size : 0, neden: '' };
    return Promise.resolve().then(function () {
      if (!file || typeof file.size !== 'number' || file.size <= 0) throw new Error('dosya yok');
      var tip = (file.type || '').toLowerCase();
      if (tip && KABUL_EDILEN.indexOf(tip) === -1) throw new Error('desteklenmeyen tür: ' + tip);

      var k = kaynakAc(file, opts);
      return k.then(function (kaynak) {
        var cv = null;
        try {
          if (!kaynak.sw || !kaynak.sh) throw new Error('boyut okunamadı');
          // zaten küçük ve küçük dosya → yeniden kodlama kazanç sağlamaz, dokunma
          if (!skalerVar(kaynak.sw, kaynak.sh, opts.maxYon) && file.size <= opts.atla) {
            sonuc.neden = 'zaten küçük';
            return sonuc;
          }
          cv = tuvalCiz(kaynak.src, kaynak.sw, kaynak.sh, opts.maxYon, kaynak.orient);
          if (!cv) throw new Error('canvas alınamadı');
          // Sunucu sınırını aşan fotoğrafta "kazanç yok" gerekçesiyle orijinali
          // göndermek yükleme hatasına yol açar: o durumda çıktı büyük olsa da
          // küçültülmüş hâli gönderilir.
          var zorunlu = Math.max(kaynak.sw, kaynak.sh) > opts.sunucuSinir;
          return tuvalBlob(cv, opts.kalite).then(function (blob) {
            if (!blob || blob.size <= 0) throw new Error('boş blob');
            var yeniBayt = blob.size;
            if (!zorunlu && yeniBayt >= file.size * opts.minKazanc) {
              sonuc.neden = 'kazanç yok (küçük görsel)';
              sonuc.yeniBayt = yeniBayt;
              return sonuc;
            }
            var yeniAd = adUret(file);
            // File kurucusu varsa HER ZAMAN File üret: isim ve tip korunur.
            // (Eskiden yalnız 1 MB üstünde File yapılıyordu; küçük çıktı isimsiz
            // Blob olarak kalıyor ve bazı tarayıcılarda dosya adı "blob" oluyordu.)
            var yeni = file.slice && typeof File === 'function'
              ? new File([blob], yeniAd, { type: 'image/jpeg', lastModified: file.lastModified || Date.now() })
              : (function () {
                  var b = blob;
                  try { b.name = yeniAd; } catch (e) { /* salt-okunur olabilir */ }
                  return b;
                })();
            sonuc.file = yeni;
            sonuc.sikistirildi = true;
            sonuc.yeniBayt = yeniBayt;
            sonuc.neden = 'sıkıştırıldı';
            return sonuc;
          });
        } finally {
          tuvalSerbestBirak(cv);
          try { kaynak.kapat(); } catch (e) { /* yoksay */ }
        }
      });
    }).catch(function (err) {
      // HATA = ORİJİNAL. Sunucu doğrulaması kendi hatasını versin.
      sonuc.file = file;
      sonuc.sikistirildi = false;
      sonuc.yeniBayt = file ? file.size : 0;
      sonuc.neden = (err && err.message) ? err.message : 'bilinmeyen hata';
      return sonuc;
    });
  }

  /* Sadece sıkıştırılmış dosyayı döndürür. Başarısız olursa orijinali döner —
   * çağıran taraf ayırt etmek isterse ayrintiliSikistir()'i kullanabilir. */
  function compressImage(file, opts) {
    return ayrintiliSikistir(file, opts).then(function (r) { return r.file; });
  }

  function baytMetraj(b) {
    if (!b || !isFinite(b)) return '—';
    if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
    if (b >= 1024) return Math.round(b / 1024) + ' KB';
    return b + ' B';
  }

  function yuzde(n) {
    if (n > 0 && isFinite(n)) return Math.max(1, Math.round(n)) + '%';
    return '—';
  }

  /* ---------------------------------------------------------------- form bağlantısı */

  function butonBul(form) {
    return form.querySelector('button[type="submit"], input[type="submit"], button:not([type])');
  }

  function durumKutusu(form) {
    var k = form.querySelector('.fc-durum');
    if (!k) {
      k = document.createElement('p');
      k.className = 'fc-durum muted small';
      k.setAttribute('role', 'status');
      k.setAttribute('aria-live', 'polite');
      k.style.margin = '8px 0 0';
      var b = butonBul(form);
      if (b && b.parentNode) b.parentNode.insertBefore(k, b.nextSibling);
      else form.appendChild(k);
    }
    return k;
  }

  function dataTransferVar() {
    try { return typeof DataTransfer === 'function' && !!new DataTransfer(); }
    catch (e) { return false; }
  }

  /* foto[] girdilerindeki dosyaları sıkıştırıp forma geri yazar.
   * DataTransfer yoksa dosya listesi değiştirilemez → dokunmadan geçer
   * (sıkıştırma yapılmaz, kullanıcı yine de orijinalini yükler). */
  /* Sıkıştırma sözünün asılı kalmasını engelleyen zaman aşımı.
   * Tarayıcıda canvas/Image yüklemesi takılabilir (bellek, WebView, bozuk dosya).
   * Böyle bir durumda kullanıcı fotoğrafını yükleyemez ve sebebini anlamaz —
   * "Sıkıştırılıyor… 0%" ekranında sonsuza kadar kalır.
   * AŞILDIĞINDA: özgün dosya gönderilir (veri kaybı YOK), kullanıcı bilgilendirilir.
   * Not: yarışta asıl işi biten söz de olabilir; ilk çözülen kazanır. */
  function zamanAsimiyla(sure, is, etiket) {
    return new Promise(function (coz, reddet) {
      var bitti = false;
      var zaman = setTimeout(function () {
        if (bitti) return;
        bitti = true;
        reddet(new Error(etiket || 'zaman aşımı'));
      }, sure);
      is.then(function (v) {
        if (bitti) return;
        bitti = true; clearTimeout(zaman); coz(v);
      }, function (h) {
        if (bitti) return;
        bitti = true; clearTimeout(zaman); reddet(h);
      });
    });
  }

  function yukleFormunuKur(formEl) {
    if (!formEl || formEl.__mimSikistiriliyor) return null;
    var fileInputs = Array.prototype.slice.call(formEl.querySelectorAll('input[type="file"]'))
      .filter(function (i) { return i.multiple || i.type === 'file'; });
    if (!fileInputs.length) return null;

    var calisiyor = false;

    function yaz(inp, dosyalar) {
      var dt = new DataTransfer();
      for (var i = 0; i < dosyalar.length; i++) dt.items.add(dosyalar[i]);
      inp.files = dt.files;
    }

    formEl.addEventListener('submit', function (ev) {
      if (formEl.__mimGonderiliyor) {       // sıkıştırılmış hâliyle GERÇEK gönderim
        formEl.__mimGonderiliyor = false;
        calisiyor = false;
        return;                              // devam et → tarayıcı formu gönderir
      }
      if (calisiyor) return;                 // kendi yeniden gönderimimiz
      if (!dataTransferVar()) return;         // sessizce orijinalle gönder
      var isler = [];
      for (var i = 0; i < fileInputs.length; i++) {
        var inp = fileInputs[i];
        if (inp.files && inp.files.length) isler.push({ inp: inp, liste: Array.prototype.slice.call(inp.files) });
      }
      var toplam = 0;
      for (var j = 0; j < isler.length; j++) toplam += isler[j].liste.length;
      if (!toplam) return;                    // dosya yok → normal akış

      ev.preventDefault();
      calisiyor = true;

      var kutu = durumKutusu(formEl);
      var btn = butonBul(formEl);
      var btnMetin = btn ? btn.innerHTML : '';
      var baslangic = Date.now();
      var eskiToplam = 0, yeniToplam = 0, sikan = 0, hatali = 0;
      for (var k = 0; k < isler.length; k++) {
        for (var l = 0; l < isler[k].liste.length; l++) eskiToplam += isler[k].liste[l].size;
      }
      if (btn) { btn.disabled = true; }
      kutu.style.color = '';
      kutu.textContent = 'Sıkıştırılıyor… 0%';

      var bitti = 0;
      var zincir = Promise.resolve();
      for (var m = 0; m < isler.length; m++) {
        (function (is) {
          var yeniListe = [];
          for (var n = 0; n < is.liste.length; n++) {
            (function (idx) {
              zincir = zincir.then(function () {
                // Asılı kalmayı engelle: sıkıştırma takılırsa özgün dosya gönderilir
                return zamanAsimiyla(SIKISTIRMA_ASIMI_MS, ayrintiliSikistir(is.liste[idx]),
                  'sıkıştırma zaman aşımı').then(function (r) {
                  if (r.sikistirildi) { sikan++; yeniToplam += r.yeniBayt; }
                  else { hatali++; yeniToplam += r.yeniBayt; }
                  yeniListe[idx] = r.file;   // hata da olsa orijinal dosya yazılır
                  bitti++;
                  kutu.textContent = 'Sıkıştırılıyor… ' + yuzde(bitti / toplam * 100);
                });
              });
            })(n);
          }
          is.bittiListe = yeniListe;
        })(isler[m]);
      }

      zincir.then(function () {
        try {
          for (var q = 0; q < isler.length; q++) {
            if (isler[q].bittiListe && isler[q].bittiListe.length === isler[q].liste.length) {
              yaz(isler[q].inp, isler[q].bittiListe);
            }
          }
        } catch (e) {
          hatali++;  // dosya listesi yazılamadı → orijinalle devam
        }
        var sn = ((Date.now() - baslangic) / 1000).toFixed(1).replace('.', ',');
        var kazanc = eskiToplam - yeniToplam;
        var ozet = 'Sıkıştırıldı: ' + baytMetraj(eskiToplam) + ' → ' + baytMetraj(yeniToplam)
          + ' (%' + yuzde(eskiToplam ? kazanc / eskiToplam * 100 : 0) + ' tasarruf, ' + sn + ' sn)';
        if (hatali > 0) ozet += ' · ' + hatali + ' dosya sıkıştırılamadı (özgün hâliyle gönderiliyor)';
        kutu.textContent = 'Yükleniyor… ' + ozet;
        // ⚠ 'calisiyor' BURADA sıfırlanmaz. Aşağıdaki yeniden gönderim
        //   requestSubmit() ile "submit" olayını tekrar tetikler; bayrak sıfır
        //   olsaydı koruma çalışmaz, form tekrar sıkıştırmaya düşer ve
        //   sonsuz döngü oluşurdu (ölülen hata). Bayrak ancak gerçek gönderim
        //   başladığında, aşağıdaki tek seferlik muhafız sayesinde temizlenir.
        // ⚠ YENİDEN GÖNDERİM — burada tuzak var:
        //   requestSubmit() "submit" OLAYINI YENİDEN TETİKLER. Bu olay aynı
        //   dinleyiciye geri döner; `calisiyor` bayrağı ZATEN false yapıldığı
        //   için koruma devreye girmez ve form tekrar sıkıştırmaya düşer.
        //   Ölçüldü: olay saniyelerce kez tekrarlandı, "Sıkıştırılıyor… 0%"
        //   takılı kalıyor ve form HİÇ gönderilmiyordu (foto kayboluyordu).
        //   Çözüm: bayrağı false YAPMADAN gönderim yapılır — ikinci tur
        //   dinleyiciye girince "kendi yeniden gönderimimiz" olarak elenip
        //   gerçek gönderime düşer. Ayrıca tek seferlik muhafız da var.
        formEl.__mimGonderiliyor = true;
        if (typeof formEl.requestSubmit === 'function') {
          try { formEl.requestSubmit(); return; } catch (e) { /* eski tarayıcı */ }
        }
        formEl.submit();
      }).catch(function () {
        // Sıkıştırma zinciri patlarsa: kullanıcı işini kaybetmez,
        // orijinal dosyalarla gönder.
        calisiyor = false;
        if (btn) btn.disabled = false;
        kutu.textContent = 'Sıkıştırılamadı, fotoğraflar özgün hâliyle gönderiliyor…';
        formEl.submit();
      });
    });

    return function ayir() { formEl.removeEventListener('submit', formEl.__mimSikistiriliyor); };
  }

  var API = {
    compressImage: compressImage,
    ayrintiliSikistir: ayrintiliSikistir,
    yukleFormunuKur: yukleFormunuKur,
    baytMetraj: baytMetraj,
    varsayilan: function () { var o = {}; for (var k in VARSAYILAN) o[k] = VARSAYILAN[k]; return o; }
  };

  global.mimFotocompress = API;

  // Sayfa hazır olduğında [data-mim-sikistir] taşıyan formları otomatik bağla.
  // Ortam eksikse (canvas yok, eski WebView) burası patlamaz: dosya yine de
  // yüklenir, form sıkıştırmasız (özgün dosyalarla) çalışmaya devam eder.
  if (typeof document !== 'undefined' && typeof document.querySelectorAll === 'function') {
    var baslat = function () {
      var formlar;
      try { formlar = document.querySelectorAll('.photoform[data-mim-sikistir], form[data-mim-sikistir]'); }
      catch (e) { return; }
      for (var i = 0; i < formlar.length; i++) {
        try {
          if (!formlar[i].__mimSikistiriliyor) yukleFormunuKur(formlar[i]);
        } catch (e2) { /* tek form bağlanamazsa diğerleri etkilenmez */ }
      }
    };
    if (document.readyState === 'loading' && typeof document.addEventListener === 'function') {
      document.addEventListener('DOMContentLoaded', baslat);
    } else {
      baslat();
    }
  }
})(typeof window !== 'undefined' ? window : this);