# Halı Yıkama İşletme Sistemi

### 🌐 Canlı demo: **[https://mimhaliyikama.com/](https://mimhaliyikama.com/)**

> Şanlıurfa'da bir halı yıkama işletmesi için geliştirilmiştir.
> Yukarıdaki bağlantıdan kurulum sonrası sistemin nasıl göründüğünü görebilirsiniz.

**Ücretsiz ve açık kaynak** bir halı yıkama işletmesi yönetim sistemi.
Kurulum gerektirmez — tek klasörü sunucuya atıp açmanız yeterli.

- **Web arayüzü:** PHP 7.4+ + SQLite (MySQL/PostgreSQL gerekmez)
- **Veritabanı:** SQLite — dosya tabanlı, ayrı sunucu kurulumu gerekmez
- **Bağımlılık:** Composer yok, npm yok, build adımı yok
- **Mobil:** Native Android APK (Kotlin + Jetpack Compose) — WebView **değil**
- **Lisans:** MIT

> Şanlıurfa'da bir halı yıkama işletmesi için geliştirilmiş, ihtiyaç dışına
> çıkmadan başlayan; sonra başkalarının da kendi sitesine kurup
> kullanabileceği şekilde paketlenmiş sürüm.
> **Canlı örnek:** [https://mimhaliyikama.com/](https://mimhaliyikama.com/)

---

## Özellikler

| Alan | Neler var |
|---|---|
| **Sipariş** | Alım → yıkama → kurutma → kalite kontrol → paketleme → teslim akışı; parça bazlı fiyatlandırma; kalan bakiye |
| **Müşteri** | Kayıt, adres, telefon, WhatsApp opt-in, harita bağlantısı |
| **Saha** | Alım / Teslimat / Gecikmiş listeleri, rota planlama, QR etiket |
| **Fotoğraf** | Alım/teslimat fotoğrafı; cihazda otomatik küçültme + EXIF düzeltme; 8192 px'e kadar destek |
| **Kasa** | Nakit/banka hareketleri, gelir/gider, aylık raporlar |
| **Müşterilere mesaj** | WhatsApp Cloud API kuyruğu (token girilmezse kapalı) |
| **Halı takibi** | Müşteri takip kodu ile salt-okunur durum sayfası |
| **Web talebi** | Giriş gerektirmeyen ücretsiz alım talebi formu |
| **PWA** | Tarayıcıya "ana ekrana eklenebilir"; çevrimdışı okuma |
| **JSON API** | Mobil uygulama için 10 uç; bearer jeton; web paneliyle **aynı** yetki modeli |
| **Android APK** | Native uygulama: giriş, panel, sipariş arama, detay, durum ilerletme, foto yükleme |
| **Güvenlik** | CSRF, rol + izin modeli, parola hash'leme, giriş kısıtlama, `data/` web'den kapalı |

---

## Kurulum (5 dakika)

Tam adımlar için **[KURULUM.md](KURULUM.md)** dosyasına bakın.

**Özet (cPanel):**

1. cPanel → **Domains** → alt alan adı oluştur (örn. `sistem.sizinsiteniz.com`)
2. cPanel → **File Manager** → bu klasöre gir → **Upload** → `mim-hali-yikama-1.0.zip`
3. Zip'i **Extract Here** yap (içindeki `app/` klasörünün **içeriği** kökte olmalı)
4. `data` klasörü otomatik oluşur; oluşmazsa elle aç ve iznini **755** yap
5. cPanel → **SSL/TLS Status** → AutoSSL
6. Tarayıcıdan adresi aç → **işletme adı, telefon ve şifreni gir** → hazır

**Başka sunucu (VPS/Dedicated):**

```bash
unzip mim-hali-yikama-1.0.zip -d /var/www/sistem
chmod 755 /var/www/sistem/data
chown -R www-data:www-data /var/www/sistem/data
# nginx için: location ~ /data/ { deny all; }
```

---

## Yapılandırma

Uygulama açılınca **Ayarlar** ekranından şunları girersiniz:

- İşletme adı, telefon, adres
- **Birim fiyatlar** (halı türü → fiyat). Fiyat girmezseniz sipariş ekranında
  elle yazarsınız — sistem çalışır, zorunlu değildir.
- WhatsApp Cloud API bilgileri (isteğe bağlı; girmezseniz mesaj gönderilmez)
- Google yorum bağlantısı (isteğe bağlı)

**Para birimi:** Her değer **kuruş** (integer) olarak saklanır. 150.000 = 1.500,00 ₺.
Yuvarlama hatası oluşmaz.

---

## Veri ve yedekleme

- Veritabanı `data/` içinde **rastgele adda** bir dosyadır (tahmin edilemez)
- `data/` klasörü `.htaccess` ile web'den tamamen kapalı
- **Ayarlar → Veritabanı yedeğini indir** → tek dosya; haftada bir indirin
- Sunucu tarafı otomatik yedek (cPanel cron):

  ```
  cp ~/sistem/data/*.db ~/backups/sistem-$(date +\%F).db
  ```

---

## Klasör yapısı

```
app/
├── index.php        tek yönlendirici + giriş + kurulum + layout
├── lib.php          şema/migrate, izinler, durum akışı, para, yardımcılar
├── api.php          JSON API (mobil uygulama) — 10 uç
├── foto.php         fotoğraf kabul/doğrulama/sunum
├── fotocompress.js  istemci tarafı küçültme (EXIF korumalı)
├── wa.php           WhatsApp Cloud API kuyruğu
├── qrgen.js         bağımlılıksız QR üretici
├── style.css        tek stil dosyası
├── sw.js            service worker (çevrimdışı)
├── mod/             ekran modülleri
├── data/            veritabanı + log + yüklenen fotoğraflar (web'den kapalı)
└── apk/             APK dosyası (varsa)
```

---

## Mobil uygulama

**Android:** `releases/MimHaliYikama-1.0.apk`

- Native Kotlin + Jetpack Compose uygulamasıdır — **WebView değildir**
- Kurulum: telefonda APK'ı aç → "bilinmeyen kaynaklara izin ver" → kur
- Giriş: paneldeki kullanıcı adı ve şifreyle
- Uygulama sunucunun JSON API'sini kullanır; yetkiler panelle aynıdır

PWA alternatifi: Telefonda tarayıcıyla siteyi açıp **"Ana ekrana ekle"** deyin.

---

## Güvenlik notları

- Şifreler `password_hash()` ile saklanır; hiçbir yerde düz metin tutulmaz
- **Bu dağıtımda hiçbir şifre içermez.** Veritabanı, kurulum sırasında
  sizin belirlediğiniz şifreyle oluşur.
- Tüm formlarda CSRF jetonu; durum değiştiren işlemler yetki kontrolüne tabidir
- Giriş denemeleri kısıtlanır (kaba kuvvet koruması)
- `data/`, `*.db`, `*.log` dosyaları web'den 404 döner

Kendi sunucunuza kurduğunuzda: ilk iş **Ayarlar → Yönetici şifresini değiştir**,
sonra işletme bilgilerini girin.

---

## Katkı

Pull request memnuniyetle karşılanır. Lütfen **gizli bilgi (şifre, API anahtarı,
gerçek müşteri verisi) eklemeyin** — PR'lar tarama ile kontrol edilir.

## Lisans

MIT — ticari kullanım dahil serbestçe kullanabilir, değiştirebilir ve
dağıtabilirsiniz. Bkz. [LICENSE](LICENSE).
