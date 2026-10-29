<?php
/**
 * Müşteri takip sayfası — KAMUYA ACIK (login gerekmez; index.php'de
 * require_login'den ÖNCE yakalanır). Kod bilen müşteri salt-okunur durum
 * görür. Mahremiyet: telefon, adres, FİYAT/BAKİYE GÖSTERİLMEZ.
 *
 * Güvenlik: kod 24 hex (96 bit) — deneme yanılma ile bulunamaz; bulunamayan
 * kod için jenerik mesaj döner (kod var/yok ayrımı sızdırılmaz).
 * layout_head KULLANMAZ — oturumsuz sayfa, kendi minimal HTML'i var;
 * stil yine style.css'ten gelir (koyu mod dahil).
 */

function takip_sayfa(): void
{
    $kod = strtolower(trim((string)($_GET['kod'] ?? '')));
    $isletme = setting('isletme_adi', 'Mim Halı Yıkama');

    $o = null; $items = []; $evs = [];
    if (preg_match('/^[a-f0-9]{24}$/', $kod)) {
        $st = db()->prepare('SELECT id, code, status, pickup_date, delivery_date, created_at
            FROM orders WHERE takip_kodu=?');
        $st->execute([$kod]);
        $o = $st->fetch();
        if ($o) {
            $oid = (int)$o['id'];
            $st = db()->prepare('SELECT type, descr, qty FROM order_items WHERE order_id=? ORDER BY id');
            $st->execute([$oid]);
            $items = $st->fetchAll();
            $st = db()->prepare('SELECT status, note, created_at FROM events
                WHERE order_id=? ORDER BY id DESC LIMIT 6');
            $st->execute([$oid]);
            $evs = $st->fetchAll();
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#10243A">
<title>Sipariş Takip — <?= e($isletme) ?></title>
<link rel="stylesheet" href="style.css">
</head><body class="login-body"><div class="login-box takip-box">
<div class="login-logo">🧼</div>
<h1><?= e($isletme) ?></h1>
<p class="muted">Sipariş Takip</p>
<?php
if (!$o) {
    if ($kod !== '') {
        echo '<div class="alert">Bu koda ait bir sipariş bulunamadı.<br>'
            . '<small>Lütfen bağlantıyı olduğu gibi kullanın; sorun sürerse bize yazın.</small></div>';
    }
    // kod'suz / hatalı: kod girişi (bağlantısını elinden kaybeden müşteri için)
    echo '<form method="get" action="?" class="form">'
        . '<input type="hidden" name="p" value="takip">'
        . '<label>Takip kodu<input name="kod" value="' . e($kod) . '" '
        . 'placeholder="bağlantıdaki kod" maxlength="24" autocomplete="off"></label>'
        . '<button class="btn btn-primary btn-block">Sorgula</button></form>'
        . '<p class="muted small">Takip kodunuz WhatsApp mesajında paylaşılır.</p>';
} else {
    echo '<div class="card"><div class="orderhead"><div><b>' . e((string)$o['code']) . '</b><br>'
        . '<small>' . e(tr_date((string)$o['created_at'], true)) . ' kayıt</small></div>'
        . status_badge((string)$o['status']) . '</div>';
    if ((string)$o['delivery_date'] !== '') {
        echo '<div class="kv"><div>Tahmini teslim</div><div><b>'
            . e(tr_date((string)$o['delivery_date'])) . '</b></div></div>';
    }
    echo '</div>';

    echo '<div class="card"><h2>Parçalar</h2>';
    if (!$items) {
        echo '<p class="muted">Parça bilgisi eklenmemiş.</p>';
    } else {
        foreach ($items as $it) {
            $tur = service_types()[(string)$it['type']][0] ?? (string)$it['type'];
            echo '<div class="row"><span class="row-main"><b>' . e((string)$it['qty'])
                . ' adet</b> · ' . e($tur)
                . ((string)$it['descr'] !== '' ? '<br><small>' . e((string)$it['descr']) . '</small>' : '')
                . '</span></div>';
        }
    }
    echo '</div>';

    if ($evs) {
        echo '<div class="card"><h2>Durum Geçmişi</h2>';
        foreach ($evs as $ev) {
            echo '<div class="row"><span class="row-main">'
                . status_badge((string)$ev['status'])
                . ((string)$ev['note'] !== '' ? ' <small>' . e((string)$ev['note']) . '</small>' : '')
                . '</span><span class="row-side"><small>' . e(tr_date((string)$ev['created_at'], true)) . '</small></span></div>';
        }
        echo '</div>';
    }
    echo '<p class="muted small">Soru ve önerileriniz için bize yazın — bu sayfada '
        . 'kişisel bilgileriniz (telefon, adres, ödeme) paylaşılmaz.</p>';
}
?>
</div></body></html><?php
}
