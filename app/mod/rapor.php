<?php
/**
 * Raporlar · Ayarlar · Yedek · WhatsApp paneli · Etiket / QR / Fiş
 * Mim Halı Yıkama — İşletme Sistemi v2
 * PHP 7.4+ · SQLite · harici bağımlılık yok (grafikler HTML/CSS ile).
 *
 * NOT: Kural — yapılandırılmamış bir özellik "çalışıyor" gibi gösterilmez.
 * WhatsApp için gerçek HTTP çağrısı (wa_http) bu dosyadan YAPILMAZ.
 */

declare(strict_types=1);

// ============================================================ YARDIMCILAR (tek dosya)
function rpr_flash(): void
{
    if (!empty($_SESSION['flash']) && is_string($_SESSION['flash'])) {
        echo '<div class="alert ok">' . e($_SESSION['flash']) . '</div>';
        unset($_SESSION['flash']);
    }
    // Hata mesajı hem dizi (birden çok satır) hem tek metin olabilir. Metin
    // yazan çağrılar vardı; yalnız dizi kontrolü onları sessizce yutuyordu.
    if (!empty($_SESSION['flash_hata'])) {
        $fh = is_array($_SESSION['flash_hata'])
            ? implode('<br>· ', $_SESSION['flash_hata'])
            : (string)$_SESSION['flash_hata'];
        echo '<div class="alert danger">' . e($fh) . '</div>';
        unset($_SESSION['flash_hata']);
    }
}

/** "2026-09" doğrula; boşsa içinde bulunulan ay */
function rpr_ay(): string
{
    $ay = (string)($_GET['ay'] ?? $_POST['ay'] ?? '');
    return preg_match('/^\d{4}-\d{2}$/', $ay) ? $ay : date('Y-m');
}
function rpr_ay_bas(string $ay): string { return $ay . '-01'; }
function rpr_ay_bit(string $ay): string { return date('Y-m-t', strtotime($ay . '-01')); }
function rpr_ay_etiket(string $ay): string
{
    $t = strtotime($ay . '-01');
    if ($t === false) return $ay;
    $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz',
        'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    return $aylar[(int)date('n', $t)] . ' ' . date('Y', $t);
}
function rpr_ay_kay(string $ay, int $diff): string
{
    $t = strtotime($ay . '-01');
    $t = strtotime(($diff >= 0 ? '+' : '-') . abs($diff) . ' month', (int)$t);
    return date('Y-m', (int)$t);
}
function rpr_yuzde(int $pay, int $toplam): string
{
    if ($toplam <= 0) return '—';
    return number_format($pay * 100.0 / $toplam, 1, ',', '.') . '%';
}
/** Yatay renkli çubuk (grafik kütüphanesi yok — saf CSS) */
function rpr_bar(int $pay, int $toplam, string $renk): void
{
    $w = $toplam > 0 ? max(1.5, min(100.0, $pay * 100.0 / $toplam)) : 0.0;
    echo '<div style="background:#eef2f6;border-radius:6px;height:9px;overflow:hidden;margin-top:4px">'
        . '<div style="width:' . number_format($w, 2, '.', '') . '%;height:100%;background:' . e($renk) . '"></div></div>';
}
/** "adet / miktar" biçiminde ondalık (m² gibi ondalıklı değerler için de) */
/** mbstring yoksa da güvenli metin kısaltma (Türkçe karakter bozulmaz) */
function rpr_kisalt(string $s, int $n): string
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s, 'UTF-8') > $n ? mb_substr($s, 0, $n - 1, 'UTF-8') . '…' : $s;
    }
    if (function_exists('iconv_strlen')) {
        return iconv_strlen($s, 'UTF-8') > $n
            ? iconv_substr($s, 0, $n - 1, 'UTF-8') . '…' : $s;
    }
    $cut = (int)max(0, $n - 1);
    return strlen($s) > $n ? substr($s, 0, $cut) . '…' : $s;
}
/**
 * ensure_item_codes() bazen aynı kodu iki kaleme verir (yeni eklenen satırlar
 * en büyük id'ye sahip olduğu için next_item_code() onları görmez).
 * Etikette/QR'da iki halı aynı kodla basılırsa işçi hangisini alacağını
 * bilemez; bu yüzden sipariş içi TEKRAR eden kodları tek seferlik düzeltiriz.
 * Boş kodları doldurmaz (onu ensure_item_codes yapar), yalnızca çakışanı.
 */
function rpr_kod_onar(int $oid): void
{
    $st = db()->prepare('SELECT id, item_code FROM order_items WHERE order_id=? ORDER BY id');
    $st->execute([$oid]);
    $satirlar = $st->fetchAll();

    $sayilmis = [];
    $tekrar = [];
    foreach ($satirlar as $r) {
        $kod = (string)$r['item_code'];
        if ($kod === '') continue;
        if (isset($sayilmis[$kod])) $tekrar[] = (int)$r['id'];
        else $sayilmis[$kod] = true;
    }
    if (!$tekrar) return;

    // bugünün prefix'ine ait mevcut en yüksek sıra numarası
    $prefix = 'H-' . date('ymd') . '-';
    $q = db()->prepare("SELECT item_code FROM order_items WHERE item_code LIKE ?");
    $q->execute([$prefix . '%']);
    $max = 0;
    foreach ($q->fetchAll() as $r) {
        $n = (int)substr((string)$r['item_code'], strlen($prefix));
        if ($n > $max) $max = $n;
    }

    $u = db()->prepare('UPDATE order_items SET item_code=? WHERE id=?');
    foreach ($tekrar as $id) {
        $max++;
        $yeni = $prefix . str_pad((string)$max, 2, '0', STR_PAD_LEFT);
        $u->execute([$yeni, $id]);
    }
}
/** Etiket/QR öncesi standart akış: kodları üret + çakışmaları düzelt */
function rpr_kodlari_hazirla(int $oid): void
{
    ensure_item_codes($oid);
    rpr_kod_onar($oid);
}

/** Türkçe büyük harf (i→İ, ı→I doğru çevrilir) */
function rpr_buyuk(string $s): string
{
    return strtoupper(strtr($s, [
        'i' => 'İ', 'ı' => 'I', 'ş' => 'Ş', 'ğ' => 'Ğ', 'ü' => 'Ü', 'ö' => 'Ö', 'ç' => 'Ç',
    ]));
}
function rpr_no_perc(float $a, float $b): string
{
    return number_format($a, 2, ',', '') . ' / ' . number_format($b, 2, ',', '');
}
/** Grafik kütüphanesi olmadan dikey sütun */
function rpr_col(int $deger, int $enbuyuk, string $renk, string $baslik): void
{
    $h = $enbuyuk > 0 ? max(2.0, min(100.0, $deger * 100.0 / $enbuyuk)) : 0.0;
    echo '<div style="flex:1 1 0;min-width:0;text-align:center">'
        . '<div style="height:96px;display:flex;align-items:flex-end;justify-content:center">'
        . '<div title="' . e($baslik) . '" style="width:70%;height:' . number_format($h, 2, '.', '') . '%;background:' . e($renk) . ';border-radius:5px 5px 0 0"></div>'
        . '</div><div class="small muted" style="margin-top:4px">' . e($baslik) . '</div></div>';
}
function rpr_tablo_baslik(array $cols): void
{
    echo '<div class="kv" style="border-bottom:1px solid var(--line);font-weight:700">'
        . '<div style="width:auto;flex:0 0 40%">' . e((string)reset($cols)) . '</div><div></div></div>';
}

// ============================================================ 1) RAPORLAR
function raporlar(): void
{
    need_perm('siparis.gor');
    $db = db();
    $ay = rpr_ay();
    $b1 = rpr_ay_bas($ay);
    $b2 = rpr_ay_bit($ay);

    // --- A) dönem ciro / sipariş
    $st = $db->prepare("SELECT COUNT(*) adet,
            COALESCE(SUM(total_kurus - discount_kurus),0) ciro,
            COALESCE(SUM(total_kurus),0) brut,
            COALESCE(SUM(discount_kurus),0) indirim
        FROM orders WHERE date(created_at) BETWEEN ? AND ?");
    $st->execute([$b1, $b2]);
    $o = $st->fetch() ?: ['adet' => 0, 'ciro' => 0, 'brut' => 0, 'indirim' => 0];
    $adet = (int)$o['adet'];
    $ciro = (int)$o['ciro'];

    // --- gider
    $st = $db->prepare('SELECT COALESCE(SUM(amount_kurus),0) g FROM expenses WHERE date(created_at) BETWEEN ? AND ? AND ' . GIDER_RAPORA_GIRER);
    $st->execute([$b1, $b2]);
    $gider = (int)$st->fetchColumn();

    // --- sipariş-dışı gelir (mod/gelir.php) — ciro değildir, kasaya girer
    $st = $db->prepare('SELECT COALESCE(SUM(amount_kurus),0) g FROM incomes WHERE date(created_at) BETWEEN ? AND ?');
    $st->execute([$b1, $b2]);
    $digerGelir = (int)$st->fetchColumn();

    $net = $ciro + $digerGelir - $gider;
    $ort = $adet > 0 ? (int)round($ciro / $adet) : 0;

    // --- tahsilat (dönemde ödeme alınan)
    $st = $db->prepare('SELECT COALESCE(SUM(amount_kurus),0) t FROM payments WHERE date(created_at) BETWEEN ? AND ?');
    $st->execute([$b1, $b2]);
    $tahsilat = (int)$st->fetchColumn();
    $alacak = $ciro - $tahsilat;

    // --- toplam açık alacak (tüm zamanlar)
    $st = $db->prepare("SELECT COALESCE(SUM(o.total_kurus - o.discount_kurus),0) t,
            COALESCE(SUM((SELECT COALESCE(SUM(amount_kurus),0) FROM payments p WHERE p.order_id=o.id)),0) o
        FROM orders o WHERE o.status NOT IN ('iptal')");
    $st->execute();
    $r = $st->fetch() ?: ['t' => 0, 'o' => 0];
    $acikAlacak = (int)$r['t'] - (int)$r['o'];

    layout_head('Raporlar');
    rpr_flash();

    // dönem seçici
    $onceki = rpr_ay_kay($ay, -1);
    $sonraki = rpr_ay_kay($ay, 1);
    echo '<div class="card"><div class="totalbar" style="margin-bottom:8px">'
        . '<a class="btn btn-sm btn-ghost" href="?p=raporlar&amp;ay=' . e($onceki) . '">←</a>'
        . '<div style="font-size:1rem"><b>' . e(rpr_ay_etiket($ay)) . '</b></div>'
        . '<a class="btn btn-sm btn-ghost" href="?p=raporlar&amp;ay=' . e($sonraki) . '">→</a></div>'
        . '<div class="btnrow">'
        . '<a class="btn btn-sm" href="?p=raporlar">Bu ay</a>';
    for ($i = 1; $i <= 5; $i++) {
        $m = rpr_ay_kay(date('Y-m'), -$i);
        echo '<a class="btn btn-sm btn-ghost" href="?p=raporlar&amp;ay=' . e($m) . '">' . e(rpr_ay_etiket($m)) . '</a>';
    }
    echo '</div>'
        . '<p class="muted small" style="margin:6px 0 0">Dönem: ' . e(tr_date($b1)) . ' – ' . e(tr_date($b2)) . '</p>'
        . '<div class="btnrow pad no-print"><button type="button" class="btn btn-sm" onclick="window.print()">🖨 Yazdır</button></div>'
        . '</div>';

    echo '<div class="print-body">';

    // ---------------------------------------------------------- A) KPI
    echo '<h2 class="sec">' . e(rpr_ay_etiket($ay)) . ' Özeti</h2>';
    echo '<div class="statrow">';
    kpi(money_tl($ciro), 'Ciro (net)');
    kpi(money_tl($tahsilat), 'Tahsilat');
    kpi(money_tl($gider), 'Gider');
    kpi(money_tl($net), 'Net Kâr', '', $net < 0 ? 'neg' : '');
    kpi((string)$adet, 'Sipariş adedi');
    kpi(money_tl($ort), 'Ortalama sipariş');
    echo '</div>';
    echo '<div class="card"><div class="kv"><div>Brüt tutar</div><div>' . e(money_tl((int)$o['brut'])) . '</div></div>'
        . '<div class="kv"><div>İndirim</div><div class="neg">− ' . e(money_tl((int)$o['indirim'])) . '</div></div>'
        . '<div class="kv"><div>Net ciro</div><div><b>' . e(money_tl($ciro)) . '</b></div></div>'
        . '<div class="kv"><div>Sipariş-dışı gelir</div><div>+ ' . e(money_tl($digerGelir)) . '</div></div>'
        . '<div class="kv"><div>Gider</div><div class="neg">− ' . e(money_tl($gider)) . '</div></div>'
        . '<div class="kv"><div>Net (ciro+gelir−gider)</div><div><b class="' . ($net < 0 ? 'neg' : '') . '">' . e(money_tl($net)) . '</b></div></div>'
        . '</div>';

    // ---------------------------------------------------------- F) tahsilat vs alacak
    echo '<h2 class="sec">Tahsilat / Alacak</h2>';
    echo '<div class="card">';
    echo '<div class="kv"><div>Dönem cirosu</div><div>' . e(money_tl($ciro)) . '</div></div>';
    echo '<div class="kv"><div>Dönem tahsilatı</div><div>' . e(money_tl($tahsilat)) . '</div></div>';
    echo '<div class="kv"><div>Bu dönem tahsil edilemeyen</div><div class="' . ($alacak > 0 ? 'neg' : '') . '">' . e(money_tl($alacak)) . '</div></div>';
    echo '<div class="kv"><div>Dönem tahsilat oranı</div><div>' . e(rpr_yuzde($tahsilat, $ciro)) . '</div></div>';
    echo '<div class="kv"><div>Tüm zamanlar açık alacak</div><div class="' . ($acikAlacak > 0 ? 'neg' : '') . '">' . e(money_tl($acikAlacak)) . '</div></div>';
    echo '</div>';

    // ---------------------------------------------------------- B) hizmet kırılımı
    echo '<h2 class="sec">Hizmet Kırılımı</h2>';
    $st = $db->prepare("SELECT i.type t, COUNT(*) adet, COALESCE(SUM(i.m2),0) m2,
            COALESCE(SUM(i.line_kurus),0) tutar
        FROM order_items i JOIN orders o ON o.id=i.order_id
        WHERE date(o.created_at) BETWEEN ? AND ?
        GROUP BY i.type ORDER BY tutar DESC");
    $st->execute([$b1, $b2]);
    $satirlar = $st->fetchAll();
    if (!$satirlar) {
        echo '<div class="card"><p class="muted" style="margin:0">Bu dönemde hizmet kaydı yok.</p></div>';
    } else {
        $types = service_types();
        $tHizmet = 0;
        foreach ($satirlar as $s) $tHizmet += (int)$s['tutar'];
        echo '<div class="card">';
        foreach ($satirlar as $s) {
            $ty = (string)$s['t'];
            $lbl = $types[$ty][0] ?? $ty;
            $pct = (int)$s['tutar'] * 100 / max(1, $tHizmet);
            echo '<div style="padding:8px 0;border-bottom:1px dashed var(--line)">'
                . '<div class="totalbar"><b>' . e($lbl) . '</b><span>' . e(money_tl((int)$s['tutar'])) . ' · ' . e(rpr_yuzde((int)$s['tutar'], $tHizmet)) . '</span></div>'
                . '<div class="small muted">' . (int)$s['adet'] . ' kalem · ' . e(number_format((float)$s['m2'], 2, ',', '')) . ' m² toplam</div>';
            rpr_bar((int)$s['tutar'], $tHizmet, '#16A6A1');
            echo '</div>';
        }
        echo '<div class="totalbar" style="padding-top:10px"><b>Toplam</b><b>' . e(money_tl($tHizmet)) . '</b></div>';
        echo '</div>';
    }

    // ---------------------------------------------------------- C) en çok kazandıran müşteriler
    echo '<h2 class="sec">En Çok Kazandıran Müşteriler</h2>';
    $st = $db->prepare("SELECT c.id cid, c.name, c.phone, COUNT(o.id) adet,
            COALESCE(SUM(o.total_kurus - o.discount_kurus),0) toplam,
            MAX(o.created_at) son
        FROM orders o JOIN customers c ON c.id=o.customer_id
        WHERE date(o.created_at) BETWEEN ? AND ?
        GROUP BY c.id ORDER BY toplam DESC LIMIT 10");
    $st->execute([$b1, $b2]);
    $cust = $st->fetchAll();
    if (!$cust) {
        echo '<div class="card"><p class="muted" style="margin:0">Bu dönemde sipariş yok.</p></div>';
    } else {
        echo '<div class="card">';
        foreach ($cust as $c) {
            echo '<div class="row"><div class="row-main"><b><a href="?p=musteri&amp;id=' . (int)$c['cid'] . '">'
                . e((string)$c['name']) . '</a></b>'
                . '<br><small>' . (int)$c['adet'] . ' sipariş · son: ' . e(tr_date((string)$c['son'])) . '</small></div>'
                . '<div class="row-side">' . e(money_tl((int)$c['toplam'])) . '</div></div>';
        }
        echo '</div>';
    }

    // ---------------------------------------------------------- D) gecikme analizi
    echo '<h2 class="sec">Gecikme Analizi</h2>';
    $st = $db->prepare("SELECT o.*, c.name cname FROM orders o JOIN customers c ON c.id=o.customer_id
        WHERE o.status NOT IN ('teslim','iptal','teslim_edilemedi')
          AND COALESCE(o.delivery_date,'') <> ''");
    $st->execute();
    $aciklar = $st->fetchAll();
    $kova = ['c1' => 0, 'c2_3' => 0, 'c4_6' => 0, 'kritik' => 0];
    $neden = [];
    $gecikenler = [];
    foreach ($aciklar as $oo) {
        $d = delay_info($oo);
        if (!$d['late']) continue;
        $b = (string)$d['bucket'];
        if (isset($kova[$b])) $kova[$b]++;
        $r = (string)($oo['delay_reason'] ?? '');
        if ($r !== '') {
            $r = str_replace('tekrar_', '', $r);
            $neden[$r] = ($neden[$r] ?? 0) + 1;
        }
        $gecikenler[] = [$oo, $d];
    }
    $gecTop = array_sum($kova);
    $kovaEtiket = ['c1' => '1 gün', 'c2_3' => '2–3 gün', 'c4_6' => '4–6 gün', 'kritik' => '7+ gün (kritik)'];
    $kovaRenk = ['c1' => '#0e7490', 'c2_3' => '#b45309', 'c4_6' => '#c2410c', 'kritik' => '#b91c1c'];
    echo '<div class="card">';
    echo '<div class="kv"><div>Açık sipariş</div><div>' . count($aciklar) . '</div></div>'
        . '<div class="kv"><div>Geciken</div><div class="' . ($gecTop > 0 ? 'neg' : '') . '">' . $gecTop . '</div></div>';
    foreach ($kova as $k => $adet) {
        echo '<div style="padding:6px 0">'
            . '<div class="totalbar"><span>' . e($kovaEtiket[$k]) . '</span><b>' . (int)$adet . ' · ' . e(rpr_yuzde((int)$adet, $gecTop)) . '</b></div>'
            . rpr_bar((int)$adet, max(1, $gecTop), $kovaRenk[$k]) . '</div>';
    }
    echo '</div>';

    // gecikme nedenleri
    echo '<div class="card"><h3>Gecikme Nedenleri</h3>';
    if (!$neden) {
        echo '<p class="muted" style="margin:0">Geciken sipariş için neden girilmemiş.</p>';
    } else {
        arsort($neden);
        $dr = delay_reasons();
        $tN = array_sum($neden);
        foreach ($neden as $k => $adet) {
            $lbl = $dr[(string)$k] ?? (string)$k;
            echo '<div class="kv"><div>' . e($lbl) . '</div><div>' . (int)$adet . ' · ' . e(rpr_yuzde((int)$adet, $tN)) . '</div></div>';
        }
    }
    echo '</div>';

    // geciken sipariş listesi
    if ($gecikenler) {
        usort($gecikenler, function ($a, $b) { return $b[1]['days'] <=> $a[1]['days']; });
        echo '<div class="card"><h3>Geciken Siparişler</h3>';
        $n = 0;
        foreach ($gecikenler as $g) {
            if ($n++ >= 15) break;
            $oo = $g[0];
            $d = $g[1];
            echo '<div class="row"><div class="row-main"><b><a href="?p=siparis&amp;id=' . (int)$oo['id'] . '">'
                . e((string)$oo['code']) . '</a></b><br><small>' . e((string)$oo['cname']) . '</small></div>'
                . '<div class="row-side"><span class="badge" style="background:' . ($d['bucket'] === 'kritik' ? '#b91c1c' : '#c2410c') . '">'
                . e((string)$d['label']) . '</span></div></div>';
        }
        if (count($gecikenler) > 15) {
            echo '<p class="muted small">… ve ' . (count($gecikenler) - 15) . ' sipariş daha. <a href="?p=gecikmis">Tümü →</a></p>';
        }
        echo '</div>';
    }

    // ---------------------------------------------------------- E) durum dağılımı
    echo '<h2 class="sec">Durum Dağılımı (tüm zamanlar)</h2>';
    $st = $db->query("SELECT status, COUNT(*) adet FROM orders GROUP BY status");
    $durumlar = $st->fetchAll();
    $tDurum = 0;
    foreach ($durumlar as $d) $tDurum += (int)$d['adet'];
    echo '<div class="card">';
    if (!$durumlar) {
        echo '<p class="muted" style="margin:0">Henüz sipariş yok.</p>';
    } else {
        $sl = status_labels();
        $sc = status_colors();
        usort($durumlar, function ($a, $b) { return (int)$b['adet'] - (int)$a['adet']; });
        foreach ($durumlar as $d) {
            $stt = (string)$d['status'];
            $lbl = $sl[$stt] ?? $stt;
            $renk = $sc[$stt] ?? '#555';
            echo '<div style="padding:5px 0">'
                . '<div class="totalbar"><span>' . status_badge($stt) . '</span><b>' . (int)$d['adet'] . ' · ' . e(rpr_yuzde((int)$d['adet'], $tDurum)) . '</b></div>'
                . rpr_bar((int)$d['adet'], max(1, $tDurum), $renk) . '</div>';
        }
        echo '<div class="totalbar" style="padding-top:8px"><b>Toplam</b><b>' . $tDurum . ' sipariş</b></div>';
    }
    echo '</div>';

    // ---------------------------------------------------------- G) 6 aylık trend
    echo '<h2 class="sec">Son 6 Ay Trendi</h2>';
    $aylar = [];
    for ($i = 5; $i >= 0; $i--) $aylar[] = rpr_ay_kay(date('Y-m'), -$i);
    $s1 = $aylar[0] . '-01';
    $s2 = rpr_ay_bit($aylar[5]);
    $st = $db->prepare("SELECT strftime('%Y-%m', created_at) y,
            COALESCE(SUM(total_kurus - discount_kurus),0) c FROM orders
        WHERE created_at >= ? GROUP BY y");
    $st->execute([$s1]);
    $cMap = [];
    foreach ($st->fetchAll() as $r) $cMap[(string)$r['y']] = (int)$r['c'];
    $st = $db->prepare("SELECT strftime('%Y-%m', created_at) y,
            COALESCE(SUM(amount_kurus),0) g FROM expenses
        WHERE created_at >= ? AND ' . GIDER_RAPORA_GIRER . ' GROUP BY y");
    $st->execute([$s1]);
    $gMap = [];
    foreach ($st->fetchAll() as $r) $gMap[(string)$r['y']] = (int)$r['g'];

    $maxV = 1;
    foreach ($aylar as $m) $maxV = max($maxV, $cMap[$m] ?? 0, $gMap[$m] ?? 0);
    $etiketler = [];
    foreach ($aylar as $m) $etiketler[$m] = substr(rpr_ay_etiket($m), 0, 3);

    echo '<div class="card"><h3>Ciro (yeşil) / Gider (kırmızı)</h3>'
        . '<div style="display:flex;gap:6px;align-items:flex-end">';
    foreach ($aylar as $m) {
        // min-width yok: 6 sütun dar ekranda (360px) de taşmasın, esnek kalsın.
        echo '<div style="flex:1 1 0;min-width:0;text-align:center">'
            . '<div style="height:100px;display:flex;align-items:flex-end;justify-content:center;gap:3px">'
            . '<div title="Ciro ' . e($etiketler[$m]) . '" style="width:38%;height:'
            . number_format(max(1.0, min(100.0, ($cMap[$m] ?? 0) * 100.0 / $maxV)), 2, '.', '') . '%;background:#16A6A1;border-radius:4px 4px 0 0"></div>'
            . '<div title="Gider ' . e($etiketler[$m]) . '" style="width:38%;height:'
            . number_format(max(1.0, min(100.0, ($gMap[$m] ?? 0) * 100.0 / $maxV)), 2, '.', '') . '%;background:#b91c1c;border-radius:4px 4px 0 0"></div>'
            . '</div><div class="small muted" style="margin-top:4px">' . e($etiketler[$m]) . '</div></div>';
    }
    echo '</div></div>';

    echo '<div class="card"><h3>Aylık tablo</h3>';
    // Para hücreleri dar ekranda alt alta kaymasın: sütun esnek + sağa yaslı + satır kaydırma yok
    $h = static function (string $cls = '', string $st = '') {
        return '<div class="' . $cls . '" style="' . $st . 'min-width:0;flex:1 1 0;text-align:right;white-space:nowrap">';
    };
    echo '<div class="kv" style="font-weight:700"><div style="flex:0 0 26%;min-width:0">Ay</div>'
        . $h() . 'Ciro</div>' . $h() . 'Gider</div>' . $h() . 'Net</div></div>';
    foreach ($aylar as $m) {
        $cc = $cMap[$m] ?? 0;
        $gg = $gMap[$m] ?? 0;
        echo '<div class="kv"><div style="flex:0 0 26%;min-width:0">' . e($etiketler[$m]) . '</div>'
            . $h() . e(money_tl($cc)) . '</div>'
            . $h('neg') . e(money_tl($gg)) . '</div>'
            . $h($cc - $gg < 0 ? 'neg' : '') . e(money_tl($cc - $gg)) . '</div></div>';
    }
    echo '</div>';

    echo '</div>'; // /print-body
    layout_foot();
}

// ============================================================ 2) AYARLAR
function ayarlar(): void
{
    // Artık "ayar.yonet" iznine sahip HERKES girebilir (ör. yöneticinin
    // yetki verdiği kıdemli eleman). Yetki verilmemişse kapalı.
    if (!can('ayar.yonet')) {
        http_response_code(403);
        layout_head('Yetkisiz');
        echo '<div class="card"><p class="alert"><b>Bu sayfa yalnızca yönetici içindir.</b></p>'
            . '<a class="btn btn-block" href="?p=">← Panele dön</a></div>';
        layout_foot();
        exit;
    }

    $db = db();
    $mesaj = [];
    $hata = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $islem = (string)($_POST['islem'] ?? '');

        // -------------------------------------------------- A) işletme bilgileri
        if ($islem === 'isletme') {
            setting_set('isletme_adi', trim((string)($_POST['isletme_adi'] ?? '')));
            setting_set('telefon', trim((string)($_POST['telefon'] ?? '')));
            setting_set('adres', trim((string)($_POST['adres'] ?? '')));
            $mesaj[] = 'İşletme bilgileri kaydedildi.';
        }

        // -------------------------------------------------- A2) Google yorum bağlantısı
        if ($islem === 'google_yorum') {
            $url = trim((string)($_POST['google_yorum_url'] ?? ''));
            if ($url !== '' && !preg_match('~^https?://~i', $url)) {
                $hata[] = 'Yorum bağlantısı http:// veya https:// ile başlamalı.';
            } else {
                setting_set('google_yorum_url', $url);
                setting_set('google_yorum_mesaj', trim((string)($_POST['google_yorum_mesaj'] ?? '')));
                $mesaj[] = $url === ''
                    ? 'Yorum bağlantısı kapatıldı (müşteriye yorum bağlantısı gösterilmeyecek).'
                    : 'Google yorum bağlantısı kaydedildi.';
            }
        }

        // -------------------------------------------------- B) WhatsApp Cloud API
        if ($islem === 'wa') {
            setting_set('wa_verify', ((string)($_POST['wa_verify'] ?? '0')) === '1' ? '1' : '0');
            // gizli alanlar: boş gönderilirse mevcut değer KORUNUR
            // ⚠ 'wa_app_secret' ve 'wa_longlived' de bu listeye girmeliydi.
            //   Eksikti: app_secret hiç kaydedilemiyordu → webhook imzası
            //   doğrulanamıyor (tüm gelen mesajlar reddedilirdi); kalıcı token
            //   da girilemiyordu → kısa ömürlü token bitince mesajlar kalıcı
            //   olarak gönderilemez hale gelirdi.
            foreach (['wa_token', 'wa_phone_id', 'wa_business_id',
                      'wa_app_secret', 'wa_longlived'] as $gizli) {
                $yeni = trim((string)($_POST[$gizli] ?? ''));
                if ($yeni !== '') setting_set($gizli, $yeni);
            }
            $mesaj[] = 'WhatsApp ayarları kaydedildi.';
        }

        // -------------------------------------------------- C) birim fiyat listesi
        if ($islem === 'fiyat') {
            $mevcut = pricelist();
            $yeni = [];
            foreach (service_types() as $key => $t) {
                $yeni[$key] = (int)($mevcut[$key] ?? 0);
            }
            foreach ($_POST['fiyat'] ?? [] as $k => $v) {
                if (!isset($yeni[(string)$k])) continue;   // bilinmeyen anahtar yok sayılır
                $yeni[(string)$k] = parse_tl((string)$v);
            }
            setting_set('pricelist', (string)json_encode($yeni, JSON_UNESCAPED_UNICODE));
            $mesaj[] = 'Birim fiyat listesi kaydedildi.';
        }

        // -------------------------------------------------- C2) WhatsApp şablonları
        if ($islem === 'sablon') {
            $metin = $_POST['sablon'] ?? [];
            if (!is_array($metin)) $metin = [];
            $onay = $_POST['onayli'] ?? [];
            if (!is_array($onay)) $onay = [];
            wa_templates_kaydet($metin, $onay);
            $mesaj[] = 'Mesaj şablonları kaydedildi (' . count($onay) . ' tanesi onaylı).';
            audit('ayar_sablon', 'settings', null,
                count($onay) . ' onaylı: ' . implode(',', array_map('strval', $onay)));
        }

        // -------------------------------------------------- D) yönetici hesabı
        if ($islem === 'hesap') {
            $user = trim((string)($_POST['username'] ?? ''));
            $ad = trim((string)($_POST['full_name'] ?? ''));
            $tel = trim((string)($_POST['tel'] ?? ''));
            $mevcutSifre = (string)($_POST['mevcut_sifre'] ?? '');
            $yeniSifre = (string)($_POST['yeni_sifre'] ?? '');
            $yeniSifre2 = (string)($_POST['yeni_sifre2'] ?? '');

            $st = $db->prepare('SELECT pass_hash FROM users WHERE id=?');
            $st->execute([uid()]);
            $hash = (string)$st->fetchColumn();

            if ($hash === '' || !password_verify($mevcutSifre, $hash)) {
                $hata[] = 'Mevcut şifreniz hatalı.';
            }
            if ($user === '' || !preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $user)) {
                $hata[] = 'Kullanıcı adı 3–32 karakter olmalı (harf, rakam, . _ -).';
            }
            if ($yeniSifre !== '') {
                if (strlen($yeniSifre) < 6) $hata[] = 'Yeni şifre en az 6 karakter olmalı.';
                elseif ($yeniSifre !== $yeniSifre2) $hata[] = 'Yeni şifreler eşleşmiyor.';
            }

            $cakisma = $db->prepare('SELECT id FROM users WHERE username=? AND id<>?');
            $cakisma->execute([$user, uid()]);
            if ($cakisma->fetchColumn()) $hata[] = 'Bu kullanıcı adı zaten kullanılıyor.';

            if (!$hata) {
                if ($yeniSifre === '') {
                    $u = $db->prepare('UPDATE users SET username=?, full_name=?, phone=? WHERE id=?');
                    $u->execute([$user, $ad, $tel, uid()]);
                    audit('ayar_hesap', 'users', uid(), 'kullanıcı bilgileri güncellendi (şifre değişmedi)');
                    $mesaj[] = 'Hesap bilgileriniz güncellendi.';
                } else {
                    $u = $db->prepare('UPDATE users SET username=?, full_name=?, phone=?, pass_hash=? WHERE id=?');
                    $u->execute([$user, $ad, $tel, password_hash($yeniSifre, PASSWORD_DEFAULT), uid()]);
                    audit('ayar_hesap', 'users', uid(), 'şifre değiştirildi');
                    $mesaj[] = 'Hesap bilgileriniz ve şifreniz güncellendi.';
                }
            }
        }

        if (!$hata) {
            $_SESSION['flash'] = '✅ ' . implode(' · ', $mesaj);
            redirect('ayarlar');
        }
    }

    $ready = whatsapp_ready();
    $tokenVar = setting('wa_token', '');
    $pidVar = setting('wa_phone_id', '');
    $bidVar = setting('wa_business_id', '');
    $verifyVar = setting('wa_verify', '0') === '1';

    layout_head('Ayarlar');
    if (!empty($_SESSION['flash']) && is_string($_SESSION['flash'])) {
        echo '<div class="alert ok">' . e($_SESSION['flash']) . '</div>';
        unset($_SESSION['flash']);
    }
    if ($hata) echo '<div class="alert danger">' . e(implode('<br>· ', $hata)) . '</div>';

    // ============================================================ B) WhatsApp durumu
    echo '<div class="card"><h2>WhatsApp Cloud API</h2>';
    if ($ready) {
        echo '<p><span class="badge" style="background:#15803d">Yapılandırılmış (gönderim bekliyor)</span></p>'
            . '<p class="small muted">Kuyruktaki mesajlar için gönderim düğmesi veya zamanlanmış görev (cron) kullanılır. '
            . 'Mesajın gerçekten iletildiği WhatsApp webhook geri bildirimiyle doğrulanır.</p>';
    } else {
        echo '<p><span class="badge" style="background:#b91c1c">Yapılandırılmamış</span></p>'
            . '<div class="alert"><b>WhatsApp yapılandırılmamış — mesajlar GÖNDERİLMEZ.</b><br>'
            . 'Sipariş olayları kuyruğa alınır ve "Bekliyor" olarak görünür; hiçbir mesaj müşteriye ulaşmaz. '
            . 'Aşağıdaki alanları doldurup kaydedin.</div>'
            . '<details class="kurulum"><summary>📘 WhatsApp Cloud API nasıl kurulur? (adım adım)</summary>'
            . '<ol class="small">'
            . '<li><b>Meta Business hesabı açın:</b> '
            . '<a href="https://business.facebook.com" target="_blank" rel="noopener">business.facebook.com</a> '
            . '→ şirket bilgileriyle ücretsiz hesap.</li>'
            . '<li><b>WhatsApp Manager\'a girin:</b> solda <b>Mesajlaşma → WhatsApp</b>. '
            . 'Numara ekleyin (sistemin göndermesini istediğiniz işletme numarası).</li>'
            . '<li><b>Numarayı doğrulayın:</b> WhatsApp\'a SMS veya sesli çağrıyla gelen kodu girin. '
            . '(WhatsApp yüklü bir telefon gerekir; mağazadan alınabilen bir SIM kart da olur.)</li>'
            . '<li><b>Token alın:</b> Ayarlar → Geliştirici → <b>Kalıcı (permanent) anahtar</b> oluşturun. '
            . 'Listeden "WhatsApp" işaretleyin; <b>EAA…</b> ile başlayan bir anahtar üretilir.</li>'
            . '<li><b>Phone Number ID</b> ve <b>Business (WABA) ID</b> değerlerini '
            . '<b>WhatsApp Manager → API Kurulumu</b> ekranından kopyalayın.</li>'
            . '<li>Üç değeri de aşağıdaki alanlara yapıştırıp <b>Kaydet</b>\'e basın, '
            . 'sonra <b>WhatsApp Bildirimleri</b> ekranından kuyruğu işleyin.</li>'
            . '</ol>'
            . '<p class="small muted">Maliyet: Meta, test hesaplarına <b>ayda 1.000 ücretsiz '
            . 'konuşma</b> (gelen-giden mesaj) verir. Bu işletme için ücretsiz katman yeterlidir. '
            . 'Sonrasında mesaj başına ücret devreye girer; ödeme yöntemi isteyeniz.</p>'
            . '<p class="small muted">Not: Sistemdeki mesajlar <b>sizin numaranızdan</b> gider '
            . '(müşteri sizi yazar). Müşteri numarası kayıtlıysa numarası değiştiğinde '
            . 'gönderim başarısız olur ve kuyrukta hata olarak görünür.</p>'
            . '</details>';
    }
    $eksik = [];
    if ($tokenVar === '') $eksik[] = 'Access Token';
    if ($pidVar === '') $eksik[] = 'Phone Number ID';
    if (!$verifyVar) $eksik[] = 'doğrulama onayı (gönderim izni)';
    if ($eksik) {
        echo '<p class="small muted">Eksik: ' . e(implode(', ', $eksik)) . '</p>';
    }
    echo '<p class="small muted" style="margin-bottom:0">Not: Bu ekran gerçek bir API çağrısı yapmaz; '
        . '"Durumu kontrol et" yalnızca alanların doluluğunu doğrular. Gerçek gönderim denemesi '
        . 'bildirim ekranındaki kuyruk işleme adımında yapılır.</p>';
    echo '</div>';

    // ============================================================ A) işletme
    echo '<form method="post" action="?p=ayarlar" class="card">'
        . csrf_field() . '<input type="hidden" name="islem" value="isletme">'
        . '<h2>İşletme Bilgileri</h2>'
        . '<label>İşletme adı<input name="isletme_adi" value="' . e(setting('isletme_adi', 'Mim Halı Yıkama')) . '" required></label>'
        . '<label>Telefon<input name="telefon" value="' . e(setting('telefon', '')) . '" inputmode="tel"></label>'
        . '<label>Adres<textarea name="adres" rows="2">' . e(setting('adres', '')) . '</textarea></label>'
        . '<button class="btn btn-primary btn-block">Kaydet</button>'
        . '</form>';

    // ============================================================ A2) Google yorumu
    $gUrl = google_yorum_linki();
    echo '<form method="post" action="?p=ayarlar" class="card">'
        . csrf_field() . '<input type="hidden" name="islem" value="google_yorum">'
        . '<h2>Google Yorum Bağlantısı</h2>'
        . '<p class="small muted">Sipariş teslim edildiğinde müşteriye bir buton çıkar; buton müşteriyi '
        . 'doğrudan Google yorum sayfanızın <b>yorum yazma</b> bölümüne götürür. Yorumu müşteri '
        . '<b>kendi telefonundan</b> yazar — sistem hiçbir yorum uydurmaz, hiçbir yorumu kendi '
        . 'namına kaydetmez.</p>'
        . '<label>Yorum bağlantısı (g.page veya maps.app.goo.gl)'
        . '<input name="google_yorum_url" inputmode="url" placeholder="https://g.page/xxxx/review" value="'
        . e(setting('google_yorum_url', '')) . '"></label>'
        . '<p class="small muted" style="margin-top:-4px">Bu bağlantıyı Google Haritalar\'daki işletme '
        . 'sayfanızdan alın: <b>Paylaş → Yorum gönder</b> diye bir bağlantı çıkar. Boş bırakırsanız '
        . 'özellik kapanır.</p>'
        . '<label>Müşteriye gösterilecek kısa metin'
        . '<textarea name="google_yorum_mesaj" rows="2" placeholder="'
        . e(google_yorum_mesaji()) . '">' . e(setting('google_yorum_mesaj', '')) . '</textarea></label>'
        . ($gUrl !== ''
            ? '<p class="small ok">Şu an etkin: <a href="' . e($gUrl) . '" target="_blank" rel="noopener">bağlantıyı dene</a></p>'
            : '<p class="small muted">Şu an kapalı — müşteriye yorum butonu gösterilmez.</p>')
        . '<button class="btn btn-primary btn-block">Kaydet</button>'
        . '</form>';
    // ============================================================ B formu: WhatsApp
    echo '<form method="post" action="?p=ayarlar" class="card">'
        . csrf_field() . '<input type="hidden" name="islem" value="wa">'
        . '<h2>WhatsApp Cloud API Anahtarları</h2>'
        . '<label>Access Token (permanent)'
        . '<input type="password" name="wa_token" value="" autocomplete="off" spellcheck="false" placeholder="'
        . ($tokenVar !== '' ? 'Kayıtlı — değiştirmek için yeni anahtar girin' : 'EAA… ile başlayan token') . '"></label>'
        . '<label>Phone Number ID'
        . '<input type="password" name="wa_phone_id" value="" autocomplete="off" placeholder="'
        . ($pidVar !== '' ? 'Kayıtlı — değiştirmek için yeni ID girin' : 'ör. 123456789012345') . '"></label>'
        . '<label>Business (WABA) ID'
        . '<input type="password" name="wa_business_id" value="" autocomplete="off" placeholder="'
        . ($bidVar !== '' ? 'Kayıtlı — değiştirmek için yeni ID girin' : 'opsiyonel') . '"></label>'
        . '<label class="small" style="font-weight:600">'
        . '<input type="hidden" name="wa_verify" value="0">'
        . '<input type="checkbox" name="wa_verify" value="1"' . ($verifyVar ? ' checked' : '') . '> '
        . 'Gönderim izni verildi (token &amp; phone ID doğrulanmış kabul edilsin)</label>'
        . '<h3>Güvenlik ve Süreklilik (ZORUNLU)</h3>'
        . '<label>App Secret — webhook imzasını doğrular'
        . '<input type="password" name="wa_app_secret" value="" autocomplete="off" spellcheck="false" placeholder="'
        . (setting('wa_app_secret', '') !== '' ? 'Kayıtlı — değiştirmek için yeni secret girin'
             : 'Meta → Ayarlar → Temel → App Secret') . '"></label>'
        . '<p class="small muted"><b>Bu alan boşsa gelen webhook istekleri REDDEDİLİR</b> '
        . '(imza doğrulanamaz). Kurulumda bu zorunlu adımdır.</p>'
        . '<label>Kalıcı (long-lived) Token — süre sonunda otomatik yenilenir'
        . '<input type="password" name="wa_longlived" value="" autocomplete="off" spellcheck="false" placeholder="'
        . (setting('wa_longlived', '') !== '' ? 'Kayıtlı — yenilenecek'
             : 'Meta → WhatsApp Manager → API Kurulumu → Kalıcı token (EAA…)') . '"></label>'
        . '<p class="small muted">Access token yaklaşık 24 saat sonra geçersiz olur. '
        . 'Kalıcı token girilmezse mesaj gönderimi <b>bir gün sonra kendiliğinden durur</b>. '
        . 'Sistem her gönderimde kısa ömürlü tokenı otomatik olarak yeniler.</p>'
        . '<p class="small muted">Boş bırakılan alanlar mevcut değeri <b>korur</b>; anahtarı değiştirmek için yeniden yazmanız gerekir.</p>'
        . '<div class="btnrow">'
        . '<button class="btn btn-primary" style="flex:1">Kaydet</button>'
        . '<button type="button" class="btn btn-ghost" style="flex:1" onclick="rprWaDurum()">Durumu kontrol et</button>'
        . '</div>'
        . '<div id="waDurum" class="small" style="margin-top:8px"></div>'
        . '</form>';

    // ============================================================ C) fiyat listesi
    $pl = pricelist();
    $types = service_types();
    echo '<form method="post" action="?p=ayarlar" class="card">'
        . csrf_field() . '<input type="hidden" name="islem" value="fiyat">'
        . '<h2>Birim Fiyat Listesi</h2>'
        . '<p class="small muted">m² / adet bazlı varsayılan birim fiyatlar (kuruş). Yeni sipariş formunda bu değerler önerilir.</p>';
    foreach ($types as $key => $t) {
        $val = (int)($pl[(string)$key] ?? 0);
        $birim = $t[1] ? 'm²' : 'adet';
        echo '<div class="kv"><div style="width:auto;flex:1">' . e((string)$t[0]) . '<br><small>' . e($birim) . '</small></div>'
            . '<div style="flex:0 0 140px"><input name="fiyat[' . e((string)$key) . ']" value="'
            . ($val > 0 ? number_format($val / 100, 2, ',', '.') : '') . '" inputmode="decimal" placeholder="0,00"></div></div>';
    }
    echo '<p class="small muted">Kuruş cinsinden kaydedilir. Boş bırakılan hizmet için fiyat girilmemiş sayılır.</p>'
        . '<button class="btn btn-primary btn-block">Fiyatları Kaydet</button></form>';

    // ============================================================ C2) WhatsApp şablonları
    $tpl = wa_templates();
    $onayli = wa_approved_templates();
    echo '<form method="post" action="?p=ayarlar" class="card">'
        . csrf_field() . '<input type="hidden" name="islem" value="sablon">'
        . '<h2>WhatsApp Mesaj Şablonları</h2>'
        . '<p class="small muted">Sipariş olayları bu metinleri kullanır. Yer tutucular: ';
    $ph = [];
    foreach (wa_placeholder_list() as $k => $ac) {
        $ph[] = '<code>{' . e((string)$k) . '}</code> (' . e((string)$ac) . ')';
    }
    echo implode(' · ', $ph) . '</p>'
        . '<div class="alert"><b>24 saatlik hizmet penceresi kuralı:</b> müşterinin son 24 saatte '
        . 'mesajı yoksa WhatsApp serbest metne izin vermez. Bu durumda yalnızca <b>onaylı</b> işaretlediğiniz '
        . 'şablonlar gönderilir; onaylı olmayanlar kuyrukta bekler ve <b>gönderilmez</b>. '
        . 'Onay durumu veritabanında saklanır ve mesaj gönderilirken kontrol edilir.</div>';

    foreach (wa_default_templates() as $key => $varsayilan) {
        $metin = (string)($tpl[(string)$key] ?? $varsayilan);
        $on = in_array((string)$key, $onayli, true);
        $etiket = status_labels()[(string)$key] ?? (string)$key;
        echo '<div class="card" style="background:var(--bg-soft,#f7f9fb)">'
            . '<div class="totalbar"><b>' . e((string)$etiket) . '</b>'
            . '<span class="badge" style="background:' . ($on ? '#15803d' : '#6b7a8c') . '">'
            . ($on ? 'WhatsApp onaylı' : 'onay değil') . '</span></div>'
            . '<label class="small">Mesaj metni'
            . '<textarea name="sablon[' . e((string)$key) . ']" rows="3">' . e($metin) . '</textarea></label>'
            . '<label class="small" style="font-weight:600">'
            . '<input type="checkbox" name="onayli[]" value="' . e((string)$key) . '"' . ($on ? ' checked' : '')
            . '> WhatsApp tarafından onaylandı</label>'
            . '<p class="small muted" style="margin:4px 0 0">Şablon kodu: <code>' . e((string)$key) . '</code></p>'
            . '</div>';
    }
    echo '<p class="small muted">Boş bırakılan şablon kaydedilmez (o olay için mesaj üretilmez). '
        . 'Şablonları <code>?p=ayarlar</code> ekranından istediğiniz zaman değiştirebilirsiniz.</p>'
        . '<button class="btn btn-primary btn-block">Şablonları Kaydet</button></form>';

    // ============================================================ D) hesap
    $u = me();
    echo '<form method="post" action="?p=ayarlar" class="card">'
        . csrf_field() . '<input type="hidden" name="islem" value="hesap">'
        . '<h2>Yönetici Hesabı</h2>'
        . '<p class="small muted">Değişiklik yapabilmek için mevcut şifrenizi doğrulamanız gerekir.</p>'
        . '<label>Kullanıcı adı<input name="username" value="' . e((string)($u['username'] ?? '')) . '" required autocomplete="username"></label>'
        . '<label>Ad Soyad<input name="full_name" value="' . e((string)($u['full_name'] ?? '')) . '"></label>'
        . '<label>Telefon<input name="tel" value="' . e((string)($u['phone'] ?? '')) . '" inputmode="tel"></label>'
        . '<label>Mevcut şifre<input type="password" name="mevcut_sifre" required autocomplete="current-password"></label>'
        . '<label>Yeni şifre (boş bırakılırsa değişmez)<input type="password" name="yeni_sifre" autocomplete="new-password"></label>'
        . '<label>Yeni şifre (tekrar)<input type="password" name="yeni_sifre2" autocomplete="new-password"></label>'
        . '<button class="btn btn-primary btn-block">Güncelle</button></form>';

    // ============================================================ E) durum akışı
    $flow = status_flow();
    $sl = status_labels();
    $sc = status_colors();
    echo '<div class="card"><h2>Durum Akışı</h2><p class="small muted">Sipariş bu aşamalardan geçerek ilerler. '
        . 'Gecikmeye düşen siparişlerde gecikme nedeni seçilir; neden kırılımı raporda görünür.</p>';
    $n = 0;
    foreach ($flow as $k => $v) {
        echo '<div class="kv"><div style="width:auto;flex:0 0 45%">' . status_badge((string)$k) . '</div>'
            . '<div>' . status_badge((string)$v) . '</div></div>';
        $n++;
    }
    echo '<div class="kv"><div style="width:auto;flex:0 0 45%">' . status_badge('dagitimda') . '</div><div>' . status_badge('teslim') . '</div></div>';
    echo '<div class="kv"><div style="width:auto;flex:0 0 45%">— her aşamada</div><div>' . status_badge('iptal') . '</div></div>';
    echo '</div>';

    // ============================================================ F) yedek
    echo '<div class="card"><h2>Veri Yedekleme</h2>'
        . '<p class="small muted">Tüm veritabanı (siparişler, müşteriler, ödemeler, ayarlar, kuyruk) tek SQLite dosyası olarak indirilir. '
        . 'Dosya tüm kullanıcıların şifre özetlerini içerir — güvenli bir yerde saklayın.</p>'
        . '<a class="btn btn-primary btn-block" href="?p=yedek">⬇ Veritabanı Yedeği İndir</a>'
        . '<a class="btn btn-block" href="?p=yedek-durum">💾 Otomatik Yedekleme Durumu</a>'
        . '<p class="small muted" style="margin-bottom:0">Sürüm: ' . e(APP_VERSION) . ' · Bu indirme işlemi denetim kaydına yazılır.</p></div>';

    echo '<div class="card"><h2>Uygulama Bilgisi</h2>'
        . '<div class="kv"><div>Sürüm</div><div>' . e(APP_VERSION) . '</div></div>'
        . '<div class="kv"><div>Rapor dönemi</div><div><a href="?p=raporlar">Raporlar →</a></div></div>'
        . '<div class="kv"><div>WhatsApp kuyruğu</div><div><a href="?p=wa">Bildirimler →</a></div></div>'
        . '</div>';

    echo '<script>
function rprWaDurum(){
  var t=document.querySelector("input[name=wa_token]").value.trim();
  var p=document.querySelector("input[name=wa_phone_id]").value.trim();
  var v=document.querySelector("input[name=wa_verify]").checked;
  var kutu=document.getElementById("waDurum");
  var eksik=[];
  if(!t) eksik.push("Access Token");
  if(!p) eksik.push("Phone Number ID");
  if(!v) eksik.push("gönderim izni onayı");
  if(eksik.length){
    kutu.innerHTML="<b style=\'color:#b91c1c\'>Yapılandırılmamış</b> — eksik: "+eksik.join(", ")+
      ". Alanları doldurup <i>Durumu kontrol et</i> için tekrar Kaydet’e basın. Bu ekran API çağrısı yapmaz, mesaj gönderilmez.";
  } else {
    kutu.innerHTML="<b style=\'color:#15803d\'>Yapılandırılmış (gönderim bekliyor)</b> — alanlar dolu. "+
      "Kaydet’e bastıktan sonra kuyruk kısmından mesajları işleyebilirsiniz.";
  }
}
</script>';
    layout_foot();
}

// ============================================================ 3) YEDEK
function yedek(): void
{
    // ÖNCE otomatik yedek durumu gösterilir: elle indirmeden önce
    // "sistem kendiliğinden yedekleniyor mu" sorusu yanıtlanır.
    // 17.10 denetiminde tespit edildi: hiç otomatik yedek YOKTU.
    $sd = yedek_saglik();
    $liste = yedek_liste();

    // Yedek = veritabanının TAMAMİ: müşteri telefon/adresleri, tüm sipariş
    // geçmişi ve kullanıcı şifre tuzları dahil. Bu yüzden yetki şart —
    // önceden burada hiç kontrol yoktu, personel hepsini indirebiliyordu.
    need_perm('yedek.indir');
    try {
        db()->exec('PRAGMA wal_checkpoint(FULL)');
    } catch (Throwable $e) {
        app_log('wal_checkpoint başarısız: ' . $e->getMessage());
    }

    $f = dbfile();
    if (!is_file($f) || !is_readable($f)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Yedek alınamadı: veritabanı dosyası okunamadı.');
    }

    audit('yedek_indir', 'settings', null, 'veritabanı indirildi');

    // Yol bilgisi ASLA çıktıya yazılmaz.
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="mim-yedek-' . date('Y-m-d') . '.sqlite"');
    header('Content-Length: ' . (string)filesize($f));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    while (ob_get_level() > 0) { @ob_end_clean(); }
    readfile($f);
    exit;
}

// ============================================================ 4) WHATSAPP PANELİ
function wa_panel(): void
{
    need_perm('siparis.gor');
    $db = db();
    $ready = whatsapp_ready();
    $durum = (string)($_GET['durum'] ?? '');
    if (!in_array($durum, ['', 'bekliyor', 'gonderildi', 'teslim', 'okundu', 'hata'], true)) $durum = '';

    $sayimlar = [];
    foreach (['', 'bekliyor', 'gonderildi', 'teslim', 'okundu', 'hata'] as $s) {
        if ($s === '') {
            $sayimlar[''] = (int)$db->query('SELECT COUNT(*) c FROM wa_queue')->fetchColumn();
        } else {
            $q = $db->prepare('SELECT COUNT(*) c FROM wa_queue WHERE status=?');
            $q->execute([$s]);
            $sayimlar[$s] = (int)$q->fetchColumn();
        }
    }

    layout_head('WhatsApp Bildirimleri');
    rpr_flash();

    // ------------------------------------------------ dürüst durum uyarısı
    if (!$ready) {
        echo '<div class="alert"><b>WhatsApp yapılandırılmamış. Mesajlar GÖNDERİLMEZ.</b><br>'
            . 'Aşağıdaki kayıtlar yalnızca <b>kuyruğa alınmış taslak</b>tır; WhatsApp’a hiçbir şey iletilmemiştir, '
            . 'gönderim denemesi de yapılmamıştır. Yapılandırılmadan “gönderildi” durumu oluşamaz.</div>';
        if (is_admin()) {
            echo '<div class="btnrow pad"><a class="btn btn-primary btn-block" href="?p=ayarlar">⚙ WhatsApp ayarlarını aç</a></div>';
        } else {
            echo '<p class="muted small">Yapılandırma yalnızca yönetici tarafından yapılabilir.</p>';
        }
    } else {
        echo '<div class="alert ok"><b>Yapılandırılmış (gönderim bekliyor).</b> '
            . 'Kuyruğu işlediğinizde mesajlar gerçekten WhatsApp API üzerinden gönderilir; '
            . 'teslim durumu webhook geri bildirimiyle doğrulanır.</div>';
    }

    // filtre
    $etiketler = [
        ''          => 'Tümü (' . $sayimlar[''] . ')',
        'bekliyor'  => 'Bekliyor (' . $sayimlar['bekliyor'] . ')',
        'gonderildi' => 'Gönderildi (' . $sayimlar['gonderildi'] . ')',
        'teslim'    => 'Teslim (' . $sayimlar['teslim'] . ')',
        'okundu'    => 'Okundu (' . $sayimlar['okundu'] . ')',
        'hata'      => 'Hata (' . $sayimlar['hata'] . ')',
    ];
    chips('wa', $etiketler, $durum);

    // yönetici: kuyruk işle
    if (is_admin()) {
        if ($ready) {
            echo '<form method="post" action="?p=wa-gonder" class="btnrow">'
                . csrf_field()
                . '<button class="btn btn-primary btn-block">▶ Kuyruğu İşle ('
                . $sayimlar['bekliyor'] . ' bekleyen)</button></form>';
        } else {
            echo '<button class="btn btn-block" disabled aria-disabled="true">▶ Kuyruğu İşle — devre dışı (yapılandırılmamış)</button>';
            echo '<p class="small muted">Yapılandırılmadan kuyruk işlenemez; mesajlar gönderilmez.</p>';
        }
    }

    // ------------------------------------------------ kuyruk listesi
    echo '<h2 class="sec">Kuyruk</h2>';
    if ($durum === '') {
        $st = $db->query('SELECT q.*, o.code, c.name cname FROM wa_queue q
            LEFT JOIN orders o ON o.id=q.order_id
            LEFT JOIN customers c ON c.id=q.customer_id
            ORDER BY q.id DESC LIMIT 100');
    } else {
        $st = $db->prepare('SELECT q.*, o.code, c.name cname FROM wa_queue q
            LEFT JOIN orders o ON o.id=q.order_id
            LEFT JOIN customers c ON c.id=q.customer_id
            WHERE q.status=? ORDER BY q.id DESC LIMIT 100');
        $st->execute([$durum]);
    }
    $rows = $st->fetchAll();

    $renk = ['bekliyor' => '#8a6d1a', 'gonderildi' => '#0e7490', 'teslim' => '#15803d',
        'okundu' => '#16A6A1', 'hata' => '#b91c1c'];
    $ad = ['bekliyor' => 'Bekliyor', 'gonderildi' => 'Gönderildi', 'teslim' => 'Teslim',
        'okundu' => 'Okundu', 'hata' => 'Hata'];

    if (!$rows) {
        echo '<div class="card"><p class="muted" style="margin:0">Bu filtrede kayıt yok.</p></div>';
    } else {
        echo '<div class="card">';
        foreach ($rows as $m) {
            $s = (string)$m['status'];
            // Yapılandırılmamışsa hiçbir kayıt "gönderilmiş" olamaz; dürüstçe göster.
            $gercek = $ready ? $ad[$s] ?? $s : ($s === 'hata' ? 'Hata' : 'Kuyrukta (gönderilmedi)');
            $renk2 = $ready ? ($renk[$s] ?? '#555') : ($s === 'hata' ? '#b91c1c' : '#6b7a8c');
            $govde = trim(preg_replace('/\s+/', ' ', (string)$m['body']));
            $govde = rpr_kisalt($govde, 160);
            echo '<div style="padding:10px 0;border-bottom:1px dashed var(--line)">';
            echo '<div class="totalbar"><div style="font-size:.9rem;font-weight:700">'
                . ($m['code'] ? '<a href="?p=siparis&amp;id=' . (int)$m['order_id'] . '">' . e((string)$m['code']) . '</a>' : '<span class="muted">—</span>')
                . '</div><span class="badge" style="background:' . $renk2 . '">' . e($gercek) . '</span></div>';
            echo '<div class="small muted">' . e(tr_date((string)$m['created_at'], true))
                . ' · ' . e((string)($m['cname'] ?? ''))
                . ' · şablon: ' . e((string)$m['template'])
                . ' · ' . e((string)$m['phone']) . '</div>';
            echo '<div class="small" style="margin-top:4px">' . e($govde) . '</div>';
            $detay = 'Deneme: ' . (int)$m['attempts'];
            if (!empty($m['sent_at'])) $detay .= ' · gönderim: ' . e(tr_date((string)$m['sent_at'], true));
            if (!empty($m['username'])) $detay .= ' · ekleyen: ' . e((string)$m['username']);
            if (!$ready) $detay .= ' · <b>gönderilmedi</b>';
            echo '<div class="small muted">' . $detay . '</div>';
            if (!empty($m['error'])) {
                echo '<div class="small neg">Hata: ' . e((string)$m['error']) . '</div>';
            }
            echo '</div>';
        }
        echo '<p class="small muted" style="margin:8px 0 0">Son 100 kayıt listelenir.</p></div>';
    }

    // ------------------------------------------------ gelen mesajlar
    $st = $db->query('SELECT m.*, c.name cname FROM wa_inbound m
        LEFT JOIN customers c ON c.id=m.customer_id ORDER BY m.id DESC LIMIT 30');
    $inb = $st->fetchAll();
    echo '<h2 class="sec">Gelen Mesajlar</h2>';
    if (!$inb) {
        echo '<div class="card"><p class="muted" style="margin:0">Gelen mesaj yok'
            . ($ready ? '.' : ' (yapılandırılmadığı için webhook da bağlı olmayabilir).') . '</p></div>';
    } else {
        echo '<div class="card">';
        foreach ($inb as $m) {
            $g = trim(preg_replace('/\s+/', ' ', (string)$m['body']));
            $g = rpr_kisalt($g, 200);
            echo '<div style="padding:8px 0;border-bottom:1px dashed var(--line)">'
                . '<div class="totalbar"><b>' . e((string)($m['cname'] ?? 'Bilinmeyen'))
                . '</b><small>' . e(tr_date((string)$m['received_at'], true)) . '</small></div>'
                . '<div class="small muted">' . e((string)$m['from_phone']) . '</div>'
                . '<div class="small">' . e($g) . '</div></div>';
        }
        echo '</div>';
    }
    layout_foot();
}

// ============================================================ 5) KUYRUK İŞLE
function wa_gonder(): void
{
    if (!can('wa.gonder')) {
        http_response_code(403);
        exit('Bu işlem için yetkiniz yok.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Yalnızca POST ile çağrılabilir.');
    }
    csrf_check();

    // Yapılandırılmamışsa SAHTE GÖNDERİM YAPILMAZ.
    if (!whatsapp_ready()) {
        $_SESSION['flash_hata'] = [
            'WhatsApp yapılandırılmamış — kuyruk işlenmedi, hiçbir mesaj gönderilmedi.',
            'Yönetici → Ayarlar → WhatsApp Cloud API bölümünden anahtarları girin.',
        ];
        redirect('wa');
    }

    $sonuc = wa_process(50);
    $bekle = $sonuc['skipped'] ?? 0;

    if ($bekle === -1) {
        $_SESSION['flash_hata'] = ['Yapılandırma hâlâ eksik — mesaj gönderilmedi.'];
    } elseif ($bekle === 'baglanti') {
        // Ağ/erişim yok — bu bir hata değil, beklenen bekleme. Kalan mesajlar
        // 'bekliyor' kalır ve bir sonraki denemede gönderilir.
        $_SESSION['flash'] = 'WhatsApp sunucusuna ulaşılamadı, işlem yarıda durdu. '
            . 'Gönderilen: ' . (int)($sonuc['sent'] ?? 0) . ' · Kalan mesajlar sırada bekliyor, '
            . 'otomatik olarak yeniden denenecek.';
    } elseif ($bekle === 'sure') {
        $_SESSION['flash'] = 'İşlem süre sınırına ulaştı, kalan mesajlar sırada bekliyor. '
            . 'Gönderilen: ' . (int)($sonuc['sent'] ?? 0) . ' — devamı bir sonraki denemede yapılacak.';
    } else {
        $_SESSION['flash'] = 'Kuyruk işlendi: ' . (int)($sonuc['sent'] ?? 0) . ' gönderildi, '
            . (int)($sonuc['fail'] ?? 0) . ' hata. Gönderilen mesajların teslim durumu webhook ile doğrulanır.';
    }
    audit('wa_process', 'wa_queue', null, 'gönderilen:' . (int)($sonuc['sent'] ?? 0) . ' hata:' . (int)($sonuc['fail'] ?? 0));
    redirect('wa');
}

// ============================================================ YAZDIRMA ORTAK
/** Yazdırma dostu minimal iskelet (etiket / QR / fiş) */
function rpr_print_head(string $title): void
{
    $ad = setting('isletme_adi', 'Mim Halı Yıkama');
    $tel = setting('telefon', '');
    ?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="style.css">
<style>
body.print-body{background:#fff}
.print-wrap{max-width:760px;margin:0 auto;padding:12px}
.label-grid{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px}
.label-grid .label{flex:0 0 calc(50% - 5px);page-break-inside:avoid;break-inside:avoid}
.qr-box{display:flex;gap:12px;align-items:center;justify-content:center}
.qr-box img,.qr-box canvas{width:150px;height:150px}
@media print{.no-print{display:none!important}.label-grid .label{width:100%;max-width:none}}
@page{margin:12mm}
</style></head><body class="print-body">
<div class="print-wrap no-print" style="margin-bottom:12px">
  <div class="btnrow">
    <a class="btn" href="?p=siparis&amp;id=<?= (int)($_GET['id'] ?? 0) ?>">← Siparişe dön</a>
    <button type="button" class="btn btn-primary" onclick="window.print()">🖨 Yazdır</button>
  </div>
  <p class="small muted" style="margin:6px 0 0"><?= e($ad) ?><?= $tel !== '' ? ' · ' . e($tel) : '' ?></p>
</div>
<?php
}
function rpr_print_foot(bool $auto = true): void
{
    ?>
<script><?= $auto ? 'window.addEventListener("load",function(){setTimeout(function(){window.print();},350);});' : '' ?></script>
</div></body></html><?php
}
/** hizmet etiketi + m²/adet + hasar notu */
function rpr_item_line(array $it): string
{
    $types = service_types();
    $t = $types[(string)$it['type']][0] ?? (string)$it['type'];
    $par = [];
    if ((float)$it['m2'] > 0) $par[] = str_replace('.', ',', (string)(float)$it['m2']) . ' m²';
    if ((int)$it['qty'] > 1) $par[] = (int)$it['qty'] . ' adet';
    if ((int)$it['qty'] === 1) $par[] = '1 adet';
    if ((string)$it['descr'] !== '') $par[] = (string)$it['descr'];
    return e($t) . ($par ? ' — ' . e(implode(' · ', $par)) : '');
}

// ============================================================ 6) ETİKET
function etiket(): void
{
    need_perm('siparis.gor');
    $o = order_or_404();
    $oid = (int)$o['id'];
    rpr_kodlari_hazirla($oid);
    $items = order_items($oid);

    $ad = setting('isletme_adi', 'Mim Halı Yıkama');
    $tel = setting('telefon', '');
    $teslim = trim((string)$o['delivery_date'] . ' ' . (string)$o['delivery_time']);

    rpr_print_head('Etiket — ' . (string)$o['code']);
    echo '<div class="card" style="text-align:center">'
        . '<b style="font-size:1.05rem">' . e($ad) . '</b>'
        . ($tel !== '' ? '<br><span>' . e($tel) . '</span>' : '')
        . '</div>';

    if (!$items) {
        echo '<div class="card"><p>Bu siparişte etiketlenecek parça yok.</p></div>';
    } else {
        echo '<div class="label-grid">';
        foreach ($items as $it) {
            echo '<div class="label">'
                . '<div class="totalbar"><span style="font-size:.72rem;font-weight:700;letter-spacing:.5px">'
                . e(rpr_buyuk((string)$ad)) . '</span>'
                . '<span style="font-size:.72rem">' . e($tel) . '</span></div>'
                . '<div class="label-code">' . e((string)$it['item_code']) . '</div>'
                . '<div class="label-name">' . e((string)$o['code']) . ' · ' . e((string)$o['cname']) . '</div>'
                . '<div class="label-line">☎ ' . e((string)$o['phone']) . '</div>'
                . '<div class="label-line">' . rpr_item_line($it) . '</div>';
            if ((string)$it['damage'] !== '') {
                echo '<div class="label-note">⚠ Hasar: ' . e((string)$it['damage']) . '</div>';
            }
            if ((string)$it['note'] !== '') {
                echo '<div class="small muted">' . e((string)$it['note']) . '</div>';
            }
            echo '<div class="label-line" style="margin-top:6px;font-weight:700">Teslim: ' . e(tr_date($teslim)) . '</div>'
                . '</div>';
        }
        echo '</div>';
    }
    echo '<p class="small muted" style="margin-top:10px">Toplam ' . count($items) . ' etiket. '
        . 'Etiketleri kesip her halının üzerine/yanına yapıştırın.</p>';
    rpr_print_foot();
}

// ============================================================ 7) QR ETİKET
function qr_kod(): void
{
    need_perm('siparis.gor');
    $o = order_or_404();
    $oid = (int)$o['id'];
    rpr_kodlari_hazirla($oid);
    $items = order_items($oid);

    // QR içeriği: uygulamanın kendi sipariş kartı adresi (kamera ile açılabilir)
    $hedef = '?p=siparis&id=' . $oid;
    $qrMetin = $hedef;
    $ad = setting('isletme_adi', 'Mim Halı Yıkama');
    $tel = setting('telefon', '');
    $teslim = trim((string)$o['delivery_date'] . ' ' . (string)$o['delivery_time']);
    $kodlar = [];
    foreach ($items as $it) $kodlar[] = (string)$it['item_code'];

    ?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>QR — <?= e((string)$o['code']) ?></title>
<link rel="stylesheet" href="style.css">
<style>
body.print-body{background:#fff}
.print-wrap{max-width:760px;margin:0 auto;padding:12px}
.label-grid{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px}
.label-grid .label{flex:0 0 calc(50% - 5px);page-break-inside:avoid;break-inside:avoid}
.qr-box{display:flex;gap:12px;align-items:center}
.qr-box img{width:140px;height:140px;flex:0 0 140px}
.qr-meta{flex:1;min-width:0;font-size:.8rem}
@media print{.no-print{display:none!important}.label-grid .label{width:100%;max-width:none}}
@page{margin:12mm}
</style></head><body class="print-body">
<div class="print-wrap">
<div class="btnrow no-print" style="margin-bottom:12px">
  <a class="btn" href="?p=siparis&amp;id=<?= $oid ?>">← Siparişe dön</a>
  <button type="button" class="btn btn-primary" onclick="window.print()">🖨 Yazdır</button>
</div>
<?php
    if (!$items) {
        echo '<div class="card"><p>Bu siparişte parça yok.</p></div>';
    } else {
        echo '<div class="label-grid">';
        foreach ($items as $it) {
            echo '<div class="label">'
                . '<div class="totalbar" style="font-size:.72rem;font-weight:700"><span>' . e($ad) . '</span><span>' . e($tel) . '</span></div>'
                . '<div class="qr-box" style="margin-top:8px">'
                . '<img data-qr="' . e($qrMetin) . '" alt="QR">'
                . '<div class="qr-meta">'
                . '<div class="label-code" style="font-size:1.2rem">' . e((string)$it['item_code']) . '</div>'
                . '<div class="label-name" style="font-size:.95rem">' . e((string)$o['code']) . '</div>'
                . '<div>' . e((string)$o['cname']) . '</div>'
                . '<div>☎ ' . e((string)$o['phone']) . '</div>'
                . '<div style="margin-top:4px">' . rpr_item_line($it) . '</div>'
                . '</div></div>'
                . '<div class="label-line" style="margin-top:6px;font-weight:700">Teslim: ' . e(tr_date($teslim)) . '</div>'
                . '</div>';
        }
        echo '</div>';
        echo '<p class="small muted" style="margin-top:10px">QR kodu sipariş kartını açar: <code>'
            . e($hedef) . '</code> — ' . count($items) . ' parça için ayrı etiket.</p>';
    }
    // Sunucuda GD yok → QR istem tarafında qrgen.js ile üretilir.
    echo '<script src="qrgen.js"></script>';
    rpr_print_foot();
}

// ============================================================ 8) FİŞ / MAKBUZ
function fis(): void
{
    need_perm('siparis.gor');
    $o = order_or_404();
    $oid = (int)$o['id'];
    $items = order_items($oid);

    $db = db();
    $st = $db->prepare('SELECT * FROM payments WHERE order_id=? ORDER BY id');
    $st->execute([$oid]);
    $pays = $st->fetchAll();

    $ad = setting('isletme_adi', 'Mim Halı Yıkama');
    $tel = setting('telefon', '');
    $adres = setting('adres', '');
    $types = service_types();

    $toplam = (int)$o['total_kurus'];
    $indirim = (int)$o['discount_kurus'];
    $net = $toplam - $indirim;
    $odenmis = 0;
    foreach ($pays as $p) $odenmis += (int)$p['amount_kurus'];
    $kalan = $net - $odenmis;

    ?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fiş — <?= e((string)$o['code']) ?></title>
<link rel="stylesheet" href="style.css">
<style>body.print-body{background:#fff}@page{margin:10mm}
.print-wrap{max-width:420px;margin:0 auto;padding:12px}</style>
</head><body class="print-body"><div class="print-wrap">
<div class="btnrow no-print" style="margin-bottom:12px">
  <a class="btn" href="?p=siparis&amp;id=<?= $oid ?>">← Siparişe dön</a>
  <button type="button" class="btn btn-primary" onclick="window.print()">🖨 Yazdır</button>
</div>
<div class="fis">
  <div style="text-align:center;font-weight:700;font-size:1.05rem"><?= e($ad) ?></div>
  <?php if ($tel !== ''): ?><div style="text-align:center"><?= e($tel) ?></div><?php endif; ?>
  <?php if ($adres !== ''): ?><div style="text-align:center" class="small"><?= nl2br(e($adres)) ?></div><?php endif; ?>
  <hr>
  <div class="fis-line"><span>Tarih</span><span><?= e(tr_date((string)$o['created_at'], true)) ?></span></div>
  <div class="fis-line"><span>Sipariş No</span><span><b><?= e((string)$o['code']) ?></b></span></div>
  <div class="fis-line"><span>Müşteri</span><span><?= e((string)$o['cname']) ?></span></div>
  <div class="fis-line"><span>Telefon</span><span><?= e((string)$o['phone']) ?></span></div>
  <hr>
  <?php foreach ($items as $it): $lbl = $types[(string)$it['type']][0] ?? (string)$it['type']; ?>
  <div style="font-weight:700"><?= e((string)$it['item_code']) ?> · <?= e($lbl) ?></div>
  <?php
  $adet = (int)$it['qty'];
  $m2 = (float)$it['m2'];
  $birim = $m2 > 0 ? str_replace('.', ',', (string)$m2) . ' m²' : $adet . ' adet';
  $birimTutar = (int)$it['unit_kurus'];
  $birimYaz = $m2 > 0
      ? rpr_no_perc($birimTutar / 100.0, $m2) . ' ₺/m² × ' . $birim
      : $adet . ' adet × ' . e(money_tl($birimTutar));
  ?>
  <div class="fis-line"><span><?= e($birimYaz) ?></span><span><?= e(money_tl((int)$it['line_kurus'], false)) ?></span></div>
  <?php if ((string)$it['damage'] !== ''): ?>
  <div class="small">⚠ <?= e((string)$it['damage']) ?></div>
  <?php endif; ?>
  <?php endforeach; ?>
  <hr>
  <div class="fis-line"><span>Ara Toplam</span><span><?= e(money_tl($toplam, false)) ?></span></div>
  <?php if ($indirim > 0): ?>
  <div class="fis-line"><span>İndirim</span><span>-<?= e(money_tl($indirim, false)) ?></span></div>
  <?php endif; ?>
  <div class="fis-line" style="font-weight:700;font-size:1.05rem"><span>TOPLAM</span><span><?= e(money_tl($net, false)) ?> ₺</span></div>
  <hr>
  <div style="font-weight:700">Ödemeler</div>
  <?php if (!$pays): ?>
  <div class="fis-line"><span>Ödeme alınmadı</span><span>0,00</span></div>
  <?php else: foreach ($pays as $p): ?>
  <div class="fis-line"><span><?= e(payment_method_label((string)$p['method'])) ?> · <?= e(tr_date((string)$p['created_at'])) ?></span><span><?= e(money_tl((int)$p['amount_kurus'], false)) ?></span></div>
  <?php endforeach; endif; ?>
  <div class="fis-line"><span style="font-weight:700">Ödenen</span><span style="font-weight:700"><?= e(money_tl($odenmis, false)) ?></span></div>
  <div class="fis-line" style="font-weight:700"><span>KALAN BAKİYE</span><span><?= e(money_tl($kalan, false)) ?> ₺</span></div>
  <hr>
  <div class="fis-line"><span>Alım</span><span><?= e(tr_date((string)$o['pickup_date'])) ?></span></div>
  <div class="fis-line"><span>Teslim</span><span><?= e(tr_date((string)$o['delivery_date'])) . ' ' . e((string)$o['delivery_time']) ?></span></div>
  <div class="fis-line"><span>Tür</span><span><?= (string)$o['pickup_type'] === 'servis' ? 'Servis (kapıdan)' : 'Elden' ?></span></div>
  <?php if ((string)$o['staff'] !== ''): ?>
  <div class="fis-line"><span>Personel</span><span><?= e((string)$o['staff']) ?></span></div>
  <?php endif; ?>
  <?php if ((string)$o['note'] !== ''): ?>
  <hr><div class="small"><?= nl2br(e((string)$o['note'])) ?></div>
  <?php endif; ?>
  <hr>
  <div style="margin-top:26px">Teslim eden: ................................</div>
  <div style="margin-top:20px">Teslim alan: ................................</div>
  <p class="small" style="text-align:center;margin-top:14px">
    Teşekkür ederiz — <?= e($ad) ?></p>
</div>
<?php
    rpr_print_foot();
}