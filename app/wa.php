<?php
/**
 * WhatsApp Cloud API entegrasyonu.
 * Anahtarlar AYARLAR'da saklanır (kodda yok). Yapılandırılmamışsa
 * sahte başarı üretmez; kuyruğa alır ve panelde "yapılandırılmamış" gösterir.
 * Kurallar: 24 saatlik hizmet penceresi, onaylı şablon, izin (opt-in),
 * dedupe (aynı olay iki kez mesaj üretmez), kontrollü tekrar deneme.
 */
declare(strict_types=1);

const WA_GRAPH = 'https://graph.facebook.com/v20.0';
const WA_UA_DEFAULT = 'Mim Halı Yıkama';

/** Şablon yer tutucuları — Ayarlar ekranında da listelenir */
function wa_placeholder_list(): array
{
    return [
        'ad'       => 'Müşteri adı',
        'kod'      => 'Sipariş numarası',
        'isletme'  => 'İşletme adı',
        'tarih'    => 'Planlanan teslim tarihi',
        'tutar'    => 'Toplam tutar',
        'adres'    => 'Müşteri adresi',
        'takip_link' => 'Sipariş takip bağlantısı',
        'link'     => 'Anket / kampanya bağlantısı',
    ];
}

/** Kod içi varsayılan şablonlar — veritabanında karşılığı yoksa bunlar kullanılır */
function wa_default_templates(): array
{
    return [
        'alim_planlandi' => 'Merhaba {ad}, {isletme} — {kod} numaralı siparişiniz için alım planlandı. Planlanan tarih: {tarih}. Teşekkür ederiz.',
        'teslim_alindi'  => 'Merhaba {ad}, halılarınız {isletme} tarafından teslim alınmıştır. Sipariş numaranız: {kod}. Tahmini teslim tarihi: {tarih}. Bizi tercih ettiğiniz için teşekkür ederiz.',
        'yikamada'       => 'Merhaba {ad}, {kod} numaralı siparişiniz yıkamaya alındı. Bizi tercih ettiğiniz için teşekkür ederiz.',
        'paketlendi'     => 'Merhaba {ad}, {kod} numaralı siparişinizin yıkama ve paketleme işlemleri tamamlandı. Teslimat planınız için bilgilendirme yapılacaktır.',
        'dagitimda'      => 'Merhaba {ad}, halılarınız teslimat için dağıtıma çıkmıştır. Sipariş numaranız: {kod}.',
        'teslim'         => 'Merhaba {ad}, {kod} numaralı siparişiniz teslim edilmiştir. {isletme}’yi tercih ettiğiniz için teşekkür ederiz.',
        'teslim_gecikti' => 'Merhaba {ad}, {kod} numaralı siparişinizin teslim tarihi {tarih} idi. Yeni teslim tarihi için sizi bilgilendireceğiz. Sorunuz varsa yazabilirsiniz.',
        'memnuniyet'     => 'Merhaba {ad}, {isletme} hizmetimizi 1 dakikada puanlar mısınız? {link} Teşekkür ederiz.',
        'hatirlatma'     => 'Merhaba {ad}, halılarınızın yıkanma zamanı geldi. {isletme} olarak kapınızdan alım yapabiliriz. Randevu için bu mesajı yanıtlayın.',
    ];
}

/**
 * Onaylı mesaj şablonları. Sıralama: kod içi varsayılan ← veritabanı.
 * Yönetici Ayarlar ekranından düzenler; kayıt JSON olarak tutulur.
 * Anahtar 'wa_sablonlar'; eski 'wa_templates' kaydı varsa geriye uyum için okunur.
 */
function wa_templates(): array
{
    $def = wa_default_templates();
    $arr = null;
    foreach (['wa_sablonlar', 'wa_templates'] as $k) {
        $raw = setting($k, '');
        if ($raw !== '') {
            $d = json_decode($raw, true);
            if (is_array($d) && $d) { $arr = $d; break; }
        }
    }
    return is_array($arr) ? array_merge($def, $arr) : $def;
}

function wa_template_text(string $key): string
{
    $t = wa_templates();
    return (string)($t[$key] ?? '');
}

/**
 * Şablonun WhatsApp tarafından onaylandığı bilgisi veritabanındadır.
 * 24 saatlik hizmet penceresi DIŞINDA yalnızca onaylı şablon gönderilebilir.
 * Onay listesi JSON dizi: ["teslim","paketlendi"]
 */
function wa_approved_templates(): array
{
    $raw = setting('wa_sablonlar_onayli', '');
    $arr = $raw !== '' ? json_decode($raw, true) : [];
    return is_array($arr) ? array_values(array_map('strval', $arr)) : [];
}

function wa_template_approved(string $key): bool
{
    return in_array($key, wa_approved_templates(), true);
}

/**
 * Şablon metnini kaydet. Onay durumu ayrı tutulur (onay = ayrı bilgi).
 * @param array $onayli onaylanan anahtarların listesi
 */
function wa_templates_kaydet(array $metinler, array $onayli): void
{
    $temiz = [];
    foreach ($metinler as $k => $v) {
        $k = (string)$k;
        $v = trim((string)$v);
        if ($v === '') continue;                       // boş şablon gönderilmez
        if (!array_key_exists($k, wa_default_templates())) continue;  // bilinmeyen anahtar yok sayılır
        $temiz[$k] = wa_kirp($v, 1024);
    }
    $liste = [];
    foreach ($onayli as $k) {
        $k = (string)$k;
        if (array_key_exists($k, wa_default_templates())) $liste[] = $k;
    }
    setting_set('wa_sablonlar', (string)json_encode($temiz, JSON_UNESCAPED_UNICODE));
    setting_set('wa_sablonlar_onayli', (string)json_encode($liste, JSON_UNESCAPED_UNICODE));
}

/** mbstring olmaya kurulumlarda da çalışan güvenli kırpma.
 *  Türkçe karakterlerde 1024 bayt sınırı yeterlidir; bozuk UTF-8 üretmemek için
 *  çok baytlı karakter varsa mbstring kullanılır, yoksa bayt sınırı uygulanır. */
function wa_kirp(string $s, int $bayt): string
{
    if (strlen($s) <= $bayt) return $s;
    if (function_exists('mb_substr')) return mb_substr($s, 0, $bayt, 'UTF-8');
    $k = substr($s, 0, $bayt);
    // sonda yarım kalan çok baytlı karakteri at
    while ($k !== '' && (ord($k[strlen($k) - 1]) & 0xC0) === 0x80) {
        $k = substr($k, 0, -1);
    }
    return $k;
}

/** Şablon yer tutucularını doldur — hem {ad} hem {{ad}} yazımını destekler */
function wa_render(string $tpl, array $ctx): string
{
    $d = [
        'ad'       => (string)($ctx['ad'] ?? ''),
        'kod'      => (string)($ctx['kod'] ?? ''),
        'isletme'  => (string)($ctx['isletme'] ?? setting('isletme_adi', 'Mim Halı Yıkama')),
        'tarih'    => (string)($ctx['tarih'] ?? ''),
        'tutar'    => (string)($ctx['tutar'] ?? ''),
        'adres'    => (string)($ctx['adres'] ?? ''),
        'takip_link' => (string)($ctx['takip_link'] ?? ''),
        'link'     => (string)($ctx['link'] ?? ''),
    ];
    // önce çift süslü {{ad}}, sonra tek süslü {ad}
    $ciift = [];
    $tek = [];
    foreach ($d as $k => $v) {
        $ciift['{{' . $k . '}}'] = $v;
        $tek['{' . $k . '}'] = $v;
    }
    return strtr(strtr($tpl, $ciift), $tek);
}

/** Son 24 saat içinde müşteriden gelen mesaj var mı (hizmet penceresi)?
 *  'localtime' zorunlu: received_at now_dt() ile PHP saat diliminde yazılır;
 *  datetime('now') ise UTC'dir — karışık kullanılırsa pencere 3 saat kayar. */
function wa_in_window(int $customerId): bool
{
    if ($customerId <= 0) return false;
    $st = db()->prepare("SELECT COUNT(*) c FROM wa_inbound WHERE customer_id=?
        AND received_at >= datetime('now','localtime','-24 hours')");
    $st->execute([$customerId]);
    return ((int)$st->fetchColumn()) > 0;
}

/** Dedupe anahtarı: aynı olay + aynı şablon → tek mesaj */
function wa_dedupe_key(?int $orderId, string $template, string $stageKey): string
{
    return 'o' . (int)$orderId . ':' . $template . ':' . $stageKey;
}

/**
 * Bildirim kuyruğa alır. Hiçbir koşulda sipariş kaydını engellemez.
 * @return int|null kuyruk id (kaydedilemezse null)
 */
function wa_enqueue(int $orderId, string $template, string $stageKey = '', string $force = ''): ?int
{
    try {
        $st = db()->prepare('SELECT o.*, c.name cname, c.phone, c.wa_optin, c.id cid
            FROM orders o JOIN customers c ON c.id=o.customer_id WHERE o.id=?');
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) return null;

        $phone = wa_phone((string)$o['phone']);
        if ($phone === '') return null;

        // opt-in yoksa bildirim gönderilmez (kural)
        if ((int)($o['wa_optin'] ?? 1) !== 1 && $force !== 'yonetici') {
            app_log('WA atlandı (opt-in yok): order ' . $orderId);
            return null;
        }

        $dedupe = wa_dedupe_key($orderId, $template, $stageKey !== '' ? $stageKey : (string)$o['status']);
        $existing = db()->prepare('SELECT id FROM wa_queue WHERE dedupe_key=?');
        $existing->execute([$dedupe]);
        if ($existing->fetchColumn()) return null;   // yinelenen bildirim yok

        $body = wa_render(wa_template_text($template), [
            'ad'      => (string)$o['cname'],
            'kod'     => (string)$o['code'],
            'tarih'   => tr_date((string)$o['delivery_date']),
            'tutar'   => money_tl((int)$o['total_kurus'] - (int)$o['discount_kurus']),
            'adres'   => (string)$o['address'],
            'takip_link' => takip_linki((int)$o['id']),
        ]);
        // Durum bildirimlerine takip linki otomatik eklenir — şablon kullanıcı
        // tarafından değiştirilse bile (kaydedilmiş şablonlar eski metni tutar).
        // Link üretilemediyse (cron bağlamı + site_url ayarsız) hiçbir şey eklenmez.
        $takipLink = takip_linki((int)$o['id']);
        if ($takipLink !== '' && strpos($body, 'takip') === false
            && in_array($template, ['teslim_alindi', 'yikamada', 'paketlendi', 'dagitimda', 'teslim_gecikti'], true)) {
            $body .= "\n\n📍 Siparişinizi buradan takip edin: " . $takipLink;
        }
        if ($body === '') return null;

        $q = db()->prepare('INSERT INTO wa_queue(order_id,customer_id,template,phone,body,status,
            dedupe_key,user_id,username,created_at,updated_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$orderId, (int)$o['cid'], $template, $phone, $body, 'bekliyor',
            $dedupe, uid() ?: null, uname_(), now_dt(), now_dt()]);
        $id = (int)db()->lastInsertId();
        audit('wa_enqueue', 'orders', $orderId, $template . ' → kuyruk #' . $id);
        return $id;
    } catch (Throwable $e) {
        app_log('wa_enqueue hata: ' . $e->getMessage(), ['order' => $orderId, 'tpl' => $template]);
        return null;
    }
}

/** Durum değişimine göre otomatik tetikleyici */
function wa_notify_status(int $orderId, string $status): void
{
    $map = [
        'teslim_alindi' => 'teslim_alindi',
        'yikamada'      => 'yikamada',
        'paketlendi'    => 'paketlendi',
        'dagitimda'     => 'dagitimda',
        'teslim'        => 'teslim',
    ];
    if (isset($map[$status])) {
        wa_enqueue($orderId, $map[$status], $status);
    }
}

/** Graph API'ye tek mesaj gönder */
function wa_send(int $queueId): array
{
    $st = db()->prepare('SELECT * FROM wa_queue WHERE id=?');
    $st->execute([$queueId]);
    $m = $st->fetch();
    if (!$m) return ['ok' => false, 'error' => 'kayıt yok'];

    $attempts = (int)$m['attempts'];
    if ($attempts >= 5) {
        return ['ok' => false, 'error' => 'deneme hakkı doldu (5)'];
    }

    // ------------------------------------------------ 24 saatlik hizmet penceresi
    // Pencere dışındaysa WhatsApp serbest metin gönderime izin vermez; yalnızca
    // Meta tarafından ONAYLANMIŞ şablonlar gönderilebilir. Onaylı değilse mesaj
    // GÖNDERİLMEZ — kuyrukta bekler, sahte başarı üretilmez.
    $cid = (int)($m['customer_id'] ?? 0);
    if ($cid > 0 && !wa_in_window($cid)) {
        $tpl = (string)($m['template'] ?? '');
        if (!wa_template_approved($tpl)) {
            $not = 'Hizmet penceresi dışında (24 saat kuralı) ve şablon onaylı değil — gönderilmedi.';
            $u = db()->prepare('UPDATE wa_queue SET status=?, error=?, updated_at=? WHERE id=?');
            $u->execute(['bekliyor', $not, now_dt(), $queueId]);
            app_log('WA pencere dışı, onaysız şablon: #' . $queueId . ' tpl=' . $tpl);
            return ['ok' => false, 'error' => $not, 'blocked' => true];
        }
    }

    $token   = setting('wa_token', '');
    $phoneId = setting('wa_phone_id', '');
    $verif   = setting('wa_app_secret', '');

    // Token yenileme (uzun ömürlü)
    if ($token !== '' && strpos($token, 'EAA') !== 0 && $verif !== '') {
        $st = db()->prepare('SELECT v FROM settings WHERE k=wa_longlived');
        $st->execute();
        $ll = (string)($st->fetchColumn() ?: '');
        if ($ll !== '') {
            $url = WA_GRAPH . '/' . rawurlencode($ll) . '/oauth/access_token'
                 . '?grant_type=fb_exchange_token&client_id=' . rawurlencode($phoneId)
                 . '&client_secret=' . rawurlencode($verif) . '&fb_exchange_token=' . rawurlencode($token);
            $j = wa_http($url, null, 'GET');
            if (!empty($j['access_token'])) {
                setting_set('wa_token', (string)$j['access_token']);
                setting_set('wa_longlived', '');
                $token = (string)$j['access_token'];
            }
        }
    }

    $url = WA_GRAPH . '/' . rawurlencode($phoneId) . '/messages';
    $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual',
                'to' => (string)$m['phone'], 'type' => 'text', 'text' => ['body' => (string)$m['body']]];
    $resp = wa_http($url, $payload, 'POST', ['Authorization: Bearer ' . $token]);

    $up = db()->prepare('UPDATE wa_queue SET attempts=attempts+1, updated_at=? WHERE id=?');
    $up->execute([now_dt(), $queueId]);

    if (!empty($resp['messages'][0]['id'])) {
        $u = db()->prepare('UPDATE wa_queue SET status=?, provider_msg_id=?, error=?, sent_at=?, updated_at=? WHERE id=?');
        $u->execute(['gonderildi', (string)$resp['messages'][0]['id'], '', now_dt(), now_dt(), $queueId]);
        return ['ok' => true, 'id' => (string)$resp['messages'][0]['id']];
    }

    // Taşıma seviyesinde hata (bağlantı kurulamadı / zaman aşımı): Graph'tan
    // hata KODU gelmemiştir. Önceki sürümde bu durum kalıcı 'hata' sayılıyordu
    // — tek ağ sarsıntısı mesajı geri dönüşsüzce düşürüyordu. Şimdi 'bekliyor'
    // kalır ve sonraki turda yeniden denenir (5 deneme üst sınırı aşılırsa hata).
    $tasimaHatasi = isset($resp['_curl']) || (int)($resp['_http'] ?? 1) === 0;
    $attemptsYeni = $attempts + 1;
    if ($tasimaHatasi && $attemptsYeni < 5) {
        $err2 = 'ağ hatası: ' . (string)($resp['_curl'] ?? 'yanıt yok (HTTP 0)');
        $u = db()->prepare("UPDATE wa_queue SET status='bekliyor', error=?, updated_at=? WHERE id=?");
        $u->execute([substr($err2, 0, 400), now_dt(), $queueId]);
        // _http=0 dönüşü wa_process'teki bağlantı kesicisini besler
        return ['ok' => false, 'error' => $err2, 'retry' => true, '_http' => 0];
    }

    $err = $resp['error']['message'] ?? ('bilinmeyen hata (HTTP ' . ($resp['_http'] ?? '?') . ')');
    // 131xxx = kurtarılabilir geçici hata → kontrollü tekrar
    $code = (int)($resp['error']['code'] ?? 0);
    $tmp = in_array($code, [130429, 131048, 131056, 133429, 1, 2, 4, 341, 613], true);
    if ($attemptsYeni >= 5) $tmp = false;
    $u = db()->prepare('UPDATE wa_queue SET status=?, error=?, updated_at=? WHERE id=?');
    $u->execute([$tmp ? 'bekliyor' : 'hata', substr((string)$err, 0, 400), now_dt(), $queueId]);
    app_log('WA gönderilemedi #' . $queueId . ': ' . $err);
    return ['ok' => false, 'error' => $err, 'retry' => $tmp];
}

/** Kuyruk işlemenin toplam süre bütçesi (saniye). Bu süre dolunca kalan iş
 *  bekliyor bırakılır — sonraki cron/tetiklemede devam eder. Amaç: tek bir
 *  HTTP isteğinin dakikalarca asılı kalmasını engellemek. */
const WA_BATCH_BUDGET = 45;

/** Üst üste bu kadar BAĞLANTI hatası → ağ/inşa erişilemez sayılır, kalan
 *  mesajlar denenmez (hepsi zaman aşımına gidecek, boşuna dakikalarca beklenecek). */
const WA_CONN_FAIL_LIMIT = 3;

/** Kuyruğu işle (kron veya elle tetiklenir) */
function wa_process(int $limit = 25): array
{
    $out = ['sent' => 0, 'fail' => 0, 'skipped' => 0, 'blocked' => 0];
    if (!whatsapp_ready()) { $out['skipped'] = -1; return $out; }
    $st = db()->prepare("SELECT id FROM wa_queue WHERE status='bekliyor' AND attempts < 5 ORDER BY id LIMIT ?");
    $st->execute([$limit]);

    $deadline   = microtime(true) + WA_BATCH_BUDGET;
    $connFails  = 0;

    foreach ($st->fetchAll() as $r) {
        // Süre bütçesi doldu → kalanlar 'bekliyor' kalır, bir sonraki turda işlenir
        if (microtime(true) >= $deadline) {
            $out['skipped'] = 'sure';
            break;
        }
        $res = wa_send((int)$r['id']);
        if ($res['ok']) { $out['sent']++; $connFails = 0; continue; }

        // Ağ seviyesinde hata mı? (HTTP 0 = bağlantı kurulamadı / zaman aşımı)
        $connErr = (int)($res['_http'] ?? 1) === 0;
        if ($connErr && ++$connFails >= WA_CONN_FAIL_LIMIT) {
            // Ağ erişilemez: geri kalanlar denenmez. Bu bir hata değil, beklenen
            // durum — cron bir sonraki çalışmada yeniden dener.
            $out['skipped'] = 'baglanti';
            app_log('WA erişilemedi, kalan kuyruk ertelendi', [
                'gonderilen' => $out['sent'], 'hata' => $out['fail'],
            ]);
            break;
        }

        // Pencere dışı + onaysız şablon bir HATA değil, kural kaynaklı beklemedir.
        if (!empty($res['blocked'])) $out['blocked']++; else $out['fail']++;
    }
    return $out;
}

/** Webhook: gönderildi / teslim / okundu / hata durumlarını güncelle.
 *  $mode çağırıcı tarafından verilmez (index.php tek argümanla çağırır) —
 *  zorunlu bırakılsaydı İMZASI GEÇERLİ her webhook isteği
 *  ArgumentCountError ile 500 döndürürdü (denetimde kanıtlandı) ve Meta
 *  webhook'u devre dışı bırakırdı. */
function wa_handle_webhook(array $body, string $mode = ''): void
{
    foreach (($body['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $ch) {
            $v = $ch['value'] ?? [];
            foreach (($v['messages'] ?? []) as $m) {   // müşteriden gelen
                $from = wa_phone((string)($m['from'] ?? ''));
                $bodyTxt = (string)($m['text']['body'] ?? '');
                $cid = wa_find_customer_by_phone($from);
                $st = db()->prepare('INSERT INTO wa_inbound(from_phone,customer_id,body,msg_id,received_at)
                    VALUES(?,?,?,?,?)');
                $st->execute([$from, $cid, $bodyTxt, (string)($m['id'] ?? ''), now_dt()]);
            }
            foreach (($v['statuses'] ?? []) as $s) {   // bizim gönderdiğimiz
                $mid = (string)($s['id'] ?? '');
                $stat = (string)($s['status'] ?? '');
                $map = ['sent' => 'gonderildi', 'delivered' => 'teslim', 'read' => 'okundu', 'failed' => 'hata'];
                $new = $map[$stat] ?? null;
                if (!$new) continue;
                $err = '';
                foreach (($s['errors'] ?? []) as $er) { $err = (string)($er['title'] ?? '') . ' ' . (string)($er['message'] ?? ''); break; }
                $st = db()->prepare('UPDATE wa_queue SET status=?, error=?, updated_at=? WHERE provider_msg_id=?');
                $st->execute([$new, substr(trim($err), 0, 400), now_dt(), $mid]);
            }
        }
    }
}

function wa_find_customer_by_phone(string $norm): ?int
{
    if ($norm === '') return null;
    $st = db()->prepare('SELECT id FROM customers WHERE phone LIKE ? OR phone2 LIKE ? ORDER BY id LIMIT 1');
    $st->execute(['%' . substr($norm, -9) . '%', '%' . substr($norm, -9) . '%']);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

/** HTTP yardımcısı (curl yoksa stream) */
function wa_http(string $url, ?array $payload, string $method = 'POST', array $headers = []): array
{
    $json = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;
    $ctx = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) return ['_http' => 0, '_curl' => 'init başarısız'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // Bağlantı ve toplam zaman aşımı AYRI tanımlıdır. Graph API erişilemez
            // olduğunda önce CONNECTTIMEOUT (4 sn) işe yarar: sunucu hiç yanıt
            // vermeyen bir ağda beklemek 25 saniyeye dayanmasın. Bu değer
            // olmadan kuyruk işleme 50 mesaj × 25 sn = dakikalarca asılı kalıyordu.
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        ]);
        if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        $res = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) return ['_http' => $http, '_curl' => $err];
        $out = json_decode((string)$res, true);
        return is_array($out) ? $out + ['_http' => $http] : ['_http' => $http, '_raw' => (string)$res];
    }
    $opts = ['http' => ['method' => $method,
        'header' => implode("\r\n", array_merge(['Content-Type: application/json'], $headers)),
        'content' => (string)$json, 'timeout' => 25, 'ignore_errors' => true]];
    $res = @file_get_contents($url, false, stream_context_create($opts));
    $out = $res !== false ? json_decode((string)$res, true) : ['_http' => 0, '_hata' => 'yanıt yok'];
    return is_array($out) ? $out : [];
}

/** Webhook imza doğrulaması (X-Hub-Signature-256) */
function wa_verify_signature(string $raw, string $sigHeader): bool
{
    $secret = setting('wa_app_secret', '');
    if ($secret === '') return false;   // secret yoksa doğrulama kapalı → reddet
    $exp = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    return hash_equals($exp, (string)$sigHeader);
}