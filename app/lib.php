<?php
/**
 * Mim Halı Yıkama — İşletme Sistemi v2
 * Çekirdek: SQLite (WAL), kullanıcı/rol, oturum, CSRF, yardımcılar.
 * PHP 7.4+ · bağımlılık yok · tek klasör.
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Saat dilimi AÇIKÇA ayarlanır. Canlı sunucuda php.ini date.timezone=UTC olduğu
// ölçüldü: bu satır yokken date()/today()/now_dt() UTC döndürüyor, SQLite'ın
// date('now','localtime')'i ise Türkiye saatini — gecike 00:00–03:00 arası
// panel sayaçları, alım/teslimat listeleri, gecikme hesabı ve sipariş kodu
// günü BİR GÜN GERİYE düşüyordu; ayrıca iki "bugün" tanımı birbirini
// tutmuyordu. NOT: bu tarihten ÖNCEKİ kayıtların zaman damgaları UTC'dir
// (3 saat geride) — geçmiş veri dönüştürülmedi, yalnız yeni kayıtlar TR saati.
date_default_timezone_set('Europe/Istanbul');

define('APP_ROOT', __DIR__);
define('DATA_DIR', APP_ROOT . '/data');
define('PHOTO_DIR', DATA_DIR . '/fotos');
define('MAX_PHOTO_BYTES', 16 * 1024 * 1024); // 16 MB (kullanıcı kararı 20.10.2026)
define('APP_VERSION', '2.0');

/** @return array{0:string,1:?string} hata kodu, mesaj */
function app_log(string $msg, array $ctx = []): void
{
    $safe = preg_replace('/(pass|sifre|token|secret|key|hash)["\']?\s*[:=]\s*\S+/i', '$1=***', $msg);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $safe
        . ($ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : '') . "\n";
    @file_put_contents(DATA_DIR . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

/** DB dosyası: rastgele ad (ilk kurulumda üretilir) → tahmin edilemez */
function dbfile(): string
{
    static $f = null;
    if ($f !== null) return $f;
    $conf = DATA_DIR . '/dbpath.php';
    if (is_file($conf)) {
        $f = (string)require $conf;
    } else {
        $f = DATA_DIR . '/mim-' . bin2hex(random_bytes(6)) . '.db';
        @file_put_contents($conf, "<?php return " . var_export($f, true) . ";");
    }
    return $f;
}

// ---------------------------------------------------------------- oturum
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'),
    ]);
    session_name('mimsys');
    session_start();
}

// ---------------------------------------------------------------- veritabanı
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0755, true); }
        $pdo = new PDO('sqlite:' . dbfile());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=5000');
        migrate($pdo);
    }
    return $pdo;
}

/** Kolon var mı? (SQLite ALTER idempotency için) */
function col_exists(PDO $pdo, string $table, string $col): bool
{
    try {
        $st = $pdo->query("PRAGMA table_info($table)");
        foreach ($st->fetchAll() as $r) if ($r['name'] === $col) return true;
    } catch (Throwable $e) { /* tablo yok */ }
    return false;
}

function add_col(PDO $pdo, string $table, string $col, string $def): void
{
    if (!col_exists($pdo, $table, $col)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def");
    }
}

function table_exists(PDO $pdo, string $t): bool
{
    $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $st->execute([$t]);
    return (bool)$st->fetchColumn();
}

/** Durum etiketleri: eski kod → yeni kod (geçmiş veri korunur) */
const STATUS_MIGRATION = [
    'planlandi' => 'alim_bekliyor',
    'alindi'    => 'teslim_alindi',
    'yikamada'  => 'yikamada',
    'kurutma'   => 'kurutuluyor',
    'hazir'     => 'paketlendi',
    'teslim'    => 'teslim',
    'iptal'     => 'iptal',
];

function migrate(PDO $pdo): void
{
    // ---------------------------------------------------------- v1 çekirdek
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings(
        k TEXT PRIMARY KEY, v TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL, phone TEXT DEFAULT '', phone2 TEXT DEFAULT '',
        address TEXT DEFAULT '', district TEXT DEFAULT '', note TEXT DEFAULT '',
        created_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        customer_id INTEGER NOT NULL REFERENCES customers(id),
        status TEXT NOT NULL DEFAULT 'yeni',
        pickup_type TEXT NOT NULL DEFAULT 'elden',
        pickup_date TEXT DEFAULT '', delivery_date TEXT DEFAULT '',
        note TEXT DEFAULT '',
        total_kurus INTEGER NOT NULL DEFAULT 0,
        discount_kurus INTEGER NOT NULL DEFAULT 0,
        staff TEXT DEFAULT '',
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        type TEXT NOT NULL, descr TEXT DEFAULT '',
        width REAL DEFAULT 0, height REAL DEFAULT 0, m2 REAL DEFAULT 0,
        qty INTEGER NOT NULL DEFAULT 1,
        unit_kurus INTEGER NOT NULL DEFAULT 0,
        line_kurus INTEGER NOT NULL DEFAULT 0,
        note TEXT DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS payments(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        amount_kurus INTEGER NOT NULL, method TEXT NOT NULL DEFAULT 'nakit',
        note TEXT DEFAULT '', created_at TEXT NOT NULL)");
    // Tahsilatı KİMİN girdiği kartta gösteriliyor ama kolon eklenmemişti:
    // her sipariş kartı açılışında "Undefined array key username" uyarısı
    // log'a düşüyordu ve tahsilat sahipsiz kalıyordu.
    add_col($pdo, 'payments', 'user_id', 'INTEGER DEFAULT NULL');
    add_col($pdo, 'payments', 'username', "TEXT NOT NULL DEFAULT ''");
    // Müşteri takip linki kodu (mod/takip.php) — tembel üretilir, kamu sayfası
    // yalnız bu kodla açılır (telefon/adres/fiyat gösterilmez).
    add_col($pdo, 'orders', 'takip_kodu', "TEXT NOT NULL DEFAULT ''");
    $pdo->exec("CREATE TABLE IF NOT EXISTS events(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        status TEXT NOT NULL, note TEXT DEFAULT '', created_at TEXT NOT NULL)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_orders_cust ON orders(customer_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_orders_status ON orders(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_pay_order ON payments(order_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_items_order ON order_items(order_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_events_order ON events(order_id)");

    // ---------------------------------------------------------- v2: kullanıcı
    $pdo->exec("CREATE TABLE IF NOT EXISTS users(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        pass_hash TEXT NOT NULL,
        full_name TEXT DEFAULT '',
        role TEXT NOT NULL DEFAULT 'personel',  -- yonetici | personel
        phone TEXT DEFAULT '',
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL, last_login TEXT DEFAULT '')");

    // İlk kurulumdaki eski tek şifre → yönetici hesabı
    $n = (int)$pdo->query("SELECT COUNT(*) c FROM users")->fetchColumn();
    if ($n === 0) {
        $old = '';
        try { $old = (string)$pdo->query("SELECT v FROM settings WHERE k='pass_hash'")->fetchColumn(); }
        catch (Throwable $e) {}
        if ($old !== '' && $old !== false) {
            $st = $pdo->prepare("INSERT INTO users(username,pass_hash,full_name,role,created_at)
                VALUES('admin',?,?, 'yonetici',?)");
            $st->execute([$old, 'Yönetici', now_dt()]);
            $pdo->exec("DELETE FROM settings WHERE k='pass_hash'");
        }
    }

    // ---------------------------------------------------------- v2: müşteri
    add_col($pdo, 'customers', 'wa_optin', "INTEGER NOT NULL DEFAULT 1");
    add_col($pdo, 'customers', 'map_lat', 'TEXT DEFAULT \'\'');
    add_col($pdo, 'customers', 'map_url', 'TEXT DEFAULT \'\'');
    add_col($pdo, 'customers', 'saved_at', 'TEXT DEFAULT \'\'');

    // ---------------------------------------------------------- v2: sipariş
    add_col($pdo, 'orders', 'client_uid', 'TEXT DEFAULT \'\'');   // çevrimdışı tekrar koruması
    add_col($pdo, 'orders', 'delivery_time', "TEXT NOT NULL DEFAULT ''");
    add_col($pdo, 'orders', 'delay_reason', "TEXT NOT NULL DEFAULT ''");
    add_col($pdo, 'orders', 'assigned_to', 'INTEGER DEFAULT NULL');
    add_col($pdo, 'orders', 'previous_delivery_date', "TEXT NOT NULL DEFAULT ''");
    add_col($pdo, 'orders', 'received_count', 'INTEGER NOT NULL DEFAULT 0');
    add_col($pdo, 'orders', 'returned_count', 'INTEGER NOT NULL DEFAULT 0');

    // ---------------------------------------------------------- v2: kalemler
    add_col($pdo, 'order_items', 'item_code', "TEXT NOT NULL DEFAULT ''");
    add_col($pdo, 'order_items', 'damage', "TEXT NOT NULL DEFAULT ''");
    add_col($pdo, 'order_items', 'received_qty', 'INTEGER NOT NULL DEFAULT 0');
    add_col($pdo, 'order_items', 'returned_qty', 'INTEGER NOT NULL DEFAULT 0');

    // ---------------------------------------------------------- v2: olaylar
    add_col($pdo, 'events', 'user_id', 'INTEGER DEFAULT NULL');
    add_col($pdo, 'events', 'username', "TEXT NOT NULL DEFAULT ''");
    add_col($pdo, 'events', 'from_status', "TEXT NOT NULL DEFAULT ''");
    add_col($pdo, 'events', 'reason', "TEXT NOT NULL DEFAULT ''");

    // ---------------------------------------------------------- v2: fotoğraf
    $pdo->exec("CREATE TABLE IF NOT EXISTS photos(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        item_id INTEGER DEFAULT NULL,
        stage TEXT NOT NULL DEFAULT 'alim',   -- alim | islem_oncesi | teslim
        file TEXT NOT NULL,
        mime TEXT DEFAULT '', bytes INTEGER DEFAULT 0,
        note TEXT DEFAULT '',
        user_id INTEGER DEFAULT NULL, username TEXT DEFAULT '',
        created_at TEXT NOT NULL)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_photos_order ON photos(order_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_photos_item ON photos(item_id)");

    // ---------------------------------------------------------- v2: gider
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cat TEXT NOT NULL DEFAULT 'diger',
        amount_kurus INTEGER NOT NULL DEFAULT 0,
        note TEXT DEFAULT '',
        user_id INTEGER DEFAULT NULL, username TEXT DEFAULT '',
        created_at TEXT NOT NULL)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_exp_date ON expenses(date(created_at))");

    // ---------------------------------------------------------- v3: sipariş-dışı gelir
    // Sipariş tahsilatı payments'tadır; burada yalnız siparişle İLİŞKİSİZ
    // para girişleri tutulur (nakliye, tamir/boyama, servis ücreti vb.).
    $pdo->exec("CREATE TABLE IF NOT EXISTS incomes(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cat TEXT NOT NULL DEFAULT 'diger',
        amount_kurus INTEGER NOT NULL DEFAULT 0,
        note TEXT DEFAULT '',
        user_id INTEGER DEFAULT NULL, username TEXT DEFAULT '',
        created_at TEXT NOT NULL)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_inc_date ON incomes(date(created_at))");

    // ---------------------------------------------------------- v2: WhatsApp
    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_queue(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER DEFAULT NULL,
        customer_id INTEGER DEFAULT NULL,
        template TEXT NOT NULL,
        phone TEXT NOT NULL DEFAULT '',
        body TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'bekliyor', -- bekliyor | gonderildi | teslim | okundu | hata
        attempts INTEGER NOT NULL DEFAULT 0,
        dedupe_key TEXT DEFAULT '',
        provider_msg_id TEXT DEFAULT '',
        error TEXT DEFAULT '',
        user_id INTEGER DEFAULT NULL, username TEXT DEFAULT '',
        created_at TEXT NOT NULL, sent_at TEXT DEFAULT '', updated_at TEXT DEFAULT '')");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_wa_dedupe ON wa_queue(dedupe_key)
        WHERE dedupe_key <> ''");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_wa_status ON wa_queue(status)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_inbound(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        from_phone TEXT NOT NULL DEFAULT '',
        customer_id INTEGER DEFAULT NULL,
        body TEXT DEFAULT '', msg_id TEXT DEFAULT '',
        received_at TEXT NOT NULL)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_wa_in_phone ON wa_inbound(from_phone)");

    // ---------------------------------------------------------- v2: denetim
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER DEFAULT NULL, username TEXT DEFAULT '',
        action TEXT NOT NULL, entity TEXT DEFAULT '', entity_id INTEGER DEFAULT NULL,
        detail TEXT DEFAULT '', created_at TEXT NOT NULL)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_audit_date ON audit_log(date(created_at))");

    // ---------------------------------------------------------- durum geçişi
    // ⚠ KİMLİK EŞLEMESİ KURALI: STATUS_MIGRATION içinde $old === $new olan
    //   girdiler ('yikamada'=>'yikamada', 'teslim'=>'teslim', 'iptal'=>'iptal')
    //   v1'den gelen kalemleri HARİTALAMAK içindir — "bu eski etiket yeni
    //   etikete karşılık geliyor" bilgisi. Ancak migrate() her sayfa açılışında
    //   çalıştığı için bu girdiler sonsuz döngü üretiyordu:
    //     UPDATE events SET status='yikamada' WHERE status='yikamada'  → hep eşleşir
    //     fetchColumn() boş DÖNMEZ → not her açılışta siparişe eklenir
    //   Ölçülen sonuç: gerçek siparişin notu 235.861 karaktere, sayfa yüksekliği
    //   70.000 piksele ulaştı (mobilde kullanılamaz).
    //   DÜZELTME: (1) kimlik eşlemeleri not yazmaz, (2) yalnız HENÜZ
    //   eşlenmemiş eski durum varsa çalışır, (3) not bir kez yazılır.
    foreach (STATUS_MIGRATION as $old => $new) {
        // 1) eski durumu yeni duruma taşı (kimlik eşlemesi zararsız)
        $pdo->prepare("UPDATE orders SET status=? WHERE status=?")->execute([$new, $old]);
        if ($old === $new) continue;                 // <- kimlik eşlemesi: not YAZMA
        // 2) bu ESKİ etiketle kayıt kaldı mı? kalmadıysa işimiz bitti
        $st = $pdo->prepare("SELECT COUNT(*) c FROM events WHERE status=?");
        $st->execute([$old]);
        if ((int)$st->fetchColumn() === 0) continue;
        $pdo->prepare("UPDATE events SET status=? WHERE status=?
            AND (note IS NULL OR note NOT LIKE '%durum eşlendi%')")->execute([$new, $old]);
        // 3) nota YALNIZCA bir kez yaz (aynı not zaten varsa dokunma)
        $st = $pdo->prepare("UPDATE orders SET note = COALESCE(note,'') || ?
            WHERE id IN (SELECT DISTINCT order_id FROM events WHERE status=?)
              AND (note IS NULL OR note NOT LIKE '%durum eşlendi%' || note NOT LIKE ?)");
        $st->execute(["\n[not] Sipariş akışı v2'ye güncellendi, eski durumlar eşlendi: $old → $new",
            $new, "%eski durumlar eşlendi: $old → $new%"]);
    }

    // ---------------------------------------------------- şişmiş notları onar
    // Migration hatası yüzünden sipariş notlarına aynı satır yüzlerce kez
    // eklenmişti (ölçülen: 235.861 karakter). Notu şişirmenin KÖK NEDENİ
    // yukarıda giderildi; burada oluşmuş veri bir kez temizlenir.
    // GÜVENLİ: yalnız tekrar eden "durumlar eşlendi" satırları silinir,
    // kullanıcının yazdığı gerçek notlar korunur.
    try {
        $rows = $pdo->query("SELECT id, note FROM orders
            WHERE note LIKE '%eski durumlar eşlendi%'")->fetchAll();
        foreach ($rows as $r) {
            $not = (string)$r['note'];
            $satirlar = explode("\n", $not);
            // aynı metnin kaç kez geçtiğini say (BİRİNCİ satırla değil,
            // genel olarak — tekrarlanan metin ilk satır olmak zorunda değil)
            $sayac = [];
            foreach ($satirlar as $ln) { $k = trim($ln); if ($k === '') continue; $sayac[$k] = ($sayac[$k] ?? 0) + 1; }
            $tekrarVar = false;
            foreach ($sayac as $adet) if ($adet >= 3) { $tekrarVar = true; break; }
            if (!$tekrarVar) continue;

            // tekrarları at, metni ilk geçtiği yerde BİR kez bırak.
            // Kullanıcının yazdığı farklı notlar korunur (sadece aynı metin
            // tekrarları elenir).
            $temiz = [];
            $gorulen = [];
            foreach ($satirlar as $ln) {
                $k = trim($ln);
                if ($k === '') continue;
                if (isset($gorulen[$k])) continue;
                $gorulen[$k] = true;
                $temiz[] = $ln;
            }
            $pdo->prepare("UPDATE orders SET note=? WHERE id=?")
               ->execute([implode("\n", $temiz), $r['id']]);
        }
    } catch (Throwable $e) { /* onarım başarısız olsa da akış durmasın */ }

    // geçmişte alınmamış siparişlerde received_count'i kalem adedine eşle
    $pdo->exec("UPDATE orders SET received_count = COALESCE((
        SELECT SUM(qty) FROM order_items WHERE order_id = orders.id), 0)
        WHERE received_count = 0 AND status <> 'yeni' AND status <> 'alim_bekliyor'");

    // v1'den gelen kalemlerde parça kodu yoktur — etiket/halı takibi çalışsın diye
    // kod burada bir kez üretilir (benzersizlik korunur)
    try {
        $sat = $pdo->query("SELECT id FROM order_items
            WHERE item_code IS NULL OR item_code='' ORDER BY id")->fetchAll();
        foreach ($sat as $it) {
            $u = $pdo->prepare('UPDATE order_items SET item_code=? WHERE id=?');
            $u->execute([next_item_code(), (int)$it['id']]);
        }
    } catch (Throwable $e) { /* kod üretilemezse akış engellenmez */ }

    // ---------------------------------------------------------- v2: müşteri birden çok adres
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_addresses(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
        label TEXT NOT NULL DEFAULT 'Ev',
        district TEXT DEFAULT '',
        address TEXT DEFAULT '',
        map_url TEXT DEFAULT '',
        is_default INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_addr_cust ON customer_addresses(customer_id)");

    // Siparişe ADRES KOPYASI: müşterinin adresi sonradan değişse bile siparişte sabit kalır
    add_col($pdo, 'orders', 'ship_label',    "TEXT NOT NULL DEFAULT ''");
    add_col($pdo, 'orders', 'ship_district', "TEXT NOT NULL DEFAULT ''");
    add_col($pdo, 'orders', 'ship_address',  "TEXT NOT NULL DEFAULT ''");
    add_col($pdo, 'orders', 'ship_map_url',  "TEXT NOT NULL DEFAULT ''");
    add_col($pdo, 'orders', 'ship_address_id', 'INTEGER DEFAULT NULL');

    // v1'de adres müşteri kaydında TEK alandaydı; o kayıt "Ana adres" olarak taşınır.
    // Idempotent: adresi olan müşterinin adres tablosunda kaydı varsa tekrar eklenmez.
    try {
        $pdo->exec("INSERT INTO customer_addresses(customer_id,label,district,address,map_url,is_default,created_at)
            SELECT c.id, 'Ana adres', COALESCE(c.district,''), COALESCE(c.address,''),
                   COALESCE(c.map_url,''), 1, COALESCE(NULLIF(c.created_at,''), datetime('now'))
            FROM customers c
            WHERE (COALESCE(c.address,'')<>'' OR COALESCE(c.district,'')<>'')
              AND NOT EXISTS (SELECT 1 FROM customer_addresses a WHERE a.customer_id=c.id)");
    } catch (Throwable $e) { app_log('adres taşınamadı: ' . $e->getMessage()); }

    // Kendiliğinden düzeltme: müşterinin tek alanında adresi var ama ana adres
    // kaydı BOŞ kalmışsa (ör. adres alanı gönderilmeden yapılan bir güncelleme)
    // o kayıt doldurulur. Aksi halde siparişlere kopyalanacak adres hep boş kalır.
    try {
        $pdo->exec("UPDATE customer_addresses SET
                address  = (SELECT COALESCE(c.address,'')  FROM customers c WHERE c.id=customer_addresses.customer_id),
                district = (SELECT COALESCE(c.district,'') FROM customers c WHERE c.id=customer_addresses.customer_id),
                map_url  = (SELECT COALESCE(c.map_url,'')  FROM customers c WHERE c.id=customer_addresses.customer_id)
            WHERE is_default=1
              AND COALESCE(address,'')=''
              AND EXISTS (SELECT 1 FROM customers c WHERE c.id=customer_addresses.customer_id
                            AND (COALESCE(c.address,'')<>'' OR COALESCE(c.district,'')<>''))");
    } catch (Throwable $e) { app_log('adres kaydı düzeltilemedi: ' . $e->getMessage()); }

    // Adres KOPYASI siparişe yapılır (müşterinin adresi sonradan değişse bile sipariş sabit).
    // Tetikleyici sayesinde sipariş kaydı hangi modülde oluşturulursa oluşturulsun
    // kural kendiliğinden uygulanır; alan zaten doluysa dokunulmaz.
    try {
        $pdo->exec("DROP TRIGGER IF EXISTS trg_orders_ship_addr");
        $pdo->exec("CREATE TRIGGER trg_orders_ship_addr AFTER INSERT ON orders
            WHEN NEW.ship_address = ''
            BEGIN
                UPDATE orders SET
                    ship_label    = COALESCE((SELECT a.label    FROM customer_addresses a
                                              WHERE a.customer_id=NEW.customer_id AND a.is_default=1 LIMIT 1), ''),
                    ship_district = COALESCE((SELECT a.district FROM customer_addresses a
                                              WHERE a.customer_id=NEW.customer_id AND a.is_default=1 LIMIT 1), ''),
                    ship_address  = COALESCE((SELECT a.address  FROM customer_addresses a
                                              WHERE a.customer_id=NEW.customer_id AND a.is_default=1 LIMIT 1), ''),
                    ship_map_url  = COALESCE((SELECT a.map_url  FROM customer_addresses a
                                              WHERE a.customer_id=NEW.customer_id AND a.is_default=1 LIMIT 1), ''),
                    ship_address_id = (SELECT a.id FROM customer_addresses a
                                       WHERE a.customer_id=NEW.customer_id AND a.is_default=1 LIMIT 1)
                WHERE id = NEW.id;
            END");
    } catch (Throwable $e) { app_log('sipariş adres tetikleyicisi kurulamadı: ' . $e->getMessage()); }

    // ================================================ v3: İNCE AYARLI YETKİLER
    // Personelin yetkileri JSON dizi olarak users.permissions'ta tutulur.
    // Boş bırakılırsa kullanıcı ROLÜNÜN varsayılan listesine düşer (geriye
    // uyumlu: mevcut davranış bozulmaz). 'hepsi' değeri yönetici muamelesi
    // görür — bir personel yanlışlıkla '*' verilirse kazara kilitlenmez.
    add_col($pdo, 'users', 'permissions', "TEXT NOT NULL DEFAULT ''");

    // ================================================ v3: GİDER ONAY AKIŞI
    // Personel gider girer → status='onay_bekliyor' → kasa ve RAPORA GİRMEZ.
    // Yönetici onaylayınca 'onaylandi' olur ve o an kâr rakamlarına girer.
    // Varsayılan 'onaylandi': eski giderler (yönetici girdi) onaylı sayılır,
    // geriye dönük hiçbir rapor rakamı değişmez.
    add_col($pdo, 'expenses', 'status',        "TEXT NOT NULL DEFAULT 'onaylandi'");
    add_col($pdo, 'expenses', 'onaylayan_id',  'INTEGER DEFAULT NULL');
    add_col($pdo, 'expenses', 'onaylayan_adi', "TEXT NOT NULL DEFAULT ''");
    add_col($pdo, 'expenses', 'onay_tarihi',   "TEXT NOT NULL DEFAULT ''");
    add_col($pdo, 'expenses', 'red_notu',      "TEXT NOT NULL DEFAULT ''");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_exp_status ON expenses(status)");

    // ================================================ v3: WEB TALEPLERİ
    // Siteden gelen alım talepleri. Siparişe dönüşene kadar ayrı tabloda
    // tutulur: müşteri/telefon doğrulanmadan orders'a yazmak, mükerrer
    // müşteri kaydı ve sahipsiz sipariş üretiyordu.
    $pdo->exec("CREATE TABLE IF NOT EXISTS requests(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        phone TEXT NOT NULL DEFAULT '',
        district TEXT NOT NULL DEFAULT '',
        address TEXT NOT NULL DEFAULT '',
        note TEXT NOT NULL DEFAULT '',
        items TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'yeni',      -- yeni | arandi | donustu | red
        source TEXT NOT NULL DEFAULT 'web',
        order_id INTEGER DEFAULT NULL,
        ip_hash TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    add_col($pdo, 'requests', 'order_id', 'INTEGER DEFAULT NULL');
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_req_status ON requests(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_req_phone ON requests(phone)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_req_created ON requests(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_req_iphash ON requests(ip_hash)");

    // ================================================ v3: MEMNUNİYET ANKETİ
    // Teslim sonrası tek kullanımlık token'lı anket. Token kamu sayfasını
    // açar; sipariş/müşteri bilgisi bu tablodan türetilir, URL'e sızmaz.
    $pdo->exec("CREATE TABLE IF NOT EXISTS surveys(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        customer_id INTEGER DEFAULT NULL,
        token TEXT NOT NULL UNIQUE,
        score INTEGER DEFAULT NULL,
        note TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'bekliyor',  -- bekliyor | cevaplandi
        ip_hash TEXT NOT NULL DEFAULT '',
        responded_at TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL)");
    add_col($pdo, 'surveys', 'ip_hash', "TEXT NOT NULL DEFAULT ''");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_survey_order ON surveys(order_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_survey_status ON surveys(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_survey_score ON surveys(score)");

    // ---------------------------------------------------------- v4: API jetonu
    // Mobil uygulama (APK) oturum yerine bearer token kullanır. Token bir
    // kullanıcıya bağlıdır; yetki kararları AYNI kanaldan (can()/me()) akar,
    // böylece ayrı bir yetki modeli doğmaz.
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id),
        token TEXT NOT NULL UNIQUE,
        label TEXT NOT NULL DEFAULT '',
        platform TEXT NOT NULL DEFAULT '',
        last_used TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_api_token_user ON api_tokens(user_id)");
}

// ---------------------------------------------------------------- ayarlar
function setting(string $k, string $def = ''): string
{
    try {
        $st = db()->prepare('SELECT v FROM settings WHERE k=?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? $def : (string)$v;
    } catch (Throwable $e) { return $def; }
}
function setting_set(string $k, string $v): void
{
    $st = db()->prepare('INSERT INTO settings(k,v) VALUES(?,?)
        ON CONFLICT(k) DO UPDATE SET v=excluded.v');
    $st->execute([$k, $v]);
}

// ---------------------------------------------------------------- kullanıcı
function me(): array
{
    static $u = null;
    if ($u !== null) return $u;
    $id = (int)($_SESSION['uid'] ?? 0);
    if ($id === 0) return [];
    $st = db()->prepare('SELECT id,username,full_name,role,permissions,phone,active FROM users WHERE id=?');
    $st->execute([$id]);
    $u = $st->fetch() ?: [];
    return $u;
}
function uid(): int { return (int)(me()['id'] ?? 0); }
function uname_(): string { return (string)(me()['username'] ?? ''); }
function role(): string { return (string)(me()['role'] ?? 'personel'); }
function is_admin(): bool { return role() === 'yonetici'; }

// ---------------------------------------------------------------- API jetonu
/** Mobil uygulama için bearer jetonu üretir (API). */
function api_token_uret(int $userId, string $label = '', string $platform = ''): string
{
    $t = bin2hex(random_bytes(32));
    $st = db()->prepare('INSERT INTO api_tokens(user_id,token,label,platform,created_at)
        VALUES(?,?,?,?,?)');
    $st->execute([$userId, $t, $label, $platform, now_dt()]);
    return $t;
}

/** Jetonu çöz → kullanıcı satırı. Geçersiz/pasif jeton boş döner. */
function api_token_user(string $token): array
{
    if ($token === '') return [];
    $st = db()->prepare('SELECT id,user_id FROM api_tokens WHERE token=?');
    $st->execute([$token]);
    $row = $st->fetch();
    if (!$row) return [];
    $ust = db()->prepare('SELECT id,username,full_name,role,permissions,phone,active
        FROM users WHERE id=?');
    $ust->execute([(int)$row['user_id']]);
    $u = $ust->fetch() ?: [];
    if (!$u || !(int)($u['active'] ?? 0)) return [];
    return $u;
}

/** Jetonun son kullanım zamanını tazele (her istekte yazma, saat başı bir kez). */
function api_token_dogusta(string $token): void
{
    try {
        $st = db()->prepare("UPDATE api_tokens SET last_used=? WHERE token=? AND
            (last_used='' OR last_used < datetime('now','-1 hour')) LIMIT 1");
        $st->execute([now_dt(), $token]);
    } catch (Throwable $e) { /* önemsiz */ }
}

/** Backend katmanında yetki kontrolü — arayüzden bağımsız */
function need_perm(string $perm): void
{
    if (!can($perm)) {
        http_response_code(403);
        $u = me();
        if ($u) {
            layout_head('Yetkisiz');
            echo '<div class="card"><p class="alert"><b>Bu işlem için yetkiniz yok.</b><br>'
                . 'Gerekli yetki: <code>' . e($perm) . '</code></p>'
                . '<a class="btn btn-block" href="?p=' . e($_GET['p'] ?? '') . '">← Geri dön</a></div>';
            layout_foot();
        } else {
            echo 'Yetkisiz.';
        }
        exit;
    }
}
/**
 * Gider akışı durumları ve rapor filtresi — lib.php'de tanımlanır çünkü
 * kasa/rapor/panel modülleri farklı sırada yükleniyor (index.php:9-16) ve
 * panel.php kasa sabitlerinden önce geliyor.
 */

/** Rapor/kasa rakamlarına GİREN giderler — her expenses sorgusu bu filtreyi kullanmalı. */
const GIDER_RAPORA_GIRER = "status='onaylandi'";

/**
 * Müşteri takip linki (mod/takip.php) — kod tembel üretilir: ilk ihtiyaçta
 * siparişe yazılır. 24 hex = 96 bit; deneme yanılma ile bulunamaz.
 */
function takip_kodu(int $orderId): string
{
    static $cache = [];
    if (isset($cache[$orderId])) return $cache[$orderId];
    $st = db()->prepare('SELECT takip_kodu FROM orders WHERE id=?');
    $st->execute([$orderId]);
    $kod = (string)$st->fetchColumn();
    if ($kod === '') {
        for ($i = 0; $i < 5; $i++) {
            $kod = bin2hex(random_bytes(12));
            // koşullu UPDATE: aynı anda iki istek üretse tek kod kalır
            $upd = db()->prepare('UPDATE orders SET takip_kodu=? WHERE id=? AND (takip_kodu IS NULL OR takip_kodu=\'\')');
            $upd->execute([$kod, $orderId]);
            if ($upd->rowCount() > 0) break;
            $st->execute([$orderId]);
            $kod = (string)$st->fetchColumn();
            if ($kod !== '') break;
        }
    }
    return $cache[$orderId] = $kod;
}

/** Takip linki. Ayarlar'da site_url varsa o kullanılır; yoksa web isteğinden
 *  türetilir (cron bağlamında HTTP_HOST yoksa link üretilmez, mesaj link size
 *  kalır — sahte adres üretilmez). */
function takip_linki(int $orderId): string
{
    $kod = takip_kodu($orderId);
    if ($kod === '') return '';
    $taban = trim((string)setting('site_url', ''));
    if ($taban === '') {
        if (empty($_SERVER['HTTP_HOST'])) return '';
        $skema = (!empty($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https');
        $taban = rtrim($skema . '://' . $_SERVER['HTTP_HOST'] . dirname((string)$_SERVER['SCRIPT_NAME']), '/');
    }
    return rtrim($taban, '/') . '/?p=takip&kod=' . $kod;
}

/**
 * İzin kataloğu — Ayarlar/Personel ekranındaki "yetki seç" listesi bunu kullanır.
 * grup => [izin => etiket]. Kilitli olanlar yalnız yöneticidedir; yönetici
 * kısmen personele devredebilir, devredemeyeceği izinler kod içinde zorlanır.
 */
function izin_katalogu(): array
{
    return [
        'Sipariş' => [
            'siparis.gor'        => 'Siparişleri görüntüle',
            'siparis.olustur'    => 'Sipariş oluştur',
            'siparis.guncelle'   => 'Sipariş düzenle / not ekle',
            'siparis.durum'      => 'Durum değiştir',
            'siparis.indirim'    => 'İndirim girebilir',
            'siparis.fiyat'      => 'Kalem fiyatını elle değiştirebilir',
            'siparis.sil'        => 'Sipariş silebilir',
            'siparis.toplu'      => 'Toplu işlem yapabilir',
        ],
        'Müşteri' => [
            'musteri.gor'        => 'Müşterileri görüntüle',
            'musteri.olustur'    => 'Müşteri ekle / düzenle',
            'musteri.adres'      => 'Müşteri adreslerini yönet',
            'musteri.sil'        => 'Müşteri silebilir',
        ],
        'Saha' => [
            'servis.gor'         => 'Alım / teslimat listelerini görüntüle',
            'foto.yukle'         => 'Siparişe fotoğraf yükle',
            'foto.sil'           => 'Fotoğraf silebilir',
        ],
        'Kasa' => [
            'odeme.gor'          => 'Kasa ve tahsilatları görüntüle',
            'odeme.ekle'         => 'Ödeme kaydı girer',
            'odeme.sil'          => 'Ödeme kaydını silebilir',
            'gelir.ekle'         => 'Sipariş dışı gelir kaydeder',
            'gelir.sil'          => 'Gelir kaydını silebilir',
            'gider.ekle'         => 'Gider kaydı girer (onaya tabi)',
            'gider.onayla'       => 'Gider onayla / reddet',
            'gider.sil'          => 'Gider kaydını silebilir',
        ],
        'Talep / Rota' => [
            'talep.gor'          => 'Web taleplerini görüntüle',
            'talep.yonet'        => 'Talebi siparişe çevir / durum değiştir',
            'talep.sil'          => 'Talebi silebilir',
            'rota.gor'           => 'Günlük alım / teslimat rotasını görüntüle',
            'anket.gor'          => 'Memnuniyet anketlerini görüntüle',
            'anket.yonet'        => 'Anket ayarlarını değiştirir',
            'kampanya.yonet'     => 'Tekrar sipariş hatırlatma kampanyası yürütür',
        ],
        'Yönetim' => [
            'rapor.gor'          => 'Raporları görüntüle',
            'wa.gor'             => 'WhatsApp bildirimlerini görüntüle',
            'wa.gonder'          => 'WhatsApp kuyruğunu işletir',
            'personel.yonet'     => 'Personel ekler / yetkilendirir',
            'ayar.yonet'         => 'Ayarları değiştirir',
            'yedek.indir'        => 'Veritabanı yedeği indirebilir',
        ],
    ];
}

/** Bir personelin sahip olabileceği TÜM izinler (yönetici muamelesi hariç). */
function izin_listesi(): array
{
    $out = [];
    foreach (izin_katalogu() as $g) foreach ($g as $k => $lbl) $out[] = $k;
    return $out;
}

/** Kullanıcının etkin izinlerini çözer: JSON kolon → yoksa rol varsayılanı. */
function kullanici_izinleri(?array $u = null): array
{
    static $cache = [];
    if ($u === null) $u = me();
    $id = (int)($u['id'] ?? 0);
    if ($id === 0) return [];
    if (isset($cache[$id])) return $cache[$id];

    // Yönetici rolü her zaman tam yetkilidir — izin kolonu onu kısıtlamaz.
    if ((string)($u['role'] ?? '') === 'yonetici') return $cache[$id] = ['*'];

    $raw = trim((string)($u['permissions'] ?? ''));
    if ($raw !== '') {
        $d = json_decode($raw, true);
        if (is_array($d)) {
            $gecerli = array_values(array_intersect(array_map('strval', $d), izin_listesi()));
            if (in_array('*', $d, true)) $gecerli[] = '*';
            return $cache[$id] = array_values(array_unique($gecerli));
        }
        // Bozuk JSON: sessizce rol varsayılanına düşmektense KİLİTLE. Yanlış
        // izin verilmesi mali hatadır; erişimi kısıtlamak güvenli taraftır.
        app_log('personel izinleri okunamadı (bozuk JSON), rol varsayılanı: uid=' . $id);
        return $cache[$id] = rol_varsayilan_izinler();
    }
    return $cache[$id] = rol_varsayilan_izinler();
}

function rol_varsayilan_izinler(): array
{
    // Personel rolünün bugünkü davranışı — geçmişe dönük uyum için birebir aynı.
    // Yeni ince ayar yetkileri (indirim, fiyat, gider, onay, yedek…) varsayılan
    // OLMAYAN yetkilerdir: eski personele hiçbir mali yetki kendiliğinden verilmez.
    return ['siparis.gor', 'siparis.olustur', 'siparis.guncelle', 'siparis.durum',
            'siparis.toplu', 'musteri.gor', 'musteri.olustur', 'musteri.adres',
            'foto.yukle', 'odeme.gor', 'servis.gor',
            // v3: saha personeli talepleri görür, rotasını açar; dönüştürme ve
            // silme yöneticide kalır (talep.yonet / talep.sil varsayılan DEĞİL).
            'talep.gor', 'rota.gor', 'anket.gor'];
}

function can(string $perm): bool
{
    // Yönetici: her zaman tam yetki (izin kolonu onu kısıtlamaz).
    if (is_admin()) return true;
    $list = kullanici_izinleri();
    return in_array('*', $list, true) || in_array($perm, $list, true);
}

/** Yetki atar (Personel ekranı). Geçersiz izin yok sayılır. */
function izinlari_kaydet(int $userId, array $secilen): bool
{
    $gecerli = array_values(array_intersect(array_map('strval', $secilen), izin_listesi()));
    $st = db()->prepare('UPDATE users SET permissions=? WHERE id=? AND role<>"yonetici"');
    $st->execute([(string)json_encode($gecerli, JSON_UNESCAPED_UNICODE), $userId]);
    audit('izin_ata', 'users', $userId, implode(',', $gecerli) ?: '(yetki yok)');
    return true;
}

function require_login(): void
{
    if (empty($_SESSION['ok'])) { header('Location: ?p=login'); exit; }
    // hesap silinmiş/pasifleştirilmiş olabilir
    if (empty(me()) || !(int)(me()['active'] ?? 0)) {
        $_SESSION = []; session_destroy();
        header('Location: ?p=login'); exit;
    }
}

function try_login(string $user, string $pass): bool
{
    $fails = (int)setting('login_fails', '0');
    $last  = (int)setting('login_fail_at', '0');
    if ($fails >= 5 && time() - $last < 300) { sleep(min(5, $fails - 4)); }

    $st = db()->prepare('SELECT * FROM users WHERE username=? AND active=1');
    $st->execute([$user]);
    $u = $st->fetch();
    if ($u && password_verify($pass, (string)$u['pass_hash'])) {
        setting_set('login_fails', '0');
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        $s = db()->prepare('UPDATE users SET last_login=? WHERE id=?');
        $s->execute([now_dt(), (int)$u['id']]);
        audit('login', 'users', (int)$u['id'], 'giriş');
        return true;
    }
    setting_set('login_fails', (string)($fails + 1));
    setting_set('login_fail_at', (string)time());
    return false;
}

function audit(string $action, string $entity = '', ?int $entityId = null, string $detail = ''): void
{
    try {
        $st = db()->prepare('INSERT INTO audit_log(user_id,username,action,entity,entity_id,detail,created_at)
            VALUES(?,?,?,?,?,?,?)');
        $st->execute([uid() ?: null, uname_(), $action, $entity, $entityId, $detail, now_dt()]);
    } catch (Throwable $e) { app_log('audit yazılamadı: ' . $e->getMessage()); }
}

// ---------------------------------------------------------------- CSRF
function csrf_field(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}
function csrf_check(): void
{
    $sent = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf'] ?? 'x'), $sent) || $sent === '') {
        http_response_code(400);
        exit('Güvenlik doğrulaması başarısız (CSRF). Sayfayı yenileyip tekrar deneyin.');
    }
}

// ---------------------------------------------------------------- yönlendirme
function redirect(string $p, string $q = ''): void
{
    $loc = '?p=' . urlencode($p) . ($q !== '' ? '&' . $q : '');
    header('Location: ' . $loc);
    exit;
}

function now_dt(): string { return date('Y-m-d H:i:s'); }
function today(): string { return date('Y-m-d'); }
function back_or_die(): void
{
    header('HTTP/1.1 303 See Other');
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '?p='));
    exit;
}

// ---------------------------------------------------------------- yardımcılar
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function money_tl(int $kurus, bool $symbol = true): string
{
    $s = number_format($kurus / 100, 2, ',', '.');
    return $symbol ? $s . ' ₺' : $s;
}

/** "1.250,50" / "1250.5" / "1250,50 ₺" → kuruş */
function parse_tl(?string $s): int
{
    $s = trim((string)$s);
    if ($s === '') return 0;
    $s = str_replace(['₺', ' ', "\xc2\xa0"], '', $s);
    if (strpos($s, ',') !== false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    }
    return (int)round(((float)$s) * 100);
}

/** wa.me için numara normalleştirme → 90XXXXXXXXXX */
function wa_phone(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone);
    if ($d === '') return '';
    if (strlen($d) === 10 && $d[0] === '0') $d = '90' . substr($d, 1);
    elseif (strlen($d) === 10) $d = '90' . $d;
    elseif (strlen($d) === 11 && $d[0] === '0') $d = '90' . substr($d, 1);
    return $d;
}

function tel_href(string $phone): string
{
    return 'tel:' . preg_replace('/[^\d+]/', '', $phone);
}

/**
 * Google işletme yorum bağlantısı (Ayarlar'da yönetici girer).
 * Yorumu MÜŞTERİ kendi telefonundan yazar — sistem sahte yorum üretmez,
 * yalnızca müşterinin kendi elinden yorum yazacağı doğru bağlantıyı açar.
 * Ayar boşsa özellik sessizce kapanır, sahte/bozuk bağlantı gösterilmez.
 */
function google_yorum_linki(): string
{
    $u = trim((string)setting('google_yorum_url', ''));
    if ($u === '') return '';
    // Yalnızca http(s) kabul edilir; javascript: gibi şemalar engellenir.
    if (!preg_match('~^https?://~i', $u)) return '';
    return $u;
}

/** Teslimde müşteriye gösterilecek kısa yorum metni. */
function google_yorum_mesaji(): string
{
    $m = trim((string)setting('google_yorum_mesaj', ''));
    if ($m !== '') return $m;
    $ad = setting('isletme_adi', 'Mim Halı Yıkama');
    return 'Halılarımızı memnun kaldıysanız, birkaç kelimelik yorumunuz bizim için çok değerli.';
}

/** Türkçe tarih */
function tr_date(?string $d, bool $withTime = false): string
{
    if (!$d) return '—';
    $ts = strtotime($d);
    if ($ts === false) return '—';
    $fmt = $withTime ? 'd.m.Y H:i' : 'd.m.Y';
    if (function_exists('intl_date_format')) {
        return str_replace(['/', '.'], '.', intl_date_format(date_create($d), $fmt));
    }
    return date($fmt, $ts);
}

/** Gecikme gün/saat hesabı — planlanan teslim tarihi esas alınır.
 *  Teslim edilmiş/iptal siparişler gecikmede sayılmaz. */
function delay_info(array $o, ?string $ref = null): array
{
    $done = in_array((string)$o['status'], ['teslim', 'iptal', 'teslim_edilemedi'], true);
    if ($done || (string)($o['delivery_date'] ?? '') === '') {
        return ['late' => false, 'days' => 0, 'hours' => 0, 'bucket' => '', 'label' => ''];
    }
    $ref = $ref ?? now_dt();
    $due = (string)$o['delivery_date'];
    $dueTs = strtotime($due . (strlen($due) === 10 ? ' 23:59:59' : ' 00:00:00'));
    $refTs = strtotime($ref);
    if ($dueTs === false || $refTs === false || $refTs <= $dueTs) {
        return ['late' => false, 'days' => 0, 'hours' => 0, 'bucket' => '', 'label' => ''];
    }
    $sec = $refTs - $dueTs;
    // Gecikme TAKVİM GÜNÜ olarak sayılır: planlı teslim günü 19 gün önceyse
    // gecikme 19 gündür. Saat bazlı hesap gece yarısını aşınca 1 gün eksik veriyordu.
    $days = (int)floor((strtotime(date('Y-m-d', $refTs)) - strtotime(date('Y-m-d', $dueTs))) / 86400);
    $hours = (int)floor(($sec - $days * 86400) / 3600);
    if ($hours < 0) $hours = 0;
    $bucket = $days >= 7 ? 'kritik' : ($days >= 4 ? 'c4_6' : ($days >= 2 ? 'c2_3' : 'c1'));
    $label = $days > 0 ? ("{$days} gün") : "{$hours} saat";
    return ['late' => true, 'days' => $days, 'hours' => $hours, 'bucket' => $bucket, 'label' => $label];
}

/** Gecikme nedenleri */
function delay_reasons(): array
{
    return [
        '' => '— seçiniz —',
        'yikama' => 'Yıkama',
        'kurutma' => 'Kurutma',
        'kalite_kontrol' => 'Kalite kontrol',
        'personel' => 'Personel',
        'arac' => 'Araç / dağıtım',
        'musteri' => 'Müşteri kaynaklı',
        'hizmet' => 'Hizmet yoğunluğu',
        'diger' => 'Diğer',
    ];
}

// ---------------------------------------------------------------- durumlar
function status_labels(): array
{
    return [
        'yeni'            => 'Yeni Sipariş',
        'alim_bekliyor'   => 'Alım Bekliyor',
        'teslim_alindi'   => 'Halı Teslim Alındı',
        'yikama_bekliyor' => 'Yıkama Bekliyor',
        'yikamada'        => 'Yıkanıyor',
        'kurutuluyor'     => 'Kurutuluyor',
        'kalite_kontrol'  => 'Kalite Kontrol',
        'paketlendi'      => 'Paketlendi / Hazır',
        'dagitimda'       => 'Dağıtımda',
        'teslim'          => 'Teslim Edildi',
        'teslim_edilemedi' => 'Teslim Edilemedi',
        'iptal'          => 'İptal',
    ];
}
function status_colors(): array
{
    return [
        'yeni' => '#525252', 'alim_bekliyor' => '#8a6d1a', 'teslim_alindi' => '#155e75',
        'yikama_bekliyor' => '#0e7490', 'yikamada' => '#7c3aed', 'kurutuluyor' => '#b45309',
        'kalite_kontrol' => '#a16207', 'paketlendi' => '#15803d', 'dagitimda' => '#047857',
        'teslim' => '#1f2937', 'teslim_edilemedi' => '#9f1239', 'iptal' => '#b91c1c',
    ];
}
/** Akış sırası (aşama atlama kuralları) */
function status_flow(): array
{
    return [
        'yeni' => 'alim_bekliyor',
        'alim_bekliyor' => 'teslim_alindi',
        'teslim_alindi' => 'yikama_bekliyor',
        'yikama_bekliyor' => 'yikamada',
        'yikamada' => 'kurutuluyor',
        'kurutuluyor' => 'kalite_kontrol',
        'kalite_kontrol' => 'paketlendi',
        'paketlendi' => 'dagitimda',
        'dagitimda' => 'teslim',
    ];
}
/** Bu durumdan hangi durumlara geçilebilir (izinli) */
function allowed_next(string $st): array
{
    if (isset(status_flow()[$st])) return [status_flow()[$st]];
    return [];
}
/** Durumlar kapanmış mı (bu durumdan ileri gidilemez) */
function is_closed(string $st): bool
{
    return in_array($st, ['teslim', 'iptal', 'teslim_edilemedi'], true);
}

function status_badge(string $st): string
{
    $lbl = status_labels()[$st] ?? $st;
    $c = status_colors()[$st] ?? '#555';
    return '<span class="badge" style="background:' . $c . '">' . e($lbl) . '</span>';
}

// ---------------------------------------------------------------- hizmetler
function service_types(): array
{
    return [
        'hali'         => ['Halı (yıkama)', true],
        'cami'         => ['Cami halısı', true],
        'kilim'        => ['Kilim', true],
        'koltuk-takim' => ['Koltuk takımı', false],
        'koltuk-tek'   => ['Tekli koltuk / berjer', false],
        'kose'         => ['Köşe takımı', false],
        'battaniye-t'  => ['Battaniye (tek)', false],
        'battaniye-c'  => ['Battaniye (çift)', false],
        'yorgan-t'     => ['Yorgan (tek)', false],
        'yorgan-c'     => ['Yorgan (çift)', false],
        'stor'         => ['Stor perde', false],
        'sark'         => ['Şark odası yıkama', false],
        'yatak'        => ['Yatak / şilte', false],
        'perde'        => ['Perde (tul)', false],
        'diger'        => ['Diğer', false],
    ];
}

/** Ayarlar'daki birim fiyat listesi: key => kuruş */
function pricelist(): array
{
    $raw = setting('pricelist', '');
    $arr = $raw !== '' ? json_decode($raw, true) : [];
    return is_array($arr) ? $arr : [];
}

// ---------------------------------------------------------------- sipariş
/** Yeni sipariş kodu: M-YYMMDD-NN */
function next_order_code(): string
{
    $prefix = 'M-' . date('ymd') . '-';
    $st = db()->prepare('SELECT code FROM orders WHERE code LIKE ? ORDER BY id DESC LIMIT 1');
    $st->execute([$prefix . '%']);
    $last = $st->fetchColumn();
    $n = $last ? ((int)substr((string)$last, strlen($prefix))) + 1 : 1;
    return $prefix . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
}

/** Halı/parça kodu: H-YYMMDD-NN */
function next_item_code(): string
{
    $prefix = 'H-' . date('ymd') . '-';
    $st = db()->prepare('SELECT item_code FROM order_items WHERE item_code LIKE ? ORDER BY id DESC LIMIT 1');
    $st->execute([$prefix . '%']);
    $last = $st->fetchColumn();
    $n = $last ? ((int)substr((string)$last, strlen($prefix))) + 1 : 1;
    return $prefix . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
}

/** Eski kayıtlar için geriye dönük parça kodu üret */
function ensure_item_codes(int $orderId): void
{
    $st = db()->prepare('SELECT id FROM order_items WHERE order_id=? AND (item_code IS NULL OR item_code=\'\')');
    $st->execute([$orderId]);
    foreach ($st->fetchAll() as $r) {
        $u = db()->prepare('UPDATE order_items SET item_code=? WHERE id=?');
        $u->execute([next_item_code(), (int)$r['id']]);
    }
}

function add_event(int $orderId, string $status, string $note = '', string $from = '', string $reason = ''): void
{
    $st = db()->prepare('INSERT INTO events(order_id,status,note,created_at,user_id,username,from_status,reason)
        VALUES(?,?,?,?,?,?,?,?)');
    $st->execute([$orderId, $status, $note, now_dt(), uid() ?: null, uname_(), $from, $reason]);
}

/** Yapılandırılmışsa WhatsApp yolları */
function whatsapp_ready(): bool
{
    return setting('wa_token', '') !== '' && setting('wa_phone_id', '') !== '' && setting('wa_verify', '1') === '1';
}
function whatsapp_mode(): string
{
    return whatsapp_ready() ? 'api' : 'manuel';
}