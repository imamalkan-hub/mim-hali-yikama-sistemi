<?php
/**
 * JSON API — mobil uygulama (APK) için.
 *
 * TASARIM:
 *  - Oturum (session) DEĞİL, bearer token: "Authorization: Bearer <token>".
 *  - Token bir kullanıcıya bağlıdır; yetki kararları AYNI kanaldan (can(),
 *    me()) akar → ikinci bir yetki modeli doğmaz, mevcut izinler aynen geçerli.
 *  - Para birimi: TÜM alanlar _kurus (integer). Uygulama TL'ye çevirir.
 *  - Hata gövdesi: {"hata":"..."} ve uygun HTTP kodu. Şema değişmez.
 *
 * UÇLAR:
 *   POST /api/giris              → {token, user}
 *   GET  /api/ozet               → panel sayaçları
 *   GET  /api/siparisler         → liste (q, durum, sayfa)
 *   GET  /api/siparis/{id}       → detay + kalemler + fotoğraf sayısı
 *   POST /api/siparis            → yeni sipariş
 *   POST /api/siparis/{id}/durum → durum değiştir
 *   POST /api/siparis/{id}/foto  → fotoğraf yükle (multipart)
 *   GET  /api/saha?tur=alim|teslimat|gecikmis
 *   GET  /api/musteriler         → müşteri arama
 *   GET  /api/talepler           → web talepleri
 *   GET  /api/durumlar           → durum sözlüğü (etiket + renk)
 */
declare(strict_types=1);

/** API hata gövdesi + uygun kod. Çıktıktan sonra betik BİTER. */
function api_hata(string $mesaj, int $kod = 400): void
{
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['hata' => $mesaj], JSON_UNESCAPED_UNICODE);
    exit;
}

function api_ok(array $veri, int $kod = 200): void
{
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($veri, JSON_UNESCAPED_UNICODE);
    exit;
}

/** JSON gövdeyi oku (bozsa boş dizi). */
function api_govde(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/** Authorization başlığından bearer token. */
function api_token_al(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach ((array)apache_request_headers() as $k => $v) {
            if (strtolower((string)$k) === 'authorization') { $h = (string)$v; break; }
        }
    }
    if (preg_match('/Bearer\s+([A-Za-z0-9_\-]{16,})/i', (string)$h, $m)) return $m[1];
    return '';
}

/**
 * Kimlik doğrulama. Token geçerliyse kullanıcıyı session'a bağlar; böylece
 * me()/can()/uid() aynen çalışır ve izin modeli tek kalır.
 * @return array kullanıcı satırı
 */
function api_giris_yap(): array
{
    $token = api_token_al();
    if ($token === '') api_hata('Yetkilendirme gerekli (Authorization: Bearer ...).', 401);
    $u = api_token_user($token);
    if (!$u) api_hata('Jeton geçersiz veya kullanıcı pasif.', 401);
    // session'a bağla → me()/can() çalışsın
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['ok'] = true;
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    api_token_dogusta($token);
    return $u;
}

/** Yetki kontrolü (JSON uçları için). */
function api_yetki(string $izin): void
{
    if (!can($izin)) api_hata('Bu işlem için yetkiniz yok.', 403);
}

/** Sipariş satırını API biçimine çevirir. */
function api_siparis_satir(array $o): array
{
    // Ödenen: payments tablosundan (orders'ta paid_kurus kolonu YOK).
    $odendi = 0;
    if (!empty($o['_odendi_kurus'])) $odendi = (int)$o['_odendi_kurus'];
    return [
        'id'         => (int)$o['id'],
        'kod'        => (string)$o['code'],
        'durum'      => (string)$o['status'],
        'durum_etiket' => (string)(status_labels()[$o['status']] ?? $o['status']),
        'durum_renk' => (string)(status_colors()[$o['status']] ?? '#64748b'),
        'musteri_id' => (int)$o['customer_id'],
        'musteri'    => (string)($o['cname'] ?? ''),
        'telefon'    => (string)($o['phone'] ?? ''),
        'telefon2'   => (string)($o['phone2'] ?? ''),
        'ilce'       => (string)($o['cdistrict'] ?? ''),
        'adres'      => (string)($o['address'] ?? ''),
        'alim_turu'  => (string)$o['pickup_type'],
        'alim_tarih' => (string)$o['pickup_date'],
        'teslim_tarih' => (string)$o['delivery_date'],
        'toplam_kurus' => (int)$o['total_kurus'],
        'indirim_kurus' => (int)($o['discount_kurus'] ?? 0),
        'odendi_kurus' => $odendi,
        'kalan_kurus'  => (int)max(0, (int)$o['total_kurus'] - $odendi),
        'parca_adedi' => (int)($o['item_qty'] ?? 0),
        'kalem_adedi' => (int)($o['item_adet'] ?? 0),
        'not'        => (string)($o['note'] ?? ''),
        'personel'   => (string)($o['staff'] ?? ''),
        'olusturma'  => (string)$o['created_at'],
        'gecikti'    => !empty($o['_gecikti']) ? 1 : 0,
    ];
}

/** Sipariş listesi için temel SELECT. */
function api_siparis_sql(): string
{
    return "SELECT o.*, c.name cname, c.phone, c.phone2, c.district cdistrict,
        c.address address,
        (SELECT COUNT(*) FROM order_items i WHERE i.order_id=o.id) item_adet,
        (SELECT COALESCE(SUM(i.qty),0) FROM order_items i WHERE i.order_id=o.id) item_qty,
        (SELECT COALESCE(SUM(p.amount_kurus),0) FROM payments p WHERE p.order_id=o.id) _odendi_kurus
        FROM orders o JOIN customers c ON c.id=o.customer_id";
}

// ================================================================ GİRİŞ
function api_giris(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_hata('POST beklenir.', 405);
    $b = api_govde();
    $k = trim((string)($b['kullanici'] ?? ''));
    $s = (string)($b['sifre'] ?? '');
    if ($k === '' || $s === '') api_hata('Kullanıcı adı ve şifre gerekli.');
    $st = db()->prepare('SELECT * FROM users WHERE username=? AND active=1');
    $st->execute([$k]);
    $u = $st->fetch();
    if (!$u || !password_verify($s, (string)$u['pass_hash'])) {
        api_hata('Kullanıcı adı veya şifre hatalı.', 401);
    }
    // Eski session'ı temizle → oturum sabitleme saldırısı engellenir
    session_regenerate_id(true);
    $token = api_token_uret((int)$u['id'], 'mobil', (string)($b['platform'] ?? 'android'));
    audit('api_giris', 'users', (int)$u['id'], 'mobil jeton');
    api_ok([
        'token' => $token,
        'user'  => [
            'id'    => (int)$u['id'],
            'ad'    => (string)$u['full_name'],
            'rol'   => (string)$u['role'],
            'yonetici' => ((string)$u['role'] === 'yonetici'),
            'izinler' => array_values(array_filter((array)(json_decode((string)($u['permissions'] ?? '[]'), true) ?: []),
                fn($x) => is_string($x))),
        ],
        'isletme' => setting('isletme_adi', 'Mim Halı Yıkama'),
    ]);
}

// ================================================================ ÖZET
function api_ozet(): void
{
    api_giris_yap();
    api_yetki('siparis.gor');
    $c = dashboard_counts();
    api_ok([
        'alim_bugun'      => (int)$c['alim_bugun'],
        'teslim_bugun'    => (int)$c['teslim_bugun'],
        'teslim_edildi_bugun' => (int)$c['teslim_edildi_bugun'],
        'yikama_bekleyen' => (int)$c['yikama_bekleyen'],
        'islemde'         => (int)$c['islemde'],
        'hazir'           => (int)$c['hazir'],
        'dagitimda'       => (int)$c['dagitimda'],
        'gecikmis'        => (int)$c['gecikmis'],
        'kritik'          => (int)$c['kritik'],
        'ciro_kurus'      => (int)$c['ciro_bugun'],
        'tahsilat_kurus'  => (int)$c['tahsilat_bugun'],
        'gider_kurus'     => (int)$c['gider_bugun'],
        'acik_hesap_kurus' => (int)$c['acik_hesap'],
        'wa_hata'         => (int)$c['wa_hata'],
        'tarih'           => today(),
    ]);
}

// ================================================================ SİPARİŞ LİSTESİ
function api_siparisler(): void
{
    api_giris_yap();
    api_yetki('siparis.gor');
    $db = db();
    $q = trim((string)($_GET['q'] ?? ''));
    $durum = (string)($_GET['durum'] ?? '');
    $sayfa = max(1, (int)($_GET['sayfa'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));

    $sql = api_siparis_sql() . ' WHERE 1=1';
    $args = [];
    if ($durum === 'kapali') {
        $sql .= " AND o.status IN ('teslim','iptal','teslim_edilemedi')";
    } elseif ($durum !== '' && $durum !== 'tum') {
        $ist = array_values(array_filter(array_map('trim', explode(',', $durum)),
            fn($s) => isset(status_labels()[$s])));
        if (count($ist) === 1) { $sql .= ' AND o.status=?'; $args[] = $ist[0]; }
        elseif (count($ist) > 1) {
            $sql .= ' AND o.status IN (' . implode(',', array_fill(0, count($ist), '?')) . ')';
            foreach ($ist as $s) $args[] = $s;
        } else {
            $sql .= ' AND o.status IN ' . open_status_sql();
        }
    } else {
        $sql .= ' AND o.status IN ' . open_status_sql();
    }
    if ($q !== '') {
        $sql .= " AND (c.name LIKE ? ESCAPE '\\' OR c.phone LIKE ? ESCAPE '\\' OR c.phone2 LIKE ? ESCAPE '\\'
            OR o.code LIKE ? ESCAPE '\\' OR o.note LIKE ? ESCAPE '\\' OR o.staff LIKE ? ESCAPE '\\')";
        for ($i = 0; $i < 6; $i++) $args[] = liste_like($q);
    }
    $sql .= ' ORDER BY o.id DESC LIMIT ' . $limit . ' OFFSET ' . (($sayfa - 1) * $limit);

    $st = $db->prepare($sql);
    $st->execute($args);
    $liste = [];
    foreach ($st->fetchAll() as $o) {
        $d = delay_info($o);
        $o['_gecikti'] = !empty($d['late']);
        $liste[] = api_siparis_satir($o);
    }
    api_ok(['siparisler' => $liste, 'sayfa' => $sayfa]);
}

// ================================================================ SİPARİŞ DETAY
function api_siparis_detay(int $id): void
{
    api_giris_yap();
    api_yetki('siparis.gor');
    $st = db()->prepare(api_siparis_sql() . ' WHERE o.id=?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) api_hata('Sipariş yok.', 404);

    $items = [];
    $ist = db()->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');
    $ist->execute([$id]);
    foreach ($ist->fetchAll() as $i) {
        $items[] = [
            'id' => (int)$i['id'], 'kod' => (string)($i['item_code'] ?? ''),
            'tur' => (string)$i['type'], 'olcu' => (string)($i['descr'] ?? ''),
            'adet' => (int)$i['qty'], 'birim_kurus' => (int)$i['unit_kurus'],
            'tutar_kurus' => (int)$i['line_kurus'],
        ];
    }
    $odemeler = [];
    $ost = db()->prepare('SELECT * FROM payments WHERE order_id=? ORDER BY id DESC');
    $ost->execute([$id]);
    foreach ($ost->fetchAll() as $pm) {
        $odemeler[] = [
            'id' => (int)$pm['id'], 'tutar_kurus' => (int)$pm['amount_kurus'],
            'yontem' => (string)$pm['method'], 'not' => (string)($pm['note'] ?? ''),
            'tarih' => (string)$pm['created_at'],
        ];
    }
    $foto = [];
    foreach (photo_rows($id) as $p) {
        $foto[] = [
            'id' => (int)$p['id'], 'asama' => (string)$p['stage'],
            'asama_etiket' => (string)(PHOTO_STAGES[$p['stage']] ?? $p['stage']),
            'not' => (string)($p['note'] ?? ''), 'bayt' => (int)$p['bytes'],
            'tarih' => (string)$p['created_at'],
            'url' => '?p=foto&id=' . (int)$p['id'],
        ];
    }
    $d = delay_info($o);
    $satir = api_siparis_satir($o);
    $satir['gecikti'] = !empty($d['late']) ? 1 : 0;
    $satir['gecikme_gun'] = (int)($d['days'] ?? 0);
    $satir['odemeler'] = $odemeler;
    $satir['foto_sayi'] = count($foto);

    api_ok([
        'siparis' => $satir,
        'kalemler' => $items,
        'odemeler' => $odemeler,
        'foto' => $foto,
        'durumlar' => status_labels(),
        'sonraki_durum' => allowed_next((string)$o['status']),
    ]);
}

// ================================================================ YENİ SİPARİŞ
function api_siparis_olustur(): void
{
    api_giris_yap();
    api_yetki('siparis.olustur');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_hata('POST beklenir.', 405);
    $b = api_govde();

    $ad = trim((string)($b['musteri_ad'] ?? ''));
    $tel = trim((string)($b['telefon'] ?? ''));
    $ilce = trim((string)($b['ilce'] ?? ''));
    $adres = trim((string)($b['adres'] ?? ''));
    $musteriId = (int)($b['musteri_id'] ?? 0);

    if ($musteriId <= 0) {
        if ($ad === '') api_hata('Müşteri adı zorunlu.');
        $st = db()->prepare('SELECT id FROM customers WHERE name=? AND phone=?');
        $st->execute([$ad, $tel]);
        $musteriId = (int)$st->fetchColumn();
        if ($musteriId <= 0) {
            $st = db()->prepare('INSERT INTO customers(name,phone,address,district,created_at)
                VALUES(?,?,?,?,?)');
            $st->execute([$ad, $tel, $adres, $ilce, now_dt()]);
            $musteriId = (int)db()->lastInsertId();
        }
    } else {
        $st = db()->prepare('SELECT id FROM customers WHERE id=?');
        $st->execute([$musteriId]);
        if (!$st->fetchColumn()) api_hata('Müşteri yok.', 404);
    }

    $kalemler = is_array($b['kalemler'] ?? null) ? $b['kalemler'] : [];
    if (!$kalemler) api_hata('En az bir parça gerekli.');
    $liste = pricelist();
    $gecerliTurler = array_keys($liste);
    if (!$gecerliTurler) $gecerliTurler = array_keys(service_types());
    $toplam = 0;
    $satirlar = [];
    foreach ($kalemler as $k) {
        $tur = trim((string)($k['tur'] ?? ''));
        $olcu = trim((string)($k['olcu'] ?? ''));
        $adet = (int)($k['adet'] ?? 0);
        if ($tur === '' || $adet <= 0) continue;
        if (!in_array($tur, $gecerliTurler, true)) api_hata('Geçersiz halı türü: ' . $tur);
        $birim = (int)($liste[$tur] ?? 0);
        if ($birim <= 0 && isset($k['birim_kurus'])) {
            // Fiyat yetkisi yoksa gönderilen fiyat ALINMAZ (sunucu listesi esas).
            if (can('fiyat.gir')) $birim = (int)$k['birim_kurus'];
        }
        if ($birim <= 0) $birim = 0;   // fiyat henüz girilmemiş olabilir
        $line = $birim * $adet;
        $toplam += $line;
        $satirlar[] = ['type' => $tur, 'olcu' => $olcu, 'adet' => $adet,
            'birim_kurus' => $birim, 'tutar_kurus' => $line];
    }
    if (!$satirlar) api_hata('Geçerli parça yok.');

    $alimTur = (string)($b['alim_turu'] ?? 'elden');
    if (!in_array($alimTur, ['elden', 'servis'], true)) $alimTur = 'elden';
    $kod = next_order_code();
    $durum = $alimTur === 'servis' ? 'alim_bekliyor' : 'teslim_alindi';

    $st = db()->prepare('INSERT INTO orders(code,customer_id,status,pickup_type,pickup_date,
        delivery_date,total_kurus,discount_kurus,note,staff,created_at,updated_at)
        VALUES(?,?,?,?,?,?,?,0,?,?,?,?)');
    $st->execute([$kod, $musteriId, $durum, $alimTur, (string)($b['alim_tarih'] ?? ''),
        (string)($b['teslim_tarih'] ?? ''), $toplam, (string)($b['not'] ?? ''),
        uname_(), now_dt(), now_dt()]);
    $oid = (int)db()->lastInsertId();

    $ist = db()->prepare('INSERT INTO order_items(order_id,item_code,type,descr,qty,
        unit_kurus,line_kurus,note) VALUES(?,?,?,?,?,?,?,?)');
    foreach ($satirlar as $s) {
        $ist->execute([$oid, next_item_code(), $s['type'], $s['olcu'], $s['adet'],
            $s['birim_kurus'], $s['tutar_kurus'], '']);
    }
    add_event($oid, $durum, 'Sipariş oluşturuldu (mobil)');
    audit('api_siparis_olustur', 'orders', $oid, $kod);
    api_ok(['id' => $oid, 'kod' => $kod, 'durum' => $durum, 'toplam_kurus' => $toplam], 201);
}

// ================================================================ DURUM DEĞİŞTİR
function api_durum_degistir(int $id): void
{
    api_giris_yap();
    api_yetki('siparis.gor');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_hata('POST beklenir.', 405);
    $b = api_govde();
    $hedef = (string)($b['durum'] ?? '');
    $gerekce = trim((string)($b['gerekce'] ?? ''));

    $st = db()->prepare('SELECT id,status,code FROM orders WHERE id=?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) api_hata('Sipariş yok.', 404);
    $mevcut = (string)$o['status'];

    $serbest = ['teslim_edilemedi', 'iptal'];
    if (!array_key_exists($hedef, status_labels())) api_hata('Geçersiz durum.');
    if (!in_array($hedef, $serbest, true)) {
        $izinli = allowed_next($mevcut);
        if (!in_array($hedef, $izinli, true)) {
            // akış dışı geçiş yalnız yöneticide + gerekçe ile
            if (!is_admin()) api_hata('Bu duruma yalnız yönetici geçebilir.', 403);
            if ($gerekce === '') api_hata('Yönetici geçişi için gerekçe zorunlu.');
        }
    } elseif (!is_admin()) {
        api_hata('Bu duruma yalnız yönetici geçebilir.', 403);
    }

    $st = db()->prepare('UPDATE orders SET status=? WHERE id=?');
    $st->execute([$hedef, $id]);
    add_event($id, $hedef, 'Mobil uygulama', $mevcut, $gerekce);
    audit('api_durum', 'orders', $id, $mevcut . ' → ' . $hedef);
    api_ok(['id' => $id, 'durum' => $hedef,
        'durum_etiket' => (string)(status_labels()[$hedef] ?? $hedef)]);
}

// ================================================================ FOTOĞRAF
function api_foto_yukle(int $id): void
{
    api_giris_yap();
    api_yetki('foto.yukle');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_hata('POST beklenir.', 405);
    $st = db()->prepare('SELECT id FROM orders WHERE id=?');
    $st->execute([$id]);
    if (!$st->fetchColumn()) api_hata('Sipariş yok.', 404);

    $asama = (string)($_POST['stage'] ?? 'alim');
    $itemId = (int)($_POST['item_id'] ?? 0);
    $not = trim((string)($_POST['foto_not'] ?? ''));
    $files = $_FILES['foto'] ?? null;
    if (!$files || !is_array($files['name'])) api_hata('Dosya gelmedi.');

    $adet = is_array($files['name']) ? count($files['name']) : 1;
    $ok = 0; $hatalar = [];
    for ($i = 0; $i < $adet; $i++) {
        $f = ['name' => $files['name'][$i], 'type' => $files['type'][$i] ?? '',
              'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i],
              'size' => $files['size'][$i]];
        $r = photo_save($id, $f, $asama, $itemId > 0 ? $itemId : null, $not);
        if (!empty($r['ok'])) $ok++; else $hatalar[] = (string)($r['error'] ?? 'bilinmeyen hata');
    }
    if ($ok === 0) api_hata(implode(' | ', $hatalar) ?: 'Fotoğraf kaydedilemedi.', 422);
    api_ok(['yuklenen' => $ok, 'hatalar' => $hatalar], 201);
}

// ================================================================ SAHA LİSTELERİ
function api_saha(): void
{
    api_giris_yap();
    api_yetki('siparis.gor');
    $tur = (string)($_GET['tur'] ?? 'alim');
    $db = db();
    $t = today();
    $open = open_status_sql();

    if ($tur === 'alim') {
        $sql = api_siparis_sql() . " WHERE o.status IN ('yeni','alim_bekliyor')
            AND (o.pickup_date='' OR o.pickup_date<=?) ORDER BY o.pickup_date, o.id DESC";
        $args = [$t];
    } elseif ($tur === 'teslimat') {
        $sql = api_siparis_sql() . " WHERE o.status IN $open
            AND o.delivery_date<>'' AND o.delivery_date<=? ORDER BY o.delivery_date, o.id DESC";
        $args = [$t];
    } else { // gecikmis
        $sql = api_siparis_sql() . " WHERE o.status IN $open
            AND o.delivery_date<>'' AND o.delivery_date<? ORDER BY o.delivery_date, o.id DESC";
        $args = [$t];
    }
    $st = $db->prepare($sql);
    $st->execute($args);
    $liste = [];
    foreach ($st->fetchAll() as $o) {
        $d = delay_info($o);
        $o['_gecikti'] = !empty($d['late']);
        $s = api_siparis_satir($o);
        $s['gecikti'] = !empty($d['late']) ? 1 : 0;
        $liste[] = $s;
    }
    api_ok(['tur' => $tur, 'siparisler' => $liste]);
}

// ================================================================ MÜŞTERİLER
function api_musteriler(): void
{
    api_giris_yap();
    api_yetki('musteri.gor');
    $q = trim((string)($_GET['q'] ?? ''));
    $sql = 'SELECT * FROM customers';
    $args = [];
    if ($q !== '') {
        $sql .= " WHERE name LIKE ? ESCAPE '\\' OR phone LIKE ? ESCAPE '\\' OR phone2 LIKE ? ESCAPE '\\'";
        for ($i = 0; $i < 3; $i++) $args[] = liste_like($q);
    }
    $sql .= ' ORDER BY name LIMIT 50';
    $st = db()->prepare($sql);
    $st->execute($args);
    $liste = [];
    foreach ($st->fetchAll() as $c) {
        $liste[] = [
            'id' => (int)$c['id'], 'ad' => (string)$c['name'],
            'telefon' => (string)$c['phone'], 'telefon2' => (string)$c['phone2'],
            'ilce' => (string)$c['district'], 'adres' => (string)$c['address'],
        ];
    }
    api_ok(['musteriler' => $liste]);
}

// ================================================================ WEB TALEPLERİ
function api_talepler(): void
{
    api_giris_yap();
    api_yetki('talep.gor');
    $st = db()->prepare('SELECT * FROM requests ORDER BY id DESC LIMIT 100');
    $st->execute();
    $liste = [];
    foreach ($st->fetchAll() as $r) {
        $liste[] = [
            'id' => (int)$r['id'], 'ad' => (string)($r['name'] ?? ''),
            'telefon' => (string)($r['phone'] ?? ''), 'ilce' => (string)($r['district'] ?? ''),
            'adres' => (string)($r['address'] ?? ''), 'not' => (string)($r['note'] ?? ''),
            'durum' => (string)($r['status'] ?? ''), 'siparis_id' => (int)($r['order_id'] ?? 0),
            'tarih' => (string)$r['created_at'],
        ];
    }
    api_ok(['talepler' => $liste]);
}

// ================================================================ SÖZLÜK
function api_sozluk(): void
{
    api_giris_yap();
    $etiket = [];
    foreach (status_labels() as $k => $v) {
        $etiket[$k] = ['ad' => $v, 'renk' => (string)(status_colors()[$k] ?? '#64748b')];
    }
    $fiyat = [];
    $liste = pricelist();
    // Fiyat listesi boş olsa bile TÜRLER bilinmeli (yönetici sonradan fiyat
    // girebilsin). Kaynak: service_types() — rapor.php'deki fiyat ekranıyla aynı.
    $turler = array_keys($liste);
    if (!$turler) $turler = array_keys(service_types());
    foreach ($turler as $tur) {
        $fiyat[] = ['tur' => (string)$tur, 'fiyat_kurus' => (int)($liste[$tur] ?? 0)];
    }
    api_ok([
        'durumlar' => $etiket,
        'fiyatlar' => $fiyat,
        'isletme' => setting('isletme_adi', 'Mim Halı Yıkama'),
        'telefon' => setting('telefon', ''),
    ]);
}

// ================================================================ YÖNLENDİRİCİ
/** ?p=api/... → uç. Diğer her şey 404. */
function api_yonlendir(): void
{
    $yol = (string)($_GET['api'] ?? '');
    $yol = trim($yol, '/');
    if ($yol === '') $yol = 'ozet';

    // /api/siparis/{id}
    if (preg_match('#^siparis/(\d+)(/(\w+))?$#', $yol, $m)) {
        $id = (int)$m[1];
        if (!isset($m[3]) || $m[3] === '') { api_siparis_detay($id); }
        if ($m[3] === 'durum') { api_durum_degistir($id); }
        if ($m[3] === 'foto') { api_foto_yukle($id); }
        api_hata('Bilinmeyen uç.', 404);
    }

    switch ($yol) {
        case 'giris':       api_giris(); break;
        case 'ozet':        api_ozet(); break;
        case 'siparisler':  api_siparisler(); break;
        case 'siparis':     api_siparis_olustur(); break;
        case 'saha':        api_saha(); break;
        case 'musteriler':  api_musteriler(); break;
        case 'talepler':    api_talepler(); break;
        case 'sozluk':      api_sozluk(); break;
        default:            api_hata('Bilinmeyen uç: ' . $yol, 404);
    }
}
