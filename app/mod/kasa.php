<?php
/**
 * Kasa modülü: kasa ekranı (açık hesap / tahsilat / gider), gider yönetimi,
 * siparişe ödeme kaydı ekleme ve silme.
 * PHP 7.4+ · SQLite · bağımlılık yok
 */

declare(strict_types=1);

const KASA_GIDER_CAT = [
    'yakit'     => 'Yakıt / Benzin',
    'deterjan'  => 'Deterjan / Temizlik Malzemesi',
    'makine'    => 'Makine / Ekipman Alımı',
    'makine_bakim' => 'Makine Bakımı / Yedek Parça',
    'maas'      => 'Maaş / Ücret',
    'sgk'       => 'SGK / Sigorta',
    'kira'      => 'Kira',
    'elektrik'  => 'Elektrik',
    'su'        => 'Su / Doğalgaz',
    'ulasim'    => 'Ulaşım / Kurye',
    'kiralama'  => 'Araç Kiralama',
    'bakim'     => 'Araç Bakım / Onarım',
    'vergi'     => 'Vergi / Harç / SGK Primi',
    'kredi'     => 'Kredi Taksiti / Faiz',
    'pazarlama' => 'Reklam / Tanıtım',
    'malzeme'   => 'Diğer Malzeme',
    'diger'     => 'Diğer',
];

/**
 * Gider akışı durumları.
 *   onay_bekliyor → kayıt yapıldı ama KASA ve RAPORA GİRMEZ (senin seçimin)
 *   onaylandi     → yönetici onayladı, kâr rakamlarına girer
 *   reddedildi    → yönetici reddetti, rapora girmez
 *   iptal         → onaylı kayıt sonradan geri alındı, rapordan düşer
 * GIDER_RAPORA_GIRER sabiti lib.php'de tanımlıdır: kasa/rapor/panel
 * modülleri farklı sırada yüklendiği için burada tanımlanırsa erken
 * yüklenen modüllerde "undefined constant" hatası verir.
 */
const GIDER_DURUM = [
    'onay_bekliyor' => 'Onay bekliyor',
    'onaylandi'     => 'Onaylandı',
    'reddedildi'    => 'Reddedildi',
    'iptal'         => 'İptal edildi',
];

function gider_durum(string $s): string
{
    return GIDER_DURUM[$s] ?? $s;
}
function gider_durum_renk(string $s): string
{
    return ['onay_bekliyor' => '#b45309', 'onaylandi' => '#15803d',
            'reddedildi' => '#b91c1c', 'iptal' => '#6b7280'][$s] ?? '#6b7280';
}

const KASA_ODEME_METHOD = [
    'nakit'  => 'Nakit',
    'kart'   => 'Kart',
    'havale' => 'Havale / IBAN',
];

/** Gider kategorisi etiketi */
function kasa_gider_cat(string $c): string
{
    return KASA_GIDER_CAT[$c] ?? ucfirst(str_replace('_', ' ', $c));
}

/** Ödeme yöntemi etiketi */
function kasa_odeme_yontemi(string $m): string
{
    return KASA_ODEME_METHOD[$m] ?? ucfirst(str_replace('_', ' ', $m));
}

/** Ay formatını doğrula → 'YYYY-MM' */
function kasa_ay(string $ay): string
{
    $ay = trim($ay);
    if (!preg_match('/^\d{4}-\d{2}$/', $ay)) return '';
    $y = (int)substr($ay, 0, 4);
    $m = (int)substr($ay, 5, 2);
    if ($y < 2000 || $y > 2100 || $m < 1 || $m > 12) return '';
    return $ay;
}

/** Seçili ayı döndür (geçersizse içinde bulunulan ay) */
function kasa_aktif_ay(): string
{
    $ay = kasa_ay((string)($_GET['ay'] ?? ''));
    return $ay !== '' ? $ay : date('Y-m');
}

/** Ay etiketi: 2026-09 → Eylül 2026 */
function kasa_ay_etiket(string $ym): string
{
    $aylar = [
        1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran',
        7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık',
    ];
    $m = (int)substr($ym, 5, 2);
    $y = substr($ym, 0, 4);
    return (isset($aylar[$m]) ? $aylar[$m] : (string)$m) . ' ' . $y;
}

/** Ay başı / ay sonu tarih aralığı için [baslangic, bitis( hariç)] */
function kasa_ay_araligi(string $ym): array
{
    $y = (int)substr($ym, 0, 4);
    $m = (int)substr($ym, 5, 2);
    $bas = sprintf('%04d-%02d-01', $y, $m);
    $son = date('Y-m-t', strtotime($bas));
    return [$bas, date('Y-m-d', strtotime($son . ' +1 day'))];
}

/** Dönem ciro / tahsilat / gider / net kâr — hepsi kuruş */
function kasa_donem_ozeti(string $ym): array
{
    $db = db();
    [$bas, $bit] = kasa_ay_araligi($ym);

    $sum = function (string $sql, array $args = []) use ($db): int {
        $st = $db->prepare($sql);
        $st->execute($args);
        return (int)$st->fetchColumn();
    };

    $ciro = $sum("SELECT COALESCE(SUM(total_kurus-discount_kurus),0) FROM orders
                  WHERE date(created_at)>=? AND date(created_at)<? AND status!='iptal'", [$bas, $bit]);
    $tahsilat = $sum("SELECT COALESCE(SUM(amount_kurus),0) FROM payments
                      WHERE date(created_at)>=? AND date(created_at)<?", [$bas, $bit]);
    $gider = $sum("SELECT COALESCE(SUM(amount_kurus),0) FROM expenses
                   WHERE date(created_at)>=? AND date(created_at)<? AND " . GIDER_RAPORA_GIRER,
                   [$bas, $bit]);
    // Sipariş-dışı gelirler (mod/gelir.php) — ciro değildir ama kasaya girer.
    $digerGelir = $sum("SELECT COALESCE(SUM(amount_kurus),0) FROM incomes
                        WHERE date(created_at)>=? AND date(created_at)<?", [$bas, $bit]);

    // Açık hesap (tüm zamanlar) — panel.php dashboard_counts ile aynı SQL
    $acik = $sum(
        "SELECT COALESCE(SUM(borc),0) FROM (
            SELECT o.total_kurus-o.discount_kurus
              - COALESCE((SELECT SUM(p.amount_kurus) FROM payments p WHERE p.order_id=o.id),0) borc
            FROM orders o WHERE o.status NOT IN ('iptal','teslim','teslim_edilemedi'))");

    return [
        'ciro' => $ciro, 'tahsilat' => $tahsilat, 'gider' => $gider,
        'diger_gelir' => $digerGelir,
        'kar' => $ciro - $gider,
        'net' => $ciro + $digerGelir - $gider,
        'acik' => $acik,
    ];
}

/** BUGÜNÜN kasa hareketi: tahsilat + sipariş-dışı gelir − gider = net.
 *  Kasa sayfasının üst şeridi için — dönem değil TAKVİMİYERİ bugün. */
function kasa_bugun_ozeti(): array
{
    $db = db();
    $bugun = today();
    $sum = function (string $sql) use ($db, $bugun): int {
        $st = $db->prepare($sql);
        $st->execute([$bugun]);
        return (int)$st->fetchColumn();
    };
    $tahsilat = $sum("SELECT COALESCE(SUM(amount_kurus),0) FROM payments WHERE date(created_at)=?");
    $gelir = $sum("SELECT COALESCE(SUM(amount_kurus),0) FROM incomes WHERE date(created_at)=?");
    $gider = $sum("SELECT COALESCE(SUM(amount_kurus),0) FROM expenses
                   WHERE date(created_at)=? AND " . GIDER_RAPORA_GIRER);
    return ['tahsilat' => $tahsilat, 'gelir' => $gelir, 'gider' => $gider,
            'net' => $tahsilat + $gelir - $gider];
}

/** Açık hesap satırları: kapanmamış + bakiyesi > 0, gecikenler önce */
function kasa_acik_hesaplar(): array
{
    // ⚠ Sınırsızdı: açık bakiyeli her sipariş listeleniyordu. Büyük
    //   işletmede yüzlerce alacak → kasada MB'larca HTML. Burada TOPLAM
    //   bütün kayıtlardan hesaplanır, liste en fazla $limit kayıtla döner.
    $st = db()->prepare(
        "SELECT o.id, o.code, o.status, o.total_kurus, o.discount_kurus, o.delivery_date,
                o.pickup_date, o.created_at, c.name cname, c.phone,
                COALESCE((SELECT SUM(p.amount_kurus) FROM payments p WHERE p.order_id=o.id),0) odenmis
         FROM orders o JOIN customers c ON c.id=o.customer_id
         WHERE o.status NOT IN ('iptal','teslim','teslim_edilemedi')
         ORDER BY
            CASE WHEN o.delivery_date <> '' AND o.delivery_date < ? THEN 0 ELSE 1 END,
            o.delivery_date, o.id");
    $st->execute([today()]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $toplam = (int)$r['total_kurus'] - (int)$r['discount_kurus'];
        $bakiye = $toplam - (int)$r['odenmis'];
        if ($bakiye > 0) $out[] = $r + ['toplam' => $toplam, 'bakiye' => $bakiye];
    }
    return $out;
}

/** Dönem ödemeleri: en yeniden eskiye.
 *  ⚠ Sınırsızdı: dönemde 1.666 ödeme varsa kasa sayfası 1,5 MB HTML
 *  üretiyordu (ölçüldü). Mobilde yavaş. En son 60 kayıt gösterilir;
 *  tüm tutarlar zaten üstteki özet kartlarda hesaplanır. */
function kasa_donem_odemeler(string $ym, int $limit = 100): array
{
    [$bas, $bit] = kasa_ay_araligi($ym);
    $st = db()->prepare("SELECT p.*, o.code, c.name cname FROM payments p
        JOIN orders o ON o.id=p.order_id
        JOIN customers c ON c.id=o.customer_id
        WHERE date(p.created_at)>=? AND date(p.created_at)<?
        ORDER BY p.created_at DESC, p.id DESC LIMIT ?");
    $st->execute([$bas, $bit, $limit]);
    return $st->fetchAll();
}

/** Dönem ödemelerinin günlük kırılımı */
function kasa_gunluk_odemeler(string $ym): array
{
    [$bas, $bit] = kasa_ay_araligi($ym);
    $st = db()->prepare("SELECT date(created_at) gun, COUNT(*) adet, SUM(amount_kurus) toplam
        FROM payments WHERE date(created_at)>=? AND date(created_at)<?
        GROUP BY date(created_at) ORDER BY gun");
    $st->execute([$bas, $bit]);
    return $st->fetchAll();
}

/** Dönem giderleri: en yeniden eskiye */
function kasa_donem_giderler(string $ym, int $limit = 100): array
{
    [$bas, $bit] = kasa_ay_araligi($ym);
    $st = db()->prepare("SELECT * FROM expenses
        WHERE date(created_at)>=? AND date(created_at)<? AND " . GIDER_RAPORA_GIRER . "
        ORDER BY created_at DESC, id DESC LIMIT ?");
    $st->execute([$bas, $bit, $limit]);
    return $st->fetchAll();
}

/** Dönem gider özeti (toplam + kategori kırılımı) */
function kasa_gider_ozeti(string $ym): array
{
    [$bas, $bit] = kasa_ay_araligi($ym);
    $st = db()->prepare("SELECT cat, COUNT(*) adet, SUM(amount_kurus) toplam
        FROM expenses WHERE date(created_at)>=? AND date(created_at)<? AND " . GIDER_RAPORA_GIRER . "
        GROUP BY cat ORDER BY toplam DESC");
    $st->execute([$bas, $bit]);
    return $st->fetchAll();
}

/** Ay gezinme çipleri + önceki/sonraki ay */
function kasa_donem_secici(string $ym): void
{
    $onceki = date('Y-m', strtotime($ym . '-01 -1 month'));
    $sonraki = date('Y-m', strtotime($ym . '-01 +1 month'));
    $aylar = [];
    for ($i = 5; $i >= 0; $i--) {
        $ym1 = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
        $aylar[$ym1] = kasa_ay_etiket($ym1);
    }

    echo '<div class="btnrow" style="justify-content:space-between">'
        . '<a class="btn btn-sm" href="?p=kasa&ay=' . e($onceki) . '">← ' . e(kasa_ay_etiket($onceki)) . '</a>'
        . '<b>' . e(kasa_ay_etiket($ym)) . '</b>'
        . '<a class="btn btn-sm" href="?p=kasa&ay=' . e($sonraki) . '">' . e(kasa_ay_etiket($sonraki)) . ' →</a>'
        . '</div>';

    chips('kasa', $aylar, $ym, 'ay');
}

/** Silme butonu — yetkisi olan kullanıcıya gösterir, diğerlerine hiç basılmaz */
function kasa_sil_butonu(string $route, int $id, string $mesaj, string $perm = 'gider.sil'): string
{
    if (!$perm) return '';
    return '<form method="post" action="?p=' . e($route) . '" onsubmit="return confirm(\''
        . e($mesaj) . '\')">' . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button class="btn btn-sm btn-ghost">✕</button></form>';
}

// ================================================================= KASA EKRANI
function kasa(): void
{
    need_perm('odeme.gor');
    $ym = kasa_aktif_ay();
    $o = kasa_donem_ozeti($ym);
    $aciklar = kasa_acik_hesaplar();
    // tüm alacak sayısı ve toplamı (gösterim kırpılır ama tutarlar korunur)
    $acikToplamKayit = count($aciklar);
    $aciklarGoster = array_slice($aciklar, 0, 50);
    $odemeler = kasa_donem_odemeler($ym);
    $gunluk = kasa_gunluk_odemeler($ym);
    $giderler = kasa_donem_giderler($ym);
    $giderOzet = kasa_gider_ozeti($ym);

    $acikToplam = 0;
    foreach ($aciklar as $a) $acikToplam += (int)$a['bakiye'];

    layout_head('Kasa', 'kasa');

    // BUGÜN şeridi — kasadaki takvimiyeri hareket (tahsilat + gelir − gider)
    $bugun = kasa_bugun_ozeti();
    echo '<div class="card"><h2>Bugün</h2><div class="statrow">'
        . '<div class="stat pos"><b>' . e(money_tl((int)$bugun['tahsilat'] + (int)$bugun['gelir'])) . '</b><span>Giren para</span></div>'
        . '<div class="stat negstat"><b>' . e(money_tl((int)$bugun['gider'])) . '</b><span>Çıkan para</span></div>'
        . '<div class="stat"><b>' . e(money_tl((int)$bugun['net'])) . '</b><span>Bugün net</span></div>'
        . '</div></div>';

    echo '<div class="card"><h2>Dönem: ' . e(kasa_ay_etiket($ym)) . '</h2>';
    kasa_donem_secici($ym);
    echo '</div>';

    // dönem özeti
    echo '<div class="statrow">';
    kpi(money_tl((int)$o['ciro']), 'Dönem cirosu');
    kpi(money_tl((int)$o['diger_gelir']), 'Sipariş-dışı gelir');
    kpi(money_tl((int)$o['tahsilat']), 'Dönem tahsilatı');
    kpi(money_tl((int)$o['gider']), 'Dönem gideri');
    kpi(money_tl((int)$o['kar']), 'Dönem kârı', '', ((int)$o['kar'] < 0 ? 'stat-danger' : ''));
    kpi(money_tl((int)$o['net']), 'Net (ciro+gelir−gider)', '', ((int)$o['net'] < 0 ? 'stat-danger' : ''));
    echo '</div>';
    echo '<div class="statrow">';
    kpi(money_tl((int)$o['acik']), 'Açık hesap (alacak)', '?p=kasa');
    kpi(money_tl($acikToplam), 'Vadeli bakiyeli sipariş');
    echo '</div>';

    if ($acikToplam > 0) {
        echo '<div class="alert warn"><b>' . money_tl($acikToplam) . '</b> tahsil edilmemiş bakiye bulunuyor.'
            . '<br><a href="?p=gecikmis">Gecikmiş siparişlere bak →</a></div>';
    }

    // ---------------------------------------------------- A) açık hesaplar
    echo '<div class="card"><h2>💳 Açık Hesaplar (Alacaklar)</h2>';
    if ($acikToplamKayit > 50) {
        echo '<p class="muted small">En bakiyesi yüksek ' . 50 . ' alacak listeleniyor '
            . '(' . $acikToplamKayit . ' açık hesabın toplamı). Tümünü Siparişler → '
            . 'Bakiyesi olanlar filtresinden görebilirsiniz.</p>';
    }
    if (!$aciklar) {
        echo '<p class="muted">Açık bakiyeli sipariş yok.</p>';
    } else {
        echo '<div class="kv"><div>Toplam alacak</div><div><b>' . money_tl($acikToplam) . '</b></div></div>';
        foreach ($aciklarGoster as $a) {
            $d = delay_info($a);
            $bal = (int)$a['bakiye'];
            echo '<a class="row" href="?p=siparis&id=' . (int)$a['id'] . '">'
                . '<span class="row-main"><b>' . e((string)$a['code']) . '</b> · ' . e((string)$a['cname'])
                . '<br><small>' . e(tr_date((string)$a['delivery_date'])) . ' teslim · '
                . e((string)$a['phone']) . '</small>'
                . ($d['late'] ? '<br><small class="neg">⏰ ' . e((string)$d['label']) . ' gecikme</small>' : '')
                . '</span>'
                . '<span class="row-side"><span class="neg">' . money_tl($bal) . '</span>'
                . '<br>' . status_badge((string)$a['status']) . '</span></a>';
        }
    }
    echo '</div>';

    // ---------------------------------------------------- B) son ödemeler
    echo '<div class="card"><h2>💵 Son Ödemeler</h2>';
    if (!$odemeler) {
        echo '<p class="muted">Bu dönemde ödeme kaydı yok.</p>';
    } else {
        foreach ($odemeler as $py) {
            echo '<div class="row"><span class="row-main">' . e(tr_date((string)$py['created_at'], true)) . ' · '
                . e(kasa_odeme_yontemi((string)$py['method']))
                . '<br><small><a href="?p=siparis&id=' . (int)$py['order_id'] . '">'
                . e((string)$py['code']) . '</a> · ' . e((string)$py['cname']) . '</small>'
                . ((string)$py['note'] !== '' ? '<br><small><i>' . e((string)$py['note']) . '</i></small>' : '')
                . '</span><span class="row-side">' . money_tl((int)$py['amount_kurus']);
            echo kasa_sil_butonu('odeme-sil', (int)$py['id'], 'Ödeme kaydı silinsin mi?', 'odeme.sil');
            echo '</span></div>';
        }

        if ($gunluk) {
            echo '<h3 class="sec">Günlük kırılım</h3>';
            echo '<div class="kv"><div>Gün</div><div>Toplam</div></div>';
            foreach ($gunluk as $g) {
                echo '<div class="kv"><div>' . e(tr_date((string)$g['gun'])) . '</div><div><b>'
                    . money_tl((int)$g['toplam']) . '</b> <small>(' . (int)$g['adet'] . ' kayıt)</small></div></div>';
            }
        }
    }
    echo '</div>';

    // ---------------------------------------------------- C) son giderler
    echo '<div class="card"><h2>📉 Son Giderler</h2>';
    if (!$giderler) {
        echo '<p class="muted">Bu dönemde gider kaydı yok.</p>';
    } else {
        foreach ($giderler as $g) {
            echo '<div class="row"><span class="row-main">' . e(tr_date((string)$g['created_at'], true)) . ' · '
                . e(kasa_gider_cat((string)$g['cat']))
                . ((string)$g['note'] !== '' ? '<br><small><i>' . e((string)$g['note']) . '</i></small>' : '')
                . ((string)$g['username'] !== '' ? '<br><small>' . e((string)$g['username']) . '</small>' : '')
                . '</span><span class="row-side">' . money_tl((int)$g['amount_kurus']);
            echo kasa_sil_butonu('gider-sil', (int)$g['id'], 'Gider kaydı silinsin mi?');
            echo '</span></div>';
        }
    }
    echo '</div>';

    // ---------------------------------------------------- gider ekleme formu
    if (is_admin()) {
        echo '<div class="card"><h2>➕ Gider Ekle</h2>';
        echo '<form method="post" action="?p=gider" class="form">' . csrf_field()
            . '<label>Kategori<select name="cat">';
        foreach (KASA_GIDER_CAT as $ck => $clbl) {
            echo '<option value="' . e($ck) . '">' . e($clbl) . '</option>';
        }
        echo '</select></label>'
            . '<label>Tutar (₺)<input name="tutar" inputmode="decimal" placeholder="0,00" required></label>'
            . '<label>Not<input name="note" placeholder="örn. akaryakıt, benzin"></label>'
            . '<button class="btn btn-primary btn-block">Gideri Kaydet</button>'
            . '</form></div>';
    }

    layout_foot('kasa');
}

// ================================================================= GİDER EKRANI
function gider_sayfa(): void
{
    // Gider ekranı artık PERSONELE AÇIK — ama yalnız "gider.ekle" yetkisi
    // olanlar görebilir. Personel kaydı girer, kayıt onay kuyruğuna düşer ve
    // onaylanana kadar kasa/rapora GİRMEZ.
    need_perm('gider.ekle');
    $db = db();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        csrf_check();
        gider_kaydet();
        return;   // gider_kaydet yönlendirir
    }

    $ym = kasa_aktif_ay();
    $giderler = kasa_donem_giderler($ym, 200);
    $ozet = kasa_gider_ozeti($ym);
    $toplam = 0;
    foreach ($ozet as $o) $toplam += (int)$o['toplam'];

    layout_head('Giderler', 'gider');
    // Kayıt hataları (geçersiz kategori / 0 tutar) burada gösterilir.
    // Önceden yalnızca session'a yazılıyor, hiçbir yerde basılmıyordu —
    // kullanıcı formu kaybettiğini nedenini anlamadan yeniden deniyordu.
    if (!empty($_SESSION['gider_hata'])) {
        echo '<div class="alert danger">' . e((string)$_SESSION['gider_hata']) . '</div>';
        unset($_SESSION['gider_hata']);
    }
    rpr_flash();

    echo '<div class="card"><h2>Dönem: ' . e(kasa_ay_etiket($ym)) . '</h2>';
    kasa_donem_secici($ym);
    echo '</div>';

    echo '<div class="statrow">';
    kpi(money_tl($toplam), 'Dönem gideri');
    kpi((string)count($giderler), 'Gider kaydı');
    echo '</div>';

    // kategori kırılımı
    echo '<div class="card"><h2>Kategori Kırılımı</h2>';
    if (!$ozet) {
        echo '<p class="muted">Bu dönemde gider kaydı yok.</p>';
    } else {
        foreach ($ozet as $o) {
            $p = $toplam > 0 ? round((int)$o['toplam'] * 100 / $toplam) : 0;
            echo '<div class="kv"><div>' . e(kasa_gider_cat((string)$o['cat'])) . '</div><div><b>'
                . money_tl((int)$o['toplam']) . '</b> <small class="muted">(' . (int)$p . '%)</small></div></div>';
        }
    }
    echo '</div>';

    // ekleme formu — gider.ekle yetkisi olan herkes (yönetici dahil)
    echo '<div class="card"><h2>➕ Gider Ekle</h2>';
    if (!can('gider.onayla')) {
        echo '<div class="alert warn">Kaydınız <b>onay bekleyecek</b>. Onaylanana kadar kasaya '
            . 've rapora girmez — net kâr rakamları değişmez.</div>';
    }
    echo '<form method="post" action="?p=gider" class="form">' . csrf_field()
        . '<label>Kategori<select name="cat">';
    foreach (KASA_GIDER_CAT as $ck => $clbl) {
        echo '<option value="' . e($ck) . '">' . e($clbl) . '</option>';
    }
    echo '</select></label>'
        . '<label>Tutar (₺)<input name="tutar" inputmode="decimal" placeholder="0,00" required></label>'
        . '<label>Not<input name="note" placeholder="örn. 60 lt benzin, litre 42,00 TL"></label>'
        . '<button class="btn btn-primary btn-block">'
        . (can('gider.onayla') ? 'Gideri Kaydet ve Onayla' : 'Gideri Kaydet (onaya gönder)') . '</button>'
        . '</form></div>';

    // liste — yalnız ONAYLANMIŞ giderler (bekleyenler onay ekranında)
    echo '<div class="card"><h2>Onaylı kayıtlar (' . count($giderler) . ')</h2>';
    if (!$giderler) {
        echo '<p class="muted">Bu dönemde onaylanmış gider kaydı yok.</p>';
    } else {
        foreach ($giderler as $g) {
            echo '<div class="row"><span class="row-main">' . e(tr_date((string)$g['created_at'], true)) . ' · '
                . e(kasa_gider_cat((string)$g['cat']))
                . ((string)$g['note'] !== '' ? '<br><small><i>' . e((string)$g['note']) . '</i></small>' : '')
                . ((string)$g['username'] !== '' ? '<br><small>' . e((string)$g['username']) . '</small>' : '')
                . '</span><span class="row-side">' . money_tl((int)$g['amount_kurus']);
            echo kasa_sil_butonu('gider-sil', (int)$g['id'], 'Gider kaydı silinsin mi?');
            echo '</span></div>';
        }
    }
    echo '</div>';

    // Personelin kendi bekleyen kayıtları (yalnız giren kişi görür)
    if (!can('gider.onayla')) {
        $st = $db->prepare("SELECT * FROM expenses WHERE status='onay_bekliyor' AND user_id=?
            ORDER BY created_at DESC LIMIT 30");
        $st->execute([uid()]);
        $benimBekleyen = $st->fetchAll();
        echo '<div class="card"><h2>Onay bekleyen kayıtlarım (' . count($benimBekleyen) . ')</h2>';
        if (!$benimBekleyen) {
            echo '<p class="muted">Bekleyen kaydınız yok.</p>';
        } else {
            foreach ($benimBekleyen as $g) {
                echo '<div class="row"><span class="row-main">' . e(tr_date((string)$g['created_at'], true)) . ' · '
                    . e(kasa_gider_cat((string)$g['cat']))
                    . ((string)$g['note'] !== '' ? '<br><small><i>' . e((string)$g['note']) . '</i></small>' : '')
                    . '</span><span class="row-side">'
                    . '<span class="badge" style="background:' . e(gider_durum_renk('onay_bekliyor'))
                    . ';color:#fff">Onay bekliyor</span> ' . money_tl((int)$g['amount_kurus'])
                    . '</span></div>';
            }
        }
        echo '</div>';
    } elseif (gider_bekleyen_sayi() > 0) {
        echo '<div class="card"><div class="alert warn">⏳ <b>' . gider_bekleyen_sayi()
            . ' gider</b> onayınızı bekliyor. <a href="?p=gider-onay">Onay ekranına git →</a></div></div>';
    }

    layout_foot('gider');
}

/** Gider kaydı (POST) — yalnızca yönetici */
function gider_kaydet(): void
{
    need_perm('gider.ekle');
    $cat = trim((string)($_POST['cat'] ?? ''));
    if (!isset(KASA_GIDER_CAT[$cat])) {
        $_SESSION['gider_hata'] = 'Geçersiz gider kategorisi.';
        redirect('gider');
    }
    $tutar = parse_tl((string)($_POST['tutar'] ?? ''));
    if ($tutar <= 0) {
        $_SESSION['gider_hata'] = 'Tutar 0’dan büyük olmalı.';
        redirect('gider');
    }
    $note = trim((string)($_POST['note'] ?? $_POST['not'] ?? ''));

    // Personelin girdiği gider ONAY BEKLER ve kasa/rapora GİRMEZ. Yönetici
    // onaylayınca kayıt ancak O AN net kâr rakamlarına dokunur.
    $durum = 'onay_bekliyor';
    if (can('gider.onayla')) {
        $durum = 'onaylandi';
        $st = db()->prepare('INSERT INTO expenses
            (cat, amount_kurus, note, user_id, username, created_at, status, onaylayan_id, onaylayan_adi, onay_tarihi)
            VALUES(?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$cat, $tutar, $note, uid() ?: null, uname_(), now_dt(),
                      'onaylandi', uid() ?: null, uname_(), now_dt()]);
    } else {
        $st = db()->prepare('INSERT INTO expenses
            (cat, amount_kurus, note, user_id, username, created_at, status)
            VALUES(?,?,?,?,?,?,?)');
        $st->execute([$cat, $tutar, $note, uid() ?: null, uname_(), now_dt(), 'onay_bekliyor']);
    }
    $id = (int)db()->lastInsertId();
    audit('gider_ekle', 'expenses', $id,
        kasa_gider_cat($cat) . ' · ' . money_tl($tutar) . ' · ' . $note . ' · durum:' . $durum);
    $_SESSION['flash'] = $durum === 'onay_bekliyor'
        ? '⏳ Giderin kaydedildi ve yönetici onayına gönderildi. Onaylanana kadar kasa ve rapora girmez.'
        : '✅ Gider kaydedildi ve onaylandı.';
    redirect('gider');
}

/** Onay bekleyen gider sayısı — panel rozeti ve menü için. */
function gider_bekleyen_sayi(): int
{
    static $n = null;
    if ($n === null) {
        try { $n = (int)db()->query("SELECT COUNT(*) c FROM expenses WHERE status='onay_bekliyor'")->fetchColumn(); }
        catch (Throwable $e) { $n = 0; }
    }
    return $n;
}

// ============================================================ GİDER ONAY EKRANI
function gider_onay_sayfa(): void
{
    need_perm('gider.onayla');
    $db = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $id = (int)($_POST['id'] ?? 0);
        $islem = (string)($_POST['islem'] ?? '');
        $st = $db->prepare('SELECT * FROM expenses WHERE id=?');
        $st->execute([$id]);
        $g = $st->fetch();
        if (!$g) { $_SESSION['flash_hata'] = 'Gider kaydı bulunamadı.'; redirect('gider-onay'); }

        if ($islem === 'onayla') {
            $db->prepare('UPDATE expenses SET status=?, onaylayan_id=?, onaylayan_adi=?, onay_tarihi=?,
                red_notu=? WHERE id=?')
                ->execute(['onaylandi', uid() ?: null, uname_(), now_dt(), '', $id]);
            audit('gider_onayla', 'expenses', $id,
                kasa_gider_cat((string)$g['cat']) . ' · ' . money_tl((int)$g['amount_kurus'])
                . ' · giren: ' . (string)$g['username']);
            $_SESSION['flash'] = '✅ Gider onaylandı — kasa ve net kâr güncellendi.';
        } elseif ($islem === 'reddet') {
            $not = trim((string)($_POST['red_notu'] ?? ''));
            if ($not === '') {
                $_SESSION['flash_hata'] = 'Ret nedeni yazılmalı — personel ne yapmadı görebilsin.';
                redirect('gider-onay');
            }
            $db->prepare('UPDATE expenses SET status=?, onaylayan_id=?, onaylayan_adi=?, onay_tarihi=?,
                red_notu=? WHERE id=?')
                ->execute(['reddedildi', uid() ?: null, uname_(), now_dt(), $not, $id]);
            audit('gider_reddet', 'expenses', $id,
                kasa_gider_cat((string)$g['cat']) . ' · ' . money_tl((int)$g['amount_kurus'])
                . ' · giren: ' . (string)$g['username'] . ' · not: ' . $not);
            $_SESSION['flash'] = 'Gider reddedildi — rapora girmedi.';
        } elseif ($islem === 'iptal') {
            $db->prepare('UPDATE expenses SET status=? WHERE id=?')->execute(['iptal', $id]);
            audit('gider_iptal', 'expenses', $id,
                kasa_gider_cat((string)$g['cat']) . ' · ' . money_tl((int)$g['amount_kurus']));
            $_SESSION['flash'] = 'Onaylı gider iptal edildi — rapordan düştü.';
        }
        redirect('gider-onay');
    }

    $bekleyen = $db->query("SELECT * FROM expenses WHERE status='onay_bekliyor'
        ORDER BY created_at ASC, id ASC")->fetchAll();
    $gecmis = $db->query("SELECT * FROM expenses WHERE status IN ('reddedildi','iptal')
        ORDER BY created_at DESC, id DESC LIMIT 30")->fetchAll();
    $onayli = $db->query("SELECT * FROM expenses WHERE status='onaylandi'
        ORDER BY created_at DESC, id DESC LIMIT 30")->fetchAll();

    $bekTop = 0; foreach ($bekleyen as $b) $bekTop += (int)$b['amount_kurus'];

    layout_head('Gider Onayı', 'gider-onay');
    rpr_flash();

    echo '<div class="statrow">';
    kpi((string)count($bekleyen), 'Onay bekleyen', '', count($bekleyen) > 0 ? 'stat-alert' : '');
    kpi(money_tl($bekTop), 'Bekleyen toplam');
    echo '</div>';

    if (!$bekleyen) {
        echo '<div class="card"><p class="muted">Şu anda onay bekleyen gider yok.</p></div>';
    } else {
        foreach ($bekleyen as $b) {
            echo '<div class="card"><div class="orderhead"><div><b>'
                . e(kasa_gider_cat((string)$b['cat'])) . '</b> · ' . money_tl((int)$b['amount_kurus'])
                . '<br><small>' . e(tr_date((string)$b['created_at'], true)) . ' · giren: '
                . e((string)$b['username'] ?: '—') . '</small>'
                . ((string)$b['note'] !== '' ? '<br><i>' . e((string)$b['note']) . '</i>' : '')
                . '</div><span class="badge" style="background:' . e(gider_durum_renk((string)$b['status']))
                . ';color:#fff">' . e(gider_durum((string)$b['status'])) . '</span></div>';
            echo '<div class="btnrow mt8">';
            echo '<form method="post" action="?p=gider-onay" class="form">' . csrf_field()
                . '<input type="hidden" name="id" value="' . (int)$b['id'] . '">'
                . '<input type="hidden" name="islem" value="onayla">'
                . '<button class="btn btn-primary btn-block">✅ Onayla — kasaya işle</button></form>';
            echo '<form method="post" action="?p=gider-onay" class="form mt8">' . csrf_field()
                . '<input type="hidden" name="id" value="' . (int)$b['id'] . '">'
                . '<input type="hidden" name="islem" value="reddet">'
                . '<label>Ret nedeni *<input name="red_notu" placeholder="örn. fiş yok, tutar yanlış" required></label>'
                . '<button class="btn btn-danger btn-block">✕ Reddet</button></form>';
            echo '</div></div>';
        }
    }

    echo '<div class="card"><h2>Onaylı giderler (son 30)</h2>';
    if (!$onayli) echo '<p class="muted">Henüz onaylı gider yok.</p>';
    foreach ($onayli as $g) {
        echo '<div class="row"><span class="row-main">'
            . e(tr_date((string)$g['created_at'])) . ' · ' . e(kasa_gider_cat((string)$g['cat']))
            . ((string)$g['note'] !== '' ? '<br><small><i>' . e((string)$g['note']) . '</i></small>' : '')
            . '<br><small>onaylayan: ' . e((string)($g['onaylayan_adi'] ?: '—')) . '</small>'
            . '</span><span class="row-side">' . money_tl((int)$g['amount_kurus']);
        if (can('gider.sil')) {
            echo '<form method="post" action="?p=gider-onay" onsubmit="return confirm(\'Gider iptal edilsin mi? Rapor rakamları değişecek.\')">'
                . csrf_field() . '<input type="hidden" name="id" value="' . (int)$g['id'] . '">'
                . '<input type="hidden" name="islem" value="iptal">'
                . '<button class="btn btn-sm btn-ghost">İptal</button></form>';
        }
        echo '</span></div>';
    }
    echo '</div>';

    if ($gecmis) {
        echo '<div class="card"><h2>Reddedilen / iptal edilenler</h2>';
        foreach ($gecmis as $g) {
            echo '<div class="row"><span class="row-main">' . e(tr_date((string)$g['created_at'])) . ' · '
                . e(kasa_gider_cat((string)$g['cat']))
                . ((string)$g['red_notu'] !== '' ? '<br><small>' . e((string)$g['red_notu']) . '</small>' : '')
                . '</span><span class="row-side">'
                . '<span class="badge" style="background:' . e(gider_durum_renk((string)$g['status']))
                . ';color:#fff">' . e(gider_durum((string)$g['status'])) . '</span> '
                . money_tl((int)$g['amount_kurus']) . '</span></div>';
        }
        echo '</div>';
    }

    layout_foot('gider-onay');
}

// ================================================================= GİDER SİLME
function gider_sil(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        exit('Geçersiz istek yöntemi.');
    }
    need_perm('gider.sil');
    csrf_check();

    $id = (int)($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM expenses WHERE id=?');
    $st->execute([$id]);
    $g = $st->fetch();
    if (!$g) {
        http_response_code(404);
        exit('Gider kaydı bulunamadı.');
    }

    // ONAYLI mali kayıt SİLİNMEZ — rapor geçmiş ayların kârını sessizce
    // değiştirirdi. Bunun yerine 'iptal' durumuna alınır (Gider Onay ekranından).
    if ((string)($g['status'] ?? 'onaylandi') === 'onaylandi') {
        $_SESSION['flash_hata'] = 'Onaylanmış gider silinemez. Kayıt kalsın diye '
            . 'Gider Onay ekranındaki "İptal" düğmesini kullanın.';
        redirect('gider');
    }
    // Bekleyen kaydı yalnızca GİREN kişi silebilir (kazara girilen kayıt geri çekilir).
    if (!can('gider.onayla') && (int)($g['user_id'] ?? 0) !== uid()) {
        http_response_code(403);
        exit('Yalnızca kendi kaydınızı silebilirsiniz.');
    }

    $del = db()->prepare('DELETE FROM expenses WHERE id=?');
    $del->execute([$id]);
    audit('gider_sil', 'expenses', $id,
        kasa_gider_cat((string)$g['cat']) . ' · ' . money_tl((int)$g['amount_kurus'])
        . ' · durum:' . (string)($g['status'] ?? 'onaylandi'));
    redirect('gider');
}

// ================================================================= ÖDEME EKLE
function odeme_ekle(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        exit('Geçersiz istek yöntemi.');
    }

    $oid = (int)($_POST['order_id'] ?? 0);
    if ($oid > 0) $_GET['id'] = $oid;   // order_or_404() $_GET['id'] okur

    // 1) yetki  2) CSRF  3) sipariş — test 400/403 bekliyor
    // "odeme.gor" = tahsilatı görmek; "odeme.ekle" = para girişi yapmak.
    if (!can('odeme.ekle')) {
        http_response_code(403);
        exit('Bu işlem için yetkiniz yok.');
    }
    csrf_check();
    $o = order_or_404();

    $toplam = (int)$o['total_kurus'] - (int)$o['discount_kurus'];
    $st = db()->prepare('SELECT COALESCE(SUM(amount_kurus),0) FROM payments WHERE order_id=?');
    $st->execute([(int)$o['id']]);
    $odenmis = (int)$st->fetchColumn();
    $bakiye = $toplam - $odenmis;

    $kuruş = parse_tl((string)($_POST['tutar'] ?? ''));
    if ($kuruş <= 0) {
        http_response_code(400);
        layout_head('Ödeme');
        echo '<div class="card"><p class="alert"><b>Geçersiz tutar.</b> Tutar 0’dan büyük olmalı.</p>'
            . '<a class="btn btn-block" href="?p=siparis&id=' . (int)$o['id'] . '">← Siparişe dön</a></div>';
        layout_foot();
        exit;
    }

    $method = trim((string)($_POST['method'] ?? 'nakit'));
    if (!isset(KASA_ODEME_METHOD[$method])) $method = 'nakit';
    $note = trim((string)($_POST['note'] ?? ''));

    // Aşırı ödeme: uyar ama reddetme (kapora / kısmi ödeme normal)
    $yeniBakiye = $bakiye - $kuruş;
    if ($yeniBakiye < 0) {
        app_log('asiri odeme uyarisi: siparis=' . (int)$o['id'] . ' bakiye=' . $bakiye
            . ' giren=' . $kuruş);
    }

    $ins = db()->prepare('INSERT INTO payments(order_id, amount_kurus, method, note, created_at, user_id, username)
        VALUES(?,?,?,?,?,?,?)');
    $ins->execute([(int)$o['id'], $kuruş, $method, $note, now_dt(), uid() ?: null, uname_()]);
    $id = (int)db()->lastInsertId();

    audit('odeme_ekle', 'payments', $id,
        (string)$o['code'] . ' · ' . money_tl($kuruş) . ' · ' . kasa_odeme_yontemi($method)
        . ' · kalan bakiye: ' . money_tl($yeniBakiye));

    redirect('siparis', 'id=' . (int)$o['id'] . ($yeniBakiye < 0 ? '&uyari=asiri' : ''));
}

// ================================================================= ÖDEME SİLME
function odeme_sil(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        exit('Geçersiz istek yöntemi.');
    }
    need_perm('odeme.sil');
    csrf_check();

    $id = (int)($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT p.*, o.code FROM payments p
        JOIN orders o ON o.id=p.order_id WHERE p.id=?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) {
        http_response_code(404);
        exit('Ödeme kaydı bulunamadı.');
    }

    $del = db()->prepare('DELETE FROM payments WHERE id=?');
    $del->execute([$id]);
    audit('odeme_sil', 'payments', $id,
        (string)$p['code'] . ' · ' . money_tl((int)$p['amount_kurus']));
    redirect('siparis', 'id=' . (int)$p['order_id']);
}