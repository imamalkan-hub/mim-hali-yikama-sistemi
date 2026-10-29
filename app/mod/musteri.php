<?php
/** Müşteri modülü: kayıt, arama, kart, mükerrer önleme */

/** Müşterinin açık bakiyesi (kuruş) */
function musteri_bakiye(int $cid): int
{
    $st = db()->prepare("SELECT COALESCE(SUM(o.total_kurus-o.discount_kurus),0)
        - COALESCE((SELECT SUM(p.amount_kurus) FROM payments p
            JOIN orders o2 ON o2.id=p.order_id WHERE o2.customer_id=?),0)
        FROM orders o WHERE o.customer_id=? AND o.status <> 'iptal'");
    $st->execute([$cid, $cid]);
    return (int)$st->fetchColumn();
}

/** Telefona göre mevcut müşteriyi bul (mükerrer önleme).
 *  Numaralar ÖNCE normalize edilir, sonra karşılaştırılır — aksi hâlde
 *  "0544 111 22 33" ile "05441112233" aynı kişi olmasına rağmen eşleşmez. */
function musteri_bul(string $phone): ?array
{
    $n = wa_phone($phone);
    if ($n === '') return null;
    // son 9 hane yeterli kimliktir; kayıtlar normalize edilerek taranır
    $son9 = substr($n, -9);
    if (strlen($son9) < 9) return null;
    $rows = db()->query("SELECT * FROM customers WHERE phone <> '' OR phone2 <> ''")->fetchAll();
    foreach ($rows as $r) {
        foreach (['phone', 'phone2'] as $k) {
            $raw = (string)($r[$k] ?? '');
            if ($raw === '') continue;
            if (substr(wa_phone($raw), -9) === $son9) return $r;
        }
    }
    return null;
}

// ---------------------------------------------------------------- adresler

/** Müşterinin adresleri (varsayılan önce) */
function musteri_adresleri(int $cid): array
{
    $st = db()->prepare('SELECT * FROM customer_addresses WHERE customer_id=?
        ORDER BY is_default DESC, id');
    $st->execute([$cid]);
    return $st->fetchAll();
}

/** Varsayılan adres kaydı (yoksa müşterinin kendi adres alanları) */
function musteri_ana_adres(int $cid): array
{
    $adr = musteri_adresleri($cid);
    foreach ($adr as $a) if ((int)$a['is_default'] === 1) return $a;
    if ($adr) return $adr[0];
    $st = db()->prepare('SELECT address, district, map_url FROM customers WHERE id=?');
    $st->execute([$cid]);
    $c = $st->fetch();
    return ['id' => 0, 'label' => 'Ana adres', 'address' => (string)($c['address'] ?? ''),
        'district' => (string)($c['district'] ?? ''), 'map_url' => (string)($c['map_url'] ?? '')];
}

/** "Mahalle, adres" tek satır */
function musteri_adres_metni(array $a): string
{
    $ad = trim((string)($a['address'] ?? ''));
    $il = trim((string)($a['district'] ?? ''));
    $s = trim($il . ($il !== '' && $ad !== '' ? ', ' : '') . $ad);
    return $s !== '' ? $s : 'Adres girilmemiş';
}

/** Adres kaydet — yalnız bir tane is_default=1 kalır (transaction ile) */
function musteri_adres_kaydet(int $cid, int $id, string $label, string $district,
    string $address, string $mapUrl, bool $isDefault): void
{
    $db = db();
    $db->beginTransaction();
    try {
        if ($isDefault) $db->prepare('UPDATE customer_addresses SET is_default=0 WHERE customer_id=?')
            ->execute([$cid]);
        if ($id > 0) {
            $st = $db->prepare('UPDATE customer_addresses SET label=?, district=?, address=?,
                map_url=?, is_default=? WHERE id=? AND customer_id=?');
            $st->execute([$label, $district, $address, $mapUrl, $isDefault ? 1 : 0, $id, $cid]);
        } else {
            // ilk adres otomatik ana adres olur
            $stC = $db->prepare('SELECT COUNT(*) c FROM customer_addresses WHERE customer_id=?');
            $stC->execute([$cid]);
            if ((int)$stC->fetchColumn() === 0) $isDefault = true;
            if ($isDefault) $db->prepare('UPDATE customer_addresses SET is_default=0 WHERE customer_id=?')
                ->execute([$cid]);
            $st = $db->prepare('INSERT INTO customer_addresses(customer_id,label,district,address,
                map_url,is_default,created_at) VALUES(?,?,?,?,?,?,?)');
            $st->execute([$cid, $label, $district, $address, $mapUrl, $isDefault ? 1 : 0, now_dt()]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        app_log('adres kaydedilemedi: ' . $e->getMessage());
        $_SESSION['hata'] = 'Adres kaydedilemedi.';
    }
}

/** Adres sil */
function musteri_adres_sil(int $cid, int $id): void
{
    $st = db()->prepare('DELETE FROM customer_addresses WHERE id=? AND customer_id=?');
    $st->execute([$id, $cid]);
}

/** Ana adres yap (yalnız bir tane) */
function musteri_adres_varsayilan(int $cid, int $id): void
{
    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE customer_addresses SET is_default=0 WHERE customer_id=?')->execute([$cid]);
        $db->prepare('UPDATE customer_addresses SET is_default=1 WHERE id=? AND customer_id=?')
            ->execute([$id, $cid]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        app_log('ana adres ayarlanamadı: ' . $e->getMessage());
    }
}

/** Adres formu (kart üzerinde açılır) */
function musteri_adres_formu(int $cid, int $editId = 0): void
{
    $a = ['label' => 'Ev', 'district' => '', 'address' => '', 'map_url' => '', 'is_default' => 0];
    if ($editId > 0) {
        foreach (musteri_adresleri($cid) as $x) {
            if ((int)$x['id'] === $editId) { $a = $x; break; }
        }
    }
    if (!$a) return;
    $dizi = ['Ev', 'İş', 'Diğer'];
    echo '<form method="post" action="?p=musteri-kaydet" class="form">' . csrf_field()
        . '<input type="hidden" name="islem" value="adres">'
        . '<input type="hidden" name="cid" value="' . $cid . '">'
        . '<input type="hidden" name="id" value="' . $editId . '">'
        . '<div class="card"><h2>' . ($editId > 0 ? '✏ Adresi Düzenle' : '➕ Yeni Adres') . '</h2>'
        . '<label>Etiket<select name="label">';
    foreach ($dizi as $d) {
        echo '<option value="' . e($d) . '"' . ((string)$a['label'] === $d ? ' selected' : '') . '>' . e($d) . '</option>';
    }
    $lbl = (string)$a['label'];
    if (!in_array($lbl, $dizi, true)) {
        echo '<option value="' . e($lbl) . '" selected>' . e($lbl) . '</option>';
    }
    echo '</select></label>'
        . '<label>Mahalle / İlçe<input name="district" value="' . e((string)$a['district']) . '"></label>'
        . '<label>Adres (sokak, bina, no)<textarea name="address" rows="2">' . e((string)$a['address']) . '</textarea></label>'
        . '<label>Harita bağlantısı<input name="map_url" value="' . e((string)$a['map_url']) . '" placeholder="(isteğe bağlı)"></label>'
        . '<label class="check"><input type="checkbox" name="is_default" value="1"'
        . ((int)$a['is_default'] === 1 ? ' checked' : '') . '> Ana adres yap</label>'
        . '<button class="btn btn-primary btn-block">Adresi Kaydet</button>'
        . '<a class="btn btn-ghost btn-block" href="?p=musteri&id=' . $cid . '">Vazgeç</a>'
        . '</div></form>';
}

// ---------------------------------------------------------------- işlem uçları

function musteri_adres_kaydet_eylem(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('musteriler');
    csrf_check();
    need_perm('musteri.olustur');
    $cid = (int)($_POST['cid'] ?? 0);
    $st = db()->prepare('SELECT COUNT(*) FROM customers WHERE id=?');
    $st->execute([$cid]);
    if ((int)$st->fetchColumn() === 0) redirect('musteriler');

    $label = trim((string)($_POST['label'] ?? 'Ev')) ?: 'Ev';
    $district = trim((string)($_POST['district'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $mapUrl = trim((string)($_POST['map_url'] ?? ''));
    if ($address === '' && $district === '') {
        $_SESSION['hata'] = 'Adres alanı boş bırakılamaz.';
        redirect('musteri', 'id=' . $cid);
    }
    $id = (int)($_POST['id'] ?? 0);
    musteri_adres_kaydet($cid, $id, $label, $district, $address, $mapUrl, isset($_POST['is_default']));
    // customers kaydındaki tek adres alanları ana adresle eşitlenir (geriye uyum)
    $mevcut = musteri_adresleri($cid);
    if ($mevcut) {
        $u = db()->prepare('UPDATE customers SET address=?, district=? WHERE id=?');
        $u->execute([(string)$mevcut[0]['address'], (string)$mevcut[0]['district'], $cid]);
    }
    audit('musteri_adres_kaydet', 'customers', $cid, $label . ' · ' . $district . ' ' . $address);
    redirect('musteri', 'id=' . $cid);
}

function musteri_adres_sil_eylem(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('musteriler');
    csrf_check();
    need_perm('musteri.olustur');
    $cid = (int)($_POST['cid'] ?? 0);
    $id = (int)($_POST['id'] ?? 0);
    $silinen = null;
    foreach (musteri_adresleri($cid) as $x) if ((int)$x['id'] === $id) { $silinen = $x; break; }
    if ($silinen) {
        musteri_adres_sil($cid, $id);
        // silinen ana adresse, kalan ilki ana adres olur
        if ((int)$silinen['is_default'] === 1) {
            $kalan = musteri_adresleri($cid);
            if ($kalan) musteri_adres_varsayilan($cid, (int)$kalan[0]['id']);
        }
        $kalan = musteri_adresleri($cid);
        if ($kalan) {
            $u = db()->prepare('UPDATE customers SET address=?, district=? WHERE id=?');
            $u->execute([(string)$kalan[0]['address'], (string)$kalan[0]['district'], $cid]);
        }
        audit('musteri_adres_sil', 'customers', $cid, (string)$silinen['label']);
    }
    redirect('musteri', 'id=' . $cid);
}

function musteri_adres_varsayilan_eylem(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('musteriler');
    csrf_check();
    need_perm('musteri.olustur');
    $cid = (int)($_POST['cid'] ?? 0);
    $id = (int)($_POST['id'] ?? 0);
    musteri_adres_varsayilan($cid, $id);
    audit('musteri_adres_ana', 'customers', $cid, 'adres #' . $id);
    redirect('musteri', 'id=' . $cid);
}

/** Adres işlemlerini kayıtlı müşteri-kaydet ucuna bağlar (index.php'ye dokunmadan).
 *  Formlarda: action="?p=musteri-kaydet" + <input name="islem" value="..."> */
function musteri_adres_dispatch(): void
{
    switch ((string)($_POST['islem'] ?? '')) {
        case 'adres':       musteri_adres_kaydet_eylem(); return;
        case 'adres-sil':   musteri_adres_sil_eylem(); return;
        case 'adres-ana':   musteri_adres_varsayilan_eylem(); return;
    }
}

/** Yeni müşteri formunda adresi ana kayda da yaz (adres tablosu migration ile eşleşir) */
function musteriler(): void
{
    need_perm('musteri.gor');
    $q = trim((string)($_GET['q'] ?? ''));
    layout_head('Müşteriler', 'musteriler');
    echo '<form method="get" action="?" class="searchbar"><input type="hidden" name="p" value="musteriler">'
        . '<input name="q" value="' . e($q) . '" placeholder="Telefon veya isim ara..." inputmode="search">'
        . '<button class="btn">Ara</button></form>';

    $db = db();
    if ($q === '') {
        $st = $db->query("SELECT phone, COUNT(*) c FROM customers WHERE phone <> ''
            GROUP BY phone HAVING c > 1 LIMIT 5");
        $dup = $st->fetchAll();
        if ($dup) {
            echo '<div class="alert warn"><b>Aynı telefon birden fazla kayıtta:</b> ';
            foreach ($dup as $d) echo e((string)$d['phone']) . ' (' . (int)$d['c'] . ' kayıt) · ';
            echo '<br>Mükerrer kayıtları birleştirin.</div>';
        }
    }

    echo '<div class="card">';
    if ($q !== '') {
        $st = $db->prepare("SELECT * FROM customers WHERE phone LIKE ? OR phone2 LIKE ? OR name LIKE ?
            OR district LIKE ? ORDER BY id DESC LIMIT 100");
        $like = '%' . $q . '%';
        $st->execute([$like, $like, $like, $like]);
    } else {
        $st = $db->query("SELECT * FROM customers ORDER BY id DESC LIMIT 100");
    }
    $rows = $st->fetchAll();
    if (!$rows) {
        echo '<p class="muted">' . ($q !== '' ? 'Sonuç bulunamadı.' : 'Henüz müşteri yok.') . '</p>';
    }
    foreach ($rows as $c) {
        $borc = musteri_bakiye((int)$c['id']);
        echo '<a class="row" href="?p=musteri&id=' . (int)$c['id'] . '">'
            . '<span class="row-main"><b>' . e($c['name']) . '</b>'
            . ($c['district'] ? ' · <small>' . e($c['district']) . '</small>' : '')
            . '<br><small>' . (customer_line($c) ?: '<span class="muted">telefon yok</span>') . '</small></span>'
            . '<span class="row-side">' . ($borc > 0 ? '<span class="neg">' . money_tl($borc) . '</span>' : '')
            . '<br><span class="chev">›</span></span></a>';
    }
    echo '</div>';
    if (can('musteri.olustur')) {
        echo '<a class="btn btn-primary btn-block" href="?p=musteri-form">+ Yeni Müşteri</a>';
    }
    layout_foot('musteriler');
}

function musteri_form_get(): void
{
    need_perm('musteri.gor');
    $edit = (int)($_GET['duzenle'] ?? 0);
    $c = [];
    if ($edit > 0) {
        $st = db()->prepare('SELECT * FROM customers WHERE id=?');
        $st->execute([$edit]);
        $c = $st->fetch() ?: [];
        if (!$c) redirect('musteriler');
    }
    musteri_form($c);
}

function musteri_form(array $c = []): void
{
    $id = (int)($c['id'] ?? 0);
    layout_head($id ? 'Müşteri Düzenle' : 'Yeni Müşteri', 'musteriler');
    $optin = (int)($c['wa_optin'] ?? 1);
    echo '<form method="post" action="?p=musteri-kaydet" class="form">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<div class="card">'
        . '<label>Ad Soyad / Ünvan<input name="name" required value="' . e($c['name'] ?? '') . '"></label>'
        . '<label>Telefon / WhatsApp<input name="phone" type="tel" inputmode="tel" value="' . e($c['phone'] ?? '') . '" placeholder="05xx xxx xx xx"></label>'
        . '<label>İkinci Telefon<input name="phone2" type="tel" inputmode="tel" value="' . e($c['phone2'] ?? '') . '"></label>'
        . '</div><div class="card">'
        . '<label>Adres (sokak, bina, no)<textarea name="address" rows="2">' . e($c['address'] ?? '') . '</textarea></label>'
        . '<label>Mahalle / İlçe<input name="district" value="' . e($c['district'] ?? '') . '" placeholder="Karaköprü, Haliliye, Eyyübiye..."></label>'
        . '<label>Adres tarifi<textarea name="note" rows="2" placeholder="Zil çalışmıyor, kapı 3. kat">' . e($c['note'] ?? '') . '</textarea></label>'
        . '</div><div class="card">'
        . '<label class="check"><input type="checkbox" name="wa_optin" value="1"' . ($optin ? ' checked' : '')
        . '> WhatsApp sipariş bildirimlerine izin veriyor</label>'
        . '<p class="muted small">Kapalıysa bu müşteriye otomatik bildirim gönderilmez.</p>'
        . '</div>'
        . '<button class="btn btn-primary btn-block">Kaydet</button></form>';
    layout_foot('musteriler');
}

function musteri_kaydet(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('musteriler');
    csrf_check();
    need_perm('musteri.olustur');
    // adres işlemleri de bu kayıtlı üçten geçer (yeni rota açmadan)
    musteri_adres_dispatch();
    $d = [
        'name'     => trim((string)($_POST['name'] ?? '')),
        'phone'    => trim((string)($_POST['phone'] ?? '')),
        'phone2'   => trim((string)($_POST['phone2'] ?? '')),
        'address'  => trim((string)($_POST['address'] ?? '')),
        'district' => trim((string)($_POST['district'] ?? '')),
        'note'     => trim((string)($_POST['note'] ?? '')),
    ];
    // Adres alanları formda GÖNDERİLMEDİYSE (ör. yalnız telefon/izin güncellemesi)
    // mevcut adres boşaltılmaz. Aksi halde "adresini temizlemek isteyen" ile
    // "adres alanını hiç göndermeyen" ayırt edilemez ve kayıt sessizce silinir.
    // Kural ALAN BAŞINA uygulanır: yalnız mahalle gönderilmişse sokak adresi,
    // yalnız adres gönderilmişse mahalle korunur.
    $adresGonderildi = array_key_exists('address', $_POST);
    $ilceGonderildi  = array_key_exists('district', $_POST);
    $adresVar = $adresGonderildi || $ilceGonderildi;
    if ($d['name'] === '') redirect('musteriler');
    $optin = isset($_POST['wa_optin']) ? 1 : 0;
    $id = (int)($_POST['id'] ?? 0);
    $db = db();

    // mükerrer önleme: aynı telefon kayıtlıysa mevcut kayda yönlendir
    if ($id === 0) {
        $dup = musteri_bul($d['phone']);
        if ($dup) {
            audit('musteri_mukerrer', 'customers', (int)$dup['id'], 'yeni kayıt yerine mevcut açıldı');
            redirect('musteri', 'id=' . (int)$dup['id'] . '&uyari=mukerrer');
        }
    }

    if ($id > 0) {
        // Gönderilmeyen adres alanları UPDATE'te hiç yer almaz → eski değer korunur.
        if ($adresVar) {
            $set = ['name=?', 'phone=?', 'phone2=?', 'note=?', 'wa_optin=?', 'saved_at=?'];
            $p = [$d['name'], $d['phone'], $d['phone2'], $d['note'], $optin, now_dt()];
            if ($adresGonderildi) { $set[] = 'address=?'; $p[] = $d['address']; }
            if ($ilceGonderildi) { $set[] = 'district=?'; $p[] = $d['district']; }
            $p[] = $id;
            $st = $db->prepare('UPDATE customers SET ' . implode(',', $set) . ' WHERE id=?');
            $st->execute($p);
        } else {
            $st = $db->prepare('UPDATE customers SET name=?,phone=?,phone2=?,note=?,wa_optin=?,saved_at=? WHERE id=?');
            $st->execute([$d['name'], $d['phone'], $d['phone2'], $d['note'], $optin, now_dt(), $id]);
        }
        audit('musteri_guncelle', 'customers', $id, $d['name']);
    } else {
        $st = $db->prepare('INSERT INTO customers(name,phone,phone2,address,district,note,wa_optin,created_at,saved_at)
            VALUES(?,?,?,?,?,?,?,?,?)');
        $st->execute([$d['name'], $d['phone'], $d['phone2'], $d['address'], $d['district'], $d['note'], $optin, now_dt(), now_dt()]);
        $id = (int)$db->lastInsertId();
        audit('musteri_olustur', 'customers', $id, $d['name']);
    }

    // Ana adres kaydı: müşterinin tek adres alanları da adres tablosuna taşınır,
    // eski ekranlar (alım/teslimat/sipariş) customers.address okumaya devam eder.
    // Gönderilmeyen alanlar mevcut kayıttan okunur; hiçbir adres alanı
    // gönderilmemişse kayıt hiç değiştirilmez.
    if ($adresVar) {
        $mevcut = musteri_adresleri($id);
        $ana = $mevcut ? $mevcut[0] : null;
        $yAdres = $adresGonderildi ? $d['address'] : (string)($ana['address'] ?? '');
        $yIlce  = $ilceGonderildi  ? $d['district'] : (string)($ana['district'] ?? '');
        if ($yAdres !== '' || $yIlce !== '') {
            if (!$mevcut) {
                musteri_adres_kaydet($id, 0, 'Ana adres', $yIlce, $yAdres, '', true);
            } else {
                musteri_adres_kaydet($id, (int)$mevcut[0]['id'], (string)$mevcut[0]['label'],
                    $yIlce, $yAdres, (string)$mevcut[0]['map_url'], true);
            }
        }
    }
    redirect('musteri', 'id=' . $id);
}

function musteri_kart(): void
{
    need_perm('musteri.gor');
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM customers WHERE id=?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) redirect('musteriler');

    layout_head((string)$c['name'], 'musteriler');
    if (($_GET['uyari'] ?? '') === 'mukerrer') {
        echo '<div class="alert warn"><b>Mükerrer kayıt önlendi.</b> Bu telefonla kayıtlı bir müşteri zaten vardı; mevcut kayda yönlendirildiniz.</div>';
    }

    $borc = musteri_bakiye($id);
    $st = db()->prepare("SELECT COUNT(*) c, COALESCE(SUM(total_kurus-discount_kurus),0) t
        FROM orders WHERE customer_id=? AND status<>'iptal'");
    $st->execute([$id]); $agg = $st->fetch();
    $st = db()->prepare("SELECT COALESCE(SUM(amount_kurus),0) t FROM payments p
        JOIN orders o ON o.id=p.order_id WHERE o.customer_id=?");
    $st->execute([$id]); $paid = (int)$st->fetchColumn();
    // adres tablosu henüz taşınmadıysa (migration çalışmamış olabilir) kayıt gösterilmez
    $adresler = musteri_adresleri($id);
    $adresAc = (string)($_GET['adres'] ?? '');

    echo '<div class="statrow">';
    kpi((string)(int)$agg['c'], 'sipariş');
    kpi(money_tl((int)$agg['t']), 'toplam iş');
    kpi(money_tl($paid), 'ödeme');
    kpi(money_tl($borc), 'borç', '', $borc > 0 ? 'stat-alert' : '');
    echo '</div>';

    echo '<div class="card">';
    echo '<div class="kv"><div>Telefon</div><div>' . (customer_line($c) ?: '<span class="muted">—</span>') . '</div></div>';
    if ($c['address'] || $c['district']) {
        echo '<div class="kv"><div>Adres</div><div>' . e((string)$c['address'])
            . ($c['district'] ? ' · ' . e($c['district']) : '') . '</div></div>';
    }
    if ($c['note']) echo '<div class="kv"><div>Not</div><div>' . e($c['note']) . '</div></div>';
    echo '<div class="kv"><div>WhatsApp</div><div>'
        . ((int)($c['wa_optin'] ?? 1) === 1
            ? '<span class="badge" style="background:#128C7E">İzin var</span>'
            : '<span class="badge" style="background:#b91c1c">İzin yok — bildirim gönderilmez</span>')
        . '</div></div>';
    $quick = nav_quick($c);
    if ($quick) echo '<div class="btnrow pad">' . $quick . '</div>';
    echo '<div class="btnrow"><a class="btn" href="?p=musteri-form&duzenle=' . $id . '">✏ Düzenle</a>'
        . (can('siparis.olustur') ? '<a class="btn btn-primary" href="?p=siparis-yeni&musteri=' . $id . '">+ Sipariş</a>' : '')
        . '</div></div>';

    // ------------------------------------------------- adresler (birden çok)
    echo '<div class="card"><h2>📍 Adresler (' . count($adresler) . ')</h2>';
    if (!$adresler) {
        echo '<p class="muted">Bu müşteri için kayıtlı adres yok.</p>';
    }
    foreach ($adresler as $a) {
        $aid = (int)$a['id'];
        $ana = (int)$a['is_default'] === 1;
        echo '<div class="kv"><div>' . e((string)$a['label']) . '</div><div>'
            . ($ana ? '<span class="badge" style="background:#15803d">Ana adres</span> ' : '')
            . e(musteri_adres_metni($a));
        $ml = (string)$a['map_url'] !== '' ? (string)$a['map_url'] : map_link((string)$a['address'], (string)$a['district']);
        if ($ml !== '') {
            echo ' <a target="_blank" rel="noopener" href="' . e($ml) . '">🗺</a>';
        }
        echo '<br><small class="muted">Eklenme: ' . e(tr_date((string)$a['created_at'])) . '</small>';
        if (can('musteri.olustur')) {
            echo ' <a href="?p=musteri&id=' . $id . '&adres=' . $aid . '">✏ Düzenle</a>';
            if (!$ana) {
                echo ' <form method="post" action="?p=musteri-kaydet" class="inline">'
                    . csrf_field()
                    . '<input type="hidden" name="islem" value="adres-ana">'
                    . '<input type="hidden" name="cid" value="' . $id . '">'
                    . '<input type="hidden" name="id" value="' . $aid . '">'
                    . '<button class="btn btn-sm btn-ghost">⭐ Ana adres yap</button></form>';
            }
            echo ' <form method="post" action="?p=musteri-kaydet" class="inline"'
                . ' onsubmit="return confirm(\'Bu adres silinsin mi?\')">' . csrf_field()
                . '<input type="hidden" name="islem" value="adres-sil">'
                . '<input type="hidden" name="cid" value="' . $id . '">'
                . '<input type="hidden" name="id" value="' . $aid . '">'
                . '<button class="btn btn-sm btn-danger">🗑 Sil</button></form>';
        }
        echo '</div></div>';
    }
    if (can('musteri.olustur')) {
        echo '<a class="btn btn-block mt8" href="?p=musteri&id=' . $id . '&adres=yeni">➕ Adres Ekle</a>';
    }
    echo '</div>';
    if ($adresAc !== '') {
        musteri_adres_formu($id, $adresAc === 'yeni' ? 0 : (int)$adresAc);
    }

    $st = db()->prepare('SELECT * FROM orders WHERE customer_id=? ORDER BY id DESC');
    $st->execute([$id]);
    $orders = $st->fetchAll();
    echo '<div class="card"><h2>Sipariş Geçmişi (' . count($orders) . ')</h2>';
    if (!$orders) echo '<p class="muted">Henüz sipariş yok.</p>';
    foreach ($orders as $o) {
        echo '<a class="row" href="?p=siparis&id=' . (int)$o['id'] . '">'
            . '<span class="row-main"><b>' . e($o['code']) . '</b><br><small>' . e(tr_date($o['created_at']))
            . ' · ' . e(tr_date($o['delivery_date'])) . ' planlı</small></span>'
            . '<span class="row-side">' . money_tl((int)$o['total_kurus'] - (int)$o['discount_kurus'])
            . '<br>' . status_badge((string)$o['status']) . '</span></a>';
    }
    echo '</div>';

    // aynı siparişi tekrar oluştur
    if (can('siparis.olustur') && $orders) {
        $son = $orders[0];
        $st = db()->prepare('SELECT * FROM order_items WHERE order_id=?');
        $st->execute([(int)$son['id']]);
        $items = $st->fetchAll();
        echo '<a class="btn btn-block" href="?p=siparis-yeni&kopyala=' . (int)$son['id'] . '">🔁 '
            . e($son['code']) . ' siparişini tekrar oluştur</a>';
    }
    layout_foot('musteriler');
}