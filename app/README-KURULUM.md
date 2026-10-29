# KURULUM — Mim Halı Yıkama İşletme Sistemi

Sistem tek klasörlük bir PHP uygulamasıdır. Paylaşımlı hosting (cPanel/Plesk) veya
herhangi bir PHP 7.4+ sunucusunda çalışır. Veritabanı gerektirmez (SQLite dahili).

## Gereksinimler
- PHP **7.4 veya üstü** (pdo_sqlite eklentisi — neredeyse her hostinge dahildir)
- Apache (önerilen; `.htaccess` dahildir) veya nginx
- HTTPS (alan adı ile birlikte ücretsiz Let's Encrypt)

## Adım adım kurulum (cPanel)

1. **Alt alan adı oluştur**: cPanel → Domains → Create A New Domain
   → örn. `sistem.mimhaliyikama.com` → dizin: `sistem.mimhaliyikama.com`
2. **Zip'i yükle**: cPanel → File Manager → `sistem.mimhaliyikama.com` klasörüne gir →
   Upload → `hali-sistemi-v1.zip` → sağ tık → Extract Here
   (zip içindeki `app` klasörünün **içeriği** klasörün kökünde olmalı:
   `index.php`, `lib.php`, `style.css`... kökte durmalı)
3. **Yazma izni**: `data` klasörü otomatik oluşur; oluşmazsa elle `data` adında
   klasör aç ve iznini 755 yap.
4. **HTTPS**: cPanel → SSL/TLS Status → `sistem.mimhaliyikama.com` için
   "Run AutoSSL".
5. **Aç**: tarayıcıdan `https://sistem.mimhaliyikama.com` → ilk ekranda
   **şifreni belirle** → sistem hazır.
6. **Ayarlar**'a gir: işletme adı/telefon ve **birim fiyatları** doldur
   (fiyat girmezsen sipariş girişinde elle yazarsın).

## Telefona kurulum (PWA)
1. Telefonda Chrome/Safari ile sisteme gir, giriş yap.
2. Chrome: menü → **"Ana ekrana ekle"** / Safari: Paylaş → **"Ana Ekrana Ekle"**.
3. Artık tek dokunuşla uygulama gibi açılır.

## Yedekleme
- Ayarlar → **"Veritabanı yedeğini indir"** → tek dosya iner; haftada bir indirip
  telefonda/bilgisayarda sakla.
- Sunucu tarafı otomatik yedek (opsiyonel): cPanel → Cron Jobs →
  `cp ~/sistem.mimhaliyikama.com/data/*.db ~/backups/mim-$(date +\%F).db` (her gece).

## Güvenlik notları
- Veritabanı `data/` içinde **rastgele adla** tutulur ve `.htaccess` ile web'den
  tamamen kapatılmıştır.
- Şifre veritabanında hash olarak saklanır; giriş denemeleri kısıtlanır.
- HTTPS şart — oturum çerezi şifreli taşınır.

## Sorun giderme
| Belirti | Çözüm |
|---|---|
| "pdo_sqlite yok" benzeri hata | Hosting'den PHP SQLite eklentisini isteyin (çoğunda standart) |
| Boş/beyaz sayfa | PHP sürümü 7.4'ten eski olabilir |
| Ana ekranda site logosu çıkmıyor | PWA ikonu için HTTPS şart |
| Veritabanı dosyası tarayıcıdan açılıyor (nginx) | `data` klasörüne nginx kuralı: `location ~ /data/ { deny all; }` |
