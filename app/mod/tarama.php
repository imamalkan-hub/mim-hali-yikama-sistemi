<?php
/**
 * QR / barkod okutma — saha hızı için.
 *
 * Telefon kamerasından QR okutur (Chrome/Android'de yerleşik BarcodeDetector),
 * okunan değeri sipariş kartına veya halı takip aramasına çevirir.
 * Destek olmayan tarayıcıda ve masaüstünde elle kod girme alanı kalır.
 * Harici kütüphane yok; kamera yalnız HTTPS'te açılır (site HTTPS).
 */

function tarama_sayfa(): void
{
    need_perm('siparis.gor');

    $kod = trim((string)($_GET['kod'] ?? ''));
    if ($kod !== '') {
        $hedef = tarama_coz($kod);
        if ($hedef !== '') {
            header('Location: ' . $hedef);
            exit;
        }
        $_SESSION['hata'] = 'Bu koda ait sipariş bulunamadı: ' . $kod;
        redirect('tarama');
    }

    layout_head('QR Okut', 'tarama');
    if (!empty($_SESSION['hata'])) {
        echo '<div class="alert">' . e((string)$_SESSION['hata']) . '</div>';
        unset($_SESSION['hata']);
    }
    ?>
<div class="card">
  <div class="orderhead">
    <div><b>Kamera ile okut</b><br><small class="muted">Etiketteki QR kodu sipariş kartını açar; parça kodu halı takibine gider.</small></div>
    <span class="badge" style="background:#10243A">📷</span>
  </div>
  <div id="kameraKutu" style="margin-top:10px">
    <video id="kamera" playsinline muted style="width:100%;max-height:60vh;border-radius:12px;background:#000;display:none"></video>
    <p id="kameraMesaj" class="muted small">Kamerayı başlatmak için düğmeye bas. Tarayıcı izin isteyecek.</p>
    <button type="button" class="btn btn-primary btn-block" id="kameraBaslat">📷 Kamerayı Başlat</button>
  </div>
</div>

<div class="card">
  <h2>Elle kod gir</h2>
  <form method="get" class="form" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="p" value="tarama">
    <label style="flex:2 1 180px">Sipariş / parça kodu
      <input name="kod" placeholder="M-261028-01 veya H-261028-01" autocomplete="off" autocapitalize="characters">
    </label>
    <button class="btn" style="align-self:end">Bul</button>
  </form>
  <p class="muted small" style="margin-bottom:0">QR etiketi sipariş kartından “🔳 QR Etiket” ile yazdırılır.</p>
</div>

<script>
(function () {
  var video = document.getElementById('kamera');
  var mesaj = document.getElementById('kameraMesaj');
  var dugme = document.getElementById('kameraBaslat');
  if (!dugme) return;

  function durum(t) { mesaj.textContent = t; }

  dugme.addEventListener('click', async function () {
    if (!('BarcodeDetector' in window)) {
      durum('Bu tarayıcı kamera ile QR okumayı desteklemiyor. Kodu aşağıdan elle girebilirsin.');
      return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      durum('Kamera erişimi yok. Sayfa HTTPS olmalı ve tarayıcı izin vermeli.');
      return;
    }
    try {
      dugme.disabled = true;
      var akis = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: 'environment' } }, audio: false
      });
      video.style.display = 'block';
      video.srcObject = akis;
      await video.play();
      durum('Kodu ekranın ortasına getir…');
      var okuyucu = new BarcodeDetector({ formats: ['qr_code', 'code_128', 'ean_13'] });
      var dur = false;
      var tara = async function () {
        if (dur) return;
        try {
          var kodlar = await okuyucu.detect(video);
          if (kodlar && kodlar.length) {
            dur = true;
            var deger = kodlar[0].rawValue || '';
            akis.getTracks().forEach(function (t) { t.stop(); });
            durum('Okundu: ' + deger);
            location.href = '?p=tarama&kod=' + encodeURIComponent(deger);
            return;
          }
        } catch (e) { /* kare atlanır */ }
        requestAnimationFrame(tara);
      };
      tara();
    } catch (e) {
      dugme.disabled = false;
      durum('Kamera açılamadı: ' + (e && e.message ? e.message : 'izin verilmedi'));
    }
  });
})();
</script>
<?php
    layout_foot('tarama');
}

/**
 * Okunan metni uygulama içi hedefe çevirir.
 * - Tam URL içinde id varsa → sipariş kartı
 * - Sipariş kodu → sipariş kartı
 * - Parça kodu → halı takibi araması
 */
function tarama_coz(string $kod): string
{
    $kod = trim($kod);
    if ($kod === '') return '';

    if (preg_match('~[?&]id=(\d+)~', $kod, $m)) {
        return '?p=siparis&id=' . (int)$m[1];
    }

    $st = db()->prepare('SELECT id FROM orders WHERE code=? LIMIT 1');
    $st->execute([$kod]);
    $oid = (int)$st->fetchColumn();
    if ($oid > 0) return '?p=siparis&id=' . $oid;

    $st = db()->prepare('SELECT order_id FROM order_items WHERE item_code=? LIMIT 1');
    $st->execute([$kod]);
    $oid = (int)$st->fetchColumn();
    // Parça kodu: halı takibi aramasında o parçayı göster (sipariş kartı değil —
    // saha personeli "bu halı hangi aşamada" sorusunu tek ekranda görür).
    if ($oid > 0) return '?p=halitakip&q=' . rawurlencode($kod);

    return '';
}
