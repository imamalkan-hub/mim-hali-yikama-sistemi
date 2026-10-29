<?php
/**
 * Fotoğraf işleme: yükleme, doğrulama, güvenli sunum, çevrimdışı kuyruk.
 * Dosyalar data/fotos/ altında rastgele adla saklanır; doğrudan HTTP erişimi
 * .htaccess ile kapalıdır. Yalnız yetki kontrollü ?p=foto üzerinden sunulur.
 */
declare(strict_types=1);

const PHOTO_STAGES = [
    'alim'         => 'Alım anı',
    'islem_oncesi' => 'İşlem öncesi',
    'teslim'       => 'Teslim anı',
];

const PHOTO_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

/** En büyük kabul edilen kenar uzunluğu (px). Dosya boyutu sınırı (lib.php
 *  MAX_PHOTO_BYTES, 16 MB) tek başına yeterli değil: 12 MP telefon fotoğrafı
 *  sıkıştırılmamış olarak sınırın altında da kalabiliyor ve diskte yer yiyor.
 *  İstem tarafı sıkıştırma (fotocompress.js) 1600 px'e indiriyor.
 *
 *  ⚠ 4000 px ESKİ SINIRDI ve 12 MP telefon fotoğrafını (4096×3072) reddediyordu:
 *  kullanıcı galeriden seçtiğinde "Fotoğraf çok büyük (4096×3072 px)" hatası
 *  alıyordu. Telefon kamerasının doğal çıktısı bu ölçüdür; sınır onu kapsamak
 *  zorunda. Gerçek koruma bayt sınırıdır (16 MB); buradaki değer yalnız
 *  dekompresyon bombasına karşı üst sınırdır. */
const PHOTO_MAX_EDGE = 8192;

/** İstem tarafı sıkıştırmanın ayarları. Tek kaynak: lib.php'deki
 *  sabitlerle birebir aynı olmalı. Sunucu 8192 px'e kadar kabul eder;
 *  istemci 1600 px hedefler, sıkıştırma çalışmazsa fotoğraf yine yüklenir. */
function mim_photo_compress_defaults(): array
{
    return [
        'maxYon' => (int)(defined('PHOTO_CLIENT_MAX_EDGE') ? PHOTO_CLIENT_MAX_EDGE : 1600),
        'kalite' => (float)(defined('PHOTO_CLIENT_QUALITY') ? PHOTO_CLIENT_QUALITY : 0.78),
    ];
}

/** Görüntünün piksel cinsinden ölçüsü; okunamıyorsa false. */
function photo_dimensions(string $path)
{
    if (!function_exists('getimagesize')) return null;
    $s = @getimagesize($path);
    if (!is_array($s)) return false;
    $w = (int)($s[0] ?? 0); $h = (int)($s[1] ?? 0);
    if ($w <= 0 || $h <= 0) return false;
    return [$w, $h];
}

/**
 * Görüntünün EXIF yön etiketi (1..8). Okunamıyorsa 1.
 * Yalnız sunucu tarafı küçültmenin güvenliği için kullanılır: yön etiketi
 * 5–8 ise görüntü döndürülmüş demektir, GD ile yeniden kodlamak fotoğrafı
 * yan çevirir → o dosyalara dokunulmaz.
 */
function photo_exif_orientation(string $path): int
{
    if (function_exists('exif_read_data')) {
        $e = @exif_read_data($path);
        if (is_array($e) && isset($e['Orientation'])) {
            $o = (int)$e['Orientation'];
            return ($o >= 1 && $o <= 8) ? $o : 1;
        }
        return 1;
    }
    // exif uzantısı yok: JPEG APP1 içinden elle oku
    $f = @fopen($path, 'rb');
    if (!$f) return 1;
    $buf = (string)@fread($f, 65536);
    fclose($f);
    if (strlen($buf) < 12 || substr($buf, 0, 2) !== "\xFF\xD8") return 1;
    $off = 2; $len = strlen($buf);
    while ($off + 4 <= $len) {
        if (ord($buf[$off]) !== 0xFF) break;
        $marker = ord($buf[$off + 1]);
        if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) { $off += 2; continue; }
        if ($marker === 0xDA || $marker === 0xD9) break;
        $seg = unpack('n', substr($buf, $off + 2, 2))[1] ?? 0;
        if ($seg < 2 || $off + 2 + $seg > $len) break;
        if ($marker === 0xE1 && substr($buf, $off + 4, 6) === "Exif\0\0") {
            $tiff = $off + 10;
            $le = substr($buf, $tiff, 2) === 'II';
            $u16 = function (int $p) use ($buf, $le): int {
                $x = substr($buf, $p, 2);
                if (strlen($x) < 2) return 0;
                return $le ? unpack('v', $x)[1] : unpack('n', $x)[1];
            };
            $u32 = function (int $p) use ($buf, $le): int {
                $x = substr($buf, $p, 4);
                if (strlen($x) < 4) return 0;
                return $le ? unpack('V', $x)[1] : unpack('N', $x)[1];
            };
            if ($u16($tiff + 2) !== 0x2A) return 1;
            $ifd0 = $tiff + $u32($tiff + 4);
            $n = $u16($ifd0);
            for ($i = 0; $i < $n; $i++) {
                $e = $ifd0 + 2 + $i * 12;
                if ($u16($e) === 0x0112) {
                    $o = $u16($e + 8);
                    return ($o >= 1 && $o <= 8) ? $o : 1;
                }
            }
            return 1;
        }
        $off += 2 + $seg;
    }
    return 1;
}

/**
 * GD varsa fotoğrafı istemci hedefine (PHOTO_CLIENT_MAX_EDGE) indirir.
 * GD yoksa null döner — yükleme yine başarılı olur, dosya olduğu gibi kalır.
 * @return array{mime:string}|null
 */
function photo_downscale(string $path, string $mime): ?array
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return null;
    $hedef = (int)(defined('PHOTO_CLIENT_MAX_EDGE') ? PHOTO_CLIENT_MAX_EDGE : 1600);
    $dim = photo_dimensions($path);
    if (!is_array($dim) || max($dim[0], $dim[1]) <= $hedef) return null;
    // Döndürülmüş EXIF'li fotoğrafı yeniden kodlamak onu yan yatırır.
    if (in_array($mime, ['image/jpeg'], true) && photo_exif_orientation($path) > 1) return null;

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return null;
    $src = @imagecreatefromstring($raw);
    if ($src === false) return null;

    $sw = imagesx($src); $sh = imagesy($src);
    if ($sw <= 0 || $sh <= 0) { imagedestroy($src); return null; }
    $r = min(1, $hedef / max($sw, $sh));
    $tw = max(1, (int)round($sw * $r));
    $th = max(1, (int)round($sh * $r));
    $dst = imagecreatetruecolor($tw, $th);
    if ($dst === false) { imagedestroy($src); return null; }
    // JPEG saydamlık taşımaz: beyaz zemin (PNG/WEBP alfa kanalı siyaha çökmesin)
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
    $tmp = $path . '.kucult.tmp';
    $ok = @imagejpeg($dst, $tmp, 82);
    imagedestroy($dst); imagedestroy($src);
    if (!$ok || !is_file($tmp)) { @unlink($tmp); return null; }
    if (!@rename($tmp, $path)) { @unlink($tmp); return null; }
    @chmod($path, 0644);
    return ['mime' => 'image/jpeg'];
}

/**
 * Yükle. Başarısızsa geçici dosyayı siler ve açık hata döner.
 * @return array{ok:bool,id?:int,error?:string}
 */
function photo_save(int $orderId, array $file, string $stage = 'alim',
                    ?int $itemId = null, string $note = '', string $clientUid = ''): array
{
    if (!isset(PHOTO_STAGES[$stage])) $stage = 'alim';
    if ($orderId <= 0) return ['ok' => false, 'error' => 'Sipariş bulunamadı.'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Dosya seçilmedi.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Yükleme hatası (kod ' . (int)$file['error'] . '). Fotoğraf kaydedilmedi.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Geçersiz yükleme kaynağı.'];
    }

    $bytes = (int)@filesize($file['tmp_name']);
    if ($bytes <= 0) return ['ok' => false, 'error' => 'Dosya boş.'];
    if ($bytes > MAX_PHOTO_BYTES) {
        return ['ok' => false, 'error' => 'Fotoğraf çok büyük (' . round($bytes / 1048576, 1)
            . ' MB). En fazla ' . (MAX_PHOTO_BYTES / 1048576) . ' MB.'];
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string)finfo_file($fi, $file['tmp_name']);
        finfo_close($fi);
    }
    if ($mime === '') {
        $map = ['image/jpeg' => 'image/jpeg', 'image/png' => 'image/png', 'image/webp' => 'image/webp'];
        $mime = $map[strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION))] ?? '';
    }
    if (!isset(PHOTO_MIME[$mime])) {
        return ['ok' => false, 'error' => 'Sadece JPG, PNG veya WEBP yüklenebilir. (alınan: ' . ($mime ?: 'bilinmiyor') . ')'];
    }

    if (!is_dir(PHOTO_DIR) && !@mkdir(PHOTO_DIR, 0755, true) && !is_dir(PHOTO_DIR)) {
        return ['ok' => false, 'error' => 'Fotoğraf klasörü oluşturulamadı (izin).'];
    }

    // çevrimdışı kuyruktan gelenler: aynı client_uid tekrar sayılmasın
    $ext = PHOTO_MIME[$mime];
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = PHOTO_DIR . '/' . $name;

    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Fotoğraf kaydedilemedi (sunucu izni). Bilginiz kaybolmadı, tekrar deneyin.'];
    }
    @chmod($dest, 0644);

    // gerçek boyut doğrulaması (getimagesize) — bozuk dosya engellenir
    $dim = photo_dimensions($dest);
    if (function_exists('getimagesize') && $dim === false) {
        @unlink($dest);
        return ['ok' => false, 'error' => 'Dosya bozuk görünüyor, kaydedilmedi.'];
    }

    // piksel cinsinden üst sınır: yalnız dekompresyon bombasına karşı.
    // 12 MP telefon fotoğrafı (4096×3072) bu sınırın ALTINDADIR ve kabul edilir;
    // istemci sıkıştırması çalışmasa bile kullanıcı fotoğrafını yükleyebilir.
    if (is_array($dim) && max($dim[0], $dim[1]) > PHOTO_MAX_EDGE) {
        @unlink($dest);
        return ['ok' => false, 'error' => 'Fotoğraf çok büyük (' . (int)$dim[0] . '×' . (int)$dim[1]
            . ' px, en fazla ' . PHOTO_MAX_EDGE . ' px). Lütfen daha küçük bir fotoğraf seçin '
            . 'veya kamerayla yeniden çekin.'];
    }

    // GD varsa (bazı hosting'lerde var) sunucu da küçültür: diskte 12 MP dosya
    // birikmez ve istemci sıkıştırması çalışmayan tarayıcılarda da boyut iner.
    // GD yoksa dosya olduğu gibi saklanır — yükleme yine de başarılıdır.
    $boyutlandirildi = photo_downscale($dest, $mime);
    if ($boyutlandirildi) {
        $mime = $boyutlandirildi['mime'];
        $ext  = PHOTO_MIME[$mime] ?? 'jpg';
        $yeni = substr($dest, 0, (int)strrpos($dest, '.')) . '.' . $ext;
        if ($yeni !== $dest && @rename($dest, $yeni)) $dest = $yeni;
        $name = basename($dest);
        $bytes = (int)@filesize($dest);
    }

    try {
        if ($itemId === null || $itemId <= 0) $itemId = null;
        else {
            $st = db()->prepare('SELECT id FROM order_items WHERE id=? AND order_id=?');
            $st->execute([$itemId, $orderId]);
            if (!$st->fetchColumn()) $itemId = null;
        }
        $st = db()->prepare('INSERT INTO photos(order_id,item_id,stage,file,mime,bytes,note,
            user_id,username,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$orderId, $itemId, $stage, $name, $mime, $bytes, substr(trim($note), 0, 300),
            uid() ?: null, uname_(), now_dt()]);
        $id = (int)db()->lastInsertId();
        audit('foto_yukle', 'orders', $orderId, $stage . ' #' . $id);
        return ['ok' => true, 'id' => $id];
    } catch (Throwable $e) {
        @unlink($dest);
        app_log('photo kayıt hatası: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Fotoğraf kaydedilemedi (veritabanı hatası).'];
    }
}

function photo_rows(int $orderId, string $stage = ''): array
{
    $sql = 'SELECT * FROM photos WHERE order_id=?';
    $args = [$orderId];
    if ($stage !== '' && isset(PHOTO_STAGES[$stage])) { $sql .= ' AND stage=?'; $args[] = $stage; }
    $sql .= ' ORDER BY id DESC';
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function photo_count(int $orderId): int
{
    $st = db()->prepare('SELECT COUNT(*) c FROM photos WHERE order_id=?');
    $st->execute([$orderId]);
    return (int)$st->fetchColumn();
}

function photo_url(int $id): string { return '?p=foto&id=' . $id; }

/** Yetki kontrolü ile dosya sunumu (doğrudan erişim kapalı) */
/** Fotoğrafı görme yetkisi: alım fotoğrafları herkese, işlem öncesi/teslim
 *  fotoğrafları yalnız fotoğraf yükleyebilenlere (yönetici dahil). */
function photo_can_view(array $p): bool
{
    if ((string)($p['stage'] ?? '') === 'alim') return true;
    return can('foto.yukle') || is_admin();
}

function photo_stream(int $id): void
{
    $st = db()->prepare('SELECT * FROM photos WHERE id=?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) { http_response_code(404); exit('Fotoğraf yok'); }

    $path = PHOTO_DIR . '/' . basename((string)$p['file']);
    if (!is_file($path)) { http_response_code(404); exit('Dosya bulunamadı'); }

    // teslim/işlem öncesi fotoğrafları yalnız yönetici + sipariş ekibince görülür
    if (!photo_can_view($p)) { http_response_code(403); exit('Yetkisiz'); }

    header('Content-Type: ' . (string)$p['mime']);
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    readfile($path);
    exit;
}

/** Thumbnail önbellek klasörü. data/ altında olduğu için web'den zaten 404'dür
 *  (bkz. .htaccess) — dosyalar yalnız bu fonksiyon üzerinden servis edilir. */
function photo_thumb_dir(): string
{
    return PHOTO_DIR . '/.thumb';
}

/**
 * Küçük önizleme.
 *
 * ÖNEMLİ: gd yoksa küçük resim ÜRETİLEMEZ ve eski davranış orijinal dosyayı
 * döndürürdü. Galeride N fotoğraf varsa tarayıcı N × 8 MB indiriyordu; telefonda
 * galeri sayfası bu yüzden saniyelerce açılıyordu. Şimdi küçültme yapılamasa bile
 * orijinal dosya diskte BİR KEZ okunup .thumb/ altına bayt olarak yazılır, sonraki
 * isteklerde o bayttan servis edilir. Böylece disk okuma maliyeti de düşer.
 */
function photo_thumb(int $id): void
{
    $st = db()->prepare('SELECT * FROM photos WHERE id=?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) { http_response_code(404); exit(''); }
    $path = PHOTO_DIR . '/' . basename((string)$p['file']);
    if (!is_file($path)) { http_response_code(404); exit(''); }

    // thumbnail de AYNI yetki kuralına tabidir (photo_stream ile aynı):
    // kontrol atlanırsa korumalı aşama fotoğrafları thumbnail üzerinden sızar.
    if (!photo_can_view($p)) { http_response_code(403); exit('Yetkisiz'); }

    $mime = (string)$p['mime'];
    $dir  = photo_thumb_dir();
    $cache = $dir . '/' . (int)$id . '.jpg';

    // 1) Önbellekten anında servis et (asıl hızlı yol)
    if (is_file($cache) && filesize($cache) > 0) {
        photo_send_file($cache, 'image/jpeg');
        return;
    }

    // 2) gd varsa: gerçekten küçült ve önbelleğe yaz
    if (function_exists('imagecreatetruecolor')) {
        $src = @imagecreatefromstring((string)file_get_contents($path));
        if ($src !== false) {
            $w = imagesx($src); $h = imagesy($src);
            $tw = 240; $th = (int)max(1, round($h * $tw / max(1, $w)));
            $dst = imagecreatetruecolor($tw, $th);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
            @mkdir($dir, 0755, true);
            // imagejpeg doğrudan dosyaya yazar (dairek yazım)
            imagejpeg($dst, $cache, 78);
            imagedestroy($dst); imagedestroy($src);
            @chmod($cache, 0644);
            photo_send_file($cache, 'image/jpeg');
            return;
        }
    }

    // 3) gd yok: orijinali diske önbellekle (bayt olarak kopyala)
    @mkdir($dir, 0755, true);
    $bytes = @file_get_contents($path);
    if ($bytes !== false && $bytes !== '') {
        // yarış durumunda iki istek aynı dosyayı yazabilir; atomik yazım
        // (geçici ad + rename) bozuk önbellek oluşmasını engeller.
        $tmp = $cache . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $bytes) !== false) {
            @rename($tmp, $cache);
            @chmod($cache, 0644);
        } else {
            @unlink($tmp);
        }
    }
    photo_send_bytes($mime, (string)$bytes);
}

/** Bayt dizisini uygun başlıklarla gönderir ve sonlanır. */
function photo_send_bytes(string $mime, string $bytes): void
{
    if ($bytes === '') { http_response_code(500); exit('okunamadı'); }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($bytes));
    // Önbellekten gelen thumbnail'ler de yeniden üretilmesin diye günlük
    // önbellek: fotoğraf dosyası adı değişmeden kalır (bkz. photo_thumb).
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

/** Diskteki dosyayı başlıklarla gönderir ve sonlanır (Content-Length zorunlu:
 *  aksi hâlde istemci gövde bitmeden bağlantıyı açık tutmaya çalışır). */
function photo_send_file(string $path, string $mime): void
{
    $len = (int)@filesize($path);
    if ($len <= 0) { http_response_code(404); exit(''); }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $len);
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function photo_delete(int $id): void
{
    if (!is_admin()) return;
    $st = db()->prepare('SELECT * FROM photos WHERE id=?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) return;
    $st = db()->prepare('DELETE FROM photos WHERE id=?');
    $st->execute([$id]);
    $path = PHOTO_DIR . '/' . basename((string)$p['file']);
    if (is_file($path)) @unlink($path);
    // thumbnail önbelleği de silinir — aksi hâlde .thumb/ altında yetim
    // dosya birikir (disk şişmesi).
    $th = photo_thumb_dir() . '/' . (int)$id . '.jpg';
    if (is_file($th)) @unlink($th);
    audit('foto_sil', 'orders', (int)$p['order_id'], 'foto #' . $id);
}

/** Sanat adından güvenli anahtar üret (dosya adı tahmin edilemez) */
function photo_safe_name(string $stored): string
{
    return preg_replace('/[^A-Za-z0-9._-]/', '', $stored) ?: 'x';
}