<?php
/**
 * Tekrar sipariş hatırlatma kampanyası.
 *
 * Son tesliminden N ay geçmiş, hâlâ açık siparişi olmayan ve WhatsApp
 * izni bulunan müşterileri listeler; seçilenleri wa_queue'ya "hatirlatma"
 * şablonuyla ekler. Kuyruk normal WhatsApp işleyicisiyle gider.
 *
 * Aynı müşteriye aynı ay içinde ikinci kez mesaj eklenmez
 * (dedupe_key = h{customer}:{YYYY-MM}).
 */

function hatirlat_sayfa(): void
{
    need_perm('kampanya.yonet');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        csrf_check();
        $eylem = (string)($_POST['eylem'] ?? '');

        if ($eylem === 'ayar') {
            $ay = max(1, min(36, (int)($_POST['ay'] ?? 6)));
            setting_set('hatirlatma_ay', (string)$ay);
            audit('hatirlatma_ayar', 'settings', null, 'ay:' . $ay);
            redirect('hatirlat');
        }

        if ($eylem === 'kuyruk') {
            $ids = array_map('intval', (array)($_POST['cid'] ?? []));
            $ids = array_values(array_filter($ids, static fn($i) => $i > 0));
            $eklenen = 0; $atlanan = 0;
            $ay = max(1, min(36, (int)setting('hatirlatma_ay', '6')));
            $donem = date('Y-m');

            foreach ($ids as $cid) {
                $st = db()->prepare('SELECT id,name,phone,wa_optin FROM customers WHERE id=?');
                $st->execute([$cid]);
                $c = $st->fetch();
                if (!$c || (int)$c['wa_optin'] !== 1) { $atlanan++; continue; }
                $phone = wa_phone((string)$c['phone']);
                if ($phone === '') { $atlanan++; continue; }

                $dedupe = 'h' . $cid . ':' . $donem;
                $q = db()->prepare('SELECT id FROM wa_queue WHERE dedupe_key=?');
                $q->execute([$dedupe]);
                if ($q->fetchColumn()) { $atlanan++; continue; }

                $body = wa_render(wa_template_text('hatirlatma'), [
                    'ad' => (string)$c['name'],
                    'isletme' => setting('isletme_adi', 'Mim Halı Yıkama'),
                ]);
                if ($body === '') { $atlanan++; continue; }

                $ins = db()->prepare('INSERT INTO wa_queue(order_id,customer_id,template,phone,body,
                    status,dedupe_key,user_id,username,created_at,updated_at)
                    VALUES(NULL,?,?,?,?,?,?,?,?,?,?)');
                $ins->execute([$cid, 'hatirlatma', $phone, $body, 'bekliyor', $dedupe,
                    uid() ?: null, uname_(), now_dt(), now_dt()]);
                $eklenen++;
            }
            audit('hatirlatma_kuyruk', 'wa_queue', null, 'eklenen:' . $eklenen . ' atlanan:' . $atlanan);
            $_SESSION['flash'] = $eklenen . ' müşteri hatırlatma kuyruğuna eklendi'
                . ($atlanan > 0 ? ' · ' . $atlanan . ' atlandı (izin yok / zaten ekli)' : '') . '.';
            redirect('hatirlat');
        }
    }

    $ay = max(1, min(36, (int)setting('hatirlatma_ay', '6')));
    $adaylar = hatirlat_adetleri($ay);

    layout_head('Hatırlatma', 'hatirlat');
    if (!empty($_SESSION['flash'])) {
        echo '<div class="alert">' . e((string)$_SESSION['flash']) . '</div>';
        unset($_SESSION['flash']);
    }
    ?>
<div class="card">
  <div class="orderhead">
    <div><b>Tekrar sipariş hatırlatma</b><br>
      <small class="muted">Son tesliminden <?= (int)$ay ?> ay geçen müşterilere WhatsApp hatırlatması.</small></div>
    <span class="badge" style="background:#10243A"><?= count($adaylar) ?> aday</span>
  </div>
  <form method="post" class="form" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:8px">
    <?= csrf_field() ?><input type="hidden" name="eylem" value="ayar">
    <label style="flex:0 0 160px">Kaç ay sonra?
      <input type="number" name="ay" min="1" max="36" value="<?= (int)$ay ?>">
    </label>
    <button class="btn btn-sm">Kaydet</button>
    <span class="muted small">Mesaj metni: Ayarlar → WhatsApp şablonları → “hatirlatma”.</span>
  </form>
</div>

<?php if (!$adaylar): ?>
  <div class="card bos-durum">
    <p>Şu an hatırlatma koşuluna uyan müşteri yok.</p>
    <p class="muted small"><?= (int)$ay ?> aydan eski teslimi olan, açık siparişi bulunmayan ve WhatsApp izni olan müşteriler burada listelenir.</p>
  </div>
<?php else: ?>
<form method="post">
<?= csrf_field() ?><input type="hidden" name="eylem" value="kuyruk">
<div class="card">
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
    <button type="button" class="btn btn-sm" onclick="document.querySelectorAll('input[name=\'cid[]\']').forEach(function(c){c.checked=true})">Tümünü seç</button>
    <button class="btn btn-primary btn-sm">Seçilenleri kuyruğa ekle</button>
  </div>
  <?php foreach ($adaylar as $c): ?>
    <label class="row" style="display:flex;gap:10px;align-items:flex-start;font-weight:400">
      <input type="checkbox" name="cid[]" value="<?= (int)$c['id'] ?>" checked style="width:auto;margin-top:4px">
      <span class="row-main"><b><?= e((string)$c['name']) ?></b>
        <br><small><?= e((string)$c['phone']) ?><?= (string)$c['district'] !== '' ? ' · ' . e((string)$c['district']) : '' ?></small></span>
      <span class="row-side"><small>Son teslim<br><b><?= e(tr_date((string)$c['son'])) ?></b></small></span>
    </label>
  <?php endforeach; ?>
</div>
</form>
<?php endif; ?>
<?php
    layout_foot('hatirlat');
}

/** Aday müşteriler: son teslimi N aydan eski, açık siparişi olmayan, opt-in'li. */
function hatirlat_adetleri(int $ay): array
{
    $st = db()->prepare("SELECT c.id,c.name,c.phone,c.district,
            MAX(COALESCE(NULLIF(o.delivery_date,''), substr(o.created_at,1,10))) son
        FROM customers c
        JOIN orders o ON o.customer_id=c.id AND o.status='teslim'
        WHERE c.wa_optin=1 AND c.phone<>''
          AND NOT EXISTS (
              SELECT 1 FROM orders o2 WHERE o2.customer_id=c.id
                AND o2.status NOT IN ('teslim','iptal')
          )
        GROUP BY c.id
        HAVING son <= date('now','localtime','-" . $ay . " months')
        ORDER BY son ASC, c.name
        LIMIT 500");
    $st->execute();
    return $st->fetchAll();
}
