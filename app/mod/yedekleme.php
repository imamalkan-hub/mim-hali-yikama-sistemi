<?php
/**
 * Otomatik yedekleme — cron tarafından çağrılır.
 *
 * NEDEN VAR: 17.10.2026 denetiminde tespit edildi — canlı sunucuda
 * 544 KB'lık veritabanının HİÇBİR yedeği yoktu. Sunucu çökerse/veri
 * bozulursa geri yüklenecek dosya bulunamazdı. Elle "?p=yedek" indirmek
 * yeterli değil: kimse her gün yapmıyor.
 *
 * NASIL ÇALIŞIR:
 *   1) SQLite'ın "VACUUM INTO" komutu ile TUTARLI anlık görüntü alınır
 *      (WAL dosyası kopyalanmaz → yarım yedek riski yok).
 *   2) Fotoğraflar ve dbpath.php kopyalanır.
 *   3) ESKİ yedekler saklama politikasına göre silinir.
 *   4) Şifreli olmayan yerel kopya: yedek web'den 404 ile kapalıdır.
 *   5) Her adım app.log'a yazılır → yedekleme hatası GİZLENMEZ.
 *
 * GÜVENLİK: yalnız geçerli cron_token ile çalışır; dosya adları
 * tahmin edilemez; web erişimi .htaccess ile kapalı.
 */
declare(strict_types=1);

/** Kaç günlük yedek saklanacak. */
const YEDEK_SAKLAMA_GUN = 14;
/** Bu yedek klasörü web'e kapalıdır (data/ altında, .htaccess → 404). */
function yedek_dizini(): string
{
    return DATA_DIR . '/yedekler';
}

/**
 * Yedek al. Dönüş: ['ok'=>bool, 'mesaj'=>string, 'dosya'=>string, 'boyut'=>int]
 */
function yedek_al(string $sebep = 'cron'): array
{
    $baslangic = microtime(true);
    $dizin = yedek_dizini();
    if (!is_dir($dizin) && !@mkdir($dizin, 0700, true) && !is_dir($dizin)) {
        $h = 'Yedek klasörü oluşturulamadı: ' . $dizin;
        app_log('YEDEK HATASI: ' . $h);
        return ['ok' => false, 'mesaj' => $h, 'dosya' => '', 'boyut' => 0];
    }

    // ⚠ "*/30 * * * *" sık cron'unda her çağrıda yeni yedek üretmek disk şişirir
    //   (14 günde ~672 kopya ≈ 356 MB). Arayüz "1 saatten yeniyse atlar" diyordu
    //   ama bu mantık kodda YOKTU. Elle alınan yedekler daima yazılır; yalnız
    //   cron çağrısı, son yedek 1 saatten yeniyse atlar.
    if ($sebep === 'cron') {
        $son = yedek_liste();
        if ($son) {
            $f = $dizin . '/' . $son[0]['dosya'];
            if (is_file($f) && (time() - (int)@filemtime($f)) < 3600) {
                return ['ok' => true, 'mesaj' => 'Son yedek 1 saatten yeni, atlandı.',
                    'dosya' => (string)$son[0]['dosya'], 'boyut' => (int)$son[0]['boyut']];
            }
        }
    }

    $zaman = date('Ymd_His');
    $ad = 'yedek-' . $zaman;
    $dbHedef = $dizin . '/' . $ad . '.db';

    // ---------------------------------------------------- 1) tutarlı DB kopyası
    // Öncelik VACUUM INTO (yazma sırasında bile tutarlı anlık görüntü).
    //
    // ⚠ VACUUM INTO yalnız SQLite 3.27+ (2021-02) ile var. ÖLÇÜLEN: canlı
    //   sunucuda SQLite 3.26.0 → "near INTO: syntax error" ve yedek HİÇ
    //   alınamıyordu (yedekleme sessizce başarısız oluyordu). Bu yüzden
    //   sürüm kontrol edilir; desteklenmiyorsa güvenli yedek yolu kullanılır:
    //     • wal_checkpoint(TRUNCATE) → WAL'i ana dosyaya yaz
    //     • BEGIN IMMEDIATE ile yazma kilidi al (yeni yazma başlamaz)
    //     • dosya kopyala
    //     • bırak (kilit kendiliğinden açılır)
    //   Arada yazma olsa bile ana veritabanı dosyası tutarlı kalır: SQLite
    //   veriyi ana dosyaya yazar, WAL ek dosyasını tamamlayıcıdır.
    //
    // ⚠ Yol tırnaklanmalıdır: PDO::quote() SQLite'te tırnak EKLEMEZ
    //   (string literal için tasarlanmıştır, tanımlayıcı için değil).
    $yolSql = "'" . str_replace("'", "''", $dbHedef) . "'";
    $vacuumVar = false;
    try {
        $v = (string)db()->query('SELECT sqlite_version()')->fetchColumn();
        $vacuumVar = version_compare($v, '3.27.0', '>=');
    } catch (Throwable $e) { $vacuumVar = false; }

    try {
        if ($vacuumVar) {
            db()->exec('VACUUM INTO ' . $yolSql);
        } else {
            db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');   // WAL'i ana dosyaya yaz
            db()->exec('BEGIN IMMEDIATE');                    // yeni yazma başlamasın
            try {
                if (!@copy(dbfile(), $dbHedef)) {
                    throw new RuntimeException('veritabanı kopyalanamadı (izin veya boyut)');
                }
            } finally {
                try { db()->exec('COMMIT'); } catch (Throwable $e) { /* kilit kapandıysa sorun değil */ }
            }
        }
    } catch (Throwable $e) {
        $h = 'Veritabanı anlık görüntüsü alınamadı: ' . $e->getMessage();
        app_log('YEDEK HATASI: ' . $h);
        return ['ok' => false, 'mesaj' => $h, 'dosya' => '', 'boyut' => 0];
    }
    if (!is_file($dbHedef)) {
        app_log('YEDEK HATASI: anlık görüntü dosyası oluşmadı');
        return ['ok' => false, 'mesaj' => 'Anlık görüntü dosyası oluşmadı', 'dosya' => '', 'boyut' => 0];
    }
    @chmod($dbHedef, 0600);

    // ---------------------------------------------------- 2) doğrulama
    try {
        $t = new PDO('sqlite:' . $dbHedef);
        $t->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sonuc = $t->query('PRAGMA integrity_check')->fetchColumn();
        if ((string)$sonuc !== 'ok') {
            @unlink($dbHedef);
            $h = 'Yedek bütünlük kontrolü başarısız: ' . $sonuc;
            app_log('YEDEK HATASI: ' . $h);
            return ['ok' => false, 'mesaj' => $h, 'dosya' => '', 'boyut' => 0];
        }
        // fotoğraf kaydı var mı dosya kontrolü
        $fotoKayit = (int)$t->query('SELECT COUNT(*) FROM photos')->fetchColumn();
    } catch (Throwable $e) {
        @unlink($dbHedef);
        $h = 'Yedek doğrulanamadı: ' . $e->getMessage();
        app_log('YEDEK HATASI: ' . $h);
        return ['ok' => false, 'mesaj' => $h, 'dosya' => '', 'boyut' => 0];
    }

    // ---------------------------------------------------- 3) dbpath + fotoğraflar
    $fotoSayisi = 0;
    $fotoKayit = $fotoKayit ?? 0;
    $fotoDizin = $dizin . '/' . $ad . '-fotos';
    $kaynaklar = glob(PHOTO_DIR . '/*') ?: [];
    $gercek = [];
    foreach ($kaynaklar as $f) {
        if (!is_file($f) || basename($f) === '.thumb') continue;   // thumbnail türevi
        $gercek[] = basename($f);
    }

    // ⚠ KAYIT VAR AMA DOSYA YOKSA yedek YARIM olur: geri yüklemede
    //   veritabanında foto kaydı var, dosya yok → kırık görsel.
    //   Bu yüzden fotoğraf klasörü/erişimi yoksa yedek BAŞARISIZ sayılır
    //   (sessizce "fotoğraf yedeği alındı" denmez).
    if ($fotoKayit > 0 && $fotoSayisi === 0 && count($gercek) === 0) {
        $eksik = 0;
        foreach ($t->query('SELECT file FROM photos') as $r) {
            if (!is_file(PHOTO_DIR . '/' . basename((string)$r['file']))) $eksik++;
        }
        $h = 'Fotoğraf klasörü okunamadı — yedek tam olmaz. Kayıtlı fotoğraf: ' . $fotoKayit
            . ', dosyada bulunan: 0';
        app_log('YEDEK HATASI: ' . $h);
        @unlink($dbHedef);
        return ['ok' => false, 'mesaj' => $h, 'dosya' => '', 'boyut' => 0];
    }
    if ($fotoKayit > 0 && count($gercek) < $fotoKayit) {
        app_log('YEDEK UYARISI: ' . count($gercek) . '/' . $fotoKayit
            . ' fotoğraf yedeklendi — bazı dosyalar diskte yok');
    }

    if ($gercek) {
        if (!@mkdir($fotoDizin, 0700, true) && !is_dir($fotoDizin)) {
            app_log('YEDEK HATASI: fotoğraf klasörü oluşturulamadı: ' . $fotoDizin);
            @unlink($dbHedef);
            return ['ok' => false, 'mesaj' => 'Fotoğraf klasörü oluşturulamadı', 'dosya' => '', 'boyut' => 0];
        }
        foreach ($gercek as $adDosya) {
            if (@copy(PHOTO_DIR . '/' . $adDosya, $fotoDizin . '/' . $adDosya)) $fotoSayisi++;
        }
        if ($fotoSayisi < count($gercek)) {
            app_log('YEDEK HATASI: ' . (count($gercek) - $fotoSayisi) . ' fotoğraf kopyalanamadı');
        }
    }
    // dbpath.php yedeği (hangi dosyanın DB olduğunu gösterir)
    if (is_file(DATA_DIR . '/dbpath.php')) {
        @copy(DATA_DIR . '/dbpath.php', $dizin . '/' . $ad . '-dbpath.php');
    }
    // MANIFEST: içerik listesi — geri yüklemede doğrulama için
    $mf = "# Mim Halı Yıkama yedek manifesti\n";
    $mf .= 'zaman: ' . date('c') . "\n";
    $mf .= 'sebep: ' . $sebep . "\n";
    $mf .= 'veritabani: ' . basename($dbHedef) . "\n";
    $mf .= 'foto_dosya: ' . $fotoSayisi . "\n";
    $mf .= 'foto_kayit: ' . $fotoKayit . "\n";
    $mf .= "fotolar:\n";
    foreach ($gercek as $adDosya) $mf .= '  ' . $adDosya . "\n";
    @file_put_contents($dizin . '/' . $ad . '.manifest', $mf);

    // ---------------------------------------------------- 4) eski yedekleri temizle
    yedek_eski_temizle();

    $bayt = (int)@filesize($dbHedef);
    $sure = round(microtime(true) - $baslangic, 2);
    $mesaj = 'Yedek alındı: ' . $ad . ' · DB ' . round($bayt / 1048576, 2) . ' MB'
        . ' · ' . $fotoSayisi . ' fotoğraf · ' . $sure . ' sn';
    app_log('YEDEK: ' . $mesaj);
    return ['ok' => true, 'mesaj' => $mesaj, 'dosya' => basename($dbHedef), 'boyut' => $bayt];
}

/** Saklama politikası: YEDEK_SAKLAMA_GUN günden eski yedekleri sil. */
function yedek_eski_temizle(): int
{
    $dizin = yedek_dizini();
    if (!is_dir($dizin)) return 0;
    $silinen = 0;
    $esik = time() - (YEDEK_SAKLAMA_GUN * 86400);
    foreach (glob($dizin . '/yedek-*') ?: [] as $f) {
        if (is_dir($f)) {                              // fotoğraf klasörü
            if (@filemtime($f) < $esik) { @array_map('unlink', glob($f . '/*') ?: []); @rmdir($f); $silinen++; }
            continue;
        }
        if (@filemtime($f) < $esik) { @unlink($f); $silinen++; }
    }
    return $silinen;
}

/** Mevcut yedekleri listele (yönetici ekranı ve testler için). */
function yedek_liste(): array
{
    $dizin = yedek_dizini();
    if (!is_dir($dizin)) return [];
    $liste = [];
    foreach (glob($dizin . '/yedek-*.db') ?: [] as $f) {
        $ad = basename($f, '.db');
        $mf = $dizin . '/' . $ad . '.manifest';
        $foto = is_dir($dizin . '/' . $ad . '-fotos') ? count(glob($dizin . '/' . $ad . '-fotos/*') ?: []) : 0;
        $liste[] = [
            'ad' => $ad,
            'dosya' => basename($f),
            'boyut' => (int)@filesize($f),
            'zaman' => date('Y-m-d H:i:s', (int)@filemtime($f)),
            'foto' => $foto,
            'manifest' => is_file($mf) ? (string)@file_get_contents($mf) : '',
        ];
    }
    rsort($liste);   // en yeni önce
    return $liste;
}

/** Son yedeğin sağlığını doğrular (yayın öncesi kontrol için). */
function yedek_saglik(): array
{
    $liste = yedek_liste();
    if (!$liste) return ['ok' => false, 'mesaj' => 'Hiç yedek yok — veri kaybı riski!'];
    $son = $liste[0];
    $yas = (time() - (int)@filemtime(yedek_dizini() . '/' . $son['dosya'])) / 3600;
    $durum = 'ok';
    if ($yas > 48) $durum = 'uyari';
    return [
        'ok' => $durum === 'ok',
        'mesaj' => 'Son yedek: ' . $son['zaman'] . ' (' . round($yas, 1) . ' saat önce, '
            . round($son['boyut'] / 1048576, 2) . ' MB, ' . $son['foto'] . ' fotoğraf)',
        'adet' => count($liste),
        'yas_saat' => round($yas, 1),
    ];
}

// ================================================================ YÖNETİCİ EKRANI
/** Otomatik yedek durumu ve yedek geçmişi (yedek.indir yetkisi gerekir). */
function yedek_durum(): void
{
    need_perm('yedek.indir');
    $sd = yedek_saglik();
    $liste = yedek_liste();
    $cronToken = (string)setting('cron_token', '');
    $healthToken = (string)setting('health_token', '');
    if ($healthToken === '') {
        setting_set('health_token', bin2hex(random_bytes(16)));
        $healthToken = (string)setting('health_token', '');
    }
    $base = rtrim((string)($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'
        ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

    layout_head('Yedekleme', 'ayarlar');

    // durum uyarısı — yedek yoksa kırmızı, eskiyse sarı
    if (!$liste) {
        echo '<div class="alert danger"><b>⚠ HİÇ YEDEK YOK.</b> Sunucu çökerse veya veri '
            . 'bozulursa geri yüklenecek dosya bulunamaz. Aşağıdaki cron kurulumu '
            . '<b>mutlaka yapılmalıdır</b>.</div>';
    } elseif (!$sd['ok']) {
        echo '<div class="alert warn"><b>⚠ Yedek eski.</b> ' . e($sd['mesaj'])
            . ' — ' . e((string)YEDEK_SAKLAMA_GUN) . ' saatten eskiyse sunucu yedeği durmuş olabilir.</div>';
    } else {
        echo '<div class="alert"><b>✓ Otomatik yedek çalışıyor.</b> ' . e($sd['mesaj']) . '</div>';
    }

    // elle yedek al
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'al') {
        csrf_check();
        $r = yedek_al('elle');
        $_SESSION[$r['ok'] ? 'flash' : 'flash_hata'] = $r['mesaj'];
        redirect('yedek-durum');
    }
    echo '<form method="post" action="?p=yedek-durum" class="btnrow">'
        . csrf_field() . '<input type="hidden" name="islem" value="al">'
        . '<button class="btn btn-primary">💾 Şimdi Yedek Al</button>'
        . '<a class="btn" href="?p=yedek">⬇ Veritabanını İndir</a></form>';

    // kurulum bilgisi — cPanel cron kurulumu
    echo '<div class="card"><h2>⏰ Otomatik Yedek Kurulumu (cPanel)</h2>'
        . '<p class="muted small">Bu bağlantıyı cPanel → Cron Jobs → "Add New Cron Job" '
        . 'kutusuna yapıştırın. Her gün gece 03:00\'te otomatik yedek alır.</p>'
        . '<label>Günlük yedek (her sabah 03:00)<input readonly '
        . 'value="' . e($base . '/index.php?p=yedek-cron&amp;token=' . $cronToken) . '"></label>'
        . '<p class="muted small"><b>Alternatif:</b> cPanel Cron Jobs → <code>*/30 * * * * '
        . 'curl -s "' . e($base . '/index.php?p=yedek-cron&amp;token=' . $cronToken) . '"</code>'
        . ' <br><span class="muted">Her 30 dakikada yedeği kontrol eder; mevcut yedek 1 saatten '
        . 'yeniyse atlar (gereksiz yedek birikmez).</span></p>'
        . '<h3>Sağlık kontrolü (izleme araçları için)</h3>'
        . '<label>Sağlık ucu<input readonly value="' . e($base . '/index.php?p=saglik&amp;token=' . $healthToken) . '"></label>'
        . '<p class="muted small">Bu adresi uptime monitörüne verirseniz sistem çökerse '
        . 'e-posta alırsınız. Erişim yalnızca bu anahtarla mümkündür.</p></div>';

    // geçmiş
    echo '<div class="card"><h2>Yedek Geçmişi (' . count($liste) . ')</h2>';
    if (!$liste) {
        echo '<p class="muted">Henüz yedek alınmamış.</p>';
    } else {
        echo '<div class="kv"><div>Saklama süresi</div><div>' . (int)YEDEK_SAKLAMA_GUN . ' gün</div></div>';
        foreach ($liste as $y) {
            echo '<div class="kv"><div>' . e($y['zaman']) . '</div><div>'
                . round($y['boyut'] / 1048576, 2) . ' MB · ' . (int)$y['foto'] . ' fotoğraf</div></div>';
        }
    }
    echo '</div>';

    // geçmiş log
    $log = DATA_DIR . '/app.log';
    if (is_file($log)) {
        $satirlar = array_filter(array_slice(file($log) ?: [], -400),
            fn($l) => stripos($l, 'YEDEK') !== false);
        echo '<div class="card"><h2>Yedekleme Kayıtları</h2>';
        if (!$satirlar) {
            echo '<p class="muted">Henüz yedekleme kaydı yok.</p>';
        } else {
            echo '<pre style="max-height:220px;overflow:auto;font-size:.75rem;white-space:pre-wrap">'
                . e(implode('', array_slice($satirlar, -30))) . '</pre>';
        }
        echo '</div>';
    }
    layout_foot('ayarlar');
}
