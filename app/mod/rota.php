<?php
/**
 * Rota / günlük sevkiyat planı — mahalle (ilçe) bazlı toplama ve dağıtım.
 *
 * Rakiplerin rota ekranı harita + optimizasyon satıyor; burada harici API
 * olmadan aynı işi gören sade bir sürüm var: siparişleri teslim tarihine ve
 * ilçeye göre gruplar, her satıra harita bağlantısı ve telefon koyar,
 * yazdırılabilir sevkiyat föyü üretir. Durum değiştirmez — salt okunur.
 */

function rota_sayfa(): void
{
    need_perm('rota.gor');

    $tur = (($_GET['tur'] ?? 'teslimat') === 'alim') ? 'alim' : 'teslimat';
    $tarih = trim((string)($_GET['tarih'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) $tarih = today();

    $rows = rota_siparisler($tur, $tarih);
    $gruplar = rota_grupla($rows);

    if (($_GET['yazdir'] ?? '') === '1') {
        rota_yazdir($tur, $tarih, $gruplar);
        return;
    }

    $toplamParca = 0;
    foreach ($rows as $r) $toplamParca += (int)$r['parca'];

    layout_head($tur === 'alim' ? 'Alım Rotası' : 'Teslimat Rotası', 'rota');
    ?>
<div class="card">
  <form method="get" class="form" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
    <input type="hidden" name="p" value="rota">
    <label style="flex:1 1 140px">Tarih
      <input type="date" name="tarih" value="<?= e($tarih) ?>">
    </label>
    <label style="flex:1 1 140px">Tür
      <select name="tur">
        <option value="teslimat"<?= $tur === 'teslimat' ? ' selected' : '' ?>>Teslimat</option>
        <option value="alim"<?= $tur === 'alim' ? ' selected' : '' ?>>Alım</option>
      </select>
    </label>
    <button class="btn btn-primary" style="flex:0 0 auto">Göster</button>
    <a class="btn" style="flex:0 0 auto" href="?p=rota&amp;tur=<?= e($tur) ?>&amp;tarih=<?= e($tarih) ?>&amp;yazdir=1" target="_blank">🖨 Yazdır</a>
  </form>
</div>

<div class="hizli-grid">
  <div class="stat"><b><?= count($rows) ?></b><span>Sipariş</span></div>
  <div class="stat"><b><?= (int)$toplamParca ?></b><span>Parça</span></div>
  <div class="stat"><b><?= count($gruplar) ?></b><span>İlçe / mahalle</span></div>
</div>

<?php if (!$rows): ?>
  <div class="card bos-durum">
    <p><b><?= e(tr_date($tarih)) ?></b> için <?= $tur === 'alim' ? 'alınacak' : 'teslim edilecek' ?> sipariş yok.</p>
    <p class="muted small">Sipariş kartındaki teslim tarihini güncelleyerek plana ekleyebilirsin.</p>
  </div>
<?php else: ?>
  <?php foreach ($gruplar as $ilce => $g): ?>
    <div class="card">
      <div class="orderhead">
        <div><b><?= e($ilce !== '' ? $ilce : 'İlçe belirtilmemiş') ?></b><br>
          <small class="muted"><?= count($g) ?> sipariş · <?= array_sum(array_column($g, 'parca')) ?> parça</small></div>
        <span class="badge" style="background:#10243A"><?= count($g) ?></span>
      </div>
      <?php foreach ($g as $r): ?>
        <div class="row">
          <span class="row-main">
            <a href="?p=siparis&amp;id=<?= (int)$r['id'] ?>"><b><?= e((string)$r['code']) ?></b></a>
            <?= status_badge((string)$r['status']) ?>
            <br><small><?= e((string)$r['ad']) ?><?= (string)$r['telefon'] !== '' ? ' · ' . e((string)$r['telefon']) : '' ?></small>
            <?php if (trim((string)$r['adres']) !== ''): ?>
              <br><small>📍 <?= e((string)$r['adres']) ?><?= (string)$r['adres_notu'] !== '' ? ' · ' . e((string)$r['adres_notu']) : '' ?></small>
            <?php endif; ?>
            <?php if (trim((string)$r['not']) !== ''): ?>
              <br><small class="muted">Not: <?= e((string)$r['not']) ?></small>
            <?php endif; ?>
          </span>
          <span class="row-side" style="display:flex;flex-direction:column;gap:4px;align-items:flex-end">
            <small><b><?= (int)$r['parca'] ?> parça</b></small>
            <?php if (trim((string)$r['harita']) !== ''): ?>
              <a class="btn btn-sm" target="_blank" rel="noopener" href="<?= e((string)$r['harita']) ?>">🗺 Yol tarifi</a>
            <?php endif; ?>
            <?php if (trim((string)$r['telefon']) !== ''): ?>
              <a class="btn btn-sm" href="<?= e(tel_href((string)$r['telefon'])) ?>">📞 Ara</a>
            <?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php
    layout_foot('rota');
}

/**
 * Rotaya girecek siparişler.
 *  - teslimat: seçilen teslim tarihli, kapanmamış siparişler
 *  - alım: servis ile alınacak, henüz teslim alınmamış siparişler
 */
function rota_siparisler(string $tur, string $tarih): array
{
    $sql = 'SELECT o.id,o.code,o.status,o.delivery_date,o.delivery_time,o.pickup_date,o.note,
                   o.ship_district,o.ship_address,o.ship_map_url,o.ship_label,
                   c.name ad,c.phone telefon
            FROM orders o JOIN customers c ON c.id=o.customer_id';
    $params = [];

    if ($tur === 'alim') {
        $sql .= " WHERE o.pickup_type='servis'
                  AND o.status IN ('yeni','alim_bekliyor')
                  AND (o.pickup_date=? OR o.pickup_date='')";
        $params[] = $tarih;
    } else {
        $sql .= " WHERE o.delivery_date=?
                  AND o.status NOT IN ('teslim','iptal')";
        $params[] = $tarih;
    }
    $sql .= ' ORDER BY o.ship_district, o.ship_address, o.id';

    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    // Parça sayısı tek sorguda: sipariş başına kalem adedi toplamı.
    if ($rows) {
        $ids = array_map(static fn($r) => (int)$r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q = db()->prepare("SELECT order_id, COALESCE(SUM(qty),0) FROM order_items
                            WHERE order_id IN ($in) GROUP BY order_id");
        $q->execute($ids);
        $adet = [];
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $p) $adet[(int)$p[0]] = (int)$p[1];
        foreach ($rows as &$r) {
            $r['parca'] = $adet[(int)$r['id']] ?? 0;
            $r['adres'] = trim((string)$r['ship_address']);
            $r['adres_notu'] = trim((string)$r['ship_label']);
            $r['harita'] = trim((string)$r['ship_map_url']);
            if ($r['harita'] === '' && $r['adres'] !== '') {
                $r['harita'] = 'https://www.google.com/maps/search/?api=1&query='
                    . rawurlencode($r['adres'] . ' ' . (string)$r['ship_district']);
            }
            $r['ilce'] = trim((string)$r['ship_district']);
        }
        unset($r);
    }
    return $rows;
}

/** Siparişleri ilçeye göre grupla; boş ilçe en sona. */
function rota_grupla(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $k = (string)$r['ilce'];
        $out[$k][] = $r;
    }
    if (isset($out[''])) {
        $bos = $out[''];
        unset($out['']);
        $out[''] = $bos;
    }
    return $out;
}

/** Yazdırma dostu sevkiyat föyü (menüsüz, A4). */
function rota_yazdir(string $tur, string $tarih, array $gruplar): void
{
    $ad = setting('isletme_adi', 'Mim Halı Yıkama');
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<title><?= e($tur === 'alim' ? 'Alım Rotası' : 'Teslimat Rotası') ?> — <?= e(tr_date($tarih)) ?></title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:16px;color:#111}
h1{font-size:18px;margin:0 0 2px}
.meta{color:#555;font-size:12px;margin-bottom:14px}
h2{font-size:14px;margin:16px 0 6px;border-bottom:2px solid #111;padding-bottom:2px}
table{width:100%;border-collapse:collapse;font-size:12px}
th,td{border-bottom:1px solid #ddd;padding:5px 4px;text-align:left;vertical-align:top}
th{background:#f3f4f6;font-size:11px;text-transform:uppercase;letter-spacing:.03em}
.kod{font-weight:700;white-space:nowrap}
.not{color:#555}
.cek{page-break-inside:avoid}
@media print{body{margin:8mm}h2{page-break-after:avoid}}
</style></head><body>
<h1><?= e($ad) ?> — Sevkiyat Föyü · <?= e($tur === 'alim' ? 'Alım Rotası' : 'Teslimat Rotası') ?></h1>
<div class="meta"><?= e(tr_date($tarih)) ?> · <?= count($gruplar) ?> bölge · Yazdırma: <?= e(tr_date(now_dt(), true)) ?></div>
<?php if (!$gruplar): ?>
  <p>Bu tarih için planlanmış sipariş yok.</p>
<?php else: foreach ($gruplar as $ilce => $g): ?>
  <div class="cek">
  <h2><?= e($ilce !== '' ? $ilce : 'İlçe belirtilmemiş') ?> (<?= count($g) ?>)</h2>
  <table>
    <tr><th style="width:64px">Kod</th><th>Müşteri</th><th>Adres</th><th style="width:110px">Telefon</th><th style="width:36px">Parça</th><th style="width:110px">Durum</th></tr>
    <?php foreach ($g as $r): ?>
    <tr>
      <td class="kod"><?= e((string)$r['code']) ?></td>
      <td><?= e((string)$r['ad']) ?><?= trim((string)$r['not']) !== '' ? '<br><span class="not">' . e((string)$r['not']) . '</span>' : '' ?></td>
      <td><?= e((string)$r['adres']) ?><?= trim((string)$r['adres_notu']) !== '' ? ' · ' . e((string)$r['adres_notu']) : '' ?></td>
      <td><?= e((string)$r['telefon']) ?></td>
      <td><?= (int)$r['parca'] ?></td>
      <td><?= e(status_labels()[(string)$r['status']] ?? (string)$r['status']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
<?php endforeach; endif; ?>
<script>window.addEventListener('load',function(){setTimeout(function(){window.print();},300);});</script>
</body></html><?php
}
