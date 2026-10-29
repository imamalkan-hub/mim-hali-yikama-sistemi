<?php
/**
 * Mim Halı Yıkama — İşletme Sistemi v2 — giriş noktası / yönlendirici
 * PHP 7.4+ · SQLite · bağımlılık yok
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/wa.php';
require __DIR__ . '/foto.php';
require __DIR__ . '/api.php';
require __DIR__ . '/mod/panel.php';
require __DIR__ . '/mod/musteri.php';
require __DIR__ . '/mod/siparis.php';
require __DIR__ . '/mod/listeler.php';
require __DIR__ . '/mod/saha.php';
require __DIR__ . '/mod/kasa.php';
require __DIR__ . '/mod/gelir.php';
require __DIR__ . '/mod/takip.php';
require __DIR__ . '/mod/rapor.php';
require __DIR__ . '/mod/yedekleme.php';
require __DIR__ . '/mod/toplu.php';
require __DIR__ . '/mod/talep.php';
require __DIR__ . '/mod/rota.php';
require __DIR__ . '/mod/anket.php';
require __DIR__ . '/mod/apk.php';
require __DIR__ . '/mod/hatirlat.php';
require __DIR__ . '/mod/tarama.php';
db();

$p = $_GET['p'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------- ilk kurulum
if (!setup_done()) {
    if ($method === 'POST' && $p === 'kurulum') {
        csrf_check();
        $k = trim((string)($_POST['kullanici'] ?? 'admin')) ?: 'admin';
        $p1 = (string)($_POST['sifre1'] ?? '');
        if (strlen($p1) >= 6 && $p1 === ($_POST['sifre2'] ?? '')) {
            $st = db()->prepare("INSERT INTO users(username,pass_hash,full_name,role,created_at)
                VALUES(?,?,?,?,?)");
            $st->execute([$k, password_hash($p1, PASSWORD_DEFAULT),
                trim((string)($_POST['ad'] ?? 'Yönetici')) ?: 'Yönetici', 'yonetici', now_dt()]);
            $uid = (int)db()->lastInsertId();
            // İşletme bilgileri kurulumda sorulur — dağıtım herkese açık olduğu
            // için tek bir işletmeye sabitlenmiş isim/telefon bırakılmaz.
            setting_set('isletme_adi', trim((string)($_POST['isletme_adi'] ?? '')) ?: 'Halı Yıkama İşletme Sistemi');
            setting_set('telefon', trim((string)($_POST['telefon'] ?? '')));
            setting_set('kurulum_ts', now_dt());
            setting_set('cron_token', bin2hex(random_bytes(16)));
            // sağlık kontrolü ve yedekleme cron'u bu anahtarlarla doğrulanır
            setting_set('health_token', bin2hex(random_bytes(16)));
            audit('kurulum', 'users', $uid, 'sistem kuruldu');
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            $_SESSION['uid'] = $uid;
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            redirect('');
        }
        login_page('Şifreler eşleşmiyor veya 6 karakterden kısa.', true);
        exit;
    }
    login_page('', true);
    exit;
}

// ---------------------------------------------------------------- giriş/çıkış
if ($p === 'login') {
    if ($method === 'POST') {
        csrf_check();
        if (try_login(trim((string)($_POST['kullanici'] ?? '')), (string)($_POST['sifre'] ?? ''))) redirect('');
        login_page('Kullanıcı adı veya şifre hatalı.');
        exit;
    }
    login_page();
    exit;
}
if ($p === 'logout') {
    audit('logout', 'users', uid(), 'çıkış');
    $_SESSION = []; session_destroy();
    redirect('login');
}

// ------------------------------------------- webhook / doğrulama (oturumsuz)
if ($p === 'wa-webhook') {
    $raw = (string)file_get_contents('php://input');
    if (!wa_verify_signature($raw, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''))) {
        app_log('webhook imza hatası');
        http_response_code(401); exit('imza doğrulanamadı');
    }
    wa_handle_webhook(json_decode($raw, true) ?: []);
    http_response_code(200); exit('ok');
}
if ($p === 'verify') {
    $want = (string)($_GET['code'] ?? $_GET['hub']['verify'] ?? $_GET['hub.challenge'] ?? '');
    if ($want === '') { http_response_code(400); exit('kod yok'); }
    header('Content-Type: text/plain');
    exit($want);
}

// ---------------------------------------------------------------- kuyruk tetikleyici (cron)
// ⚠ cPanel cron GET atar (curl). Bu yüzden uca BOTH yöntem açıktır; koruma
// anahtarda, yöntemde değil. Önceden yalnız POST kabul ediyordu ve kullanıcıya
// verilen cron komutu GET olduğu için yedekleme hiç çalışmıyordu.
if ($p === 'wa-cron') {
    $tok = (string)($_GET['token'] ?? '');
    $exp = setting('cron_token', '');
    if ($exp === '' || !hash_equals($exp, $tok)) {
        app_log('cron yetkisiz deneme');
        http_response_code(403); exit('geçersiz token');
    }
    header('Content-Type: application/json');
    echo json_encode(wa_process(50));
    exit;
}

// ---------------------------------------------------------------- yedekleme (cron)
// AYRI uç nokta: WhatsApp kuyruğu gecikirse/çökerse yedekleme ETKİLENMEZ.
// (İkisi tek uç noktada olsaydı, birinin hatası diğerini durdururdu.)
if ($p === 'yedek-cron') {
    $tok = (string)($_GET['token'] ?? '');
    $exp = setting('cron_token', '');
    if ($exp === '' || !hash_equals($exp, $tok)) {
        app_log('yedek cron yetkisiz deneme');
        http_response_code(403); exit('geçersiz token');
    }
    header('Content-Type: application/json');
    echo json_encode(yedek_al('cron'));
    exit;
}

// ---------------------------------------------------------------- sağlık kontrolü
// Yönetici girişi GEREKMEZ ama gizli anahtarla korunur: izleme araçları
// (uptime robot, cron hata denetleyicisi) erişebilsin, kimse başkası göremez.
if ($p === 'saglik') {
    $tok = (string)($_GET['token'] ?? '');
    $exp = setting('health_token', '');
    if ($exp === '' || !hash_equals($exp, $tok)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'hata' => 'geçersiz token']);
        exit;
    }
    $dbOk = false; $tablo = 0; $foto = 0; $hata = '';
    try {
        db()->query('SELECT 1')->fetchColumn();
        $dbOk = true;
        $tablo = count(db()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll());
        $foto = (int)db()->query('SELECT COUNT(*) FROM photos')->fetchColumn();
    } catch (Throwable $e) { $hata = $e->getMessage(); }
    $sd = yedek_saglik();
    $kuyruk = 0;
    try { $kuyruk = (int)db()->query("SELECT COUNT(*) FROM wa_queue WHERE status='bekliyor'")->fetchColumn(); } catch (Throwable $e) {}
    header('Content-Type: application/json');
    http_response_code($dbOk ? 200 : 500);
    echo json_encode([
        'ok' => $dbOk,
        'zaman' => date('c'),
        'surum' => APP_VERSION,
        'veritabani' => $dbOk ? 'bağlı (' . $tablo . ' tablo)' : 'HATA: ' . $hata,
        'foto_kayit' => $foto,
        'wa_kuyruk' => $kuyruk,
        'yedek' => $sd['mesaj'],
        'yedek_ok' => $sd['ok'],
        'dis_kayit' => (int)@filesize(dbfile()),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ---------------------------------------------------------------- müşteri takip
// KAMUYA ACIK: login gerektirmez — kod bilen müşteri salt-okunur durum görür.
// Giriş kapısından ÖNCE yakalanır (mod/takip.php).
if ($p === 'takip') {
    takip_sayfa();
    exit;
}

// ---------------------------------------------------------------- web talepleri
// KAMUYA AÇIK: site ziyaretçisi alım talebi bırakır. Oturum yok, CSRF + kota var.
if ($p === 'talep') {
    talep_sayfa();
    exit;
}
// KAMUYA AÇIK: teslim sonrası memnuniyet anketi — yalnız tek kullanımlık token.
if ($p === 'anket') {
    anket_sayfa();
    exit;
}

// ---------------------------------------------------------------- JSON API (mobil APK)
// Giriş kapısından ÖNCE: kimlik jetonla (Authorization: Bearer) yapılır.
// Mobil uygulama HTML değil JSON bekler; ayrı yönlendirici, aynı yetki modeli.
if ($p === 'api') {
    api_yonlendir();
    exit;
}

// ---------------------------------------------------------------- APK indirme
// Panelden (oturumla) indirilebilir. Mobil uygulama kendini güncellerken
// jetonla da erişebilsin diye Authorization: Bearer da kabul edilir.
// DİKKAT: jeton yoksa da akış require_login()'e düşmeli — APK dosyası
// girişsiz indirilemez.
if ($p === 'apk-indir') {
    $jetonVar = api_token_al() !== '';
    if ($jetonVar) {
        $u = api_token_user(api_token_al());
        if (!$u) { http_response_code(401); exit('yetkisiz'); }
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['ok'] = true;
    }
    // Oturum da yoksa → giriş sayfasına yönlendir (require_login ile aynı).
    if (empty($_SESSION['ok'])) {
        header('Location: ?p=login');
        exit;
    }
    apk_indir();
    exit;
}

require_login();

// ================================================================ YÖNLENDİRİCİ
$routes = [
    ''               => 'dashboard',
    'panel'          => 'dashboard',
    'musteriler'     => 'musteriler',
    'musteri'        => 'musteri_kart',
    'musteri-form'   => 'musteri_form_get',
    'musteri-kaydet' => 'musteri_kaydet',
    'siparis-yeni'   => 'siparis_yeni',
    'siparis'        => 'siparis_kart',
    'siparis-durum'  => 'siparis_durum',
    'siparis-toplu'  => 'siparis_toplu',
    'siparis-tarih'  => 'siparis_tarih',
    'siparis-tarih-form' => 'siparis_tarih_form',
    'siparis-not'    => 'siparis_not',
    'siparis-atama'  => 'siparis_atama',
    'odeme'          => 'odeme_ekle',
    'odeme-sil'      => 'odeme_sil',
    'etiket'         => 'etiket',
    'qr'             => 'qr_kod',
    'fis'            => 'fis',
    'siparisler'     => 'siparisler',
    'halitakip'      => 'hali_takip',
    'item-kod'       => 'item_kod',
    'alim'           => 'alim_listesi',
    'servis'         => 'alim_listesi',
    'teslimat'       => 'teslimat_listesi',
    'gecikmis'       => 'gecikmis_listesi',
    'kasa'           => 'kasa',
    'gelir'          => 'gelir_sayfa',
    'gelir-sil'      => 'gelir_sil',
    'gider'          => 'gider_sayfa',
    'gider-sil'      => 'gider_sil',
    'gider-onay'     => 'gider_onay_sayfa',
    'raporlar'       => 'raporlar',
    'wa'             => 'wa_panel',
    'bildirimler'    => 'wa_panel',
    'wa-gonder'      => 'wa_gonder',
    'benim'          => 'benim',
    'personel'       => 'personel_sayfa',
    'personel-kaydet'=> 'personel_kaydet',
    'apk'            => 'apk_sayfa',
    'apk-indir'      => 'apk_indir',
    'ayarlar'        => 'ayarlar',
    'yedek'          => 'yedek',
    'foto'           => 'foto_stream',
    'foto-sil'       => 'foto_sil_eylem',
    'yedek-durum'    => 'yedek_durum',
    // v3 — web talebi, rota, anket, hatırlatma, QR okutma
    'talepler'       => 'talepler_sayfa',
    'rota'           => 'rota_sayfa',
    'anketler'       => 'anketler_sayfa',
    'hatirlat'       => 'hatirlat_sayfa',
    'tarama'         => 'tarama_sayfa',
];

if (!isset($routes[$p])) {
    http_response_code(404);
    layout_head('Bulunamadı');
    echo '<div class="card"><p>Böyle bir sayfa yok.</p><a class="btn" href="?p=">Panele dön</a></div>';
    layout_foot();
    exit;
}

// foto: giriş zaten yapıldı, doğrudan çıktı üretir ve sonlanır
if ($p === 'foto') {
    $fid = (int)($_GET['id'] ?? 0);
    if (($_GET['t'] ?? '') === 'thumb') photo_thumb($fid); else photo_stream($fid);
    exit;
}

$fn = $routes[$p];
$fn();

// ================================================================ GİRİŞ / İSKELET

function setup_done(): bool
{
    try {
        return (int)db()->query('SELECT COUNT(*) c FROM users WHERE active=1')->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

function login_page(string $err = '', bool $first = false): void
{
    $ad = setting('isletme_adi', 'Mim Halı Yıkama');
    ?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#10243A">
<title>Giriş — <?= e($ad) ?></title><link rel="stylesheet" href="style.css"></head>
<body class="login-body"><div class="login-box">
<div class="login-logo">🧼</div>
<h1><?= e($ad) ?></h1>
<p class="muted"><?= $first ? 'İlk kurulum' : 'İşletme yönetim sistemi' ?></p>
<?php if ($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>
<form method="post" action="?p=<?= $first ? 'kurulum' : 'login' ?>">
<?= csrf_field() ?>
<?php if ($first): ?>
<label>Kullanıcı adı<input name="kullanici" value="admin" required autocomplete="username" autocapitalize="none"></label>
<label>Ad Soyad<input name="ad" placeholder="Yönetici"></label>
<label>İşletme adı<input name="isletme_adi" placeholder="Örn. Şanlıurfa Halı Yıkama" required></label>
<label>Telefon (isteğe bağlı)<input name="telefon" inputmode="tel" placeholder="0XXX XXX XX XX"></label>
<label>Şifre (en az 6 karakter)<input type="password" name="sifre1" required autocomplete="new-password"></label>
<label>Şifre (tekrar)<input type="password" name="sifre2" required autocomplete="new-password"></label>
<button class="btn btn-primary btn-block">Kurulumu Bitir</button>
<?php else: ?>
<label>Kullanıcı adı<input name="kullanici" required autofocus autocomplete="username" autocapitalize="none"></label>
<label>Şifre<input type="password" name="sifre" required autocomplete="current-password"></label>
<button class="btn btn-primary btn-block">Giriş Yap</button>
<?php endif; ?>
</form>
<p class="muted small" style="margin-top:14px">Sürüm <?= APP_VERSION ?></p>
<?= kamu_linki() ?>
</div></body></html><?php
}

/**
 * Kamuya açık site bağlantısı (footer).
 *
 * Giriş ekranındaki ziyaretçiler işletmenin kamuya açık sitesine
 * yönlendirilir. Bağlantı **sabit** — ayarlardan değiştirilemez.
 */
function kamu_linki(): string
{
    $site = 'https://mimhaliyikama.com/';
    return '<footer class="kamu-footer">'
        . '<a href="' . e($site) . '" target="_blank" rel="noopener">🌐 ' . e($site) . '</a>'
        . '</footer>';
}

/** Menü — tekrarlar önlenerek birleştirildi; ilk 5 alt navigasyonda */
function menu_items(): array
{
    return [
        ''           => ['🏠', 'Panel'],
        'siparis-yeni'=> ['➕', 'Sipariş'],
        'alim'        => ['🚐', 'Alım'],
        'teslimat'    => ['📦', 'Teslimat'],
        'gecikmis'    => ['⏰', 'Gecikmiş'],
    ];
}
function menu_more(): array
{
    return [
        'siparisler'  => ['📋', 'Siparişler'],
        'talepler'    => ['📥', 'Web Talepleri'],
        'rota'        => ['🗺', 'Rota'],
        'halitakip'   => ['🧶', 'Halı Takibi'],
        'tarama'      => ['📷', 'QR Okut'],
        'musteriler'  => ['👥', 'Müşteriler'],
        'kasa'        => ['💰', 'Kasa'],
        'raporlar'    => ['📊', 'Raporlar'],
        'anketler'    => ['⭐', 'Memnuniyet'],
        'hatirlat'    => ['🔁', 'Hatırlatma'],
        'wa'          => ['📲', 'Bildirimler'],
        'benim'       => ['📍', 'Bana Atananlar'],
        'personel'    => ['👷', 'Personel'],
        'gelir'       => ['📈', 'Gelirler'],
        'gider'       => ['📉', 'Giderler'],
        'gider-onay'  => ['⏳', 'Gider Onay'],
        'apk'         => ['📱', 'Mobil Uygulama'],
        'ayarlar'     => ['⚙', 'Ayarlar'],
    ];
}

function layout_head(string $title, string $active = ''): void
{
    $ad = setting('isletme_adi', 'Mim Halı Yıkama');
    $u = me();
    $wafail = 0;
    try {
        $wafail = (int)db()->query("SELECT COUNT(*) c FROM wa_queue WHERE status='hata'")->fetchColumn();
    } catch (Throwable $e) {}
    $month = false;
    if (function_exists('intl_date_format')) {
        $month = strtolower(intl_date_format(new DateTime('now'), 'MMMM')) !== 'august';
    }
    ?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<script>(function(){try{var t=localStorage.getItem('mimTema');if(t)document.documentElement.dataset.theme=t;}catch(e){}})();</script>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#10243A">
<title><?= e($title) ?> — <?= e($ad) ?></title>
<link rel="stylesheet" href="style.css">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="icon.svg" type="image/svg+xml">
</head><body>
<header class="topbar"><div class="topbar-in">
<span class="brand"><?= e($ad) ?></span>
<nav class="top-menu">
<a href="?p=raporlar">📊</a>
<a href="?p=benim">📍</a>
<a href="?p=wa">📲<?= $wafail ? '<b class="dot">' . $wafail . '</b>' : '' ?></a>
<button type="button" id="temaDugme" class="tema-dugme" aria-label="Koyu / açık mod">🌙</button>
<a href="?p=logout" title="Çıkış">↩</a>
</nav>
</div></header>
<main class="main">
<h1 class="page-title"><?= e($title) ?></h1>
<p class="whoami"><?= e($u['full_name'] ?: $u['username']) ?>
 <span class="badge" style="background:<?= is_admin() ? '#10243A' : '#16A6A1' ?>"><?= is_admin() ? 'Yönetici' : 'Personel' ?></span></p>
<?php
}

function layout_foot(string $active = ''): void
{
    $menu = menu_items();
    $more = menu_more();
    // Menü gizleme YETKİYE göre: yönetici olmak değil, ilgili izne sahip olmak.
    // Personel artık gider.ekle / personel.yonet izni verilebiliyor.
    if (!can('personel.yonet')) unset($more['personel']);
    if (!can('gelir.ekle'))    unset($more['gelir']);
    if (!can('gider.ekle'))    unset($more['gider']);
    if (!can('gider.onayla'))  unset($more['gider-onay']);
    if (!can('ayar.yonet'))    unset($more['ayarlar']);
    if (!can('talep.gor'))     unset($more['talepler']);
    if (!can('rota.gor'))      unset($more['rota']);
    if (!can('anket.gor'))     unset($more['anketler']);
    if (!can('kampanya.yonet')) unset($more['hatirlat']);
    ?><nav class="bottomnav"><?php
    foreach ($menu as $pp => $it) {
        echo '<a class="bn' . ($pp === $active ? ' on' : '') . '" href="?p=' . $pp . '">'
            . '<span>' . $it[0] . '</span>' . e($it[1]) . '</a>';
    }
    ?><a class="bn<?= isset($more[$active]) ? ' on' : '' ?>" href="?p=<?= array_key_first($more) ?>"
        onclick="mimSheet(event,'moreMenu')"><span>⋯</span>Daha fazla</a>
</nav>
<dialog id="moreMenu" class="sheet">
<div class="sheet-in">
<div class="sheet-head"><b>Menü</b><button type="button" class="btn btn-sm" data-close>✕</button></div>
<?php foreach ($more as $pp => $it): ?>
<a class="sheet-row<?= $pp === $active ? ' on' : '' ?>" href="?p=<?= $pp ?>"><span><?= $it[0] ?></span><?= e($it[1]) ?></a>
<?php endforeach; ?>
<a class="sheet-row" href="?p=logout"><span>↩</span>Çıkış</a>
</div></dialog>
<script src="app.js"></script>
</main></body></html><?php
}

/** Müşteri iletişim satırı (tel + WhatsApp) */
function customer_line(array $c): string
{
    $out = '';
    foreach (['phone', 'phone2'] as $k) {
        $raw = (string)($c[$k] ?? '');
        if ($raw === '') continue;
        $out .= '<a href="' . e(tel_href($raw)) . '">' . e($raw) . '</a>';
        $w = wa_phone($raw);
        if ($w) $out .= ' <a class="wa-link" href="https://wa.me/' . e($w) . '" target="_blank" rel="noopener">WA</a>';
        $out .= ' · ';
    }
    return rtrim($out, ' ·');
}

function map_link(?string $address, string $district = ''): string
{
    $q = trim(($district !== '' ? $district . ', ' : '') . (string)$address);
    if ($q === '') return '';
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q);
}

/** Saha kısayolları: ara / WhatsApp / harita */
function nav_quick(array $c): string
{
    $out = '';
    $p1 = (string)($c['phone'] ?? '');
    if ($p1 !== '') $out .= '<a class="btn btn-sm" href="' . e(tel_href($p1)) . '">📞 Ara</a> ';
    $w = wa_phone($p1);
    if ($w) $out .= '<a class="btn btn-sm btn-wa" href="https://wa.me/' . e($w) . '" target="_blank" rel="noopener">💬 WhatsApp</a> ';
    $ml = map_link((string)($c['address'] ?? ''), (string)($c['district'] ?? ''));
    if ($ml) $out .= '<a class="btn btn-sm" target="_blank" rel="noopener" href="' . e($ml) . '">🗺 Harita</a>';
    return $out;
}

/** Gösterge kartı */
function kpi(string $val, string $lbl, string $href = '', string $cls = ''): void
{
    $tag = $href !== '' ? 'a' : 'div';
    echo '<' . $tag . ($href !== '' ? ' href="' . e($href) . '"' : '') . ' class="stat ' . e($cls) . '">'
        . '<b>' . $val . '</b><span>' . e($lbl) . '</span></' . $tag . '>';
}

/** Boş durum ekranı */
function empty_state(string $icon, string $msg, string $href = '', string $btn = ''): void
{
    echo '<div class="empty"><div class="empty-i">' . $icon . '</div><p>' . e($msg) . '</p>';
    if ($href !== '') echo '<a class="btn btn-primary" href="' . e($href) . '">' . e($btn) . '</a>';
    echo '</div>';
}

/** Filtre çipleri */
function chips(string $page, array $defs, string $current, string $param = 'durum'): void
{
    echo '<div class="chips">';
    foreach ($defs as $k => $lbl) {
        $on = (string)$k === $current ? ' on' : '';
        $u = $k === '' ? '?p=' . $page : ('?p=' . $page . '&' . $param . '=' . urlencode((string)$k));
        echo '<a class="chip' . $on . '" href="' . e($u) . '">' . e($lbl) . '</a>';
    }
    echo '</div>';
}

/** Durum değişikliği butonları (izinli akış + geri alma) */
function status_actions(array $o): void
{
    $oid = (int)$o['id'];
    $st = (string)$o['status'];
    if (is_closed($st)) { echo '<p class="muted small">Bu sipariş kapanmış.</p>'; return; }

    $next = allowed_next($st);
    foreach ($next as $nsk) {
        $lbl = status_labels()[$nsk];
        if ($nsk === 'teslim') $lbl = '✅ Teslim Edildi';
        elseif ($nsk === 'dagitimda') $lbl = '🚚 Dağıtıma Çıkar';
        elseif ($nsk === 'teslim_alindi') $lbl = '📦 Halılar Teslim Alındı';
        elseif ($nsk === 'yikamada') $lbl = '🧼 Yıkamaya Al';
        elseif ($nsk === 'yikama_bekliyor') $lbl = '🧼 Yıkamaya Beklet';
        elseif ($nsk === 'kurutuluyor') $lbl = '💨 Kurutmaya Al';
        elseif ($nsk === 'kalite_kontrol') $lbl = '🔍 Kalite Kontrol';
        elseif ($nsk === 'paketlendi') $lbl = '📋 Paketlendi / Hazır';
        echo '<form method="post" action="?p=siparis-durum">' . csrf_field()
            . '<input type="hidden" name="id" value="' . $oid . '">'
            . '<input type="hidden" name="durum" value="' . e($nsk) . '">'
            . '<button class="btn btn-primary btn-block">' . e($lbl) . '</button></form>';
    }

    // geri alma
    $prev = array_search($st, status_flow(), true);
    if ($prev !== false) {
        echo '<form method="post" action="?p=siparis-durum" class="mt8">' . csrf_field()
            . '<input type="hidden" name="id" value="' . $oid . '">'
            . '<input type="hidden" name="durum" value="' . e((string)$prev) . '">'
            . '<button class="btn btn-ghost btn-sm">← Bir önceki duruma al</button></form>';
    }
    // istisna / iptal
    echo '<form method="post" action="?p=siparis-durum" class="mt8">' . csrf_field()
        . '<input type="hidden" name="id" value="' . $oid . '">'
        . '<input type="hidden" name="durum" value="iptal">'
        . '<button class="btn btn-danger btn-sm" onclick="return confirm(\'Sipariş iptal edilsin mi?\')">İptal</button></form>';
}

/** Adet uyumsuzluk uyarısı */
function count_warning(array $o): string
{
    $beklenen = (int)($o['received_count'] ?? 0);
    $teslim = (int)($o['returned_count'] ?? 0);
    if ($beklenen <= 0) return '';
    if ($teslim === 0 && in_array((string)$o['status'], ['teslim', 'dagitimda'], true)) {
        return '<div class="alert warn"><b>⚠ Teslim edilen halı adedi girilmemiş.</b> Kayıtlı: ' . $beklenen . ' adet.</div>';
    }
    if ($teslim > 0 && $teslim !== $beklenen) {
        return '<div class="alert danger"><b>⚠ Halı adedi uyuşmuyor!</b> Alınan: <b>' . $beklenen . '</b> · Teslim edilen: <b>'
            . $teslim . '</b> — fark ' . ($teslim - $beklenen) . ' adet.</div>';
    }
    return '';
}