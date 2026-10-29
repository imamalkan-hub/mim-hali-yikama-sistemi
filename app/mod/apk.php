<?php
/**
 * Mobil uygulama (APK) indirme sayfası.
 *
 * APK dosyası `apk/MimHaliYikama.apk` yolunda durur ve doğrudan
 * sunucudan indirilir. Sayfa yalnızca giriş yapmış personel/yöneticiye
 * görünür (menü girdisi de aynı koşulu taşır).
 */
declare(strict_types=1);

const APK_DOSYA_ADI = 'MimHaliYikama.apk';

/**
 * APK dosyasının tam yolu.
 * mod/ klasörü sitenin kök dizinindedir → apk/ bir üst dizindedir.
 * (Sabit ifade kullanılamaz: dirname() derleme zamanında çalışmaz.)
 */
function apk_yol(): string
{
    return dirname(__DIR__) . '/apk/' . APK_DOSYA_ADI;
}

/** APK sürüm bilgisi (dosya adına gömülü değil; build sırasında sabit). */
function apk_surum(): string
{
    return '1.0 (build 1)';
}

/** APK dosyası sunucuda var mı ve okunabilir mi? */
function apk_hazir_mi(): bool
{
    return is_file(apk_yol()) && is_readable(apk_yol())
        && filesize(apk_yol()) > 0;
}

/** APK dosyasını indir (Content-Disposition: attachment). */
function apk_indir(): void
{
    if (!apk_hazir_mi()) {
        http_response_code(404);
        echo '<p class="alert">Uygulama dosyası henüz sunucuya yüklenmemiş.</p>';
        return;
    }
    $boyut = filesize(apk_yol());
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Length: ' . $boyut);
    header('Content-Disposition: attachment; filename="' . APK_DOSYA_ADI . '"');
    header('Cache-Control: no-store');
    readfile(apk_yol());
    exit;
}

/** Panelde görünecek indirme sayfası. */
function apk_sayfa(): void
{
    layout_head('Mobil Uygulama', 'apk');
    $hazir = apk_hazir_mi();
    $boyut = $hazir ? filesize(apk_yol()) : 0;
    $mb = $boyut > 0 ? number_format($boyut / 1048576, 1, ',', '.') . ' MB' : '—';
    $tarih = $hazir ? date('d.m.Y H:i', (int)filemtime(apk_yol())) : '—';

    echo '<div class="card">';
    echo '<h2>📱 Mobil Uygulama (Android)</h2>';
    echo '<p>Saha personeli için <b>native Android uygulaması</b>. Web tarayıcısı değil; '
        . 'doğrudan kurulabilir, çevrimdışı arayüz + hızlı veri erişimi sunar.</p>';

    if ($hazir) {
        echo '<div class="alert" style="margin:14px 0">';
        echo '<b>Sürüm:</b> ' . e(apk_surum()) . ' &nbsp;·&nbsp; ';
        echo '<b>Boyut:</b> ' . e($mb) . ' &nbsp;·&nbsp; ';
        echo '<b>Güncelleme:</b> ' . e($tarih);
        echo '</div>';
        echo '<a class="btn btn-block" href="?p=apk-indir">⬇ Android uygulamasını indir</a>';
        echo '<p style="font-size:.85rem;color:#64748b;margin-top:10px">';
        echo 'Telefonda <b>Ayarlar → Bilgi → Uygulamalar</b> bölümünden bilinmeyen kaynaklara izin verin. ';
        echo 'Kurulum sonrası uygulamaya <b>kullanıcı adınız ve şifrenizle</b> giriş yapın.';
        echo '</p>';
    } else {
        echo '<p class="alert">Uygulama dosyası henüz sunucuya yüklenmemiş. '
            . 'Bu durum normaldir; yönetici APK\'ı yüklediğinde burada indirme düğmesi görünür.</p>';
    }
    echo '</div>';

    echo '<div class="card">';
    echo '<h3>Uygulama ne yapar?</h3><ul>';
    echo '<li>Panel özeti — bugünkü alım/teslim, gecikmiş sayıları, günlük kasa</li>';
    echo '<li>Sipariş listesi ve arama (kod, müşteri, telefon)</li>';
    echo '<li>Sipariş detayı — parçalar, ödemeler, fotoğraflar</li>';
    echo '<li>Durum ilerletme (akış kuralları ve yönetici gerekçesi geçerlidir)</li>';
    echo '<li>Alım / Teslimat / Gecikmiş listeleri</li>';
    echo '<li>Müşteri arama</li>';
    echo '</ul>';
    echo '<p style="font-size:.85rem;color:#64748b">Yetkiler web paneliyle birebir aynıdır; '
        . 'birim fiyat ve para değerleri kuruş cinsinden saklanır.</p>';
    echo '</div>';

    layout_foot();
}
