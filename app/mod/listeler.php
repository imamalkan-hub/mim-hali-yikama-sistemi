<?php
/**
 * Listeler modülü: sipariş listesi, halı takibi, parça kodu detayı,
 * alım / teslimat / gecikmiş sahâ listeleri.
 * Tüm listeler mobil önceliklidir; durum değişimi ?p=siparis-durum üzerinden yapılır.
 */

// ---------------------------------------------------------------- yardımcılar

/** LIKE kalıbına güvenli dönüşüm (% _ jokerleri kaçırılır) */
function liste_like(string $s): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($s)) . '%';
}

/** Sorgu dizesi kurar — boş değerler atlanır */
function liste_url(string $page, array $params = []): string
{
    $q = ['p' => $page];
    foreach ($params as $k => $v) {
        if ((string)$v !== '') $q[$k] = (string)$v;
    }
    return '?' . http_build_query($q);
}

/** Alt sayfalama çipleri */
function liste_sayfalama(string $page, array $params, int $sayfa, int $toplam, int $limit = 50): void
{
    $sayfa = max(1, $sayfa);
    $adet = (int)ceil($toplam / $limit);
    if ($adet <= 1) return;
    $link = function (int $n) use ($page, $params): string {
        $u = $n > 1 ? array_merge($params, ['sayfa' => (string)$n]) : $params;
        return liste_url($page, $u);
    };
    echo '<div class="chips">';
    if ($sayfa > 1) echo '<a class="chip" href="' . e($link($sayfa - 1)) . '">← Önceki</a>';
    $bas = max(1, $sayfa - 2);
    $son = min($adet, $bas + 4);
    for ($i = $bas; $i <= $son; $i++) {
        echo '<a class="chip' . ($i === $sayfa ? ' on' : '') . '" href="' . e($link($i)) . '">' . $i . '</a>';
    }
    if ($sayfa < $adet) echo '<a class="chip" href="' . e($link($sayfa + 1)) . '">Sonraki →</a>';
    echo '</div><p class="muted small">Toplam ' . $toplam . ' kayıt · Sayfa ' . $sayfa . '/' . $adet . '</p>';
}

/** Gecikme rozeti (kırmızı vurgulu) */
function liste_gecikme_rozeti(array $d): string
{
    if (empty($d['late'])) return '';
    $renk = ((string)$d['bucket'] === 'kritik') ? '#b91c1c' : '#c2410c';
    return '<span class="badge" style="background:' . $renk . '">⏰ ' . e((string)$d['label']) . ' gecikme</span>';
}

/** Gecikme kovalarının görünen adı */
function liste_gecikmis_kova_etiket(string $bucket): string
{
    $defs = [
        'c1' => '1 gün', 'c2_3' => '2-3 gün', 'c4_6' => '4-6 gün', 'kritik' => '7+ gün kritik',
    ];
    return $defs[$bucket] ?? 'Diğer';
}

/** Sipariş durum çipleri tanımı (varsayılan = açık siparişler) */
function liste_durum_defleri(): array
{
    $defs = ['' => 'Açık', 'tum' => 'Tümü', 'kapali' => 'Kapanmış'];
    foreach (status_labels() as $k => $v) $defs[$k] = $v;
    return $defs;
}

/** order_items.type listesinden okunabilir hizmet özeti */
function liste_hizmet_ozeti(string $types, int $adet, int $qty): string
{
    $liste = service_types();
    $adetler = [];
    foreach (array_filter(explode(',', $types)) as $t) {
        $adetler[] = (string)($liste[trim($t)][0] ?? trim($t));
    }
    $out = $adetler ? implode(' · ', array_slice(array_unique($adetler), 0, 3)) : 'Hizmet girilmemiş';
    if ($adet > 0) $out .= ' · ' . $adet . ' kalem';
    if ($qty > 0) $out .= ' / ' . $qty . ' adet';
    return $out;
}

/** Arama çubuğu (GET formu) */
function liste_arama_bar(string $page, string $q, string $yer = ''): void
{
    ?>
<form method="get" class="searchbar">
<input type="hidden" name="p" value="<?= e($page) ?>">
<input name="q" value="<?= e($q) ?>" placeholder="<?= e($yer) ?>" autocapitalize="none" autocomplete="off">
<button class="btn">Ara</button>
<?php if ($q !== ''): ?><a class="btn btn-ghost" href="?p=<?= e($page) ?>">✕</a><?php endif; ?>
</form>
<?php
}

/** Sayfalı sorgu: SELECT'i sarmalayıp toplam kayıt sayısını çeker → [rows, total, sayfa] */
function liste_sayfali(PDO $db, string $sql, array $args, int $limit = 50): array
{
    $sayfa = max(1, (int)($_GET['sayfa'] ?? 1));
    $st = $db->prepare('SELECT COUNT(*) FROM (' . $sql . ')');
    $st->execute($args);
    $toplam = (int)$st->fetchColumn();
    $st = $db->prepare($sql . ' LIMIT ? OFFSET ?');
    $st->execute(array_merge($args, [$limit, ($sayfa - 1) * $limit]));
    return [$st->fetchAll(), $toplam, $sayfa];
}

// ---------------------------------------------------------------- gelişmiş filtreler

/** Filtre değerleri — her zaman beyaz listeden geçer, SQL'e gömülmez.
 *  Her anahtar = sütun/değer çifti; SQL parçası sabit, veriler prepared ile gider. */
function liste_filtreler(): array
{
    return [
        'tarih_alani' => ['olusturma', 'teslim'],   // created_at | delivery_date
        'tarih_bas'   => '',                          // YYYY-MM-DD
        'tarih_bit'   => '',
        'mahalle'     => '',
        'personel'    => '',                          // kullanıcı id
        'personel_ada'=> '',                          // serbest metin (staff)
        'musteri_id'  => 0,                           // tam sayı
        'odeme'       => ['', 'odendi', 'kismi', 'odeyenmemis'],
        'gecikme'     => ['', 'c1', 'c2_3', 'c4_6', 'kritik'],
        'wa'          => ['', 'ok', 'hata', 'kuyrukta'],
        'sirala'      => ['', 'tarih', 'tutar', 'gecikme', 'musteri'],
        'yon'         => ['', 'artan', 'azalan'],
    ];
}

/** GET'ten normalize edilmiş filtre seti (beyaz listedeki değerler dışında atılır) */
function liste_filtre_oku(): array
{
    $d = liste_filtreler();
    $f = [];
    foreach ($d as $k => $allowed) {
        $v = trim((string)($_GET[$k] ?? ''));
        if (is_array($allowed)) {
            if (!in_array($v, $allowed, true)) $v = $allowed[0] ?? '';
        } elseif ($k === 'musteri_id') {
            $v = (int)$v;
        } elseif ($k === 'tarih_alani') {
            if (!in_array($v, $d['tarih_alani'], true)) $v = $d['tarih_alani'][0];
        } elseif ($k === 'tarih_bas' || $k === 'tarih_bit') {
            // yalnızca geçerli YYYY-MM-DD kabul edilir
            if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $v = '';
        }
        $f[$k] = $v;
    }
    // sıralama alanı seçilmişse yön zorunlu
    if ($f['sirala'] === '') $f['yon'] = '';
    elseif ($f['yon'] === '') $f['yon'] = 'azalan';
    return $f;
}

/** Aktif filtrelerden ?p=...&... bağlantısı üretir (durum + arama korunur) */
function liste_filtre_url(array $f, array $ek = []): string
{
    $p = array_merge(['q' => trim((string)($_GET['q'] ?? '')), 'durum' => (string)($_GET['durum'] ?? '')], $f, $ek);
    return liste_url('siparisler', $p);
}

/** Tek bir filtreyi kaldırmak için bağlantı (sayfa 1'e döner) */
function liste_filtre_kaldir(string $anahtar): string
{
    $f = liste_filtre_oku();
    if ($anahtar === 'tarih') {
        $f['tarih_bas'] = '';
        $f['tarih_bit'] = '';
        $f['tarih_alani'] = 'olusturma';
    } else {
        $f[$anahtar] = is_array(liste_filtreler()[$anahtar] ?? '') ? '' : 0;
    }
    unset($f['yon']);
    return liste_filtre_url($f);
}

/** Ödeme durumu SQL parçası — bakiye = net tutar − ödenen */
function liste_odeme_sql(string $t): string
{
    $bakiye = '(o.total_kurus - o.discount_kurus
        - COALESCE((SELECT SUM(p.amount_kurus) FROM payments p WHERE p.order_id=o.id),0))';
    if ($t === 'odendi')     return " AND $bakiye <= 0";
    if ($t === 'kismi')     return " AND $bakiye > 0 AND $bakiye < (o.total_kurus - o.discount_kurus)";
    if ($t === 'odeyenmemis')return " AND COALESCE((SELECT SUM(p.amount_kurus) FROM payments p WHERE p.order_id=o.id),0) <= 0";
    return '';
}

/** Gecikme kovası SQL parçası — delay_info() ile aynı gün hesabı (takvim günü) */
function liste_gecikme_sql(string $kova): string
{
    if (!in_array($kova, ['c1', 'c2_3', 'c4_6', 'kritik'], true)) return '';
    $gecikmis = " AND o.status NOT IN ('teslim','iptal','teslim_edilemedi')
        AND o.delivery_date <> '' AND date(o.delivery_date) < date('now','localtime')";
    $gun = "(julianday('now','localtime') - julianday(o.delivery_date))";
    if ($kova === 'kritik') return $gecikmis . " AND $gun >= 7";
    if ($kova === 'c4_6')  return $gecikmis . " AND $gun >= 4 AND $gun < 7";
    if ($kova === 'c2_3')  return $gecikmis . " AND $gun >= 2 AND $gun < 4";
    return $gecikmis . " AND $gun >= 1 AND $gun < 2";
}

/** Seçili filtreleri çubuk hâlinde gösterir; her çip tek tıkla kaldırılır */
function liste_filtre_cubugu(array $f): void
{
    $aktif = [];
    if ($f['tarih_bas'] !== '' || $f['tarih_bit'] !== '') {
        $alan = $f['tarih_alani'] === 'teslim' ? 'Teslim' : 'Oluşturma';
        $ar = trim($f['tarih_bas'] . ' – ' . $f['tarih_bit']);
        $aktif[] = ['t', $alan . ': ' . ($ar !== ' – ' ? $ar : '—'), 'tarih'];
    }
    if ($f['mahalle'] !== '')   $aktif[] = ['t', '📍 ' . $f['mahalle'], 'mahalle'];
    if ((int)$f['musteri_id'] > 0) {
        $st = db()->prepare('SELECT name FROM customers WHERE id=?');
        $st->execute([(int)$f['musteri_id']]);
        $nm = (string)$st->fetchColumn();
        $aktif[] = ['t', '👤 ' . ($nm !== '' ? $nm : '#' . (int)$f['musteri_id']), 'musteri_id'];
    }
    if ((int)$f['personel'] > 0) {
        $st = db()->prepare('SELECT full_name,username FROM users WHERE id=?');
        $st->execute([(int)$f['personel']]);
        $u = $st->fetch();
        $aktif[] = ['t', '👷 ' . ($u ? (string)($u['full_name'] ?: $u['username']) : '#' . (int)$f['personel']), 'personel'];
    }
    if ($f['personel_ada'] !== '') $aktif[] = ['t', '🔎 alan: ' . $f['personel_ada'], 'personel_ada'];
    $odeme = ['odendi' => '✅ Ödendi', 'kismi' => '🟡 Kısmi ödeme', 'odeyenmemis' => '❌ Ödemedi'];
    if ($f['odeme'] !== '')  $aktif[] = ['t', $odeme[$f['odeme']] ?? $f['odeme'], 'odeme'];
    if ($f['gecikme'] !== '') {
        $gk = ['c1' => '⏰ 1 gün', 'c2_3' => '⏰ 2-3 gün', 'c4_6' => '⏰ 4-6 gün', 'kritik' => '🚨 7+ gün kritik'];
        $aktif[] = ['t', $gk[$f['gecikme']] ?? $f['gecikme'], 'gecikme'];
    }
    $wak = ['ok' => '📲 Gönderildi', 'hata' => '📲 Hata', 'kuyrukta' => '📲 Kuyrukta'];
    if ($f['wa'] !== '') $aktif[] = ['t', $wak[$f['wa']] ?? $f['wa'], 'wa'];

    if (!$aktif) return;
    echo '<div class="chips"><span class="chip on">Aktif filtreler:</span>';
    foreach ($aktif as $a) {
        echo '<a class="chip" href="' . e(liste_filtre_kaldir($a[2]))
            . '" title="Kaldır">✕ ' . e($a[1]) . '</a>';
    }
    echo '<a class="chip" href="' . e(liste_filtre_url($f, ['q' => '', 'durum' => ''])) . '">🧹 Tümünü temizle</a></div>';
}

/** Gelişmiş filtre formu: tarih, mahalle, personel, müşteri, ödeme, gecikme, WA, sıralama */
function liste_filtre_form(array $f): void
{
    $db = db();
    $mahalleler = $db->query("SELECT DISTINCT district FROM customers
        WHERE COALESCE(district,'')<>'' ORDER BY district")->fetchAll();
    $personel = $db->query("SELECT id,full_name,username FROM users WHERE active=1 ORDER BY full_name")->fetchAll();
    $musteriler = $db->query("SELECT id,name FROM customers ORDER BY name")->fetchAll();
    ?>
<form method="get" class="filterbar">
<input type="hidden" name="p" value="siparisler">
<input type="hidden" name="q" value="<?= e(trim((string)($_GET['q'] ?? ''))) ?>">
<input type="hidden" name="durum" value="<?= e((string)($_GET['durum'] ?? '')) ?>">
<details class="filterbox" <?= ($f['mahalle'] !== '' || $f['odeme'] !== '' || $f['gecikme'] !== '' || $f['wa'] !== ''
        || (int)$f['personel'] > 0 || $f['sirala'] !== '' || $f['tarih_bas'] !== '') ? 'open' : '' ?>>
<summary>⚙️ Gelişmiş filtreler<?= liste_filtre_aktif_sayi($f) > 0 ? ' (' . liste_filtre_aktif_sayi($f) . ')' : '' ?></summary>

<label>Tarih alanı
<select name="tarih_alani">
<option value="olusturma" <?= $f['tarih_alani'] === 'olusturma' ? 'selected' : '' ?>>Oluşturma tarihi</option>
<option value="teslim" <?= $f['tarih_alani'] === 'teslim' ? 'selected' : '' ?>>Planlı teslim tarihi</option>
</select></label>
<div class="row2">
<label>Başlangıç<input type="date" name="tarih_bas" value="<?= e((string)$f['tarih_bas']) ?>"></label>
<label>Bitiş<input type="date" name="tarih_bit" value="<?= e((string)$f['tarih_bit']) ?>"></label>
</div>

<label>Mahalle / İlçe
<select name="mahalle"><option value="">— tümü —</option>
<?php foreach ($mahalleler as $m): ?>
<option value="<?= e((string)$m['district']) ?>" <?= $f['mahalle'] === (string)$m['district'] ? 'selected' : '' ?>><?= e((string)$m['district']) ?></option>
<?php endforeach; ?></select></label>

<label>Müşteri
<select name="musteri_id"><option value="0">— tümü —</option>
<?php foreach ($musteriler as $m): ?>
<option value="<?= (int)$m['id'] ?>" <?= (int)$f['musteri_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= e((string)$m['name']) ?></option>
<?php endforeach; ?></select></label>

<label>Sorumlu personel
<select name="personel"><option value="0">— tümü —</option>
<?php foreach ($personel as $p): ?>
<option value="<?= (int)$p['id'] ?>" <?= (int)$f['personel'] === (int)$p['id'] ? 'selected' : '' ?>><?= e((string)($p['full_name'] ?: $p['username'])) ?></option>
<?php endforeach; ?></select></label>
<label>Alan personeli (metin)<input name="personel_ada" value="<?= e((string)$f['personel_ada']) ?>" placeholder="Ali"></label>

<label>Ödeme durumu
<select name="odeme">
<option value="" <?= $f['odeme'] === '' ? 'selected' : '' ?>>— fark etmez —</option>
<option value="odendi" <?= $f['odeme'] === 'odendi' ? 'selected' : '' ?>>Ödendi (bakiye 0)</option>
<option value="kismi" <?= $f['odeme'] === 'kismi' ? 'selected' : '' ?>>Kısmi ödeme</option>
<option value="odeyenmemis" <?= $f['odeme'] === 'odeyenmemis' ? 'selected' : '' ?>>Hiç ödeme yok</option>
</select></label>

<label>Gecikme
<select name="gecikme">
<option value="" <?= $f['gecikme'] === '' ? 'selected' : '' ?>>— fark etmez —</option>
<option value="c1" <?= $f['gecikme'] === 'c1' ? 'selected' : '' ?>>1 gün</option>
<option value="c2_3" <?= $f['gecikme'] === 'c2_3' ? 'selected' : '' ?>>2-3 gün</option>
<option value="c4_6" <?= $f['gecikme'] === 'c4_6' ? 'selected' : '' ?>>4-6 gün</option>
<option value="kritik" <?= $f['gecikme'] === 'kritik' ? 'selected' : '' ?>>7+ gün kritik</option>
</select></label>

<label>WhatsApp bildirimi
<select name="wa">
<option value="" <?= $f['wa'] === '' ? 'selected' : '' ?>>— fark etmez —</option>
<option value="ok" <?= $f['wa'] === 'ok' ? 'selected' : '' ?>>Gönderildi</option>
<option value="hata" <?= $f['wa'] === 'hata' ? 'selected' : '' ?>>Hata</option>
<option value="kuyrukta" <?= $f['wa'] === 'kuyrukta' ? 'selected' : '' ?>>Kuyrukta (bekliyor)</option>
</select></label>

<div class="row2">
<label>Sıralama
<select name="sirala">
<option value="" <?= $f['sirala'] === '' ? 'selected' : '' ?>>Varsayılan</option>
<option value="tarih" <?= $f['sirala'] === 'tarih' ? 'selected' : '' ?>>Tarih</option>
<option value="tutar" <?= $f['sirala'] === 'tutar' ? 'selected' : '' ?>>Tutar</option>
<option value="gecikme" <?= $f['sirala'] === 'gecikme' ? 'selected' : '' ?>>Gecikme</option>
<option value="musteri" <?= $f['sirala'] === 'musteri' ? 'selected' : '' ?>>Müşteri</option>
</select></label>
<label>Yön
<select name="yon" <?= $f['sirala'] === '' ? 'disabled' : '' ?>>
<option value="azalan" <?= $f['yon'] !== 'artan' ? 'selected' : '' ?>>Azalan ↓</option>
<option value="artan" <?= $f['yon'] === 'artan' ? 'selected' : '' ?>>Artan ↑</option>
</select></label>
</div>

<button class="btn btn-primary btn-block">Filtrele</button>
<a class="btn btn-ghost btn-block" href="?p=siparisler">Temizle</a>
</details>
</form>
<?php
}

/** Kaç filtre etkin — başlık parantezi için */
function liste_filtre_aktif_sayi(array $f): int
{
    $n = 0;
    foreach (['tarih_bas', 'tarih_bit', 'mahalle', 'personel_ada', 'odeme', 'gecikme', 'wa', 'sirala'] as $k) {
        if ((string)$f[$k] !== '') $n++;
    }
    if ((int)$f['musteri_id'] > 0) $n++;
    if ((int)$f['personel'] > 0) $n++;
    return $n;
}

// ---------------------------------------------------------------- sipariş listesi

/** Tüm siparişler: arama + durum filtresi + gelişmiş filtreler + sayfalama */
function siparisler(): void
{
    need_perm('siparis.gor');
    $db = db();
    $q = trim((string)($_GET['q'] ?? ''));
    $durum = (string)($_GET['durum'] ?? '');
    $f = liste_filtre_oku();

    $sql = "SELECT o.*, c.name cname, c.phone, c.phone2, c.district cdistrict,
        (SELECT COUNT(*) FROM order_items i WHERE i.order_id=o.id) item_adet,
        (SELECT COALESCE(SUM(i.qty),0) FROM order_items i WHERE i.order_id=o.id) item_qty,
        (SELECT group_concat(DISTINCT i.type) FROM order_items i WHERE i.order_id=o.id) item_types
        FROM orders o JOIN customers c ON c.id=o.customer_id WHERE 1=1";
    $args = [];

    // ---- mevcut durum filtresi ----
    // Tek durum ("yikamada") ya da virgüllü çoklu durum
    // ("yikamada,kurutuluyor,kalite_kontrol" — panel sayaçları bunu kullanır).
    if ($durum === 'tum') {
        // tümü
    } elseif ($durum === 'kapali') {
        $sql .= " AND o.status IN ('teslim','iptal','teslim_edilemedi')";
    } elseif ($durum !== '') {
        $ist = array_values(array_filter(array_map('trim', explode(',', $durum)),
            fn($s) => isset(status_labels()[$s])));
        if (count($ist) === 1) {
            $sql .= ' AND o.status=?';
            $args[] = $ist[0];
        } elseif (count($ist) > 1) {
            $sql .= ' AND o.status IN (' . implode(',', array_fill(0, count($ist), '?')) . ')';
            foreach ($ist as $s) $args[] = $s;
        } else {
            $sql .= ' AND o.status IN ' . open_status_sql();
        }
    } else {
        $sql .= ' AND o.status IN ' . open_status_sql();
    }

    // ---- mevcut arama (aynen korunur) ----
    if ($q !== '') {
        $sql .= " AND (c.name LIKE ? ESCAPE '\\' OR c.phone LIKE ? ESCAPE '\\' OR c.phone2 LIKE ? ESCAPE '\\'
            OR o.code LIKE ? ESCAPE '\\' OR o.note LIKE ? ESCAPE '\\' OR o.staff LIKE ? ESCAPE '\\'
            OR EXISTS(SELECT 1 FROM order_items i2 WHERE i2.order_id=o.id AND i2.item_code LIKE ? ESCAPE '\\'))";
        for ($i = 0; $i < 7; $i++) $args[] = liste_like($q);
    }

    // ---- gelişmiş filtreler (hepsi birleşebilir) ----
    $tarihKolon = $f['tarih_alani'] === 'teslim' ? 'o.delivery_date' : 'o.created_at';
    if ($f['tarih_bas'] !== '') { $sql .= " AND date($tarihKolon) >= date(?)"; $args[] = $f['tarih_bas']; }
    if ($f['tarih_bit'] !== '') { $sql .= " AND date($tarihKolon) <= date(?)"; $args[] = $f['tarih_bit']; }

    if ($f['mahalle'] !== '') { $sql .= ' AND c.district = ?'; $args[] = $f['mahalle']; }
    if ((int)$f['musteri_id'] > 0) { $sql .= ' AND o.customer_id = ?'; $args[] = (int)$f['musteri_id']; }
    if ((int)$f['personel'] > 0) { $sql .= ' AND o.assigned_to = ?'; $args[] = (int)$f['personel']; }
    if ($f['personel_ada'] !== '') {
        $sql .= " AND o.staff LIKE ? ESCAPE '\\'"; $args[] = liste_like($f['personel_ada']);
    }
    $sql .= liste_odeme_sql((string)$f['odeme']);
    $sql .= liste_gecikme_sql((string)$f['gecikme']);

    if ($f['wa'] !== '') {
        if ($f['wa'] === 'ok') {
            $sql .= " AND EXISTS(SELECT 1 FROM wa_queue w WHERE w.order_id=o.id
                AND w.status IN ('gonderildi','teslim','okundu'))";
        } elseif ($f['wa'] === 'hata') {
            $sql .= " AND EXISTS(SELECT 1 FROM wa_queue w WHERE w.order_id=o.id AND w.status='hata')";
        } else { // kuyrukta
            $sql .= " AND EXISTS(SELECT 1 FROM wa_queue w WHERE w.order_id=o.id AND w.status='bekliyor')";
        }
    }

    // ---- sıralama ----
    $y = $f['yon'] === 'artan' ? 'ASC' : 'DESC';
    switch ($f['sirala']) {
        case 'tarih':
            $sql .= " ORDER BY date($tarihKolon) $y, o.id $y"; break;
        case 'tutar':
            $sql .= " ORDER BY (o.total_kurus - o.discount_kurus) $y, o.id DESC"; break;
        case 'gecikme':
            // gecikmesi çok olan önce; hiç gecikme yoksa en eski teslim önce
            $sql .= " ORDER BY CASE WHEN o.delivery_date='' THEN 1 ELSE 0 END,
                CASE WHEN o.status IN ('teslim','iptal','teslim_edilemedi') THEN 1 ELSE 0 END,
                o.delivery_date $y, o.id $y"; break;
        case 'musteri':
            $sql .= " ORDER BY c.name $y, o.id DESC"; break;
        default:
            $sql .= ' ORDER BY o.updated_at DESC, o.id DESC';
    }

    [$rows, $toplam, $sayfa] = liste_sayfali($db, $sql, $args, 50);

    layout_head('Siparişler', 'siparisler');
    liste_arama_bar('siparisler', $q, 'Müşteri, telefon, sipariş kodu veya parça kodu');
    liste_filtre_form($f);
    chips('siparisler', liste_durum_defleri(), $durum, 'durum');
    liste_filtre_cubugu($f);
    echo '<p class="muted small">' . $toplam . ' sipariş bulundu</p>';

    if (!$rows) {
        if ($q !== '' || liste_filtre_aktif_sayi($f) > 0) {
            empty_state('🔍', 'Aramanıza uyan sipariş bulunamadı.', liste_filtre_url($f, ['q' => '', 'durum' => '']), 'Filtreleri temizle');
        } else {
            empty_state('📋', 'Bu filtrede sipariş yok.', '?p=siparis-yeni', 'Yeni sipariş aç');
        }
    }
    foreach ($rows as $o) {
        $d = delay_info($o);
        echo '<div class="card">';
        echo '<a class="row" href="?p=siparis&id=' . (int)$o['id'] . '">'
            . '<span class="row-main"><b>' . e((string)$o['code']) . '</b> '
            . e((string)$o['cname']) . '<br>'
            . '<small>' . e(liste_hizmet_ozeti((string)$o['item_types'], (int)$o['item_adet'], (int)$o['item_qty'])) . '</small>'
            . '<br><small>📞 ' . e((string)$o['phone']) . ' · Teslim: <b>' . e(tr_date((string)$o['delivery_date'])) . '</b>'
            . ((string)$o['delivery_time'] !== '' ? ' ' . e((string)$o['delivery_time']) : '') . '</small>'
            . '<br>' . status_badge((string)$o['status']);
        if ($d['late']) echo ' ' . liste_gecikme_rozeti($d);
        echo '</span><span class="row-side">' . money_tl((int)$o['total_kurus'] - (int)$o['discount_kurus'])
            . '<br><small>' . e(tr_date((string)$o['updated_at'] ?: (string)$o['created_at'])) . '</small>'
            . '</span></a></div>';
    }
    liste_sayfalama('siparisler', array_merge(
        ['q' => $q, 'durum' => $durum],
        array_diff_key($f, array_flip(['tarih_alani', 'yon'])),
        ['sirala' => $f['sirala'], 'yon' => $f['yon'], 'tarih_alani' => $f['tarih_alani']]
    ), $sayfa, $toplam, 50);
    layout_foot('siparisler');
}

// ---------------------------------------------------------------- halı takibi

/** Halı/parça bazlı takip listesi */
function hali_takip(): void
{
    need_perm('siparis.gor');
    $db = db();
    $q = trim((string)($_GET['q'] ?? ''));

    $sql = "SELECT i.*, o.id oid, o.code ocode, o.status ostatus, o.delivery_date,
        c.name cname, c.phone
        FROM order_items i
        JOIN orders o ON o.id=i.order_id
        JOIN customers c ON c.id=o.customer_id WHERE 1=1";
    $args = [];
    if ($q !== '') {
        $sql .= " AND (i.item_code LIKE ? ESCAPE '\\' OR c.name LIKE ? ESCAPE '\\' OR c.phone LIKE ? ESCAPE '\\'
            OR i.descr LIKE ? ESCAPE '\\' OR i.damage LIKE ? ESCAPE '\\' OR o.code LIKE ? ESCAPE '\\'
            OR i.note LIKE ? ESCAPE '\\')";
        for ($i = 0; $i < 7; $i++) $args[] = liste_like($q);
    }
    $sql .= ' ORDER BY i.id DESC';

    [$rows, $toplam, $sayfa] = liste_sayfali($db, $sql, $args, 50);
    $types = service_types();

    layout_head('Halı Takibi', 'halitakip');
    liste_arama_bar('halitakip', $q, 'Parça kodu (H-...), müşteri adı veya hasar');
    chips('halitakip', ['' => 'Tümü'], '', 'f');
    echo '<p class="muted small">' . $toplam . ' parça kaydı</p>';

    if (!$rows) {
        $q !== ''
            ? empty_state('🔍', 'Aramanıza uyan parça bulunamadı.', '?p=halitakip', 'Temizle')
            : empty_state('🧶', 'Henüz parça kaydı yok.', '?p=siparis-yeni', 'Sipariş aç');
    }
    foreach ($rows as $it) {
        $t = $types[(string)$it['type']] ?? [(string)$it['type'], false];
        $olcu = [];
        if ((float)$it['m2'] > 0) $olcu[] = str_replace('.', ',', (string)$it['m2']) . ' m²';
        if ((float)$it['width'] > 0 && (float)$it['height'] > 0) {
            $olcu[] = str_replace('.', ',', (string)$it['width']) . '×' . str_replace('.', ',', (string)$it['height']) . ' m';
        }
        $olcu[] = (int)$it['qty'] . ' adet';

        echo '<div class="card">';
        echo '<a class="row" href="?p=siparis&id=' . (int)$it['oid'] . '">'
            . '<span class="row-main"><b>' . e((string)$it['cname']) . '</b> · '
            . e((string)$it['ocode']) . '<br><small>' . e($t[0]) . ' · ' . e(implode(' · ', $olcu))
            . ((string)$it['descr'] !== '' ? ' · ' . e((string)$it['descr']) : '') . '</small>'
            . ((string)$it['damage'] !== '' ? '<br><small class="neg">⚠ Hasar: ' . e((string)$it['damage']) . '</small>' : '')
            . '<br><small>Alınan: <b>' . (int)$it['received_qty'] . '</b> · Teslim: <b>'
            . (int)$it['returned_qty'] . '</b> adet</small>'
            . '<br>' . status_badge((string)$it['ostatus'])
            . '</span>'
            . '<span class="row-side">'
            . '<a class="badge badge-soft" href="?p=item-kod&id=' . (int)$it['id'] . '">'
            . e((string)($it['item_code'] ?: 'kod yok')) . '</a><br>'
            . '<small>' . money_tl((int)$it['line_kurus']) . '</small>'
            . '</span></a></div>';
    }
    liste_sayfalama('halitakip', ['q' => $q], $sayfa, $toplam, 50);
    layout_foot('halitakip');
}

// ---------------------------------------------------------------- parça detayı

/** Tek bir parçanın (order_items satırı) detay ekranı */
function item_kod(): void
{
    need_perm('siparis.gor');
    $db = db();
    $iid = (int)($_GET['id'] ?? 0);

    $st = $db->prepare("SELECT i.*, o.id oid, o.code ocode, o.status ostatus, o.note onote,
        o.pickup_date, o.delivery_date, o.delivery_time, o.received_count, o.returned_count,
        o.staff, o.delay_reason, c.id cid, c.name cname, c.phone, c.phone2,
        c.address, c.district
        FROM order_items i
        JOIN orders o ON o.id=i.order_id
        JOIN customers c ON c.id=o.customer_id WHERE i.id=?");
    $st->execute([$iid]);
    $it = $st->fetch();

    if (!$it) {
        http_response_code(404);
        layout_head('Bulunamadı');
        echo '<div class="card"><p>Bu parça kaydı bulunamadı.</p>'
            . '<a class="btn btn-block" href="?p=halitakip">← Halı Takibi</a></div>';
        layout_foot('halitakip');
        exit;
    }

    $types = service_types();
    $t = $types[(string)$it['type']] ?? [(string)$it['type'], false];
    $st = $db->prepare('SELECT * FROM photos WHERE item_id=? ORDER BY id DESC');
    $st->execute([$iid]);
    $fotos = $st->fetchAll();
    $fotoSayi = count($fotos);

    layout_head('Parça ' . (string)($it['item_code'] ?: '#' . $iid), 'halitakip');
    echo '<div class="card"><div class="orderhead"><div><b>' . e((string)($it['item_code'] ?: 'Kod atanmamış'))
        . '</b><br><small>' . e($t[0]) . '</small></div>'
        . status_badge((string)$it['ostatus']) . '</div>'
        . '<a class="btn btn-ghost btn-sm btn-block" href="?p=siparis&id=' . (int)$it['oid'] . '">'
        . '← Siparişe dön: ' . e((string)$it['ocode']) . '</a></div>';

    if ((string)$it['damage'] !== '') {
        echo '<div class="alert danger"><b>⚠ Hasar notu:</b> ' . e((string)$it['damage']) . '</div>';
    }

    echo '<div class="card"><h2>Parça Bilgileri</h2>'
        . '<div class="kv"><div>Hizmet</div><div>' . e($t[0]) . '</div></div>'
        . '<div class="kv"><div>Açıklama</div><div>' . ((string)$it['descr'] !== '' ? e((string)$it['descr']) : '—') . '</div></div>'
        . '<div class="kv"><div>Ölçüler</div><div>'
        . ((float)$it['width'] > 0 && (float)$it['height'] > 0
            ? e(str_replace('.', ',', (string)$it['width'])) . ' × ' . e(str_replace('.', ',', (string)$it['height'])) . ' m'
            : '—') . '</div></div>'
        . '<div class="kv"><div>Alan</div><div>'
        . ((float)$it['m2'] > 0 ? e(str_replace('.', ',', (string)$it['m2'])) . ' m²' : '—') . '</div></div>'
        . '<div class="kv"><div>Adet</div><div><b>' . (int)$it['qty'] . '</b></div></div>'
        . '<div class="kv"><div>Birim tutar</div><div>' . money_tl((int)$it['unit_kurus']) . '</div></div>'
        . '<div class="kv"><div>Satır tutarı</div><div><b>' . money_tl((int)$it['line_kurus']) . '</b></div></div>'
        . ((string)$it['note'] !== '' ? '<div class="kv"><div>Parça notu</div><div>' . nl2br(e((string)$it['note'])) . '</div></div>' : '')
        . '</div>';

    echo '<div class="card"><h2>Adet Takibi</h2>'
        . '<div class="kv"><div>Alınan</div><div><b>' . (int)$it['received_qty'] . '</b> adet</div></div>'
        . '<div class="kv"><div>Teslim edilen</div><div><b>' . (int)$it['returned_qty'] . '</b> adet</div></div>';
    $bek = (int)$it['received_qty'] > 0 ? (int)$it['received_qty'] : (int)$it['qty'];
    if ((int)$it['returned_qty'] > 0 && (int)$it['returned_qty'] !== $bek) {
        echo '<div class="alert danger">Adet uyuşmuyor: alınan <b>' . $bek . '</b> · teslim edilen <b>'
            . (int)$it['returned_qty'] . '</b></div>';
    }
    echo '</div>';

    echo '<div class="card"><h2>Sipariş & Müşteri</h2>'
        . '<div class="kv"><div>Sipariş</div><div><a href="?p=siparis&id=' . (int)$it['oid'] . '"><b>'
        . e((string)$it['ocode']) . '</b></a></div></div>'
        . '<div class="kv"><div>Müşteri</div><div><a href="?p=musteri&id=' . (int)$it['cid'] . '">'
        . e((string)$it['cname']) . '</a></div></div>'
        . '<div class="kv"><div>Telefon</div><div>' . (customer_line($it) ?: '—') . '</div></div>'
        . '<div class="kv"><div>Adres</div><div>' . ((string)$it['address'] !== '' ? e((string)$it['address']) : '—')
        . ((string)$it['district'] !== '' ? ' · ' . e((string)$it['district']) : '') . '</div></div>'
        . '<div class="kv"><div>Teslim</div><div>' . e(tr_date((string)$it['delivery_date']))
        . ((string)$it['delivery_time'] !== '' ? ' ' . e((string)$it['delivery_time']) : '') . '</div></div>'
        . '<div class="kv"><div>Sipariş adet</div><div>' . (int)$it['received_count'] . ' alındı / '
        . (int)$it['returned_count'] . ' teslim</div></div>';
    if ((string)$it['staff'] !== '') {
        echo '<div class="kv"><div>Personel</div><div>' . e((string)$it['staff']) . '</div></div>';
    }
    $quick = nav_quick($it);
    if ($quick) echo '<div class="btnrow pad">' . $quick . '</div>';
    echo '</div>';

    echo '<div class="card"><h2>📷 Bu Parçanın Fotoğrafları (' . $fotoSayi . ')</h2>';
    if (!$fotoSayi) {
        echo '<p class="muted">Bu parçaya bağlı fotoğraf yok. Sipariş kartından yükleyebilirsiniz.</p>';
    }
    foreach (['alim', 'islem_oncesi', 'teslim'] as $stage) {
        $grp = array_values(array_filter($fotos, fn($x) => (string)$x['stage'] === $stage));
        if (!$grp) continue;
        echo '<h3>' . e(PHOTO_STAGES[$stage] ?? $stage) . ' (' . count($grp) . ')</h3><div class="thumbs">';
        foreach ($grp as $ph) {
            echo '<figure class="thumb"><a target="_blank" rel="noopener" href="?p=foto&id=' . (int)$ph['id'] . '">'
                . '<img loading="lazy" src="?p=foto&id=' . (int)$ph['id'] . '&t=thumb" alt=""></a>'
                . '<figcaption>' . e(tr_date((string)$ph['created_at'])) . ' · ' . e((string)$ph['username'])
                . ((string)$ph['note'] !== '' ? '<br>' . e((string)$ph['note']) : '') . '</figcaption></figure>';
        }
        echo '</div>';
    }
    echo '<a class="btn btn-block mt8" href="?p=siparis&id=' . (int)$it['oid'] . '#foto">Fotoğraf yükle →</a>';
    echo '</div>';

    layout_foot('halitakip');
}

// ---------------------------------------------------------------- alım listesi

/** Alınacak halılar: durum yeni / alim_bekliyor */
function alim_listesi(): void
{
    need_perm('siparis.gor');
    $db = db();
    // VARSAYILAN FİLTRE = "Bugün".
    // Ekranın adı "Alım Listesi" ama panel kartı ve menü "Bugün alınacak"
    // diyor. Varsayılan 'Tümü' iken yarının ve geçmiş tarihli siparişler de
    // listeleniyordu; görevli yarının işini bugünün işi sanıp çıkıyordu.
    // "Tümü" ve "Bu hafta" seçenekleri çip olarak KORUNUR — yalnız açılış
    // görünümü değişti. Geçmiş tarihli ama hâlâ alınmamış siparişler de
    // bugün işidir, bu yüzden 'today' koşulu pickup_date<=bugün'dür.
    $f = (string)($_GET['f'] ?? 'today');
    if (!in_array($f, ['today', 'tomorrow', 'week', ''], true)) $f = 'today';
    $t = today();
    $yarin = date('Y-m-d', strtotime('+1 day'));
    $hafta = date('Y-m-d', strtotime('+6 days'));

    $sql = "SELECT o.*, c.name cname, c.phone, c.phone2, c.address, c.district,
        (SELECT COALESCE(SUM(i.qty),0) FROM order_items i WHERE i.order_id=o.id) item_qty
        FROM orders o JOIN customers c ON c.id=o.customer_id
        WHERE o.status IN ('yeni','alim_bekliyor')";
    $args = [];
    if ($f === 'today')      { $sql .= ' AND (o.pickup_date=\'\' OR o.pickup_date<=?)'; $args[] = $t; }
    elseif ($f === 'tomorrow') { $sql .= ' AND o.pickup_date=?'; $args[] = $yarin; }
    elseif ($f === 'week')   { $sql .= ' AND (o.pickup_date=\'\' OR (o.pickup_date>=? AND o.pickup_date<=?))'; $args = [$t, $hafta]; }
    $sql .= " ORDER BY CASE WHEN o.pickup_date='' THEN 0 ELSE 1 END, o.pickup_date, o.id";

    // ⚠ SAYFALAMA: alım listesi TÜM alım bekleyenleri basıyordu. Büyük
    //   işletmede bu yüzlerce kayıt = MB'larca HTML (ölçüldü: 678 KB).
    $st = $db->prepare('SELECT COUNT(*) FROM (' . $sql . ')');
    $st->execute($args);
    $alimToplam = (int)$st->fetchColumn();
    $limit = 50;
    $sayfaNo = max(1, (int)($_GET['sayfa'] ?? 1));
    $alimSayfa = max(1, (int)ceil($alimToplam / $limit));
    if ($sayfaNo > $alimSayfa) $sayfaNo = $alimSayfa;
    $st = $db->prepare($sql . ' LIMIT ? OFFSET ?');
    $st->execute(array_merge($args, [$limit, ($sayfaNo - 1) * $limit]));
    $rows = $st->fetchAll();

    layout_head('Alım Listesi', 'alim');
    chips('alim', ['today' => 'Bugün', 'tomorrow' => 'Yarın', 'week' => 'Bu hafta', '' => 'Tümü'], $f, 'f');
    echo '<p class="muted small">' . $alimToplam . ' alım bekleyen sipariş'
        . ($alimSayfa > 1 ? ' · sayfa ' . $sayfaNo . '/' . $alimSayfa : '') . '</p>';

    if (!$rows) {
        empty_state('🚐', 'Alınacak halı yok. Liste temiz!', '?p=siparis-yeni', 'Yeni sipariş aç');
        layout_foot('alim');
        return;
    }
    liste_sayfalama('alim', ['f' => $f], $sayfaNo, $alimSayfa, $limit);
    foreach ($rows as $o) {
        $oid = (int)$o['id'];
        $st2 = (string)$o['status'] === 'yeni';
        $alimTarih = (string)$o['pickup_date'];
        $gecik = $alimTarih !== '' && $alimTarih < $t;

        echo '<div class="card">';
        echo '<a class="row" href="?p=siparis&id=' . $oid . '">'
            . '<span class="row-main"><b>' . e((string)$o['cname']) . '</b>'
            . ((string)$o['district'] !== '' ? ' · ' . e((string)$o['district']) : '')
            . '<br><small>' . e((string)$o['code']) . ' · ' . (int)$o['item_qty'] . ' adet halı</small>'
            . '<br><small>📅 Alım: <b>' . ($alimTarih === '' ? 'Tarih belirlenmedi' : e(tr_date($alimTarih))) . '</b>'
            . ($gecik ? ' <span class="neg">(gecikti)</span>' : '') . '</small>'
            . '<br>' . status_badge((string)$o['status'])
            . '</span><span class="row-side">' . ((int)$o['total_kurus'] - (int)$o['discount_kurus'] > 0
                ? money_tl((int)$o['total_kurus'] - (int)$o['discount_kurus']) : '—')
            . '</span></a>';

        echo '<div class="btnrow pad">';
        if ((string)$o['phone'] !== '') {
            echo '<a class="btn btn-primary" href="' . e(tel_href((string)$o['phone'])) . '" style="flex:1">📞 ' . e((string)$o['phone']) . '</a>';
        }
        echo nav_quick($o);
        echo '</div>';

        if ((string)$o['address'] !== '' || (string)$o['district'] !== '') {
            echo '<p class="muted small">📍 ' . e(trim((string)$o['address'] . ' ' . (string)$o['district'])) . '</p>';
        }
        if ((string)$o['note'] !== '') {
            echo '<p class="small"><i>' . e((string)$o['note']) . '</i></p>';
        }

        if (can('siparis.durum')) {
            $yeni = $st2 ? 'alim_bekliyor' : 'teslim_alindi';
            $lbl = $st2 ? '📋 Alım Planlandı' : '✅ Alındı';
            echo '<form method="post" action="?p=siparis-durum" class="mt8">' . csrf_field()
                . '<input type="hidden" name="id" value="' . $oid . '">'
                . '<input type="hidden" name="durum" value="' . e($yeni) . '">'
                . '<button class="btn btn-primary btn-block">' . e($lbl) . '</button></form>';
        }
        echo '</div>';
    }
    layout_foot('alim');
}

// ---------------------------------------------------------------- teslimat listesi

/**
 * TESLİM GÜNÜ GELMİŞ AMA HENÜZ HAZIR OLMAYAN siparişler.
 *
 * Ölçülen eksiklik: teslim günü geldiği hâlde hâlâ kurutuluyor/yıkamada olan
 * bir sipariş HİÇBİR YERDE görünmüyordu. Teslimat listesi yalnız
 * paketlendi/dagitimda gösterir; gecikme listesi ise yalnız delivery_date
 * < BUGÜN olanları sayar. Sonuç: teslim günü geldiği hâlde gecikmeye başlamamış
 * sipariş sessizce kayboluyor ve teslim günü fark edilmiyordu.
 *
 * Burada teslim tarihi bugün/yarın olan ama henüz teslimata HAZIR OLMAYAN
 * siparişler uyarı kutusunda gösterilir. Böylece "bugün teslim edilecek ama
 * daha hazır değil" durumu gözden kaçmaz.
 */
function teslimat_riskli_kutu(PDO $db, string $bugun, string $yarin): void
{
    $st = $db->prepare("SELECT o.id, o.code, o.status, o.delivery_date, o.delivery_time,
            o.received_count, c.name cname
        FROM orders o JOIN customers c ON c.id=o.customer_id
        WHERE o.status IN ('teslim_alindi','yikama_bekliyor','yikamada','kurutuluyor','kalite_kontrol')
          AND o.delivery_date<>'' AND o.delivery_date<=?
        ORDER BY o.delivery_date, o.id LIMIT 20");
    $st->execute([$yarin]);
    $riskli = $st->fetchAll();
    if (!$riskli) return;

    echo '<div class="card" style="border-left:4px solid #b45309">'
        . '<h2>⚠️ Bugün teslim günü olan ama henüz hazır olmayan siparişler</h2>'
        . '<p class="muted small">Bu siparişlerin teslim tarihi bugün veya yarın, ancak işlem '
        . 'henüz tamamlanmadı. Teslim gününde fark edilmeleri gerekir.</p>';
    foreach ($riskli as $o) {
        $durum = (string)$o['status'];
        $gecikti = (string)$o['delivery_date'] < $bugun;
        echo '<a class="row" href="?p=siparis&id=' . (int)$o['id'] . '">'
            . '<span class="row-main"><b>' . e((string)$o['cname']) . '</b><br>'
            . '<small>' . e((string)$o['code']) . ' · 📅 ' . e(tr_date((string)$o['delivery_date']))
            . ((string)$o['delivery_time'] !== '' ? ' ' . e((string)$o['delivery_time']) : '')
            . ' · 📦 ' . (int)$o['received_count'] . ' halı</small><br>'
            . status_badge($durum)
            . ($gecikti ? ' <span class="badge" style="background:#9f1239">gecikti</span>' : '')
            . '</span></a>';
    }
    echo '</div>';
}

/** Teslim edilecekler: paketlendi / dagitimda */
function teslimat_listesi(): void
{
    need_perm('siparis.gor');
    $db = db();
    $f = (string)($_GET['f'] ?? '');
    $t = today();
    $yarin = date('Y-m-d', strtotime('+1 day'));

    $sql = "SELECT o.*, c.name cname, c.phone, c.phone2, c.address, c.district,
        COALESCE((SELECT SUM(p.amount_kurus) FROM payments p WHERE p.order_id=o.id),0) odenen
        FROM orders o JOIN customers c ON c.id=o.customer_id
        WHERE o.status IN ('paketlendi','dagitimda')";
    $args = [];
    if ($f === 'today')        { $sql .= ' AND o.delivery_date=?'; $args[] = $t; }
    elseif ($f === 'tomorrow') { $sql .= ' AND o.delivery_date=?'; $args[] = $yarin; }
    elseif ($f === 'late')     { $sql .= " AND o.delivery_date<>'' AND o.delivery_date<?"; $args[] = $t; }
    $sql .= " ORDER BY (o.delivery_date='') DESC, o.delivery_date, o.delivery_time, o.id";

    // ⚠ SAYFALAMA: teslimat listesi de tümünü basıyordu (ölçüldü: 1,6 MB).
    $st = $db->prepare('SELECT COUNT(*) FROM (' . $sql . ')');
    $st->execute($args);
    $teslToplam = (int)$st->fetchColumn();
    $limit = 50;
    $sayfaNo = max(1, (int)($_GET['sayfa'] ?? 1));
    $teslSayfa = max(1, (int)ceil($teslToplam / $limit));
    if ($sayfaNo > $teslSayfa) $sayfaNo = $teslSayfa;
    $st = $db->prepare($sql . ' LIMIT ? OFFSET ?');
    $st->execute(array_merge($args, [$limit, ($sayfaNo - 1) * $limit]));
    $rows = $st->fetchAll();

    layout_head('Teslimat Listesi', 'teslimat');
    chips('teslimat', ['today' => 'Bugün', 'tomorrow' => 'Yarın', 'late' => 'Gecikmiş', '' => 'Tümü'], $f, 'f');
    echo '<p class="muted small">' . $teslToplam . ' teslim edilecek sipariş'
        . ($teslSayfa > 1 ? ' · sayfa ' . $sayfaNo . '/' . $teslSayfa : '') . '</p>';

    if (!$rows) {
        empty_state('📦', 'Teslim edilecek hazır sipariş yok.', '?p=siparisler&durum=paketlendi', 'Paketlenenleri gör');
        teslimat_riskli_kutu($db, $t, $yarin);
        layout_foot('teslimat');
        return;
    }
    liste_sayfalama('teslimat', ['f' => $f], $sayfaNo, $teslSayfa, $limit);
    teslimat_riskli_kutu($db, $t, $yarin);
    foreach ($rows as $o) {
        $oid = (int)$o['id'];
        $toplam = (int)$o['total_kurus'] - (int)$o['discount_kurus'];
        $bakiye = $toplam - (int)$o['odenen'];
        $d = delay_info($o);
        $adet = (int)$o['received_count'];

        echo '<div class="card">';
        echo '<a class="row" href="?p=siparis&id=' . $oid . '">'
            . '<span class="row-main"><b>' . e((string)$o['cname']) . '</b>'
            . ((string)$o['district'] !== '' ? ' · ' . e((string)$o['district']) : '') . '<br>'
            . '<small>' . e((string)$o['code']) . ' · 📦 <b>' . $adet . '</b> halı</small><br>'
            . '<small>📅 Teslim: <b>' . e(tr_date((string)$o['delivery_date'])) . '</b>'
            . ((string)$o['delivery_time'] !== '' ? ' ' . e((string)$o['delivery_time']) : '') . '</small>'
            . ((string)$o['address'] !== '' ? '<br><small>📍 ' . e((string)$o['address']) . '</small>' : '')
            . '<br>' . status_badge((string)$o['status']);
        if ($d['late']) echo ' ' . liste_gecikme_rozeti($d);
        echo '</span>'
            . '<span class="row-side">' . money_tl($toplam)
            . '<br><small class="' . ($bakiye > 0 ? 'neg' : '') . '">'
            . ($bakiye > 0 ? 'Kalan: ' . money_tl($bakiye) : 'Ödendi ✓') . '</small>'
            . '</span></a>';

        echo '<div class="btnrow pad">';
        if ((string)$o['phone'] !== '') {
            echo '<a class="btn btn-primary" href="' . e(tel_href((string)$o['phone'])) . '" style="flex:1">📞 ' . e((string)$o['phone']) . '</a>';
        }
        echo nav_quick($o);
        echo '</div>';

        if ((string)$o['note'] !== '') {
            echo '<p class="small"><i>' . e((string)$o['note']) . '</i></p>';
        }

        if (can('siparis.durum')) {
            echo '<form method="post" action="?p=siparis-durum" class="mt8">' . csrf_field()
                . '<input type="hidden" name="id" value="' . $oid . '">'
                . '<input type="hidden" name="durum" value="teslim">'
                . '<label>Teslim edilen halı adedi'
                . '<input name="returned_qty" type="number" min="0" inputmode="numeric"'
                . ' value="' . $adet . '" required></label>'
                . '<p class="muted small">Alınan: <b>' . $adet . '</b> adet. Sayı uyuşmuyorsa düzeltin.</p>'
                . '<button class="btn btn-primary btn-block">✅ Teslim Edildi</button></form>';
        }
        echo '</div>';
    }
    layout_foot('teslimat');
}

// ---------------------------------------------------------------- gecikmiş listesi

/** Gecikmiş siparişler: kova çipleri + gecikme nedeni kaydı */
function gecikmis_listesi(): void
{
    need_perm('siparis.gor');
    $db = db();
    $grup = (string)($_GET['grup'] ?? '');
    $t = today();

    // ⚠ SAYFALAMA + PERFORMANS: liste TÜM gecikmeleri tek sayfada basıyordu
    //   (5.000 siparişlik veride 9 MB HTML — ölçüldü). Ayrıca WA/sayfalama
    //   alt sorguları HER satır için 4 kez çalışıyordu: 3.200 gecikmiş
    //   kayıtta sayfa 1.023 ms sürüyordu (alt sorgularsız SQL 44 ms).
    //   Rozetler yalnız GÖSTERİLEN satırlarda kullanıldığı için alt sorgular
    //   sayfalanan 50 kayıt için ayrı sorguyla çekilir (aşağıda).
    //   Sayaçlar (kova adetleri) TÜM veriden hesaplanmaya devam eder.
    $st = $db->prepare("SELECT o.*, c.name cname, c.phone, c.phone2, c.district cdistrict
        FROM orders o JOIN customers c ON c.id=o.customer_id
        WHERE o.status NOT IN ('teslim','iptal','teslim_edilemedi')
        AND o.delivery_date <> '' AND o.delivery_date < ?
        ORDER BY o.delivery_date, o.id");
    $st->execute([$t]);
    $gecikmeRows = $st->fetchAll();

    $tum = [];
    $sayac = ['c1' => 0, 'c2_3' => 0, 'c4_6' => 0, 'kritik' => 0];
    $personel = [];
    $stU = $db->query("SELECT id,full_name,username FROM users WHERE active=1 ORDER BY full_name");
    foreach ($stU->fetchAll() as $u) {
        $personel[(int)$u['id']] = (string)($u['full_name'] ?: $u['username']);
    }

    // Kova sayaçları TÜM kayıtlardan hesaplanır (filtreleme sayfalamadan ÖNCE)
    $uygun = [];
    foreach ($gecikmeRows as $o) {
        $d = delay_info($o);
        if (!$d['late']) continue;
        $o['_gecikme'] = $d;
        if (isset($sayac[(string)$d['bucket']])) $sayac[(string)$d['bucket']]++;
        if ($grup === '' || (string)$d['bucket'] === $grup) $uygun[] = $o;
    }
    // …sonra sadece bulunduğu sayfa gösterilir
    $limit = 50;
    $sayfaNo = max(1, (int)($_GET['sayfa'] ?? 1));
    $toplamKayit = count($uygun);
    $toplamSayfa = max(1, (int)ceil($toplamKayit / $limit));
    if ($sayfaNo > $toplamSayfa) $sayfaNo = $toplamSayfa;
    $tum = array_slice($uygun, ($sayfaNo - 1) * $limit, $limit);
    $toplam = $toplamKayit;

    // Rozet alt sorguları (WA durumu + son değişiklik) yalnız BU sayfanın
    // kayıtları için — tüm gecikmişler için değil (bkz. yukarıdaki not).
    if ($tum) {
        $ids = array_map(fn($x) => (int)$x['id'], $tum);
        $in = implode(',', $ids);
        $waMap = [];
        $st = $db->query("SELECT order_id,
                SUM(status IN ('gonderildi','teslim','okundu')) wa_ok,
                SUM(status='hata') wa_hata,
                SUM(status='bekliyor') wa_bekliyor
            FROM wa_queue WHERE order_id IN ($in) GROUP BY order_id");
        foreach ($st->fetchAll() as $w) $waMap[(int)$w['order_id']] = $w;
        $evMap = [];
        $st = $db->query("SELECT order_id, MAX(created_at) son_event FROM events
            WHERE order_id IN ($in) GROUP BY order_id");
        foreach ($st->fetchAll() as $ev) $evMap[(int)$ev['order_id']] = (string)$ev['son_event'];
        foreach ($tum as $k => $o) {
            $w = $waMap[(int)$o['id']] ?? null;
            $tum[$k]['wa_ok'] = (int)($w['wa_ok'] ?? 0);
            $tum[$k]['wa_hata'] = (int)($w['wa_hata'] ?? 0);
            $tum[$k]['wa_bekliyor'] = (int)($w['wa_bekliyor'] ?? 0);
            $tum[$k]['son_event'] = $evMap[(int)$o['id']] ?? '';
        }
    }

    layout_head('Gecikmiş Siparişler', 'gecikmis');
    echo '<div class="statrow">';
    foreach ($sayac as $bk => $adet) {
        if ($adet <= 0) continue;
        kpi((string)$adet, liste_gecikmis_kova_etiket((string)$bk),
            liste_url('gecikmis', ['grup' => (string)$bk]),
            $bk === 'kritik' ? 'stat-danger' : 'stat-alert');
    }
    echo '</div>';
    chips('gecikmis', [
        'c1' => '1 gün (' . $sayac['c1'] . ')',
        'c2_3' => '2-3 gün (' . $sayac['c2_3'] . ')',
        'c4_6' => '4-6 gün (' . $sayac['c4_6'] . ')',
        'kritik' => '7+ gün kritik (' . $sayac['kritik'] . ')',
        '' => 'Tümü',
    ], $grup, 'grup');

    if (!$tum) {
        $grup !== ''
            ? empty_state('✅', 'Bu kovada gecikmiş sipariş yok.', '?p=gecikmis', 'Tümünü gör')
            : empty_state('🎉', 'Gecikmiş sipariş yok. Her şey yolunda!', '?p=siparisler', 'Siparişlere dön');
        layout_foot('gecikmis');
        return;
    }
    // sayfalama çipleri (gecikme listesi artık 50'şer gösterir)
    liste_sayfalama('gecikmis', ['grup' => $grup], $sayfaNo, $toplamSayfa, $limit);

    foreach ($tum as $o) {
        $oid = (int)$o['id'];
        $d = $o['_gecikme'];
        $kritik = (string)$d['bucket'] === 'kritik';
        $reason = (string)$o['delay_reason'];
        $sorumlu = isset($personel[(int)$o['assigned_to']])
            ? $personel[(int)$o['assigned_to']]
            : ((string)$o['staff'] !== '' ? (string)$o['staff'] : '');
        // son durum değişikliği: en yeni olay kaydı, yoksa güncelleme zamanı
        $sonHareket = (string)($o['son_event'] ?? '');
        if ($sonHareket === '') $sonHareket = (string)$o['updated_at'];
        $takipNotu = trim((string)$o['note']);
        $anaTel = (string)$o['phone'];
        $ikinciTel = (string)($o['phone2'] ?? '');
        $adayi = $anaTel !== '' ? $anaTel : $ikinciTel;
        $wl = wa_phone($adayi);

        echo '<div class="card"' . ($kritik ? ' style="border-left:4px solid #b91c1c"' : '') . '>';
        echo '<a class="row" href="?p=siparis&id=' . $oid . '">'
            . '<span class="row-main">'
            . ($kritik
                ? '<span class="badge" style="background:#7f1d1d">🚨 KRİTİK — 7+ gün gecikti</span> ' : '')
            . '<span class="badge" style="background:'
            . ($kritik ? '#b91c1c' : '#c2410c') . '">⏰ '
            . e((string)$d['label']) . '</span> '
            . '<b>' . e((string)$o['cname']) . '</b>'
            . ((string)$o['cdistrict'] !== '' ? ' · <small>' . e((string)$o['cdistrict']) . '</small>' : '')
            . '<br>'
            . '<small>' . e((string)$o['code']) . ' · planlı teslim: <b>'
            . e(tr_date((string)$o['delivery_date'])) . '</b>'
            . ((string)$o['delivery_time'] !== '' ? ' ' . e((string)$o['delivery_time']) : '') . '</small>'
            // son durum değişikliği
            . '<br><small class="muted">🕘 Son değişiklik: <b>'
            . e($sonHareket !== '' ? tr_date($sonHareket, true) : '—') . '</b></small>'
            // müşteri iletişim (telefon + ikinci varsa)
            . ($adayi !== ''
                ? '<br><small>📞 ' . e($adayi)
                  . ($ikinciTel !== '' && $ikinciTel !== $adayi ? ' · ' . e($ikinciTel) : '') . '</small>'
                : '<br><small class="muted">📞 Telefon kayıtlı değil</small>')
            // WhatsApp bildirim durumu
            . '<br><small>' . liste_wa_durum_rozet($o) . '</small>'
            . '<br>' . status_badge((string)$o['status'])
            . ($sorumlu !== '' ? ' <small>👷 ' . e($sorumlu) . '</small>' : '')
            . ($reason !== ''
                ? ' <span class="badge badge-soft">' . e(delay_reasons()[$reason] ?? $reason) . '</span>' : '')
            . '</span><span class="row-side">' . e(liste_gecikmis_kova_etiket((string)$d['bucket']))
            . '<br><small>' . money_tl((int)$o['total_kurus'] - (int)$o['discount_kurus']) . '</small>'
            . '</span></a>';

        // takip notu
        if ($takipNotu !== '') {
            echo '<p class="small"><b>📌 Takip notu:</b> ' . nl2br(e($takipNotu)) . '</p>';
        }

        echo '<div class="btnrow pad">';
        if ($adayi !== '') {
            echo '<a class="btn btn-sm" href="' . e(tel_href($adayi)) . '">📞 Ara</a> ';
            if ($ikinciTel !== '' && $ikinciTel !== $adayi) {
                echo '<a class="btn btn-sm" href="' . e(tel_href($ikinciTel)) . '">📞 2. numara</a> ';
            }
            if ($wl !== '') {
                echo '<a class="btn btn-sm btn-wa" href="https://wa.me/' . e($wl) . '?text='
                    . rawurlencode('Merhaba ' . (string)$o['cname'] . ', ' . (string)$o['code']
                        . ' numaralı siparişinizin teslim tarihi ' . tr_date((string)$o['delivery_date'])
                        . ' olarak planlıydı. Gecikme için özür dileriz, bilgi verebilir miyiz?')
                    . '" target="_blank" rel="noopener">💬 WhatsApp</a> ';
            }
        }
        if ($sorumlu !== '') {
            echo '<a class="btn btn-sm" href="?p=benim">👷 Sorumlu</a> ';
        }
        echo '<a class="btn btn-sm" href="?p=siparis-not&id=' . $oid . '">📅 Tarih değiştir</a></div>';

        if (can('siparis.guncelle')) {
            // ⚠ Metin alanının adı `note` OLMALI: siparis_not() yalnız `note`
            // alanını okur. Önceki sürümde textarea `not_gir` adını taşıyor,
            // gizli `note` alanı eski notu gönderiyordu → kullanıcının yazdığı
            // takip notu SESSİZCE KAYBOLUYORDU (denetimde kanıtlandı).
            // Alan mevcut notla doldurulur; değiştirilirse yeni hali kaydedilir.
            echo '<form method="post" action="?p=siparis-not" class="mt8">' . csrf_field()
                . '<input type="hidden" name="id" value="' . $oid . '">'
                . '<label>Gecikme nedeni<select name="delay_reason">';
            foreach (delay_reasons() as $k => $lbl) {
                echo '<option value="' . e((string)$k) . '"'
                    . ($reason === (string)$k ? ' selected' : '') . '>' . e($lbl) . '</option>';
            }
            echo '</select></label>'
                . '<label>Takip notu<textarea name="note" rows="2" placeholder="Müşteri arandı, öğleden sonra uğrayacağız.">'
                . e($takipNotu) . '</textarea></label>'
                . '<button class="btn btn-sm btn-block">Nedeni Kaydet</button></form>';
        }
        echo '</div>';
    }
    layout_foot('gecikmis');
}

/** Gecikme kartında WhatsApp bildirim durumu rozeti — kayıt yoksa dürüstçe belirtilir */
function liste_wa_durum_rozet(array $o): string
{
    $ok = (int)($o['wa_ok'] ?? 0);
    $hata = (int)($o['wa_hata'] ?? 0);
    $bekliyor = (int)($o['wa_bekliyor'] ?? 0);
    $p = [];
    if ($ok > 0)    $p[] = '<span class="badge" style="background:#15803d">📲 Gönderildi (' . $ok . ')</span>';
    if ($bekliyor > 0) $p[] = '<span class="badge" style="background:#8a6d1a">📲 Kuyrukta (' . $bekliyor . ')</span>';
    if ($hata > 0)  $p[] = '<span class="badge" style="background:#b91c1c">📲 Hata (' . $hata . ')</span>';
    return $p ? implode(' ', $p) : '<span class="muted">📲 Bildirim kaydı yok</span>';
}
