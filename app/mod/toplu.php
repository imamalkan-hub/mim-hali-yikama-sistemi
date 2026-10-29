<?php
/**
 * Toplu durum güncellemesi — yönetici birden fazla siparişi tek seferde
 * ileri taşımak istediğinde kullanılır.
 *
 * TASARIM KARARI — kısmi başarı:
 *   İş bir tekrarlı döngüdür; bir sipariş hata verse bile diğerleri işlenir.
 *   Döngü asla durmaz/exception yutmaz; her adım kendi sonucunu toplar ve
 *   sonda "N başarılı, M başarısız" dökümü gösterilir.
 *
 * Her sipariş ayrı ayrı:
 *   - akış kontrolünden geçer (allowed_next),
 *   - teslim öncesi halı adedi kontrolünden geçer,
 *   - kendi add_event() kaydını alır,
 *   - kendi WhatsApp bildirimini üretir (dedupe korunur),
 *   - kendi audit() kaydını yazar.
 *
 * Bu dosya siparis.php'deki tekil geçişin YENİDEN yazımı değildir: ortak
 * kuralları kopyalamak yerine her sipariş için aynı kontroller burada da
 * uygulanır, ama fonksiyon adları benzersizdir (PHP fatal koruması).
 */

/** Tek sipariş için geçişi dener. Yönlendirme YAPMAZ, sonucu döndürür.
 *  @return array{ok:bool, hata:string, eski:string, yeni:string} */
function toplu_gecis_dene(int $oid, string $yeni, string $force = '', ?int $teslimAdedi = null): array
{
    $basarisiz = static function (string $hata): array {
        return ['ok' => false, 'hata' => $hata, 'eski' => '', 'yeni' => ''];
    };

    $st = db()->prepare('SELECT * FROM orders WHERE id=?');
    $st->execute([$oid]);
    $o = $st->fetch();
    if (!$o) return $basarisiz('sipariş bulunamadı');

    $eski = (string)$o['status'];
    $kod = (string)$o['code'];

    if (!isset(status_labels()[$yeni])) {
        return ['ok' => false, 'hata' => 'geçersiz durum: ' . $yeni, 'eski' => $eski, 'yeni' => ''];
    }
    if ($yeni === $eski) {
        return ['ok' => false, 'hata' => 'zaten bu durumda', 'eski' => $eski, 'yeni' => $yeni];
    }
    if (is_closed($eski)) {
        return ['ok' => false, 'hata' => 'sipariş kapanmış (' . (status_labels()[$eski] ?? $eski) . ')',
            'eski' => $eski, 'yeni' => $yeni];
    }

    // teslim öncesi halı adedi — sipariş kartındaki kuralın aynısı
    $beklenen = (int)$o['received_count'];
    $teslim = $teslimAdedi !== null ? $teslimAdedi : (int)$o['returned_count'];
    if ($yeni === 'teslim' && $beklenen > 0) {
        if ($teslim === 0) {
            return ['ok' => false, 'hata' => 'teslim edilmeden önce halı adedi girilmeli',
                'eski' => $eski, 'yeni' => $yeni];
        }
        if ($teslim !== $beklenen) {
            return ['ok' => false, 'hata' => 'adet uyuşmuyor (beklenen ' . $beklenen . ', girilen ' . $teslim . ')',
                'eski' => $eski, 'yeni' => $yeni];
        }
    }

    // akış kuralı — sessizce atlanmaz, neden döner
    $izinli = in_array($yeni, allowed_next($eski), true);
    if (!$izinli && !is_admin()) {
        return ['ok' => false, 'hata' => 'bu akış dışı geçiş için yetkiniz yok',
            'eski' => $eski, 'yeni' => $yeni];
    }
    if (!$izinli && is_admin() && $force === '') {
        return ['ok' => false, 'hata' => 'akış dışı geçiş — istisna nedeni yazılmadı',
            'eski' => $eski, 'yeni' => $yeni];
    }

    // teslim edilen adet kaydı (tekil akışla aynı)
    if ($yeni === 'teslim') {
        $u = db()->prepare('UPDATE orders SET returned_count=? WHERE id=?');
        $u->execute([$teslim, $oid]);
        $s = db()->prepare('UPDATE order_items SET returned_qty=received_qty WHERE order_id=? AND received_qty>0');
        $s->execute([$oid]);
    }

    $note = $force !== '' ? ('İstisna geçiş: ' . $force) : 'Toplu güncelleme';
    $up = db()->prepare('UPDATE orders SET status=?, updated_at=? WHERE id=?');
    $up->execute([$yeni, now_dt(), $oid]);
    add_event($oid, $yeni, $note, $eski);
    audit('siparis_durum_toplu', 'orders', $oid,
        $kod . ' · ' . $eski . ' → ' . $yeni . ($force !== '' ? ' (istisna: ' . $force . ')' : ''));

    // bildirim asla kaydı engellemez
    wa_notify_status($oid, $yeni);
    // toplu teslimde de memnuniyet anketi oluşmalı (tekil akışla aynı kural)
    if ($yeni === 'teslim') anket_olustur($oid);

    return ['ok' => true, 'hata' => '', 'eski' => $eski, 'yeni' => $yeni];
}

/** Toplu güncelleme ekranı ve POST işleyicisi */
function siparis_toplu(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        need_perm('siparis.toplu');
        toplu_isle();
        return;
    }

    // Toplu işlem birden çok siparişi tek hamlede değiştirir — ayrı izin,
    // çünkü "bir siparişin durumunu değiştirebilir" ile "50 siparişi
    // topluca değiştirebilir" aynı risk değildir.
    need_perm('siparis.toplu');
    $db = db();
    $durum = (string)($_GET['durum'] ?? '');

    $sql = "SELECT o.id, o.code, o.status, o.pickup_type, o.delivery_date, o.received_count,
            o.returned_count, c.name cname, c.phone
        FROM orders o JOIN customers c ON c.id=o.customer_id
        WHERE o.status IN " . open_status_sql();
    $args = [];
    if ($durum !== '' && isset(status_labels()[$durum])) {
        $sql .= ' AND o.status=?';
        $args[] = $durum;
    }
    $sql .= ' ORDER BY o.updated_at DESC, o.id DESC LIMIT 200';

    $st = $db->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    layout_head('Toplu Durum Güncelle');
    if (!empty($_SESSION['toplu_sonuc'])) {
        $sonuc = $_SESSION['toplu_sonuc'];
        unset($_SESSION['toplu_sonuc']);
        toplu_sonuc_goster($sonuc);
    }
    if (!empty($_SESSION['flash_hata'])) {
        echo '<div class="alert danger">' . e(implode('<br>· ',
            array_map('strval', (array)$_SESSION['flash_hata']))) . '</div>';
        unset($_SESSION['flash_hata']);
    }

    chips('siparis-toplu', liste_durum_defleri(), $durum);
    echo '<p class="muted small">Açık durumdaki siparişler (en fazla 200). '
        . 'Yalnızca <b>sipariş durumu</b> yetkisi olanlar kullanabilir.</p>';

    if (!$rows) {
        empty_state('📋', 'Bu filtrede açık sipariş yok.');
        layout_foot();
        return;
    }

    $secilen = (array)($_SESSION['toplu_secili'] ?? []);
    echo '<form method="post" action="?p=siparis-toplu" class="form" id="topluForm">' . csrf_field()
        . '<input type="hidden" name="islem" value="durum">'
        . '<div class="card">'
        . '<div class="totalbar"><label class="small" style="font-weight:600">'
        . '<input type="checkbox" id="tumunuSec" onclick="topluHepsi(this)"> Tümünü seç</label>'
        . '<span class="badge">' . count($rows) . ' sipariş</span></div>';

    foreach ($rows as $o) {
        $oid = (int)$o['id'];
        $beklenen = (int)$o['received_count'];
        $teslimEdilen = (int)$o['returned_count'];
        $on = in_array($oid, $secilen, true);
        $sonraki = allowed_next((string)$o['status']);
        $sonrakiLbl = $sonraki ? (status_labels()[$sonraki[0]] ?? $sonraki[0]) : '—';
        echo '<label class="row" style="align-items:flex-start">'
            . '<input type="checkbox" name="t_id[]" value="' . $oid . '"' . ($on ? ' checked' : '')
            . ' data-toplu style="margin-top:4px">'
            . '<span class="row-main"><b>' . e((string)$o['code']) . '</b> · ' . e((string)$o['cname'])
            . '<br><small>📞 ' . e((string)$o['phone'])
            . ' · teslim ' . e(tr_date((string)$o['delivery_date'])) . '</small><br>'
            . status_badge((string)$o['status'])
            . ' <small>→ ' . e($sonrakiLbl) . '</small></span>'
            . '<span class="row-side"><small>' . $beklenen . ' adet</small>'
            . ($teslimEdilen > 0 ? '<br><small>teslim: ' . $teslimEdilen . '</small>' : '')
            . '</span></label>';
    }
    echo '</div>';

    $sl = status_labels();
    echo '<div class="card"><h2>Hedef durum</h2>'
        . '<label>Yeni durum<select name="durum" id="topluDurum" required>';
    foreach ($sl as $k => $lbl) {
        if (is_closed((string)$k) && $k !== 'teslim' && $k !== 'iptal' && $k !== 'teslim_edilemedi') continue;
        echo '<option value="' . e((string)$k) . '">' . e((string)$lbl) . '</option>';
    }
    echo '</select></label>'
        . '<label>Teslim edilen halı adedi (yalnız “Teslim Edildi” hedefi için)<br>'
        . '<input type="number" name="returned_qty" min="0" inputmode="numeric" placeholder="—">'
        . '</label>'
        . '<label>İstisna nedeni (akış dışı geçiş için zorunlu)<br>'
        . '<input name="force_reason" placeholder="örn. acil müşteri, toplu taşıma"></label>'
        . '<p class="muted small">Bir sipariş hata verse bile diğerleri işlenir. '
        . 'Sonuçta her sipariş için ayrı ayrı durum ve neden gösterilir.</p>'
        . '<button class="btn btn-primary btn-block">Seçilenleri Güncelle</button></div></form>';

    echo '<script>
function topluHepsi(kutu){
  var xs=document.querySelectorAll("input[data-toplu]");
  for(var i=0;i<xs.length;i++) xs[i].checked=kutu.checked;
}
</script>';
    layout_foot();
}

/** POST gövdesini işler, sonucu session'a yazar ve geri döner */
function toplu_isle(): void
{
    $ids = $_POST['t_id'] ?? [];
    if (!is_array($ids) || !$ids) {
        $_SESSION['flash_hata'] = ['Hiçbir sipariş seçilmedi.'];
        redirect('siparis-toplu');
    }
    $yeni = (string)($_POST['durum'] ?? '');
    $force = trim((string)($_POST['force_reason'] ?? ''));
    $ret = isset($_POST['returned_qty']) && trim((string)$_POST['returned_qty']) !== ''
        ? (int)$_POST['returned_qty'] : null;

    $basarili = [];
    $basarisiz = [];
    $gorulen = [];

    foreach ($ids as $ham) {
        $oid = (int)$ham;
        if ($oid <= 0) continue;
        if (in_array($oid, $gorulen, true)) continue;   // aynı id iki kez işlenmez
        $gorulen[] = $oid;

        try {
            $r = toplu_gecis_dene($oid, $yeni, $force, $ret);
        } catch (Throwable $e) {
            // TEK bir sipariş patlarsa döngü devam eder (kısmi başarı).
            app_log('toplu gecis hata #' . $oid . ': ' . $e->getMessage());
            $r = ['ok' => false, 'hata' => 'sunucu hatası: ' . $e->getMessage(), 'eski' => '', 'yeni' => $yeni];
        }

        $st = db()->prepare('SELECT code FROM orders WHERE id=?');
        $st->execute([$oid]);
        $kod = (string)($st->fetchColumn() ?: ('#' . $oid));

        if ($r['ok']) {
            $basarili[] = ['kod' => $kod, 'id' => $oid,
                'yol' => ($r['eski'] ?? '') . ' → ' . ($r['yeni'] ?? '')];
        } else {
            $basarisiz[] = ['kod' => $kod, 'id' => $oid, 'hata' => (string)$r['hata'],
                'eski' => (string)($r['eski'] ?? '')];
        }
    }

    $_SESSION['toplu_secili'] = $gorulen;
    $_SESSION['toplu_sonuc'] = [
        'basarili' => $basarili, 'basarisiz' => $basarisiz, 'hedef' => $yeni,
    ];
    audit('siparis_toplu', 'orders', null,
        count($basarili) . ' başarılı, ' . count($basarisiz) . ' başarısız → ' . $yeni);
    redirect('siparis-toplu');
}

/** Sonuç dökümü — "N başarılı, M başarısız" + her sipariş için ayrı satır */
function toplu_sonuc_goster(array $s): void
{
    $bas = $s['basarili'] ?? [];
    $ol = $s['basarisiz'] ?? [];
    $nb = count($bas);
    $no = count($ol);
    $sinif = $no === 0 ? 'ok' : ($nb === 0 ? 'danger' : 'warn');

    echo '<div class="alert ' . $sinif . '"><b>' . $nb . ' başarılı, ' . $no . ' başarısız</b>'
        . ($no === 0 && $nb > 0 ? ' — tüm siparişler güncellendi.' : '')
        . ($nb === 0 && $no > 0 ? ' — hiçbiri güncellenemedi.' : '')
        . ' Hedef durum: ' . e((string)($s['hedef'] ?? '')) . '</div>';

    if ($bas) {
        echo '<div class="card"><h2>✅ Başarılı (' . $nb . ')</h2>';
        foreach ($bas as $x) {
            echo '<div class="row"><span class="row-main"><a href="?p=siparis&id=' . (int)$x['id'] . '">'
                . e((string)$x['kod']) . '</a><br><small>' . e((string)$x['yol']) . '</small></span>'
                . '<span class="row-side"><span class="badge" style="background:#15803d">Güncellendi</span></span></div>';
        }
        echo '</div>';
    }
    if ($ol) {
        echo '<div class="card"><h2>❌ Başarısız (' . $no . ')</h2>';
        foreach ($ol as $x) {
            $lbl = $x['eski'] !== '' ? (status_labels()[$x['eski']] ?? $x['eski']) : '—';
            echo '<div class="row"><span class="row-main"><a href="?p=siparis&id=' . (int)$x['id'] . '">'
                . e((string)$x['kod']) . '</a><br><small>şu anki durum: ' . e((string)$lbl) . '</small><br>'
                . '<small class="neg"><b>Neden:</b> ' . e((string)$x['hata']) . '</small></span>'
                . '<span class="row-side"><span class="badge" style="background:#b91c1c">Değişmedi</span></span></div>';
        }
        echo '<p class="small muted" style="margin:8px 0 0">Bu siparişlerin durumu <b>değiştirilmedi</b>. '
            . 'Nedenleri yukarıda; sipariş kartından tek tek düzeltebilirsiniz.</p></div>';
    }
}