<?php
/** Panel — ana ekran sayaçları ve günlük iş özeti */

/** Açık sipariş durumları */
function open_statuses(): array
{
    return ['yeni', 'alim_bekliyor', 'teslim_alindi', 'yikama_bekliyor', 'yikamada',
            'kurutuluyor', 'kalite_kontrol', 'paketlendi', 'dagitimda'];
}
function open_status_sql(): string
{
    return "('" . implode("','", open_statuses()) . "')";
}

function dashboard_counts(): array
{
    $t = today();
    $db = db();
    $open = open_status_sql();
    $out = [
        'alim_bugun' => 0, 'teslim_bugun' => 0, 'teslim_edildi_bugun' => 0,
        'yikama_bekleyen' => 0, 'islemde' => 0, 'hazir' => 0, 'dagitimda' => 0,
        'gecikmis' => 0, 'kritik' => 0, 'wa_hata' => 0,
        'ciro_bugun' => 0, 'tahsilat_bugun' => 0, 'gider_bugun' => 0, 'acik_hesap' => 0,
    ];

    $q = function (string $sql, array $args = []) use ($db): int {
        $st = $db->prepare($sql); $st->execute($args);
        $r = $st->fetch();
        return (int)($r['c'] ?? 0);
    };
    $sum = function (string $sql, array $args = []) use ($db): int {
        $st = $db->prepare($sql); $st->execute($args);
        return (int)$st->fetchColumn();
    };

    // 'Yıkama bekleyen' = henüz yıkamaya başlanmamış, işlemde olmayan açık siparişler.
    // Bağlandığı liste filtresiyle AYNI durum kümesini kullanır (aşma/uyumsuzluk olmasın).
    $out['yikama_bekleyen'] = $q("SELECT COUNT(*) c FROM orders WHERE status IN ('yeni','alim_bekliyor','teslim_alindi','yikama_bekliyor')");
    $out['islemde']       = $q("SELECT COUNT(*) c FROM orders WHERE status IN ('yikamada','kurutuluyor','kalite_kontrol')");
    $out['hazir']         = $q("SELECT COUNT(*) c FROM orders WHERE status='paketlendi'");
    $out['dagitimda']     = $q("SELECT COUNT(*) c FROM orders WHERE status='dagitimda'");
    $out['alim_bugun']    = $q("SELECT COUNT(*) c FROM orders WHERE status IN ('alim_bekliyor','yeni')
                                AND (pickup_date='' OR pickup_date<=?)", [$t]);
    $out['teslim_bugun']  = $q("SELECT COUNT(*) c FROM orders WHERE delivery_date=? AND status IN $open", [$t]);
    $out['teslim_edildi_bugun'] = $q("SELECT DISTINCT order_id c FROM events WHERE status='teslim' AND date(created_at)=?", [$t]);
    $out['wa_hata']       = $q("SELECT COUNT(*) c FROM wa_queue WHERE status='hata'");

    // gecikme (saat bazlı, planlanan teslim tarihi esas)
    $st = $db->prepare("SELECT * FROM orders WHERE status IN $open AND delivery_date <> '' AND delivery_date < ?");
    $st->execute([$t]);
    foreach ($st->fetchAll() as $o) {
        $d = delay_info($o);
        if ($d['late']) { $out['gecikmis']++; if ($d['bucket'] === 'kritik') $out['kritik']++; }
    }

    $out['ciro_bugun'] = $sum("SELECT COALESCE(SUM(total_kurus-discount_kurus),0) FROM orders
                               WHERE date(created_at)=? AND status!='iptal'", [$t]);
    $out['tahsilat_bugun'] = $sum("SELECT COALESCE(SUM(amount_kurus),0) FROM payments WHERE date(created_at)=?", [$t]);
    $out['gelir_bugun']    = $sum("SELECT COALESCE(SUM(amount_kurus),0) FROM incomes WHERE date(created_at)=?", [$t]);
    $out['gider_bugun']    = $sum("SELECT COALESCE(SUM(amount_kurus),0) FROM expenses WHERE date(created_at)=? AND " . GIDER_RAPORA_GIRER, [$t]);
    $out['acik_hesap']     = $sum(
        "SELECT COALESCE(SUM(borc),0) FROM (
            SELECT o.total_kurus-o.discount_kurus
              - COALESCE((SELECT SUM(p.amount_kurus) FROM payments p WHERE p.order_id=o.id),0) borc
            FROM orders o WHERE o.status NOT IN ('iptal','teslim','teslim_edilemedi'))");
    return $out;
}

/** Son N günün kasa hareketi: gelir (tahsilat + sipariş-dışı) ve gider, günlük. */
function panel_hareket(int $gun = 14): array
{
    $db = db();
    $bas = date('Y-m-d', strtotime(today() . ' -' . ($gun - 1) . ' days'));
    $son = date('Y-m-d', strtotime(today() . ' +1 day'));
    $q = function (string $sql) use ($db, $bas, $son): array {
        $st = $db->prepare($sql);
        $st->execute([$bas, $son]);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[(string)$r['gun']] = (int)$r['t'];
        return $out;
    };
    $tahsilat = $q("SELECT date(created_at) gun, COALESCE(SUM(amount_kurus),0) t FROM payments
        WHERE date(created_at)>=? AND date(created_at)<? GROUP BY date(created_at)");
    $gelir = $q("SELECT date(created_at) gun, COALESCE(SUM(amount_kurus),0) t FROM incomes
        WHERE date(created_at)>=? AND date(created_at)<? GROUP BY date(created_at)");
    $gider = $q("SELECT date(created_at) gun, COALESCE(SUM(amount_kurus),0) t FROM expenses
        WHERE date(created_at)>=? AND date(created_at)<? AND " . GIDER_RAPORA_GIRER . " GROUP BY date(created_at)");
    $out = [];
    for ($i = 0; $i < $gun; $i++) {
        $gun = date('Y-m-d', strtotime($bas . ' +' . $i . ' days'));
        $out[] = ['gun' => $gun,
                  'gelen' => ($tahsilat[$gun] ?? 0) + ($gelir[$gun] ?? 0),
                  'giden' => $gider[$gun] ?? 0];
    }
    return $out;
}

/** 14 günlük gelir/gider çubuk grafiği — saf inline SVG (buildsiz mimari).
 *  Renkler CSS sınıflarından gelir (.g-gelir/.g-gider) → koyu mod otomatik. */
function panel_cubuk_grafik(array $rows): string
{
    $enBuyuk = 0;
    foreach ($rows as $r) $enBuyuk = max($enBuyuk, (int)$r['gelen'], (int)$r['giden']);
    if ($enBuyuk <= 0) return '';   // hiç hareket yoksa boş grafik basma

    $W = 640; $H = 132; $padB = 18;
    $n = count($rows);
    $adim = $W / $n;
    $cubuk = max(3, (int)floor($adim / 2) - 3);

    $svg = '<svg class="mim-grafik" viewBox="0 0 ' . $W . ' ' . $H . '" width="100%" height="' . $H
        . '" role="img" aria-label="Son ' . $n . ' günlük gelir ve gider karşılaştırması">';
    foreach ($rows as $i => $r) {
        $x = $i * $adim + ($adim - $cubuk * 2 - 2) / 2;
        $hg = (int)round((($H - $padB) * (int)$r['gelen']) / $enBuyuk);
        $hc = (int)round((($H - $padB) * (int)$r['giden']) / $enBuyuk);
        $baslik = tr_date($r['gun']) . ' · gelir ' . money_tl((int)$r['gelen'])
            . ' · gider ' . money_tl((int)$r['giden']);
        $svg .= '<g><title>' . e($baslik) . '</title>';
        if ((int)$r['gelen'] > 0) {
            $svg .= '<rect class="g-gelir" x="' . $x . '" y="' . ($H - $padB - $hg)
                . '" width="' . $cubuk . '" height="' . max($hg, 2) . '" rx="2"/>';
        }
        if ((int)$r['giden'] > 0) {
            $svg .= '<rect class="g-gider" x="' . ($x + $cubuk + 2) . '" y="' . ($H - $padB - $hc)
                . '" width="' . $cubuk . '" height="' . max($hc, 2) . '" rx="2"/>';
        }
        $svg .= '<text class="g-etiket" x="' . ($x + $cubuk + 1) . '" y="' . ($H - 5)
            . '" text-anchor="middle">' . (int)substr($r['gun'], 8, 2) . '</text>';
        $svg .= '</g>';
    }
    $svg .= '<line class="g-taban" x1="0" y1="' . ($H - $padB) . '" x2="' . $W . '" y2="' . ($H - $padB) . '"/>';
    $svg .= '</svg>';
    return $svg;
}

function dashboard(): void
{
    layout_head('Genel Bakış');
    $c = dashboard_counts();
    $db = db();
    $t = today();

    // hızlı işlemler — en sık yapılan işler tek dokunuş
    echo '<div class="card"><div class="hizli-grid">'
        . '<a class="hizli" href="?p=siparis-yeni"><span>➕</span>Yeni Sipariş</a>'
        . '<a class="hizli" href="?p=kasa"><span>💵</span>Kasa</a>'
        . (can('gelir.ekle') ? '<a class="hizli" href="?p=gelir"><span>📈</span>Gelir</a>' : '')
        . (can('gider.ekle') ? '<a class="hizli" href="?p=gider"><span>📉</span>Gider</a>' : '')
        . '<a class="hizli" href="?p=musteriler"><span>👥</span>Müşteriler</a>'
        . '</div></div>';

    // bugünün parası — giren / çıkan / net (renk kodlu şerit)
    $giren = (int)$c['tahsilat_bugun'] + (int)$c['gelir_bugun'];
    $netBugun = $giren - (int)$c['gider_bugun'];
    echo '<div class="card"><h2>💰 Bugün</h2><div class="statrow">'
        . '<div class="stat pos"><b>' . e(money_tl($giren)) . '</b><span>Giren para</span></div>'
        . '<div class="stat negstat"><b>' . e(money_tl((int)$c['gider_bugun'])) . '</b><span>Çıkan para</span></div>'
        . '<div class="stat"><b>' . e(money_tl($netBugun)) . '</b><span>Bugün net</span></div>'
        . '<div class="stat"><b>' . e(money_tl((int)$c['acik_hesap'])) . '</b><span>Açık hesap</span></div>'
        . '</div>'
        . '<div class="kv"><div>Bugünkü iş</div><div>' . e(money_tl((int)$c['ciro_bugun']))
        . ' ciro · ' . e(money_tl((int)$c['tahsilat_bugun'])) . ' tahsilat</div></div></div>';

    // 14 günlük gelir/gider grafiği
    $grafik = panel_cubuk_grafik(panel_hareket(14));
    if ($grafik !== '') {
        echo '<div class="card"><h2>Son 14 Gün</h2><div class="grafik-kutu">' . $grafik . '</div>'
            . '<div class="grafik-bilgi"><span><i class="gl"></i>Gelir (tahsilat + diğer)</span>'
            . '<span><i class="gg"></i>Gider</span></div></div>';
    }

    echo '<div class="statrow">';
    kpi((string)$c['alim_bugun'], 'Bugün alınacak', '?p=alim');
    kpi((string)$c['teslim_bugun'], 'Bugün teslim', '?p=teslimat');
    kpi((string)$c['teslim_edildi_bugun'], 'Bugün teslim edilen');
    kpi((string)$c['yikama_bekleyen'], 'Yıkama bekleyen', '?p=siparisler&durum=' . implode(',', ['yeni','alim_bekliyor','teslim_alindi','yikama_bekliyor']));
    echo '</div>';
    echo '<div class="statrow">';
    kpi((string)$c['islemde'], 'İşlemde', '?p=siparisler&durum=' . implode(',', ['yikamada','kurutuluyor','kalite_kontrol']));
    kpi((string)$c['hazir'], 'Teslime hazır', '?p=siparisler&durum=paketlendi');
    kpi((string)$c['dagitimda'], 'Dağıtımda', '?p=siparisler&durum=dagitimda');
    echo '</div>';

    echo '<div class="statrow">';
    kpi((string)$c['gecikmis'], 'Gecikmiş', '?p=gecikmis', $c['gecikmis'] > 0 ? 'stat-alert' : '');
    kpi((string)$c['kritik'], '7+ gün kritik', '?p=gecikmis&grup=kritik', $c['kritik'] > 0 ? 'stat-danger' : '');
    kpi((string)$c['wa_hata'], 'WA hatalı', '?p=wa&durum=hata', $c['wa_hata'] > 0 ? 'stat-danger' : '');
    echo '</div>';

    echo '<div class="statrow">';
    kpi(money_tl((int)$c['acik_hesap']), 'Açık hesap', '?p=kasa');
    kpi(money_tl((int)$c['gelir_bugun']), 'Bugün diğer gelir');
    echo '</div>';

    // Onay bekleyen giderler: yönetici kuyruğu kaçırmasın. Bu giderler
    // kasadaki "Bugün gider" rakamına DAHİL DEĞİLDİR — onaylanınca girer.
    if (can('gider.onayla')) {
        $bek = gider_bekleyen_sayi();
        if ($bek > 0) {
            echo '<div class="statrow">';
            kpi((string)$bek, '⏳ Gider onayı bekliyor', '?p=gider-onay', 'stat-alert');
            echo '</div>';
            echo '<div class="alert warn"><b>⏳ ' . $bek . ' gider</b> onayınızı bekliyor. '
                . 'Onaylanana kadar kasa ve net kâr rakamlarına girmez. '
                . '<a href="?p=gider-onay">Onay ekranına git →</a></div>';
        }
    }

    if ($c['kritik'] > 0) {
        echo '<div class="alert danger"><b>⚠ ' . (int)$c['kritik']
            . ' sipariş 7 günden uzun süredir gecikti.</b><br><a href="?p=gecikmis&grup=kritik">Hemen incele →</a></div>';
    }
    if ($c['gecikmis'] > 0 && $c['kritik'] === 0) {
        echo '<div class="alert warn"><b>⏰ ' . (int)$c['gecikmis'] . ' sipariş gecikti.</b> '
            . '<a href="?p=gecikmis">Listele →</a></div>';
    }
    if (!whatsapp_ready()) {
        echo '<div class="alert warn"><b>WhatsApp otomatik bildirimi yapılandırılmamış.</b> '
            . 'Mesajlar gönderilmiyor, yalnızca kuyruğa alınıyor. '
            . (is_admin() ? '<a href="?p=ayarlar">WhatsApp ayarları →</a>' : 'Yöneticiden isteyin.') . '</div>';
    }

    // gecikmiş önizleme
    if ($c['gecikmis'] > 0) {
        echo '<div class="card"><h2>⏰ Gecikmiş Siparişler</h2>';
        $st = $db->prepare("SELECT o.*, c.name cname FROM orders o JOIN customers c ON c.id=o.customer_id
            WHERE o.delivery_date <> '' AND o.delivery_date < ?
            AND o.status NOT IN ('teslim','iptal','teslim_edilemedi')
            ORDER BY o.delivery_date LIMIT 6");
        $st->execute([$t]);
        foreach ($st->fetchAll() as $o) {
            $d = delay_info($o);
            echo '<a class="row" href="?p=siparis&id=' . (int)$o['id'] . '">'
                . '<span class="row-main"><b>' . e($o['code']) . '</b> ' . e($o['cname'])
                . '<br><small>' . e(tr_date($o['delivery_date'])) . ' planlı · <b>' . e($d['label']) . ' gecikme</b></small></span>'
                . '<span class="row-side">' . status_badge((string)$o['status']) . '</span></a>';
        }
        echo '<a class="btn btn-block mt8" href="?p=gecikmis">Tüm gecikmişler (' . (int)$c['gecikmis'] . ') →</a></div>';
    }

    // bugünün işleri
    echo '<div class="card"><h2>📋 Bugünün İşleri</h2>';
    $st = $db->prepare("SELECT o.*, c.name cname, c.phone, c.district cdistrict
        FROM orders o JOIN customers c ON c.id=o.customer_id
        WHERE o.status IN ('yeni','alim_bekliyor','teslim_alindi','paketlendi','dagitimda')
        AND (o.pickup_date=? OR o.delivery_date=?)
        ORDER BY o.delivery_date, o.id LIMIT 10");
    $st->execute([$t, $t]);
    $rows = $st->fetchAll();
    if (!$rows) echo '<p class="muted">Bugün için planlanmış iş yok.</p>';
    foreach ($rows as $o) {
        $isAlim = in_array((string)$o['status'], ['yeni', 'alim_bekliyor'], true);
        echo '<a class="row" href="?p=siparis&id=' . (int)$o['id'] . '">'
            . '<span class="row-main"><b>' . e($o['code']) . '</b> ' . e($o['cname'])
            . ($o['cdistrict'] ? ' · ' . e($o['cdistrict']) : '')
            . '<br><small>' . ($isAlim ? 'Alım: ' . e(tr_date($o['pickup_date'] ?: $o['created_at']))
                 : 'Teslim: ' . e(tr_date($o['delivery_date']))) . ' · ' . e($o['phone']) . '</small></span>'
            . '<span class="row-side">' . status_badge((string)$o['status']) . '</span></a>';
    }
    echo '</div>';

    // son siparişler
    $st = $db->query("SELECT o.*, c.name cname FROM orders o JOIN customers c ON c.id=o.customer_id
        ORDER BY o.id DESC LIMIT 8");
    echo '<div class="card"><h2>Son Siparişler</h2>';
    $son = $st->fetchAll();
    if (!$son) echo '<p class="muted">Henüz sipariş yok. <a href="?p=siparis-yeni">İlk siparişi oluştur →</a></p>';
    foreach ($son as $o) {
        echo '<a class="row" href="?p=siparis&id=' . (int)$o['id'] . '">'
            . '<span class="row-main"><b>' . e($o['code']) . '</b> ' . e($o['cname'])
            . '<br><small>' . e(tr_date($o['created_at'], true)) . '</small></span>'
            . '<span class="row-side">' . money_tl((int)$o['total_kurus'] - (int)$o['discount_kurus'])
            . '<br>' . status_badge((string)$o['status']) . '</span></a>';
    }
    echo '</div>';
    layout_foot('');
}