<?php
/**
 * Gelir modülü — siparişle İLİŞKİSİZ para girişleri (19.10.2026).
 * Sipariş tahsilatları payments'tadır (odeme_ekle); burada nakliye,
 * tamir/boyama, servis ücreti gibi düz gelir kayıtları tutulur.
 *
 * Yetki: gelir.ekle (mali yetki — varsayılan KAPALI, izin kataloğunda).
 * Silme: gelir.sil (ayrı izin — gelir silmek kasayı küçültür, gider.sil gibi
 * ayrı izne bağlı). Onay akışı YOKTUR (kullanıcı kararı: izinli direkt giriş).
 *
 * ⚠ Yükleme sırası: kasa.php'den SONRA yüklenir (index.php) — kasa_aktif_ay(),
 *   kasa_ay_araligi(), kasa_sil_butonu() kasa.php'den gelir.
 */

const KASA_GELIR_CAT = [
    'nakliye'      => 'Nakliye / Servis',
    'tamir_boyama' => 'Tamir / Boyama',
    'diger'        => 'Diğer gelir',
];

/** Gelir kategorisi etiketi */
function kasa_gelir_cat(string $c): string
{
    return KASA_GELIR_CAT[$c] ?? ucfirst(str_replace('_', ' ', $c));
}

/** Dönem gelir kayıtları (en yeniden eskiye, sınırlı) */
function kasa_donem_gelirler(string $ym, int $limit = 200): array
{
    [$bas, $bit] = kasa_ay_araligi($ym);
    $st = db()->prepare("SELECT * FROM incomes
        WHERE date(created_at)>=? AND date(created_at)<?
        ORDER BY id DESC LIMIT " . (int)$limit);
    $st->execute([$bas, $bit]);
    return $st->fetchAll();
}

/** Dönem gelir toplamı (kuruş) */
function kasa_donem_gelir_toplam(string $ym): int
{
    [$bas, $bit] = kasa_ay_araligi($ym);
    $st = db()->prepare("SELECT COALESCE(SUM(amount_kurus),0) FROM incomes
        WHERE date(created_at)>=? AND date(created_at)<?");
    $st->execute([$bas, $bit]);
    return (int)$st->fetchColumn();
}

// ================================================================ SAYFA
function gelir_sayfa(): void
{
    need_perm('gelir.ekle');
    $db = db();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        csrf_check();
        gelir_kaydet();
        return;
    }

    $ym = kasa_aktif_ay();
    $gelirler = kasa_donem_gelirler($ym);
    $toplam = kasa_donem_gelir_toplam($ym);

    layout_head('Gelirler', 'gelir');
    if (!empty($_SESSION['gelir_hata'])) {
        echo '<div class="alert danger">' . e((string)$_SESSION['gelir_hata']) . '</div>';
        unset($_SESSION['gelir_hata']);
    }
    rpr_flash();

    echo '<div class="card"><h2>Dönem: ' . e(kasa_ay_etiket($ym)) . '</h2>';
    kasa_donem_secici($ym);
    echo '</div>';

    echo '<div class="statrow">';
    kpi(money_tl($toplam), 'Dönem geliri');
    kpi((string)count($gelirler), 'Gelir kaydı');
    echo '</div>';

    echo '<div class="card"><h2>➕ Gelir Kaydı</h2>';
    echo '<p class="muted small">Sipariş tahsilatı buraya DEĞİL, sipariş kartındaki '
        . '"Ödeme Kaydet"e girilir. Bu ekran siparişle ilişkisiz gelirlere aittir.</p>';
    echo '<form method="post" action="?p=gelir" class="form">' . csrf_field()
        . '<label>Kategori<select name="cat">';
    foreach (KASA_GELIR_CAT as $ck => $clbl) {
        echo '<option value="' . e($ck) . '">' . e($clbl) . '</option>';
    }
    echo '</select></label>'
        . '<label>Tutar (₺)<input name="tutar" inputmode="decimal" placeholder="0,00" required></label>'
        . '<label>Not<input name="note" placeholder="örn. koltuk nakliyesi, ek servis"></label>'
        . '<button class="btn btn-primary btn-block">Geliri Kaydet</button>'
        . '</form></div>';

    echo '<div class="card"><h2>Kayıtlar (' . count($gelirler) . ')</h2>';
    if (!$gelirler) {
        echo '<div class="bos-durum"><span>📈</span><p>Bu dönemde gelir kaydı yok.</p></div>';
    } else {
        foreach ($gelirler as $g) {
            echo '<div class="row"><span class="row-main">' . e(tr_date((string)$g['created_at'], true)) . ' · '
                . e(kasa_gelir_cat((string)$g['cat']))
                . ((string)$g['note'] !== '' ? '<br><small><i>' . e((string)$g['note']) . '</i></small>' : '')
                . ((string)$g['username'] !== '' ? '<br><small>' . e((string)$g['username']) . '</small>' : '')
                . '</span><span class="row-side pos">' . money_tl((int)$g['amount_kurus']);
            echo kasa_sil_butonu('gelir-sil', (int)$g['id'], 'Gelir kaydı silinsin mi?', 'gelir.sil');
            echo '</span></div>';
        }
    }
    echo '</div>';

    layout_foot('gelir');
}

// ================================================================ KAYIT (POST)
function gelir_kaydet(): void
{
    need_perm('gelir.ekle');
    $cat = trim((string)($_POST['cat'] ?? ''));
    if (!isset(KASA_GELIR_CAT[$cat])) {
        $_SESSION['gelir_hata'] = 'Geçersiz gelir kategorisi.';
        redirect('gelir');
    }
    // ⚠ parse_tl girdiyi 100 ile çarpar: "100,50" → 10050 kuruş. Kuruş yazma!
    $tutar = parse_tl((string)($_POST['tutar'] ?? ''));
    if ($tutar <= 0) {
        $_SESSION['gelir_hata'] = 'Tutar 0’dan büyük olmalı.';
        redirect('gelir');
    }
    $note = trim((string)($_POST['note'] ?? ''));

    $st = db()->prepare('INSERT INTO incomes(cat, amount_kurus, note, user_id, username, created_at)
        VALUES(?,?,?,?,?,?)');
    $st->execute([$cat, $tutar, $note, uid() ?: null, uname_(), now_dt()]);
    $id = (int)db()->lastInsertId();
    audit('gelir_ekle', 'incomes', $id,
        kasa_gelir_cat($cat) . ' · ' . money_tl($tutar) . ' · ' . $note);
    $_SESSION['flash'] = '✅ Gelir kaydedildi ve kasaya işlendi.';
    redirect('gelir');
}

// ================================================================ SİLME (POST)
function gelir_sil(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        exit('Geçersiz istek yöntemi.');
    }
    need_perm('gelir.sil');
    csrf_check();

    $id = (int)($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM incomes WHERE id=?');
    $st->execute([$id]);
    $g = $st->fetch();
    if (!$g) {
        http_response_code(404);
        exit('Gelir kaydı bulunamadı.');
    }

    $del = db()->prepare('DELETE FROM incomes WHERE id=?');
    $del->execute([$id]);
    audit('gelir_sil', 'incomes', $id,
        kasa_gelir_cat((string)$g['cat']) . ' · ' . money_tl((int)$g['amount_kurus']));
    $_SESSION['flash'] = 'Gelir kaydı silindi.';
    redirect('gelir');
}
