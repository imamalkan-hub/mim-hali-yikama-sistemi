# KURULUM — Halı Yıkama İşletme Sistemi

Bu sistem tek klasörlük bir PHP uygulamasıdır. Paylaşımlı hosting (cPanel/Plesk)
veya kendi sunucunuzda çalışır. **Veritabanı sunucusu gerekmez** — SQLite
dahili gelir.

Canlı örnek: [https://mimhaliyikama.com/](https://mimhaliyikama.com/)

---

## Gereksinimler

| Gereksinim | Sürüm | Not |
|---|---|---|
| PHP | **7.4 veya üstü** | `pdo_sqlite` eklentisi şart (çoğu hostingde varsayılan) |
| Web sunucusu | Apache (önerilir) veya nginx | `.htaccess` ve `.user.ini` Apache/LiteSpeed içindir |
| HTTPS | Ücretsiz Let's Encrypt | Oturum çerezi şifreli taşınmazsa güvenlik düşer |
| Disk | ~2 MB + veri | Veritabanı ve fotoğraflar büyür |

**Kontrol:** Hosting panelinizde PHP sürümünü 7.4+ yapın. cPanel → *Select PHP
Version* → 7.4 veya 8.x.

---

## Kurulum — cPanel (en kolay yol)

### 1. Alt alan adı oluşturun
`cPanel → Domains → Create A New Domain`

- Alan adı: `sistem.sizinsiteniz.com`
- Kök dizin: `sistem.sizinsiteniz.com` (otomatik oluşur)

### 2. Dosyayı yükleyin
`cPanel → File Manager → sistem.sizinsiteniz.com → Upload`

- `mim-hali-yikama-1.0.zip` dosyasını yükleyin
- Yükledikten sonra dosyaya sağ tık → **Extract**

> ⚠️ Zip içindeki `app/` klasörünün **içeriği** kök dizinde olmalı.
> Yani `sistem.sizinsiteniz.com/index.php` görünmeli.
> Yanlışsa: `app` klasörünü açıp içindeki her şeyi bir üst dizine taşıyın.

### 3. `data` klasörünü hazırlayın
Zip içinde `app/data` klasörü boş gelir ve ilk istekte otomatik oluşur.
Olmazsa elle `data` adında bir klasör açın ve iznini **755** yapın.

### 4. HTTPS
`cPanel → SSL/TLS Status → Run AutoSSL` (ücretsiz, ~2 dakika)

### 5. İlk açılış
Tarayıcıdan `https://sistem.sizinsiteniz.com` adresini açın.

Karşınıza **kurulum ekranı** çıkar. Burada:

- **Kullanıcı adı** — `admin` önerilir (istediğinizi yazabilirsiniz)
- **Ad Soyad** — sizin adınız
- **İşletme adı** — örn. "Şanlıurfa Halı Yıkama"
- **Telefon** — işletme numaranız (boş bırakabilirsiniz)
- **Şifre** — en az 6 karakter, **iki kez** aynı

Bu şifre sizin belirlediğiniz şifredir; sistemde hazır bir şifre yoktur.

### 6. Ayarları doldurun
`Ayarlar → İşletme Bilgileri` ve `Ayarlar → Fiyat Listesi`

- İşletme adı / telefon / adres
- **Birim fiyatlar** — halı türü başına fiyat (TL). Boş bırakırsanız
  sipariş ekranında fiyatı elle yazarsınız; sistem yine çalışır.

### 7. Bitti
Panel açıldı. Alttaki menüden sipariş girebilirsiniz.

---

## Kurulum — kendi sunucunuz (VPS / Dedicated)

### Apache
```bash
unzip mim-hali-yikama-1.0.zip -d /var/www/sistem
chown -R www-data:www-data /var/www/sistem
chmod 755 /var/www/sistem/data
# .htaccess'in çalıştığından emin olun:
a2enmod rewrite
```

### nginx
`.htaccess` nginx'de çalışmaz — kuralları `server` bloğuna ekleyin:

```nginx
server {
    listen 443 ssl http2;
    server_name sistem.sizinsiteniz.com;
    root /var/www/sistem;
    index index.php;

    location ~ /data/ { deny all; }
    location ~ \.(db|db-wal|db-shm|log|sql|bak|zip)$ { deny all; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### PHP ayarları
`php.ini` (veya hosting paneli):

```ini
upload_max_filesize = 16M
post_max_size = 20M
max_execution_time = 120
```

Bu değerler zaten `.user.ini` ile birlikte gelir; yine de paylaşımlı hostingde
kontrol edin.

---

## Mobil uygulama (Android)

`releases/MimHaliYikama-1.0.apk` dosyası pakette gelir.

**Sunucuya kurmak (isteğe bağlı):**
1. APK'yı `app/apk/MimHaliYikama.apk` adıyla sunucuya kopyalayın
2. Panel → menüde **📱 Mobil Uygulama** → indirme düğmesi görünür

**Kurulum (telefon):**
1. APK dosyasını telefona indirin
2. Dosyayı açın → "bilinmeyen kaynaklara izin ver" uyarısını onaylayın
3. **Kur** → **Aç**
4. Paneldeki kullanıcı adı ve şifrenizle giriş yapın

> Uygulama **native** bir uygulamadır (WebView değil) ve sunucunuzun JSON
> API'sine bağlanır. Bu yüzden **sunucunuzun adresi** API taban adresi olarak
> kullanılır — kurulumdan sonra adresiniz değişirse uygulama bağlanamaz.

**PWA alternatifi (kurulum gerektirmez):**
Telefonda tarayıcıyla sisteminizi açın → tarayıcı menüsü → **"Ana ekrana
ekle"**.

---

## Veritabanı yedeği

### Manuel
`Ayarlar → Veritabanı yedeğini indir` → tek `.db` dosyası iner.
Haftada bir indirip saklayın.

### Otomatik (cPanel cron)
`cPanel → Cron Jobs → New Cron Job → Once per day`:

```bash
cp ~/sistem.sizinsiteniz.com/data/*.db ~/backups/sistem-$(date +\%F).db
```

### Otomatik (kendi sunucunuz)
```bash
0 3 * * * cp /var/www/sistem/data/*.db /var/backups/sistem-$(date +\%F).db
```

---

## Sorun giderme

| Belirti | Çözüm |
|---|---|
| "Class not found" / "pdo_sqlite yok" | Hosting'den `pdo_sqlite` eklentisini açın |
| Boş veya beyaz sayfa | PHP sürümü 7.4'ten eski olabilir; `error_log` kontrol edin |
| "Kurulum zaten tamamlanmış" | `data/` klasöründeki veritabanı dosyasını silin, tekrar açın |
| Fotoğraf yüklenmiyor | `upload_max_filesize` ≥ 16M olmalı (`.user.ini` mevcut) |
| 404 / kırmızı ekran | Apache'de `mod_rewrite` kapalı olabilir; `AllowOverride All` gerekir |
| Veritabanı tarayıcıda açılıyor | nginx kuralları eksik (yukarıya bakın) |
| Ana ekranda simge çıkmıyor | PWA simgesi için HTTPS şart |
| "İzin reddedildi" hatası | `data/` klasörü yazılabilir değil: `chmod 755` |
| Dosya yükleme sınırı hatası | `post_max_size` / `upload_max_filesize` değerlerini yükseltin |

### Tanılama
`Ayarlar → Sistem Durumu` ekranı: PHP sürümü, eklentiler, veritabanı sağlığı,
disk alanı, son hata kaydı.

---

## Yükseltme (yeni sürüm)

1. Mevcut `data/` klasörünü **yedekleyin** (Ayarlar → Veritabanı yedeğini indir)
2. Yeni sürümün dosyalarını üzerine yazın — **`data/` klasörüne dokunmayın**
3. `index.php` açıldığında şema otomatik güncellenir (migrate)

`data/` klasörünü silmeyin — tüm sipariş, müşteri ve ödeme kayıtları oradadır.

---

## Güvenlik kontrol listesi (kurulum sonrası)

- [ ] HTTPS çalışıyor (adres çubuğunda kilit)
- [ ] Yönetici şifreniz güçlü ve benzersiz
- [ ] Varsayılan kullanıcı adını (`admin`) değiştirdiniz mi? (isteğe bağlı)
- [ ] `data/` klasörü web'den 404 dönüyor
- [ ] `Ayarlar → Personel` ekranından kullanılmayan hesapları kapatın
- [ ] Otomatik yedek cron'u kuruldu

---

## Destek

Sorularınız için proje sayfasındaki **Issues** bölümünü kullanın.
Kendi sitenize kurulum yaparken karşılaştığınız hataları paylaşırsanız
yardımcı olunur.
