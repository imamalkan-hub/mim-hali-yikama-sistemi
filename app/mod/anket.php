<?php
/**
 * Teslim sonrası memnuniyet anketi + Google yorum hunisi.
 *
 * Akış: sipariş "teslim" durumuna geçince tek kullanımlık token'lı anket
 * oluşur ve WhatsApp kuyruğuna düşer. Müşteri ?p=anket&t=... sayfasında
 * 1–5 puan verir. 4–5 puan → Google yorum bağlantısı gösterilir;
 * 1–3 puan → panelde "düşük puan" olarak işaretlenir, müşteriye yorum
 * bağlantısı GÖSTERİLMEZ.
 *
 * Gizlilik: kamu sayfası yalnız token bilir; isim/telefon/fiyat gösterilmez.
 */

function anket_taban(): string
{
    $taban = trim((string)setting('site_url', ''));
    if ($taban === '') {
        if (empty($_SERVER['HTTP_HOST'])) return '';
        $skema = (!empty($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https');
        $taban = rtrim($skema . '://' . $_SERVER['HTTP_HOST'] . dirname((string)$_SERVER['SCRIPT_NAME']), '/');
    }
    return rtrim($taban, '/');
}

function anket_linki(string $token): string
{
    $taban = anket_taban();
    return $taban === '' ? '' : $taban . '/?p=anket&t=' . rawurlencode($token);
}

/**
 * Sipariş için anket oluşturur (idempotent) ve istenirse WhatsApp kuyruğuna
 * "memnuniyet" mesajı ekler. Hata sipariş akışını ASLA engellemez.
 */
function anket_olustur(int $orderId, bool $kuyruk = true): ?array
{
    try {
        $st = db()->prepare('SELECT * FROM surveys WHERE order_id=? LIMIT 1');
        $st->execute([$orderId]);
        $var = $st->fetch();
        if ($var) return $var;

        $st = db()->prepare('SELECT o.id,o.code,o.total_kurus,o.discount_kurus,c.id cid,c.name,c.phone,c.wa_optin
            FROM orders o JOIN customers c ON c.id=o.customer_id WHERE o.id=?');
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) return null;

        $token = bin2hex(random_bytes(12));
        $st = db()->prepare('INSERT INTO surveys(order_id,customer_id,token,status,created_at)
            VALUES(?,?,?,?,?)');
        $st->execute([$orderId, (int)$o['cid'], $token, 'bekliyor', now_dt()]);
        $sid = (int)db()->lastInsertId();
        audit('anket_olustur', 'surveys', $sid, 'order:' . $orderId);

        if ($kuyruk && setting('anket_otomatik', '1') === '1'
            && (int)($o['wa_optin'] ?? 1) === 1) {
            $phone = wa_phone((string)$o['phone']);
            $link = anket_linki($token);
            if ($phone !== '' && $link !== '') {
                $body = wa_render(wa_template_text('memnuniyet'), [
                    'ad' => (string)$o['name'],
                    'kod' => (string)$o['code'],
                    'link' => $link,
                ]);
                $dedupe = 'o' . $orderId . ':memnuniyet:teslim';
                $q = db()->prepare('SELECT id FROM wa_queue WHERE dedupe_key=?');
                $q->execute([$dedupe]);
                if (!$q->fetchColumn() && $body !== '') {
                    $ins = db()->prepare('INSERT INTO wa_queue(order_id,customer_id,template,phone,body,
                        status,dedupe_key,user_id,username,created_at,updated_at)
                        VALUES(?,?,?,?,?,?,?,?,?,?,?)');
                    $ins->execute([$orderId, (int)$o['cid'], 'memnuniyet', $phone, $body,
                        'bekliyor', $dedupe, uid() ?: null, uname_(), now_dt(), now_dt()]);
                    audit('wa_enqueue', 'orders', $orderId, 'memnuniyet');
                }
            }
        }

        $st = db()->prepare('SELECT * FROM surveys WHERE id=?');
        $st->execute([$sid]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        app_log('anket_olustur hata: ' . $e->getMessage(), ['order' => $orderId]);
        return null;
    }
}

/** Kamuya açık anket sayfası — oturumsuz. */
function anket_sayfa(): void
{
    $token = strtolower(trim((string)($_GET['t'] ?? '')));
    $isletme = setting('isletme_adi', 'Mim Halı Yıkama');
    $s = null; $mesaj = ''; $gonderildi = false;

    if (preg_match('/^[a-f0-9]{24}$/', $token)) {
        $st = db()->prepare('SELECT s.*, o.code, c.name FROM surveys s
            LEFT JOIN orders o ON o.id=s.order_id LEFT JOIN customers c ON c.id=s.customer_id
            WHERE s.token=?');
        $st->execute([$token]);
        $s = $st->fetch() ?: null;
    }

    if ($s && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $s['status'] === 'bekliyor') {
        csrf_check();
        $puan = (int)($_POST['puan'] ?? 0);
        $not = trim((string)($_POST['not'] ?? ''));
        if ($puan < 1 || $puan > 5) {
            $mesaj = 'Lütfen 1–5 arasında bir puan seçin.';
        } else {
            if (function_exists('mb_substr')) $not = mb_substr($not, 0, 500, 'UTF-8');
            else $not = substr($not, 0, 500);
            $st = db()->prepare('UPDATE surveys SET score=?, note=?, status=?, ip_hash=?, responded_at=?
                WHERE id=? AND status=\'bekliyor\'');
            $st->execute([$puan, $not, 'cevaplandi',
                hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . setting('cron_token', 'x')),
                now_dt(), (int)$s['id']]);
            audit('anket_cevap', 'surveys', (int)$s['id'], 'puan:' . $puan);
            $gonderildi = true;
            $s['score'] = $puan; $s['status'] = 'cevaplandi';
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#10243A">
<title>Memnuniyet — <?= e($isletme) ?></title>
<link rel="stylesheet" href="style.css">
<style>
.puanlar{display:flex;gap:8px;justify-content:center;margin:14px 0}
.puanlar label{flex:1;max-width:64px}
.puanlar input{position:absolute;opacity:0;width:1px;height:1px}
.puanlar span{display:block;text-align:center;font-size:30px;padding:8px 0;border:1px solid #d1d5db;border-radius:12px;cursor:pointer;filter:grayscale(1);opacity:.55}
.puanlar input:checked + span,.puanlar label:hover span{filter:none;opacity:1;border-color:#16A6A1;background:#ecfdf5}
</style>
</head><body class="login-body"><div class="login-box">
<div class="login-logo">⭐</div>
<h1><?= e($isletme) ?></h1>
<p class="muted">Memnuniyet anketi</p>
<?php
if (!$s) {
    echo '<div class="card"><p>Bu anket bağlantısı geçersiz veya süresi dolmuş.</p>'
        . '<p class="muted small">Bize telefon veya WhatsApp ile ulaşabilirsiniz.</p></div>';
} elseif ($s['status'] !== 'bekliyor') {
    $p = (int)$s['score'];
    echo '<div class="card"><h2>Teşekkürler ✓</h2>'
        . '<p>Puanınız: <b>' . $p . ' / 5</b></p>';
    if ($p >= 4) {
        $g = google_yorum_linki();
        echo '<p>Memnun kaldıysanız bize Google’da bir yorum bırakmanız çok yardımcı olur.</p>';
        if ($g !== '') {
            echo '<a class="btn btn-primary btn-block" target="_blank" rel="noopener" href="' . e($g) . '">⭐ Google’da yorum yaz</a>';
        }
    } else {
        echo '<p class="muted small">Geri bildiriminiz işletme yöneticisine iletildi. '
            . 'En kısa sürede sizinle ilgileneceğiz.</p>';
    }
    echo '</div>';
} else {
    if ($mesaj !== '') echo '<div class="alert">' . e($mesaj) . '</div>';
    echo '<form method="post" action="?p=anket&amp;t=' . e($token) . '" class="form">'
        . csrf_field()
        . '<div class="card"><h2>Hizmetimizi puanlar mısınız?</h2>'
        . '<p class="muted small">1 = çok kötü · 5 = çok iyi</p>'
        . '<div class="puanlar">';
    for ($i = 1; $i <= 5; $i++) {
        echo '<label><input type="radio" name="puan" value="' . $i . '"' . ($i === 5 ? ' required' : '') . '>'
            . '<span>⭐</span></label>';
    }
    echo '</div>'
        . '<label>Not (isteğe bağlı)<textarea name="not" rows="3" maxlength="500" '
        . 'placeholder="Eklemek istediğiniz bir şey var mı?"></textarea></label>'
        . '<button class="btn btn-primary btn-block">Gönder</button>'
        . '<p class="muted small">Adınız, telefonunuz ve ödeme bilgileriniz bu sayfada gösterilmez.</p>'
        . '</div></form>';
}
?>
</div></body></html><?php
}

/** Yönetim: anket sonuçları. */
function anketler_sayfa(): void
{
    need_perm('anket.gor');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        csrf_check();
        if (($_POST['eylem'] ?? '') === 'ayar') {
            need_perm('anket.yonet');
            setting_set('anket_otomatik', isset($_POST['otomatik']) ? '1' : '0');
            audit('anket_ayar', 'settings', null, setting('anket_otomatik'));
            redirect('anketler');
        }
    }

    $filtre = (string)($_GET['durum'] ?? '');
    $where = ''; $params = [];
    if ($filtre === 'dusuk') $where = ' WHERE s.status=\'cevaplandi\' AND s.score<=3';
    elseif ($filtre === 'yuksek') $where = ' WHERE s.status=\'cevaplandi\' AND s.score>=4';
    elseif ($filtre === 'bekleyen') $where = ' WHERE s.status=\'bekliyor\'';

    $st = db()->query("SELECT COUNT(*) c, AVG(CASE WHEN status='cevaplandi' THEN score END) ort,
        SUM(CASE WHEN status='cevaplandi' AND score<=3 THEN 1 ELSE 0 END) dusuk,
        SUM(CASE WHEN status='bekliyor' THEN 1 ELSE 0 END) bekleyen FROM surveys");
    $ozet = $st->fetch() ?: ['c' => 0, 'ort' => 0, 'dusuk' => 0, 'bekleyen' => 0];

    $st = db()->prepare('SELECT s.*, o.code, c.name customer_name FROM surveys s
        LEFT JOIN orders o ON o.id=s.order_id LEFT JOIN customers c ON c.id=s.customer_id'
        . $where . ' ORDER BY s.id DESC LIMIT 200');
    $st->execute($params);
    $rows = $st->fetchAll();

    layout_head('Memnuniyet', 'anketler');
    ?>
<div class="card">
  <div class="orderhead">
    <div><b>Memnuniyet anketleri</b><br><small class="muted">Teslim sonrası otomatik gönderilir; düşük puanlar burada toplanır.</small></div>
    <span class="badge" style="background:#10243A"><?= (int)($ozet['c'] ?? 0) ?> anket</span>
  </div>
  <div class="hizli-grid" style="margin-top:8px">
    <a class="stat" href="?p=anketler"><b><?= $ozet['ort'] !== null ? number_format((float)$ozet['ort'], 1, ',', '') : '—' ?></b><span>Ortalama puan</span></a>
    <a class="stat" href="?p=anketler&amp;durum=dusuk"><b><?= (int)$ozet['dusuk'] ?></b><span>Düşük puan (≤3)</span></a>
    <a class="stat" href="?p=anketler&amp;durum=bekleyen"><b><?= (int)$ozet['bekleyen'] ?></b><span>Bekleyen</span></a>
    <a class="stat" href="?p=anketler&amp;durum=yuksek"><b><?= (int)($ozet['c'] - $ozet['dusuk'] - $ozet['bekleyen']) ?></b><span>Yüksek puan</span></a>
  </div>
</div>

<?php if (can('anket.yonet')): ?>
<form method="post" class="card" style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap">
  <?= csrf_field() ?><input type="hidden" name="eylem" value="ayar">
  <div><b>Teslim sonrası otomatik anket</b><br><small class="muted">Kapatırsan yeni teslimlerde anket mesajı kuyruğa girmez.</small></div>
  <label style="display:flex;gap:8px;align-items:center;font-weight:400;margin:0">
    <input type="checkbox" name="otomatik" value="1" style="width:auto"<?= setting('anket_otomatik', '1') === '1' ? ' checked' : '' ?>> Açık
  </label>
  <button class="btn btn-sm">Kaydet</button>
</form>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="card bos-durum"><p>Bu filtrede anket yok. Sipariş teslim edildiğinde anket otomatik oluşur.</p></div>
<?php else: foreach ($rows as $r):
    $cevaplandi = (string)$r['status'] === 'cevaplandi';
    $puan = (int)$r['score'];
    $renk = !$cevaplandi ? '#6b7280' : ($puan <= 3 ? '#b91c1c' : '#15803d');
?>
  <div class="card">
    <div class="orderhead">
      <div><b><?= e((string)($r['customer_name'] ?? '')) ?></b><br>
        <small><?= e((string)($r['code'] ?? '')) ?> · <?= e(tr_date((string)$r['created_at'], true)) ?></small></div>
      <span class="badge" style="background:<?= $renk ?>"><?= $cevaplandi ? e($puan . ' / 5') : 'Bekliyor' ?></span>
    </div>
    <?php if ($cevaplandi && trim((string)$r['note']) !== ''): ?>
      <p style="margin:6px 0">“<?= nl2br(e((string)$r['note'])) ?>”</p>
    <?php endif; ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
      <?php if ((int)$r['order_id'] > 0): ?>
        <a class="btn btn-sm" href="?p=siparis&amp;id=<?= (int)$r['order_id'] ?>">Siparişi aç</a>
      <?php endif; ?>
      <?php if (!$cevaplandi): ?>
        <a class="btn btn-sm" target="_blank" rel="noopener" href="<?= e(anket_linki((string)$r['token'])) ?>">Anket linkini aç</a>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; endif; ?>
<?php
    layout_foot('anketler');
}
