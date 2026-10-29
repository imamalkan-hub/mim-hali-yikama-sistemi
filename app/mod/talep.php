<?php
/**
 * Web sipariş talepleri.
 *
 * İki taraf:
 *  - talep_sayfa()  : KAMUYA AÇIK form (index.php'de login'den ÖNCE yakalanır).
 *  - talepler_sayfa(): yönetici/personel kuyruğu; talebi tek tıkla sipariş
 *    formuna taşır (?p=siparis-yeni&talep=ID).
 *
 * Kötüye kullanım koruması: honeypot alanı, minimum doldurma süresi,
 * IP başına saatlik kota, aynı telefondan 10 dakikalık mükerrer engeli.
 * Hiçbir alan HTML olarak basılmaz; hepsi e() ile kaçırılır.
 */

function talep_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return hash('sha256', $ip . '|' . setting('cron_token', 'mim-talep'));
}

/** mbstring her sunucuda olmayabilir (PHP 7.4 kısıtlı hosting). */
function talep_uzunluk(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

/** Kamuya açık talep formu — kendi minimal iskeleti (oturumsuz). */
function talep_sayfa(): void
{
    $isletme = setting('isletme_adi', 'Mim Halı Yıkama');
    $tel = setting('telefon', '');
    $aktif = setting('talep_aktif', '1') === '1';
    $ok = false; $hata = '';

    if (!$aktif) {
        talep_kabuk($isletme, '<div class="card"><h2>Şu anda kapalı</h2>'
            . '<p>Online talep formu geçici olarak kapalı. Bize telefon veya WhatsApp ile ulaşabilirsiniz.</p>'
            . talep_iletisim_dugmesi($tel) . '</div>');
        return;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        csrf_check();

        $ad = trim((string)($_POST['ad'] ?? ''));
        $telefon = trim((string)($_POST['telefon'] ?? ''));
        $ilce = trim((string)($_POST['ilce'] ?? ''));
        $adres = trim((string)($_POST['adres'] ?? ''));
        $parcalar = trim((string)($_POST['parcalar'] ?? ''));
        $not = trim((string)($_POST['not'] ?? ''));
        $kvkk = isset($_POST['kvkk']);
        $tuzak = trim((string)($_POST['website'] ?? ''));
        $t0 = (int)($_POST['t0'] ?? 0);

        $telNorm = preg_replace('/\D+/', '', $telefon) ?? '';
        if (strlen($telNorm) === 12 && substr($telNorm, 0, 2) === '90') $telNorm = substr($telNorm, 2);
        if (strlen($telNorm) === 11 && substr($telNorm, 0, 1) === '0') $telNorm = substr($telNorm, 1);

        if ($tuzak !== '') {
            $hata = 'Form doğrulanamadı. Sayfayı yenileyip tekrar deneyin.';
        } elseif ($t0 > 0 && (time() - $t0) < 2) {
            $hata = 'Form çok hızlı gönderildi. Sayfayı yenileyip tekrar deneyin.';
        } elseif (talep_uzunluk($ad) < 2 || talep_uzunluk($ad) > 80) {
            $hata = 'Ad soyad 2–80 karakter olmalı.';
        } elseif (strlen($telNorm) < 10 || strlen($telNorm) > 11) {
            $hata = 'Geçerli bir telefon numarası girin.';
        } elseif (talep_uzunluk($parcalar) < 3 || talep_uzunluk($parcalar) > 300) {
            $hata = 'Yıkanacak parçaları kısaca yazın (örn. 3 halı, 1 koltuk).';
        } elseif (talep_uzunluk($adres) > 300 || talep_uzunluk($not) > 500 || talep_uzunluk($ilce) > 80) {
            $hata = 'Alanlardan biri çok uzun.';
        } elseif (!$kvkk) {
            $hata = 'Devam etmek için kişisel veri bilgilendirmesini onaylayın.';
        } else {
            $ipHash = talep_ip_hash();
            $st = db()->prepare("SELECT COUNT(*) FROM requests
                WHERE ip_hash=? AND created_at >= datetime('now','localtime','-1 hour')");
            $st->execute([$ipHash]);
            $sonSaat = (int)$st->fetchColumn();

            $st = db()->prepare("SELECT id FROM requests
                WHERE phone=? AND created_at >= datetime('now','localtime','-10 minutes') LIMIT 1");
            $st->execute([$telNorm]);
            $mukerrer = (int)$st->fetchColumn();

            if ($sonSaat >= 5) {
                $hata = 'Çok fazla talep gönderildi. Lütfen bir saat sonra tekrar deneyin veya bizi arayın.';
            } elseif ($mukerrer > 0) {
                // Aynı kişi iki kez bastı: sessizce başarı göster, kayıt açma.
                $ok = true;
            } else {
                $st = db()->prepare('INSERT INTO requests
                    (name,phone,district,address,note,items,status,source,ip_hash,created_at,updated_at)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?)');
                $st->execute([$ad, $telNorm, $ilce, $adres, $not, $parcalar, 'yeni', 'web',
                    $ipHash, now_dt(), now_dt()]);
                audit('talep_web', 'requests', (int)db()->lastInsertId(), 'web formu');
                $ok = true;
            }
        }
    }

    if ($ok) {
        talep_kabuk($isletme, '<div class="card">'
            . '<h2>Talebiniz alındı ✓</h2>'
            . '<p>En kısa sürede sizi arayıp alım zamanını netleştireceğiz.</p>'
            . '<p class="muted small">Talebiniz işletme paneline düştü; bu ekranı kapatabilirsiniz.</p>'
            . talep_iletisim_dugmesi($tel) . '</div>');
        return;
    }

    $body = '<div class="card"><h2>Ücretsiz alım talebi</h2>'
        . '<p class="muted">Formu doldurun; halılarınızı kapınızdan alalım. '
        . 'Fiyat, parça ve kumaş durumuna göre alım sırasında netleşir.</p>'
        . ($hata !== '' ? '<div class="alert">' . e($hata) . '</div>' : '')
        . '<form method="post" action="?p=talep" class="form">'
        . csrf_field()
        . '<input type="hidden" name="t0" id="talepT0" value="' . time() . '">'
        . '<div style="position:absolute;left:-9999px" aria-hidden="true">'
        . '<label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>'
        . '<label>Ad Soyad<input name="ad" required maxlength="80" autocomplete="name" value="'
            . e((string)($_POST['ad'] ?? '')) . '"></label>'
        . '<label>Telefon<input name="telefon" type="tel" inputmode="tel" required '
            . 'placeholder="05xx xxx xx xx" autocomplete="tel" value="' . e((string)($_POST['telefon'] ?? '')) . '"></label>'
        . '<label>Mahalle / İlçe<input name="ilce" maxlength="80" placeholder="örn. Haliliye / Şair Nabi" value="'
            . e((string)($_POST['ilce'] ?? '')) . '"></label>'
        . '<label>Adres<textarea name="adres" rows="2" maxlength="300" placeholder="alım yapılacak adres">'
            . e((string)($_POST['adres'] ?? '')) . '</textarea></label>'
        . '<label>Yıkanacak parçalar<textarea name="parcalar" rows="2" required maxlength="300" '
            . 'placeholder="örn. 3 halı, 1 koltuk takımı, 2 yorgan">' . e((string)($_POST['parcalar'] ?? '')) . '</textarea></label>'
        . '<label>Ek not (isteğe bağlı)<textarea name="not" rows="2" maxlength="500">'
            . e((string)($_POST['not'] ?? '')) . '</textarea></label>'
        . '<label style="display:flex;gap:8px;align-items:flex-start;font-weight:400">'
        . '<input type="checkbox" name="kvkk" required style="width:auto;margin-top:3px">'
        . '<span class="small">Kişisel verilerimin bu talep için işlenmesine onay veriyorum '
        . '(KVKK aydınlatma metni sitede).</span></label>'
        . '<button class="btn btn-primary btn-block">Talebi Gönder</button>'
        . '</form></div>'
        . talep_iletisim_dugmesi($tel);

    talep_kabuk($isletme, $body);
}

function talep_iletisim_dugmesi(string $tel): string
{
    if ($tel === '') return '';
    $wa = wa_phone($tel);
    return '<div class="card"><p class="muted small" style="margin-top:0">Acil işler için:</p>'
        . '<p style="display:flex;gap:8px;flex-wrap:wrap;margin:0">'
        . '<a class="btn" href="' . e(tel_href($tel)) . '">📞 ' . e($tel) . '</a>'
        . ($wa !== '' ? '<a class="btn" target="_blank" rel="noopener" href="https://wa.me/' . e($wa) . '">💬 WhatsApp</a>' : '')
        . '</p></div>';
}

/** Kamu sayfası iskeleti — oturumsuz, noindex. */
function talep_kabuk(string $isletme, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#10243A">
<title>Alım Talebi — <?= e($isletme) ?></title>
<link rel="stylesheet" href="style.css">
</head><body class="login-body"><div class="login-box talep-box">
<div class="login-logo">🧼</div>
<h1><?= e($isletme) ?></h1>
<p class="muted">Halı yıkama alım talebi</p>
<?= $body ?>
<?php /* script yok: t0 sunucudan gelir; botlar için yeterli, gerçek kullanıcıyı yormaz */ ?>
</div></body></html><?php
}

/** Yönetim tarafı: talep kuyruğu. */
function talepler_sayfa(): void
{
    need_perm('talep.gor');

    // Durum değiştir / sil / formu aç-kapa
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        csrf_check();
        $eylem = (string)($_POST['eylem'] ?? '');
        if ($eylem === 'ayar') {
            need_perm('talep.yonet');
            setting_set('talep_aktif', isset($_POST['aktif']) ? '1' : '0');
            audit('talep_ayar', 'settings', null, setting('talep_aktif'));
            redirect('talepler');
        }
        if ($eylem === 'durum') {
            need_perm('talep.yonet');
            $id = (int)($_POST['id'] ?? 0);
            $yeni = (string)($_POST['durum'] ?? '');
            if ($id > 0 && in_array($yeni, ['yeni', 'arandi', 'donustu', 'red'], true)) {
                $st = db()->prepare('UPDATE requests SET status=?, updated_at=? WHERE id=?');
                $st->execute([$yeni, now_dt(), $id]);
                audit('talep_durum', 'requests', $id, $yeni);
            }
            redirect('talepler', 'durum=' . urlencode((string)($_GET['durum'] ?? '')));
        }
        if ($eylem === 'sil') {
            need_perm('talep.sil');
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $st = db()->prepare('DELETE FROM requests WHERE id=?');
                $st->execute([$id]);
                audit('talep_sil', 'requests', $id, '');
            }
            redirect('talepler');
        }
    }

    $filtre = (string)($_GET['durum'] ?? '');
    if (!in_array($filtre, ['yeni', 'arandi', 'donustu', 'red'], true)) $filtre = '';
    $q = trim((string)($_GET['q'] ?? ''));

    $where = []; $params = [];
    if ($filtre !== '') { $where[] = 'status=?'; $params[] = $filtre; }
    if ($q !== '') {
        $where[] = '(name LIKE ? OR phone LIKE ? OR district LIKE ? OR items LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql = 'SELECT * FROM requests' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY CASE status WHEN \'yeni\' THEN 0 WHEN \'arandi\' THEN 1 ELSE 2 END, id DESC LIMIT 200';
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $say = ['yeni' => 0, 'arandi' => 0, 'donustu' => 0, 'red' => 0];
    foreach (db()->query('SELECT status, COUNT(*) c FROM requests GROUP BY status')->fetchAll() as $r) {
        $say[(string)$r['status']] = (int)$r['c'];
    }

    layout_head('Web Talepleri', 'talepler');
    ?>
<div class="card">
  <div class="orderhead">
    <div><b>Talep kuyruğu</b><br><small class="muted">Siteden gelen alım talepleri — tek tıkla siparişe dönüştür.</small></div>
    <span class="badge" style="background:#10243A"><?= (int)$say['yeni'] ?> yeni</span>
  </div>
  <div class="hizli-grid" style="margin-top:8px">
    <a class="stat" href="?p=talepler&amp;durum=yeni"><b><?= (int)$say['yeni'] ?></b><span>Yeni</span></a>
    <a class="stat" href="?p=talepler&amp;durum=arandi"><b><?= (int)$say['arandi'] ?></b><span>Arandı</span></a>
    <a class="stat" href="?p=talepler&amp;durum=donustu"><b><?= (int)$say['donustu'] ?></b><span>Sipariş</span></a>
    <a class="stat" href="?p=talepler&amp;durum=red"><b><?= (int)$say['red'] ?></b><span>Red</span></a>
  </div>
  <form method="get" class="form" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
    <input type="hidden" name="p" value="talepler">
    <label style="flex:2 1 180px">Ara<input name="q" value="<?= e($q) ?>" placeholder="ad, telefon, parça"></label>
    <label style="flex:1 1 120px">Durum
      <select name="durum">
        <option value="">Tümü</option>
        <?php foreach (['yeni' => 'Yeni', 'arandi' => 'Arandı', 'donustu' => 'Siparişe döndü', 'red' => 'Red'] as $k => $lbl): ?>
          <option value="<?= e($k) ?>"<?= $filtre === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select></label>
    <button class="btn">Filtrele</button>
  </form>
</div>

<?php if (can('talep.yonet')): ?>
<form method="post" class="card" style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap">
  <?= csrf_field() ?><input type="hidden" name="eylem" value="ayar">
  <div><b>Web formu</b><br><small class="muted">Kapalıyken site ziyaretçisi form yerine telefon/WhatsApp görür.</small></div>
  <label style="display:flex;gap:8px;align-items:center;font-weight:400;margin:0">
    <input type="checkbox" name="aktif" value="1" style="width:auto"<?= setting('talep_aktif', '1') === '1' ? ' checked' : '' ?>>
    Açık
  </label>
  <button class="btn btn-sm">Kaydet</button>
</form>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="card bos-durum"><p>Bu filtrede talep yok.</p></div>
<?php else: foreach ($rows as $r):
    $durum = (string)$r['status'];
    $renk = ['yeni' => '#0e7490', 'arandi' => '#b45309', 'donustu' => '#15803d', 'red' => '#b91c1c'][$durum] ?? '#555';
?>
  <div class="card">
    <div class="orderhead">
      <div><b><?= e((string)$r['name']) ?></b><br>
        <small><?= e((string)$r['phone']) ?><?= (string)$r['district'] !== '' ? ' · ' . e((string)$r['district']) : '' ?></small></div>
      <span class="badge" style="background:<?= $renk ?>"><?= e(['yeni' => 'Yeni', 'arandi' => 'Arandı', 'donustu' => 'Sipariş', 'red' => 'Red'][$durum] ?? $durum) ?></span>
    </div>
    <p style="margin:6px 0"><?= nl2br(e((string)$r['items'])) ?></p>
    <?php if (trim((string)$r['address']) !== ''): ?>
      <p class="muted small" style="margin:2px 0">📍 <?= e((string)$r['address']) ?></p>
    <?php endif; ?>
    <?php if (trim((string)$r['note']) !== ''): ?>
      <p class="muted small" style="margin:2px 0">Not: <?= e((string)$r['note']) ?></p>
    <?php endif; ?>
    <p class="muted small" style="margin:2px 0"><?= e(tr_date((string)$r['created_at'], true)) ?> · <?= e((string)$r['source']) ?></p>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
      <a class="btn btn-sm" href="tel:<?= e((string)$r['phone']) ?>">📞 Ara</a>
      <a class="btn btn-sm" target="_blank" rel="noopener" href="https://wa.me/<?= e(wa_phone((string)$r['phone'])) ?>">💬 WhatsApp</a>
      <?php if (can('talep.yonet')): ?>
        <a class="btn btn-sm btn-primary" href="?p=siparis-yeni&amp;talep=<?= (int)$r['id'] ?>">➕ Siparişe Dönüştür</a>
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="eylem" value="durum"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="durum" value="arandi">
          <button class="btn btn-sm"<?= $durum === 'arandi' ? ' disabled' : '' ?>>☎ Arandı</button>
        </form>
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="eylem" value="durum"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="durum" value="red">
          <button class="btn btn-sm"<?= $durum === 'red' ? ' disabled' : '' ?>>✕ Red</button>
        </form>
      <?php endif; ?>
      <?php if (can('talep.sil')): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Talep silinsin mi?')"><?= csrf_field() ?>
          <input type="hidden" name="eylem" value="sil"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-sm">🗑</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; endif; ?>
<?php
    layout_foot('talepler');
}
