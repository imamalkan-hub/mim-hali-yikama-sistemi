<?php
/**
 * Saha modülü
 *  - benim()          : saha personelinin mobil ekranı (alım + teslimat)
 *  - personel_sayfa() : personel yönetimi (yalnız yönetici)
 *  - personel_kaydet(): personel ekle / düzenle (yalnız yönetici)
 * PHP 7.4+ · SQLite · bağımlılık yok
 */

// ================================================================ ortak yardımcılar

/** Saha ekranındaki tek seferlik mesajları gösterir (flash + hata). */
function saha_mesaj_goster(): void
{
    if (!empty($_SESSION['flash']) && is_string($_SESSION['flash'])) {
        echo '<div class="alert ok">' . e($_SESSION['flash']) . '</div>';
        unset($_SESSION['flash']);
    }
    if (!empty($_SESSION['hata']) && is_string($_SESSION['hata'])) {
        echo '<div class="alert"><b>' . e($_SESSION['hata']) . '</b></div>';
        unset($_SESSION['hata']);
    }
    if (!empty($_SESSION['flash_hata']) && is_array($_SESSION['flash_hata'])) {
        echo '<div class="alert"><b>Kaydedilemedi:</b><br>· '
            . e(implode('<br>· ', $_SESSION['flash_hata'])) . '</div>';
        unset($_SESSION['flash_hata']);
    }
}

/** Yönetici tüm açık işleri görür, personel yalnızca kendine atananları.
 *  @return array{0:string,1:array} [sql_fragment, params] */
function saha_atama_kosulu(): array
{
    if (is_admin()) return ['', []];
    return [' AND o.assigned_to = ?', [uid()]];
}

/** Siparişin alınacak/teslim edilecek halı adedi. */
function saha_adet(array $o): int
{
    $rc = (int)($o['received_count'] ?? 0);
    if ($rc > 0) return $rc;
    return (int)($o['item_qty'] ?? 0);
}

/** Büyük dokunma hedefli saha butonları için sayfa içi stil. */
function saha_stil(): void
{
    echo '<style>'
        . '.saha{background:#fff;border:1px solid var(--line);border-radius:16px;padding:14px;margin-bottom:14px}'
        . '.saha-h{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}'
        . '.saha-h b{font-size:1.2rem;color:var(--navy);display:block;line-height:1.25}'
        . '.saha-code{font-size:.78rem;color:var(--muted);font-weight:600;letter-spacing:.4px}'
        . '.saha-adres{margin:10px 0;font-size:.92rem;color:var(--text);line-height:1.45}'
        . '.saha-adres small{display:block;color:var(--muted)}'
        . '.saha-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}'
        . '.saha-row>a,.saha-row>form{flex:1 1 45%;margin:0}'
        . '.saha-ara{font-size:1.25rem;padding:18px 18px;min-height:62px;border-radius:14px}'
        . '.saha-ok{font-size:1.15rem;padding:18px 18px;min-height:62px;border-radius:14px;letter-spacing:.3px}'
        . '.saha-meta{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0;font-size:.85rem}'
        . '.saha-meta>div{background:#f4f7fa;border-radius:10px;padding:6px 10px;flex:1 1 45%}'
        . '.saha-meta b{display:block;color:var(--navy);font-size:.95rem}'
        . '.saha-meta span{color:var(--muted);font-size:.72rem}'
        . '.saha-adet{display:flex;align-items:center;gap:10px;margin:10px 0}'
        . '.saha-adet label{flex:1;margin:0;font-size:.85rem;font-weight:600;color:var(--navy)}'
        . '.saha-adet input{margin:0;width:110px;text-align:center;font-size:1.15rem;font-weight:700}'
        . '.saha-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin:18px 2px 10px}'
        . '.saha-head h2{margin:0;font-size:1.05rem}'
        . '.saha-tel{display:none}'
        . '@media(max-width:360px){.saha-row>a,.saha-row>form{flex:1 1 100%}}'
        . '</style>';
}

// ================================================================ 1) SAHA EKRANI

function benim(): void
{
    layout_head('Bana Atananlar', 'benim');
    saha_stil();
    saha_mesaj_goster();

    [$fSql, $fArgs] = saha_atama_kosulu();
    $db = db();

    // ------------------------------------------------------------------ veri
    $sqlAlim = "SELECT o.*, c.name cname, c.phone, c.phone2, c.address, c.district,
            (SELECT COALESCE(SUM(qty),0) FROM order_items WHERE order_id = o.id) item_qty
        FROM orders o JOIN customers c ON c.id = o.customer_id
        WHERE o.status IN ('yeni','alim_bekliyor')" . $fSql . "
        ORDER BY (o.pickup_date = ''), o.pickup_date, o.id";
    $st = $db->prepare($sqlAlim);
    $st->execute($fArgs);
    $alimlar = $st->fetchAll();

    $sqlTes = "SELECT o.*, c.name cname, c.phone, c.phone2, c.address, c.district,
            (SELECT COALESCE(SUM(qty),0) FROM order_items WHERE order_id = o.id) item_qty
        FROM orders o JOIN customers c ON c.id = o.customer_id
        WHERE o.status IN ('paketlendi','dagitimda')" . $fSql . "
        ORDER BY (o.delivery_date = ''), o.delivery_date, o.delivery_time, o.id";
    $st = $db->prepare($sqlTes);
    $st->execute($fArgs);
    $teslimatlar = $st->fetchAll();

    // gecikme sayısı
    $gecikmis = 0;
    foreach (array_merge($alimlar, $teslimatlar) as $o) {
        $d = delay_info($o);
        if (!empty($d['late'])) $gecikmis++;
    }

    // ------------------------------------------------------------------ özet
    echo '<div class="statrow">';
    kpi((string)count($alimlar), 'Alım bekliyor', '', count($alimlar) > 0 ? 'stat-alert' : '');
    kpi((string)count($teslimatlar), 'Teslimat', '', count($teslimatlar) > 0 ? 'stat-alert' : '');
    kpi((string)$gecikmis, 'Gecikmiş', '', $gecikmis > 0 ? 'stat-danger' : '');
    echo '</div>';

    if (is_admin()) {
        echo '<div class="alert warn"><b>Yönetici görünümü:</b> Personel filtresi uygulanmadı — '
            . 'tüm açık işleri görüyorsunuz. Bir işi kendinize almak için '
            . '<b>“👤 Bana Ata”</b> butonunu kullanın.</div>';
    }

    // ------------------------------------------------------------------ A) ALIM
    echo '<div class="saha-head"><h2>🚐 Alımlar</h2>'
        . '<span class="badge" style="background:' . (count($alimlar) ? '#8a6d1a' : '#525252') . '">'
        . count($alimlar) . ' iş</span></div>';

    if (!$alimlar) {
        echo '<div class="card"><p class="muted">Alınacak halı yok. '
            . 'Yeni siparişler otomatik olarak burada görünür.</p></div>';
    }
    foreach ($alimlar as $o) {
        saha_kart_alim($o);
    }

    // ------------------------------------------------------------ B) TESLİMAT
    echo '<div class="saha-head"><h2>📦 Teslimatlar</h2>'
        . '<span class="badge" style="background:' . (count($teslimatlar) ? '#15803d' : '#525252') . '">'
        . count($teslimatlar) . ' iş</span></div>';

    if (!$teslimatlar && !$alimlar) {
        empty_state('🎉', 'Şu an size atanmış açık iş yok. Yeni iş geldiğinde burada görünecek.',
            '?p=panel', 'Panele dön');
    } elseif (!$teslimatlar) {
        echo '<div class="card"><p class="muted">Teslim edilecek paket yok.</p></div>';
    }
    foreach ($teslimatlar as $o) {
        saha_kart_teslim($o);
    }

    layout_foot('benim');
}

/** Saha kartı: ortak üst kısım (ad, kod, iletişim butonları, adres) */
function saha_kart_ust(array $o, string $durumEtiketi): void
{
    $oid = (int)$o['id'];
    $phone = trim((string)$o['phone']);
    $adet = saha_adet($o);
    $ml = map_link((string)$o['address'], (string)$o['district']);

    echo '<div class="saha">';
    echo '<div class="saha-h"><div><b>' . e((string)$o['cname']) . '</b>'
        . '<span class="saha-code">' . e((string)$o['code']) . '</span></div>'
        . status_badge($durumEtiketi) . '</div>';

    // DEVASA dokunma hedefli iletişim butonları
    echo '<div class="saha-row">';
    if ($phone !== '') {
        echo '<a class="btn btn-primary saha-ara" href="' . e(tel_href($phone)) . '">📞 Ara</a>';
    }
    $w = wa_phone($phone);
    if ($w !== '') {
        echo '<a class="btn btn-wa" target="_blank" rel="noopener" href="https://wa.me/' . e($w) . '">💬 WhatsApp</a>';
    }
    if ($ml !== '') {
        echo '<a class="btn" target="_blank" rel="noopener" href="' . e($ml) . '">🗺 Harita</a>';
    }
    echo '</div>';

    // adres
    echo '<div class="saha-adres">📍 ' . e((string)$o['address'] ?: 'Adres girilmemiş');
    if ((string)$o['district'] !== '') echo ' <small>' . e((string)$o['district']) . '</small>';
    echo '</div>';
    if ($phone !== '') {
        echo '<div class="saha-adres small">☎ <a href="' . e(tel_href($phone)) . '">'
            . e($phone) . '</a></div>';
    }

    // adet rozeti
    echo '<div class="saha-meta"><div><span>Halı adedi</span><b>' . $adet . ' adet</b></div>';
    echo '<div><span>Sipariş tutarı</span><b>'
        . e(money_tl((int)$o['total_kurus'] - (int)$o['discount_kurus'])) . '</b></div></div>';
}

/** Kart sonu: atanmamışsa "Bana Ata", detay linki */
function saha_kart_alt(array $o): void
{
    $oid = (int)$o['id'];
    echo '<div class="saha-row">';
    if ((int)($o['assigned_to'] ?? 0) === 0) {
        echo '<form method="post" action="?p=siparis-atama">' . csrf_field()
            . '<input type="hidden" name="id" value="' . $oid . '">'
            . '<input type="hidden" name="assigned_to" value="' . uid() . '">'
            . '<button class="btn btn-block">👤 Bana Ata</button></form>';
    }
    echo '<a class="btn btn-ghost" href="?p=siparis&id=' . $oid . '">ℹ Detay</a>';
    echo '</div></div>';
}

/** A) Alım kartı */
function saha_kart_alim(array $o): void
{
    $oid = (int)$o['id'];
    $st = (string)$o['status'];
    $yeni = $st === 'yeni' ? 'alim_bekliyor' : 'teslim_alindi';

    saha_kart_ust($o, $st);

    $pl = trim((string)$o['pickup_date']);
    echo '<div class="saha-meta"><div><span>Alım tarihi</span><b>'
        . e($pl !== '' ? tr_date($pl) : 'Planlanmadı') . '</b></div>';
    echo '<div><span>Alınacak</span><b>' . saha_adet($o) . ' adet</b></div></div>';

    if (trim((string)$o['note']) !== '') {
        echo '<div class="alert warn small" style="margin:10px 0">📝 '
            . e((string)$o['note']) . '</div>';
    }

    if (can('siparis.durum')) {
        echo '<form method="post" action="?p=siparis-durum">'
            . csrf_field()
            . '<input type="hidden" name="id" value="' . $oid . '">'
            . '<input type="hidden" name="durum" value="' . e($yeni) . '">'
            . '<button class="btn btn-primary btn-block saha-ok">✅ Alındı</button></form>';
    }
    saha_kart_alt($o);
}

/** B) Teslimat kartı */
function saha_kart_teslim(array $o): void
{
    $oid = (int)$o['id'];
    $st = (string)$o['status'];
    $beklenen = saha_adet($o);

    saha_kart_ust($o, $st);

    $saat = trim((string)$o['delivery_time']);
    $d = delay_info($o);
    echo '<div class="saha-meta">'
        . '<div><span>Teslim saati</span><b>' . e($saat !== '' ? $saat : '—') . '</b></div>'
        . '<div><span>Planlı teslim</span><b>'
        . e((string)$o['delivery_date'] !== '' ? tr_date((string)$o['delivery_date']) : '—') . '</b></div>'
        . '</div>';

    if (!empty($d['late'])) {
        $cls = $d['bucket'] === 'kritik' ? 'alert danger' : 'alert warn';
        echo '<div class="' . $cls . '" style="margin:10px 0"><b>⏰ ' . e((string)$d['label'])
            . ' gecikme.</b>' . ((string)$o['delay_reason'] !== ''
                ? ' Sebep: ' . e((string)$o['delay_reason']) : '') . '</div>';
    }
    if (trim((string)$o['note']) !== '') {
        echo '<div class="alert warn small" style="margin:10px 0">📝 '
            . e((string)$o['note']) . '</div>';
    }

    // teslim adedi girişi
    if (can('siparis.durum')) {
        echo '<form method="post" action="?p=siparis-durum">'
            . csrf_field()
            . '<input type="hidden" name="id" value="' . $oid . '">'
            . '<input type="hidden" name="durum" value="teslim">'
            . '<div class="saha-adet"><label for="rq' . $oid . '">Teslim edilen adet (alınan: '
            . $beklenen . ')</label>'
            . '<input type="number" id="rq' . $oid . '" name="returned_qty" inputmode="numeric"'
            . ' min="0" max="' . max($beklenen, 999) . '" value="' . $beklenen . '"></div>'
            . '<button class="btn btn-primary btn-block saha-ok">✅ Teslim Edildi</button></form>';

        // akış sırasına uygun ön adım: paketlendi → dağıtıma çıkar
        if ($st === 'paketlendi') {
            echo '<form method="post" action="?p=siparis-durum" class="mt8">'
                . csrf_field()
                . '<input type="hidden" name="id" value="' . $oid . '">'
                . '<input type="hidden" name="durum" value="dagitimda">'
                . '<button class="btn btn-ghost btn-block">🚚 Önce “Dağıtıma Çıkar” yap</button></form>';
        }
    }

    // foto
    echo '<div class="saha-row mt8">'
        . '<a class="btn" href="?p=siparis&amp;id=' . $oid . '">📷 Foto</a>'
        . '<a class="btn btn-ghost" href="?p=siparis&amp;id=' . $oid . '">ℹ Detay</a></div>';

    // atanmamışsa
    if ((int)($o['assigned_to'] ?? 0) === 0) {
        echo '<form method="post" action="?p=siparis-atama" class="mt8">' . csrf_field()
            . '<input type="hidden" name="id" value="' . $oid . '">'
            . '<input type="hidden" name="assigned_to" value="' . uid() . '">'
            . '<button class="btn btn-block">👤 Bana Ata</button></form>';
    }
    echo '</div>';
}

// ================================================================ 2) PERSONEL LİSTESİ

function personel_sayfa(): void
{
    saha_yonetici_mi();   // personel → 403

    layout_head('Personel', 'personel');
    saha_stil();
    saha_mesaj_goster();

    $st = db()->query('SELECT * FROM users ORDER BY active DESC, role, full_name');
    $rows = $st->fetchAll();

    $aktif = 0;
    $yonetici = 0;
    foreach ($rows as $u) {
        if ((int)$u['active'] === 1) $aktif++;
        if ($u['role'] === 'yonetici' && (int)$u['active'] === 1) $yonetici++;
    }

    echo '<div class="statrow">';
    kpi((string)count($rows), 'Toplam kullanıcı');
    kpi((string)$aktif, 'Aktif', '', $aktif < 2 ? 'stat-danger' : '');
    kpi((string)$yonetici, 'Yönetici', '', $yonetici < 2 ? 'stat-alert' : '');
    echo '</div>';

    if ($yonetici < 1) {
        echo '<div class="alert"><b>⚠ Aktif yönetici kalmadı.</b> Sisteme girecek yönetici bulunmuyor.</div>';
    } elseif ($yonetici === 1) {
        echo '<div class="alert warn">Son aktif yönetici pasifleştirilemez ve rolü düşürülemez.</div>';
    }

    echo '<a class="btn btn-primary btn-block" href="?p=personel-kaydet">➕ Yeni Personel Ekle</a>';

    echo '<div class="sec">👷 Kullanıcılar (' . count($rows) . ')</div>';
    foreach ($rows as $u) {
        $uidK = (int)$u['id'];
        $q = db()->prepare('SELECT COUNT(*) FROM orders WHERE assigned_to=?');
        $q->execute([$uidK]);
        $sip = (int)$q->fetchColumn();

        $isYon = $u['role'] === 'yonetici';
        $isAktif = (int)$u['active'] === 1;
        $ben = $uidK === uid();

        echo '<div class="card" style="padding:12px 14px">';
        echo '<div class="saha-h"><div><b>' . e((string)($u['full_name'] ?: $u['username'])) . '</b>'
            . '<span class="saha-code">@' . e((string)$u['username']) . '</span></div>'
            . '<span class="badge" style="background:' . ($isYon ? '#10243A' : '#16A6A1') . '">'
            . ($isYon ? 'Yönetici' : 'Personel') . '</span></div>';

        echo '<div class="saha-meta">'
            . '<div><span>Durum</span><b>' . ($isAktif
                ? '<span class="badge" style="background:#15803d">Aktif</span>'
                : '<span class="badge" style="background:#b91c1c">Pasif</span>') . '</b></div>'
            . '<div><span>Atanan sipariş</span><b>' . $sip . '</b></div></div>';

        echo '<div class="kv"><div>Telefon</div><div>'
            . ((string)$u['phone'] !== ''
                ? '<a href="' . e(tel_href((string)$u['phone'])) . '">' . e((string)$u['phone']) . '</a>'
                : '<span class="muted">—</span>')
            . '</div></div>';
        echo '<div class="kv"><div>Son giriş</div><div>'
            . e((string)$u['last_login'] !== '' ? tr_date((string)$u['last_login'], true) : '—')
            . '</div></div>';
        echo '<div class="kv"><div>Kayıt</div><div>'
            . e(tr_date((string)$u['created_at'])) . '</div></div>';

        if ($ben) {
            echo '<p class="muted small" style="margin:8px 0 0">Bu sizsiniz — kendinizi pasifleştiremezsiniz.</p>';
        }
        echo '<div class="saha-row" style="margin-top:10px">'
            . '<a class="btn" href="?p=personel-kaydet&amp;id=' . $uidK . '">✏ Düzenle</a>'
            . ($isAktif ? '<a class="btn btn-ghost" href="?p=benim">📍 İşlerini gör</a>' : '')
            . '</div></div>';
    }

    layout_foot('personel');
}

// ================================================================ 3) PERSONEL EKLE / DÜZENLE

function personel_kaydet(): void
{
    saha_yonetici_mi();   // personel → 403

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        csrf_check();
        saha_personel_kaydet_post();
        return;
    }
    saha_personel_form();
}

/** Personel yönetimi yalnız yöneticidedir. */
function saha_yonetici_mi(): void
{
    // Personel yönetimi artık yalnız yöneticiye değil, personel.yonet
    // iznine sahip olana açık — yönetici "personel.yonet" iznini bir başkasına
    // devredebilir (kıdemli eleman yeni personel açabilir).
    if (can('personel.yonet')) return;
    if (empty($_SESSION['ok'])) { header('Location: ?p=login'); exit; }
    http_response_code(403);
    layout_head('Yetkisiz');
    echo '<div class="card"><p class="alert"><b>Bu sayfaya erişim yetkiniz yok.</b><br>'
        . 'Bu bölüm yalnızca yöneticiler içindir.</p>'
        . '<a class="btn btn-block" href="?p=">← Panele dön</a></div>';
    layout_foot();
    exit;
}

/** GET: yeni kullanıcı / düzenleme formu */
function saha_personel_form(): void
{
    saha_yonetici_mi();

    $id = (int)($_GET['id'] ?? 0);
    $u = [];
    if ($id > 0) {
        $st = db()->prepare('SELECT * FROM users WHERE id=?');
        $st->execute([$id]);
        $u = $st->fetch();
        if (!$u) redirect('personel');
    }
    $yeni = $id === 0;
    $rol  = (string)($u['role'] ?? 'personel');
    $aktif = $yeni ? 1 : (int)($u['active'] ?? 1);
    $ben = !$yeni && $id === uid();

    layout_head($yeni ? 'Yeni Personel' : 'Personel Düzenle', 'personel');
    saha_mesaj_goster();

    echo '<form method="post" action="?p=personel-kaydet" class="form">'
        . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">';

    echo '<div class="card">';
    if ($yeni) {
        echo '<label>Kullanıcı adı *'
            . '<input name="username" required autocapitalize="none" autocomplete="off"'
            . ' autocorrect="off" spellcheck="false" inputmode="latin" pattern="[a-z0-9_]{3,32}"'
            . ' placeholder="ornek: mehmet" maxlength="32"></label>'
            . '<p class="muted small">3–32 karakter: sadece küçük harf, rakam ve alt çizgi.</p>';
        echo '<label>Şifre * (en az 6 karakter)'
            . '<input type="password" name="sifre1" required autocomplete="new-password" minlength="6"></label>';
        echo '<label>Şifre tekrar *'
            . '<input type="password" name="sifre2" required autocomplete="new-password" minlength="6"></label>';
    } else {
        echo '<div class="kv"><div>Kullanıcı adı</div><div>@' . e((string)$u['username']) . '</div></div>';
    }
    echo '<label>Ad Soyad *'
        . '<input name="full_name" required value="' . e((string)($u['full_name'] ?? '')) . '"'
        . ' placeholder="Mehmet Yılmaz"></label>';
    echo '<label>Telefon (isteğe bağlı)'
        . '<input type="tel" name="phone" inputmode="tel" value="' . e((string)($u['phone'] ?? '')) . '"'
        . ' placeholder="05xx xxx xx xx"></label>';
    echo '</div>';

    echo '<div class="card">';
    echo '<label>Rol'
        . '<select name="role" required>'
        . '<option value="personel"' . ($rol === 'personel' ? ' selected' : '') . '>Personel</option>'
        . '<option value="yonetici"' . ($rol === 'yonetici' ? ' selected' : '') . '>Yönetici</option>'
        . '</select></label>';
    echo '<p class="muted small">Personel: sipariş, müşteri, foto ve ödeme görebilir. '
        . 'Yönetici: raporlar, kasa, gider ve personel yönetimi dahil her şey.</p>';

    if ($yeni) {
        echo '<label class="check"><input type="checkbox" name="active" value="1" checked> Aktif</label>';
    } elseif ($ben) {
        echo '<p class="muted small">Kendi hesabınızı pasifleştiremez ve rolünüzü düşüremezsiniz.</p>';
    } else {
        echo '<label class="check"><input type="checkbox" name="active" value="1"'
            . ($aktif ? ' checked' : '') . '> Aktif (pasifse giriş yapamaz)</label>';
    }
    echo '</div>';

    // ======================================================== İNCE AYARLI YETKİLER
    // Yönetici rolü daima tam yetkilidir; izin atama yalnız PERSONEL rolüne
    // uygulanır (aksi halde yanlışlıkla kendini kilitleme riski).
    if ($rol !== 'yonetici') {
        $secili = kullanici_izinleri($u ?: null);
        $varsayilan = rol_varsayilan_izinler();
        $ozel = $yeni ? true : (trim((string)($u['permissions'] ?? '')) !== '');

        echo '<div class="card"><h2>Yetkiler</h2>';
        echo '<p class="small muted">Bu elemanın ne yapabileceğini tek tek seç. '
            . 'Kutu işaretli değilse o işlemi <b>yapamaz</b> — ekranı göremez, '
            . 'kayıt da gönderemez. Yönetici her zaman her şeyi yapar.</p>';

        echo '<div class="btnrow" style="margin-bottom:10px">'
            . '<button type="button" class="btn btn-sm" onclick="mimYetkiTum(1)">Tümünü işaretle</button>'
            . '<button type="button" class="btn btn-sm" onclick="mimYetkiTum(0)">Tümünü kaldır</button>'
            . '<button type="button" class="btn btn-sm" onclick="mimYetkiTum(-1)">Varsayılana dön</button>'
            . '</div>';

        foreach (izin_katalogu() as $grupAdi => $grup) {
            echo '<h3 class="yetki-grup">' . e($grupAdi) . '</h3><div class="yetki-liste">';
            foreach ($grup as $kod => $lbl) {
                echo '<label class="check"><input type="checkbox" name="perm[]" value="' . e($kod) . '"'
                    . ' data-varsayilan="' . (in_array($kod, $varsayilan, true) ? '1' : '0') . '"'
                    . (in_array($kod, $secili, true) ? ' checked' : '') . '> '
                    . e($lbl) . ' <small class="muted">(' . e($kod) . ')</small></label>';
            }
            echo '</div>';
        }

        if ($yeni) {
            echo '<p class="small muted">Hiçbir şeyi işaretlemezsen eleman <b>hiçbir ekrana giremez</b>. '
                . 'Personel rolünün varsayılanı: ' . count($varsayilan) . ' izin.</p>';
        } elseif (!$ozel) {
            echo '<p class="small muted">⚠ Bu eleman henüz <b>özel yetki seçilmemiş</b>; '
                . 'şu an rol varsayılanıyla çalışıyor (' . count($varsayilan) . ' izin).</p>';
        }
        echo '</div>';
    } else {
        echo '<div class="card"><div class="alert ok">Yönetici rolü tüm yetkilere sahiptir — '
            . 'yetki seçimi yalnız <b>Personel</b> rolüne uygulanır.</div></div>';
    }

    if (!$yeni) {
        echo '<div class="card">'
            . '<label>Yeni şifre <span class="muted small">(boş bırakılırsa değişmez)</span>'
            . '<input type="password" name="sifre1" autocomplete="new-password" minlength="6"'
            . ' placeholder="••••••"></label>'
            . '<label>Yeni şifre tekrar'
            . '<input type="password" name="sifre2" autocomplete="new-password" minlength="6"></label>'
            . '</div>';
    }

    echo '<button class="btn btn-primary btn-block">' . ($yeni ? '👷 Personeli Oluştur' : '💾 Değişiklikleri Kaydet')
        . '</button>';
    echo '</form>';
    echo '<a class="btn btn-ghost btn-block mt8" href="?p=personel">← Vazgeç</a>';

    // Yetki kutuları için kısayollar: 1 = tümünü işaretle, 0 = kaldır,
    // -1 = personel rolünün varsayılanına dön (hazır "kidemli" profil).
    echo '<script>
function mimYetkiTum(mod) {
  var sel = mod === -1 ? "input[data-varsayilan=\'1\']" : "input[type=checkbox][name=\'perm[]\']";
  var kutular = document.querySelectorAll(sel);
  for (var i = 0; i < kutular.length; i++) kutular[i].checked = (mod === 1);
}
</script>';

    layout_foot('personel');
}

/** POST: doğrulama + kayıt */
function saha_personel_kaydet_post(): void
{
    saha_yonetici_mi();
    csrf_check();   // doğrudan çağrılsa bile korumalı

    $db = db();
    $id = (int)($_POST['id'] ?? 0);
    $hata = [];

    $fullName = trim((string)($_POST['full_name'] ?? $_POST['ad'] ?? ''));
    $phone    = trim((string)($_POST['phone'] ?? ''));
    $role     = (string)($_POST['role'] ?? $_POST['rol'] ?? 'personel');
    $s1       = (string)($_POST['sifre1'] ?? '');
    $s2       = (string)($_POST['sifre2'] ?? '');
    // YENİ kullanıcı, "active" alanı gönderilmese bile AKTİF açılır.
    // (Düzenlemede alan yoksa mevcut durum korunur; aşağıda ayrıca ele alınır.)
    $active   = array_key_exists('active', $_POST) ? (int)(bool)$_POST['active'] : ($id > 0 ? 1 : 1);

    if ($fullName === '')              $hata[] = 'Ad soyad zorunludur.';
    if (!in_array($role, ['yonetici', 'personel'], true)) $hata[] = 'Geçersiz rol.';
    if ($s1 !== '' || $s2 !== '') {
        if (strlen($s1) < 6)           $hata[] = 'Şifre en az 6 karakter olmalıdır.';
        elseif ($s1 !== $s2)           $hata[] = 'Şifreler eşleşmiyor.';
    }

    // ---------------------------------------------------------------- YENİ
    if ($id === 0) {
        $username = strtolower(trim((string)($_POST['username'] ?? $_POST['kullanici'] ?? '')));
        if (!preg_match('/^[a-z0-9_]{3,32}$/', $username)) {
            $hata[] = 'Kullanıcı adı 3–32 karakter olmalı; sadece küçük harf, rakam ve alt çizgi kullanın.';
        } else {
            $q = $db->prepare('SELECT id FROM users WHERE username=?');
            $q->execute([$username]);
            if ($q->fetchColumn()) $hata[] = 'Bu kullanıcı adı zaten alınmış.';
        }
        if ($s1 === '') $hata[] = 'Şifre zorunludur.';

        if ($hata) {
            $_SESSION['flash_hata'] = $hata;
            redirect('personel-kaydet');
        }

        $st = $db->prepare('INSERT INTO users(username, pass_hash, full_name, role, phone, active, created_at, permissions)
            VALUES(?,?,?,?,?,?,?,?)');
        // Yeni personelde perm[] gönderilmemişse (ör. form yeni eklendiğinde
        // kutular işaretli kalmışsa) rol varsayılanı uygulanır — "hiçbir yetki"
        // kasıtlıysa kullanıcı kutuları kaldırıp yine de gönderebilir, ama
        // varsayılan güvenli taraftır: eski davranış korunur.
        $gelen = array_key_exists('perm', $_POST) ? (array)$_POST['perm'] : rol_varsayilan_izinler();
        $izinler = $role === 'yonetici' ? ''
            : (string)json_encode(array_values(array_intersect($gelen, izin_listesi())), JSON_UNESCAPED_UNICODE);
        $st->execute([$username, password_hash($s1, PASSWORD_DEFAULT), $fullName, $role, $phone, $active, now_dt(), $izinler]);
        $yeniId = (int)$db->lastInsertId();
        audit('personel_ekle', 'users', $yeniId, $username);
        $yetkiSay = $role === 'yonetici' ? 'yönetici (tüm yetkiler)'
            : count(array_intersect((array)($_POST['perm'] ?? []), izin_listesi())) . ' yetki';
        $_SESSION['flash'] = '✅ <b>' . e($fullName) . '</b> eklendi. Kullanıcı adı: @' . e($username)
            . ' · Yetki: ' . e($yetkiSay) . '.';
        redirect('personel');
    }

    // -------------------------------------------------------------- DÜZENLE
    $st = $db->prepare('SELECT * FROM users WHERE id=?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) {
        $_SESSION['flash_hata'] = ['Kullanıcı bulunamadı.'];
        redirect('personel');
    }

    $eskiAktif = (int)$u['active'];
    $eskiRol   = (string)$u['role'];

    // kendini pasifleştirme / rolünü düşürme kilidi
    if ($id === uid()) {
        // yönetici kendi rolünü düşüremez (kendini kilitler) — bildirilmiş değişiklik varsa hata ver
        if ($role !== $eskiRol) {
            $hata[] = 'Kendi rolünüzü değiştiremezsiniz.';
        }
        // pasifleştirme denemesi: sessizce yok sayılır, hataya düşürülmez
        $active = $eskiAktif;
    }

    // son yönetciyi koru
    if ($eskiRol === 'yonetici') {
        $q = $db->prepare("SELECT COUNT(*) FROM users WHERE role='yonetici' AND active=1");
        $q->execute();
        $yon = (int)$q->fetchColumn();
        if ($yon <= 1 && ($active === 0 || $role !== 'yonetici')) {
            $hata[] = 'Sistemdeki son yöneticiyi pasifleştiremez veya rolünü düşüremezsiniz.';
            $active = $eskiAktif;
            $role   = $eskiRol;
        }
    }

    if ($hata) {
        $_SESSION['flash_hata'] = $hata;
        redirect('personel-kaydet', 'id=' . $id);
    }

    $degisen = [];
    if ($fullName !== (string)$u['full_name']) $degisen[] = 'ad';
    if ($phone    !== (string)$u['phone'])    $degisen[] = 'telefon';
    if ($role     !== $eskiRol)               $degisen[] = 'rol (' . $eskiRol . '→' . $role . ')';
    if ($active   !== $eskiAktif)             $degisen[] = $active ? 'aktifleştirildi' : 'pasifleştirildi';
    $sifreYeni = ($s1 !== '' && $s1 === $s2);
    if ($sifreYeni) $degisen[] = 'şifre değişti';

    // İzinler yalnız PERSONEL rolünde anlamlı; yöneticiye yazılmaz.
    if ($role !== 'yonetici' && array_key_exists('perm', $_POST)) {
        $yeniIzin = array_values(array_intersect((array)$_POST['perm'], izin_listesi()));
        izinlari_kaydet($id, $yeniIzin);
        $degisen[] = count($yeniIzin) . ' yetki';
    }

    if ($sifreYeni) {
        $st = $db->prepare('UPDATE users SET full_name=?, role=?, phone=?, active=?, pass_hash=? WHERE id=?');
        $st->execute([$fullName, $role, $phone, $active, password_hash($s1, PASSWORD_DEFAULT), $id]);
    } else {
        $st = $db->prepare('UPDATE users SET full_name=?, role=?, phone=?, active=? WHERE id=?');
        $st->execute([$fullName, $role, $phone, $active, $id]);
    }

    audit('personel_guncelle', 'users', $id, $degisen ? implode(', ', $degisen) : 'değişiklik yok');
    $_SESSION['flash'] = $degisen
        ? '✅ <b>' . e($fullName) . '</b> güncellendi: ' . e(implode(' · ', $degisen)) . '.'
        : '✅ Değişiklik yok.';
    redirect('personel');
}