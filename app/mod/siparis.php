<?php
/** Sipariş modülü: oluşturma, kart, durum akışı, fotoğraf, halı takibi, ödeme */

function order_or_404(): array
{
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare('SELECT o.*, c.name cname, c.phone, c.phone2, c.address caddress,
        c.district cdistrict, c.wa_optin FROM orders o
        JOIN customers c ON c.id=o.customer_id WHERE o.id=?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) {
        http_response_code(404);
        layout_head('Bulunamadı');
        echo '<div class="card"><p>Sipariş bulunamadı.</p><a class="btn" href="?p=siparisler">← Siparişler</a></div>';
        layout_foot();
        exit;
    }
    ensure_item_codes($id);
    return $o;
}

function order_items(int $oid): array
{
    $st = db()->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');
    $st->execute([$oid]);
    return $st->fetchAll();
}

/**
 * Siparişe kopyalanacak teslim adresi.
 *
 * Sipariş kaydı o anlık GÖRÜNTÜ (snapshot) tutar: müşteri sonradan adresini
 * değiştirse bile sipariş satırındaki adres değişmez. Bu yüzden değerler
 * INSERT anında okunup yazılır, sonradan sorgulanmaz.
 *
 * Öncelik: seçilen adres (address_id) > müşterinin varsayılan adres kaydı >
 * geriye uyum için customers.address/district. Müşterinin adres tablosunda
 * kaydı yoksa ya da seçilen kayıt silinmişse elde kalan son alana düşülür.
 */
function order_ship_address(int $custId, int $addressId = 0): array
{
    $blank = ['id' => 0, 'label' => '', 'district' => '', 'address' => '', 'map_url' => ''];
    if ($custId <= 0) return $blank;

    // 1) Formda seçilen adres (müşterinin kendi adresi olduğu doğrulanır)
    if ($addressId > 0) {
        $st = db()->prepare('SELECT id,label,district,address,map_url FROM customer_addresses
            WHERE id=? AND customer_id=?');
        $st->execute([$addressId, $custId]);
        $a = $st->fetch();
        if ($a) {
            return ['id' => (int)$a['id'], 'label' => (string)$a['label'],
                'district' => (string)$a['district'], 'address' => (string)$a['address'],
                'map_url' => (string)$a['map_url']];
        }
    }

    // 2) Varsayılan adres kaydı
    $adr = musteri_adresleri($custId);
    $a = null;
    foreach ($adr as $x) if ((int)$x['is_default'] === 1) { $a = $x; break; }
    if (!$a && $adr) $a = $adr[0];
    if ($a) {
        return ['id' => (int)$a['id'], 'label' => (string)($a['label'] ?? ''),
            'district' => (string)($a['district'] ?? ''), 'address' => (string)($a['address'] ?? ''),
            'map_url' => (string)($a['map_url'] ?? '')];
    }

    // 3) Geriye uyum: adres tablosunda kaydı olmayan eski müşteriler
    $st = db()->prepare('SELECT address,district,map_url FROM customers WHERE id=?');
    $st->execute([$custId]);
    $c = $st->fetch();
    if (!$c) return $blank;
    return ['id' => 0, 'label' => '', 'district' => (string)($c['district'] ?? ''),
        'address' => (string)($c['address'] ?? ''), 'map_url' => (string)($c['map_url'] ?? '')];
}

// ---------------------------------------------------------------- yeni sipariş
function siparis_yeni(): void
{
    need_perm('siparis.olustur');
    // Web talebinden dönüştürme: ?p=siparis-yeni&talep=ID
    $talepId = (int)($_GET['talep'] ?? $_POST['talep_id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $db = db();

        // çevrimdışı kuyruktan gelen yinelenen kayıt engeli
        $clientUid = trim((string)($_POST['client_uid'] ?? ''));
        if ($clientUid !== '') {
            $st = $db->prepare('SELECT id FROM orders WHERE client_uid=? LIMIT 1');
            $st->execute([$clientUid]);
            $dup = $st->fetchColumn();
            if ($dup) { redirect('siparis', 'id=' . (int)$dup . '&uyari=tekrar'); }
        }

        $custId = (int)($_POST['customer_id'] ?? 0);
        $tel = trim((string)($_POST['yeni_phone'] ?? ''));
        if ($custId === 0 && trim((string)($_POST['yeni_name'] ?? '')) !== '') {
            // aynı telefon varsa yeni kayıt açma — mükerrer önleme
            $dup = musteri_bul($tel);
            if ($dup) {
                $custId = (int)$dup['id'];
            } else {
                $st = $db->prepare('INSERT INTO customers(name,phone,phone2,address,district,note,created_at,saved_at)
                    VALUES(?,?,?,?,?,?,?,?)');
                $st->execute([trim((string)$_POST['yeni_name']), $tel, '',
                    trim((string)($_POST['yeni_address'] ?? '')),
                    trim((string)($_POST['yeni_district'] ?? '')), '', now_dt(), now_dt()]);
                $custId = (int)$db->lastInsertId();
            }
        }
        if ($custId === 0) redirect('siparis-yeni', $talepId > 0 ? 'talep=' . $talepId : '');

        $items = [];
        $types = service_types();
        $qtyArr = $_POST['t_qty'] ?? [];
        foreach (($_POST['t_type'] ?? []) as $i => $type) {
            if (!isset($types[$type])) continue;
            $qty = max(1, (int)($qtyArr[$i] ?? 1));
            $w = (float)str_replace(',', '.', (string)($_POST['t_w'][$i] ?? 0));
            $h = (float)str_replace(',', '.', (string)($_POST['t_h'][$i] ?? 0));
            $m2 = ($w > 0 && $h > 0) ? round($w * $h, 2) : 0;
            $unit = parse_tl($_POST['t_unit'][$i] ?? '');
            // Ayarlar'daki fiyat listesinden otomatik gelen fiyat herkes için
            // geçerlidir. Elle yazılan fiyat ise ayrı yetki ister: yetkisi
            // olmayan eleman POST ile gönderdiği tutarı da yazamaz.
            $liste = pricelist();
            $normal = (int)($liste[$type] ?? 0);
            if ($unit === 0 && $normal > 0) $unit = $normal;
            if (!can('siparis.fiyat') && $unit !== $normal) {
                $unit = $normal;
            }
            // Fiyat listesi boşsa ve elemanın fiyat yetkisi yoksa birim 0'a
            // eziliyordu → sipariş SESSİZCE 0 ₺ açılıyordu ve bedava
            // teslim edilebiliyordu. Yorumda "liste boşsa hata ver" yazıyordu;
            // şimdi gerçekten açık hata veriyor (tasarım niyeti).
            if ($unit === 0 && !can('siparis.fiyat')) {
                $_SESSION['hata'] = 'Fiyat listesinde "' . ($types[$type][0] ?? $type)
                    . '" için fiyat girilmemiş. Yönetici Ayarlar → Birim Fiyatlar '
                    . 'bölümünden fiyat girene kadar sipariş açılamaz.';
                redirect('siparis-yeni');
            }
            // Satır tutarı yetkisiz kişiden ALINMAZ. Önceki sürümde istemcinin
            // gönderdiği t_line herkese aynen yazılıyordu: siparis.fiyat
            // yetkisi olmayan personel formu değiştirerek 50 ₺'lik işi
            // 88.888 ₺ olarak kaydedebiliyordu (denetimde ölçüldü).
            //  - siparis.fiyat YETKİLİSİ: pazarlıkli toplam (indirimli iş)
            //    için elle tutar girebilir; boşsa tutar = birim × çarpan.
            //  - Yetkisi yok: tutar DAİMA birim × (m² varsa m², yoksa adet)
            //    ile yeniden hesaplanır, POST'taki tutar yok sayılır.
            $carpan = ($m2 > 0) ? $m2 : $qty;
            if (can('siparis.fiyat')) {
                $line = parse_tl($_POST['t_line'][$i] ?? '');
                if ($line === 0 && $unit > 0) $line = (int)round($unit * $carpan);
            } else {
                $line = ($unit > 0) ? (int)round($unit * $carpan) : 0;
            }
            $items[] = [$type, trim((string)($_POST['t_descr'][$i] ?? '')), $w, $h, $m2, $qty, $unit, $line,
                trim((string)($_POST['t_note'][$i] ?? '')), trim((string)($_POST['t_damage'][$i] ?? ''))];
        }
        if (!$items) redirect('siparis-yeni', $talepId > 0 ? 'talep=' . $talepId : '');

        $pickupType = ($_POST['pickup_type'] ?? 'elden') === 'servis' ? 'servis' : 'elden';
        $status = $pickupType === 'servis' ? 'alim_bekliyor' : 'teslim_alindi';
        $code = next_order_code();
        $now = now_dt();

        // Adres KOPYASI: sipariş kendi adresini tutar, müşteri sonradan
        // adresini değiştirse bile bu kayıt sabit kalır.
        $ship = order_ship_address($custId, (int)($_POST['address_id'] ?? 0));

        $db->beginTransaction();
        try {
            $st = $db->prepare('INSERT INTO orders(code,customer_id,status,pickup_type,pickup_date,
                delivery_date,delivery_time,note,total_kurus,discount_kurus,staff,assigned_to,
                client_uid,received_count,created_at,updated_at,
                ship_label,ship_district,ship_address,ship_map_url,ship_address_id)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$code, $custId, $status, $pickupType,
                (string)($_POST['pickup_date'] ?? ''), (string)($_POST['delivery_date'] ?? ''),
                (string)($_POST['delivery_time'] ?? ''),
                trim((string)($_POST['note'] ?? '')), array_sum(array_column($items, 7)),
                // İndirim yetkisi yoksa alan YOK sayılır — sadece formu
                // gizlemek yetmez, POST ile gönderilmesi de engellenmelidir.
                can('siparis.indirim') ? parse_tl($_POST['indirim'] ?? '') : 0,
                trim((string)($_POST['staff'] ?? '')),
                (int)($_POST['assigned_to'] ?? 0) ?: null,
                $clientUid,
                array_sum(array_column($items, 5)),
                $now, $now,
                $ship['label'], $ship['district'], $ship['address'], $ship['map_url'],
                $ship['id'] ?: null]);
            $oid = (int)$db->lastInsertId();

            $sti = $db->prepare('INSERT INTO order_items(order_id,type,descr,width,height,m2,qty,
                unit_kurus,line_kurus,note,item_code,damage,received_qty,returned_qty)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($items as $it) {
                $sti->execute([$oid, $it[0], $it[1], $it[2], $it[3], $it[4], $it[5], $it[6], $it[7],
                    $it[8], next_item_code(), $it[9], 0, 0]);
            }
            add_event($oid, $status, 'Sipariş oluşturuldu');
            // Web talebi siparişe dönüştü: talep kuyruğunda iz kalır (silinmez),
            // böylece hangi talebin hangi siparişe gittiği sonradan görülebilir.
            if ($talepId > 0) {
                $tr = $db->prepare("UPDATE requests SET status='donustu', order_id=?, updated_at=?
                    WHERE id=?");
                $tr->execute([$oid, $now, $talepId]);
                audit('talep_donustu', 'requests', $talepId, 'order:' . $oid);
            }
            $db->commit();
            audit('siparis_olustur', 'orders', $oid, $code);
            // bildirim kuyruğu — sipariş kaydını asla engellemez
            wa_enqueue($oid, $pickupType === 'servis' ? 'alim_planlandi' : 'teslim_alindi', 'olusturma');
            redirect('siparis', 'id=' . $oid);
        } catch (Throwable $e) {
            $db->rollBack();
            app_log('siparis olusturulamadı: ' . $e->getMessage());
            $_SESSION['hata'] = 'Sipariş kaydedilemedi: ' . $e->getMessage();
            redirect('siparis-yeni');
        }
    }

    $preCust = (int)($_GET['musteri'] ?? 0);
    $copyFrom = (int)($_GET['kopyala'] ?? 0);
    // Web talebi ön dolgusu: iletişim bilgileri forma taşınır, müşteri
    // telefonda zaten kayıtlıysa mükerrer kayıt açılmaz.
    $preTalep = null;
    if ($talepId > 0) {
        $stq = db()->prepare('SELECT * FROM requests WHERE id=? LIMIT 1');
        $stq->execute([$talepId]);
        $preTalep = $stq->fetch() ?: null;
        if (!$preTalep) $talepId = 0;
    }
    layout_head('Yeni Sipariş', 'siparis-yeni');
    if (!empty($_SESSION['hata'])) {
        echo '<div class="alert">' . e($_SESSION['hata']) . '</div>';
        unset($_SESSION['hata']);
    }
    $db = db();
    $st = $db->query('SELECT id,name,phone FROM customers ORDER BY id DESC LIMIT 80');
    $custs = $st->fetchAll();
    $prices = pricelist();
    $types = service_types();
    $staff = db()->query("SELECT id,full_name,username FROM users WHERE active=1 AND role='personel'")->fetchAll();

    $pre = ['musteri' => $preCust, 'items' => [], 'note' => '', 'pickup_type' => 'elden',
            'pickup_date' => today(), 'delivery_date' => '', 'delivery_time' => ''];
    if ($copyFrom > 0) {
        $st = $db->prepare('SELECT o.*, c.name cname FROM orders o JOIN customers c ON c.id=o.customer_id WHERE o.id=?');
        $st->execute([$copyFrom]);
        $src = $st->fetch();
        if ($src) {
            $pre['musteri'] = (int)$src['customer_id'];
            $pre['note'] = (string)$src['note'];
            $pre['pickup_type'] = (string)$src['pickup_type'];
            $pre['items'] = order_items($copyFrom);
        }
    }

    // Web talebi: müşteri telefonda kayıtlıysa onu seç, değilse "yeni müşteri"
    // alanlarını talepten doldur. Talep parçaları serbest metindir; sipariş
    // satırlarına otomatik bölünmez, notta taşınır (kullanıcı satırlara kendi böler).
    $yeniAd = ''; $yeniTel = ''; $yeniAdres = ''; $yeniIlce = '';
    if ($preTalep) {
        $dup = musteri_bul((string)$preTalep['phone']);
        if ($dup && $pre['musteri'] === 0) $pre['musteri'] = (int)$dup['id'];
        if ($pre['musteri'] === 0) {
            $yeniAd = (string)$preTalep['name'];
            $yeniTel = (string)$preTalep['phone'];
            $yeniAdres = (string)$preTalep['address'];
            $yeniIlce = (string)$preTalep['district'];
        }
        if ($pre['note'] === '') {
            $pre['note'] = 'Web talebi: ' . (string)$preTalep['items']
                . (trim((string)$preTalep['note']) !== '' ? "\nNot: " . (string)$preTalep['note'] : '')
                . (trim((string)$preTalep['address']) !== '' ? "\nAdres: " . (string)$preTalep['address'] : '');
        }
        // Site formu "kapınızdan alalım" vaadiyle dolduruluyor: servis varsayılan.
        $pre['pickup_type'] = 'servis';
    }

    // Müşteri birden çok adres kaydına sahipse siparişte hangisinin
    // kopyalanacağı seçilir. Tek adresi olanlarda form yorulmaz; o adres
    // zaten siparişe kendiliğinden kopyalanır.
    $addrMap = [];
    foreach ($custs as $c) {
        $liste = musteri_adresleri((int)$c['id']);
        if (count($liste) > 1) {
            $addrMap[(int)$c['id']] = array_map(function ($a) {
                return ['id' => (int)$a['id'], 'label' => (string)($a['label'] ?? ''),
                    'metin' => musteri_adres_metni($a),
                    'varsayilan' => (int)$a['is_default'] === 1 ? 1 : 0];
            }, $liste);
        }
    }
    $preAddrId = 0;
    foreach ($addrMap[$pre['musteri']] ?? [] as $a) {
        if ($a['varsayilan']) { $preAddrId = $a['id']; break; }
    }
    $addrJson = json_encode($addrMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    ?>
<form method="post" action="?p=siparis-yeni" class="form" id="orderForm">
<?= csrf_field() ?>
<input type="hidden" name="talep_id" value="<?= (int)$talepId ?>">
<?php if ($preTalep): ?>
<div class="card" style="border-left:4px solid var(--teal)">
  <b>📥 Web talebinden dönüştürülüyor</b>
  <p class="small" style="margin:6px 0"><?= e((string)$preTalep['name']) ?> · <?= e((string)$preTalep['phone']) ?>
     <?= trim((string)$preTalep['district']) !== '' ? ' · ' . e((string)$preTalep['district']) : '' ?></p>
  <p class="small muted" style="margin:0 0 8px">Talep: <?= e((string)$preTalep['items']) ?>
     <?= trim((string)$preTalep['address']) !== '' ? ' · 📍 ' . e((string)$preTalep['address']) : '' ?></p>
  <a class="btn btn-sm" href="?p=talepler">← Taleplere dön</a>
</div>
<?php endif; ?>
<input type="hidden" name="client_uid" id="clientUid" value="">
<div id="mimOfflineMesaj" class="alert warn" style="display:none"></div>
<div id="mimOfflineKuyruk"></div>
<div class="card">
<h2>Müşteri</h2>
<label>Mevcut müşteri
<select name="customer_id" id="custSel">
<option value="0">— seç / aşağıda yeni gir —</option>
<?php foreach ($custs as $c): ?>
<option value="<?= (int)$c['id'] ?>" <?= $pre['musteri'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name'] . ' · ' . $c['phone']) ?></option>
<?php endforeach; ?>
</select></label>
<?php /* Birden çok adresi olan müşteride adres seçimi; tek adresde gerek yok. */ ?>
<label id="addrSecimKutu" style="display:none">Teslim adresi
<select name="address_id" id="addrSel">
<?php foreach ($addrMap[$pre['musteri']] ?? [] as $a): ?>
<option value="<?= $a['id'] ?>" <?= $a['id'] === $preAddrId ? 'selected' : '' ?>><?= e($a['label'] . ' — ' . $a['metin']) ?></option>
<?php endforeach; ?>
</select></label>
<script>window.MIM_ADDRES = <?= $addrJson ?: '{}' ?>;</script>
<details <?= $pre['musteri'] ? '' : 'open' ?>><summary>Yeni müşteri gir</summary>
<label>Ad Soyad<input name="yeni_name" value="<?= e($yeniAd) ?>"></label>
<label>Telefon<input name="yeni_phone" type="tel" inputmode="tel" value="<?= e($yeniTel) ?>"></label>
<label>Adres<input name="yeni_address" value="<?= e($yeniAdres) ?>"></label>
<label>Mahalle / İlçe<input name="yeni_district" value="<?= e($yeniIlce) ?>"></label>
<p class="muted small">Telefon kayıtlıysa mevcut müşteriye eklenir, mükerrer kayıt açılmaz.</p>
</details>
</div>

<div class="card">
<h2>Hizmetler</h2>
<div id="items"></div>
<button type="button" class="btn btn-block" onclick="addItemRow()">+ Satır Ekle</button>
<?php if (can('siparis.indirim')): ?>
<label>İndirim<input name="indirim" inputmode="decimal" placeholder="0,00"></label>
<?php else: ?>
<p class="muted small" style="margin:0">İndirim giremiyorsunuz — bu yetki yöneticiniz tarafından verilir.</p>
<?php endif; ?>
</div>

<div class="card">
<h2>Servis & Teslim</h2>
<label>Teslim şekli
<select name="pickup_type" id="pickupType">
<option value="elden" <?= $pre['pickup_type'] === 'elden' ? 'selected' : '' ?>>Müşteri elden getirdi/götürecek</option>
<option value="servis" <?= $pre['pickup_type'] === 'servis' ? 'selected' : '' ?>>Servis ile alınacak (kapıdan)</option>
</select></label>
<label>Alım / Kabul tarihi<input type="date" name="pickup_date" value="<?= e($pre['pickup_date']) ?>"></label>
<label>Planlanan teslim tarihi<input type="date" name="delivery_date" value="<?= e($pre['delivery_date']) ?>"></label>
<label>Planlanan teslim saati<input type="time" name="delivery_time" value="<?= e($pre['delivery_time']) ?>"></label>
<label>Sorumlu personel
<select name="assigned_to"><option value="">— atanmadı —</option>
<?php foreach ($staff as $s): ?>
<option value="<?= (int)$s['id'] ?>"><?= e($s['full_name'] ?: $s['username']) ?></option>
<?php endforeach; ?>
</select></label>
<label>Alan personeli<input name="staff" placeholder="kim aldı?"></label>
<label>Sipariş notu<textarea name="note" rows="2" placeholder="örn. leke var, renk atma riski sorma"><?= e($pre['note']) ?></textarea></label>
</div>
<div class="card totalbar"><div>Toplam: <b id="grandTotal">0,00 ₺</b></div>
<button class="btn btn-primary btn-block">Siparişi Kaydet</button></div>
</form>
<script id="priceData" type="application/json"><?= json_encode([
    'types' => $types, 'prices' => $prices, 'preItems' => $pre['items'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<!-- app.js'i BURADA yükleme: layout_foot zaten yüklüyor. Çift yükleme satır
     mantığını iki kez kuruyor, artık (çift gönderim koruması ile birlikte)
     tek submit'i iki kez işleyip gönderimi engelliyordu. -->
<script src="offline.js"></script>
<script>
// Bağlantı yokken form gönderimi yakalanır, sipariş IndexedDB'ye yazılır.
// Bağlantı varsa hiçbir şeye dokunulmaz — sunucunun mevcut akışı aynen çalışır.
(function(){
  if (!window.mimOffline) return;
  window.mimOffline.sayfaKur();
  window.mimOffline.periyodikBaslat(60000);
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function(){ /* kayıt olmazsa uygulama normal çalışır */ });
  }
})();

// Adres seçimi: yalnız birden çok adresi olan müşteride görünür.
// Sunucu tarafı zaten güvenli: address_id yoksa ya da geçersizse
// siparişe varsayılan adres kopyalanır.
(function(){
  var HARITA = window.MIM_ADDRES || {};
  var kutu = document.getElementById('addrSecimKutu');
  var sel = document.getElementById('addrSel');
  var musteri = document.getElementById('custSel');
  if (!kutu || !sel || !musteri) return;

  function ciz(){
    var liste = HARITA[musteri.value] || [];
    if (liste.length < 2) { kutu.style.display = 'none'; return; }
    var eski = sel.value;
    sel.innerHTML = '';
    for (var i = 0; i < liste.length; i++) {
      var o = document.createElement('option');
      o.value = liste[i].id;
      o.textContent = liste[i].label + ' — ' + liste[i].metin;
      sel.appendChild(o);
    }
    var vars = '';
    for (var j = 0; j < liste.length; j++) if (liste[j].varsayilan) { vars = liste[j].id; break; }
    sel.value = (eski && HARITA[musteri.value].some(function(x){return String(x.id)===eski;})) ? eski : (vars || liste[0].id);
    kutu.style.display = '';
  }
  musteri.addEventListener('change', ciz);
  ciz();
})();
</script>
<?php
    layout_foot('siparis-yeni');
}

// ---------------------------------------------------------------- sipariş kartı
function siparis_kart(): void
{
    need_perm('siparis.gor');
    foto_yukle_form();   // POST: multipart yükleme, aksi hâlde ekrana devam eder
    $o = order_or_404();
    $oid = (int)$o['id'];
    $db = db();
    $items = order_items($oid);

    // Durum değişikliği hataları (adet uyuşmazlığı, akış dışı geçiş…) BURADA
    // gösterilir. Önceki sürümde $_SESSION['hata'] yalnız siparis_yeni()
    // ekranında okunuyordu; siparis kartına yönlendirilen hatalar sessizce
    // kayboluyordu — kullanıcı butona basıyor, hiçbir şey olmuyor, nedenini
    // de anlamıyordu. "İptal" gibi akış dışı butonlar kalıcı olarak ölüydü.
    if (!empty($_SESSION['hata'])) {
        echo '<div class="alert danger">' . e((string)$_SESSION['hata']) . '</div>';
        unset($_SESSION['hata']);
    }
    if (!empty($_SESSION['flash'])) {
        echo '<div class="alert">' . e((string)$_SESSION['flash']) . '</div>';
        unset($_SESSION['flash']);
    }

    // Akış dışı geçiş istenmiş ama gerekçe yazılmamışsa: nedenini soran form.
    // (Önceki sürümde &istisna=1 ile yönlendiriliyor ama bu form hiç basılmıyordu.)
    if (isset($_GET['istisna']) && (int)$_GET['istisna'] === 1 && is_admin()) {
        $hedef = (string)($_GET['hedef'] ?? '');
        if ($hedef !== '' && isset(status_labels()[$hedef])) {
            echo '<div class="card" id="istisna-kart"><h2>⚠️ Akış dışı durum değişikliği</h2>'
                . '<p class="muted small">"<b>' . e(status_labels()[$hedef]) . '</b>" bu siparişin '
                . 'şu anki durumuna (<b>' . e(status_labels()[(string)$o['status']] ?? $o['status']) . '</b>) '
                . 'sırasıyla uymuyor. Devam ederseniz bu değişiklik istisna olarak kaydedilir.</p>'
                . '<form method="post" action="?p=siparis-durum">' . csrf_field()
                . '<input type="hidden" name="id" value="' . $oid . '">'
                . '<input type="hidden" name="durum" value="' . e($hedef) . '">'
                . '<label>İstisna nedeni (zorunlu)<input name="force_reason" required '
                . 'placeholder="örn. müşteri iptal etti, halı iade edildi"></label>'
                . '<button class="btn btn-danger btn-block">Onayla ve kaydet</button></form>'
                . '<a class="btn btn-ghost btn-block mt8" href="?p=siparis&id=' . $oid . '">Vazgeç</a>'
                . '</div>';
        }
    }

    $st = $db->prepare('SELECT * FROM payments WHERE order_id=? ORDER BY id');
    $st->execute([$oid]); $pays = $st->fetchAll();
    $st = $db->prepare('SELECT * FROM events WHERE order_id=? ORDER BY id DESC LIMIT 30');
    $st->execute([$oid]); $evs = $st->fetchAll();
    $photos = photo_rows($oid);

    $toplam = (int)$o['total_kurus'] - (int)$o['discount_kurus'];
    $odenmis = 0; foreach ($pays as $p) $odenmis += (int)$p['amount_kurus'];
    $bakiye = $toplam - $odenmis;
    $beklenen = (int)$o['received_count'];
    $teslimEdilen = (int)$o['returned_count'];
    $d = delay_info($o);

    layout_head('Sipariş ' . (string)$o['code']);
    if (($_GET['uyari'] ?? '') === 'tekrar') {
        echo '<div class="alert warn">Bu sipariş zaten kayıtlıydı, yinelenen kayıt oluşturulmadı.</div>';
    }
    // AŞIRI ÖDEME UYARISI — para hatasını kullanıcı görmelidir.
    // Önceki sürümde odeme_ekle() kullanıcıyı buraya "&uyari=asiri" ile
    // yönlendiriyordu ama bu parametre HİÇ OKUNMUYORDU: ödeme sessizce
    // kaydediliyor, bakiye negatife düşüyor ve kullanıcı hiçbir şey
    // öğrenemiyordu. Kasadaki rakam yanlış görünüyordu.
    if (($_GET['uyari'] ?? '') === 'asiri') {
        $toplamOdenen = (int)db()->query('SELECT COALESCE(SUM(amount_kurus),0) FROM payments WHERE order_id=' . $oid)->fetchColumn();
        $fark = $toplamOdenen - ((int)$o['total_kurus'] - (int)$o['discount_kurus']);
        echo '<div class="alert danger"><b>⚠️ Aşırı ödeme yapıldı.</b> '
            . 'Kaydedilen ödeme: <b>' . e(money_tl($toplamOdenen)) . '</b> · '
            . 'Sipariş tutarı: <b>' . e(money_tl((int)$o['total_kurus'] - (int)$o['discount_kurus'])) . '</b> · '
            . 'Fazla: <b>' . e(money_tl($fark)) . '</b><br>'
            . '<span class="small">Ödeme kaydı silinmedi (kapora/ek ödeme meşru olabilir). '
            . 'Fazladan tahsilat varsa ödemeyi silin veya müşteriye iade edin.</span></div>';
    }
    if ($d['late']) {
        echo '<div class="alert ' . ($d['bucket'] === 'kritik' ? 'danger' : 'warn') . '">'
            . '<b>⏰ ' . e($d['label']) . ' gecikme.</b> Planlanan teslim: ' . e(tr_date((string)$o['delivery_date']))
            . ($o['delay_reason'] ? ' · Neden: ' . e(delay_reasons()[(string)$o['delay_reason']] ?? (string)$o['delay_reason']) : '')
            . '</div>';
    }
    echo count_warning($o);

    // durum başlığı + aksiyonlar
    echo '<div class="card"><div class="orderhead"><div><b>' . e($o['code']) . '</b><br>'
        . '<small>' . e(tr_date((string)$o['created_at'], true)) . ' · ' . e((string)$o['status'] === '' ? '' : '')
        . (int)$beklenen . ' parça</small></div>' . status_badge((string)$o['status']) . '</div>';
    echo '<div class="btnrow mt8">';
    status_actions($o);
    echo '</div></div>';

    // müşteri
    echo '<div class="card">';
    echo '<div class="kv"><div>Müşteri</div><div><a href="?p=musteri&id=' . (int)$o['customer_id'] . '"><b>'
        . e($o['cname']) . '</b></a></div></div>';
    echo '<div class="kv"><div>Telefon</div><div>' . (customer_line($o) ?: '<span class="muted">—</span>') . '</div></div>';
    // Siparişin KENDİ adres kopyası gösterilir (snapshot). Müşteri sonradan
    // adresini değiştirdiyse fatura/adres kaydı yine siparişteki eski haliyle
    // durur. ship_address boşsa eski tek alanlı müşteri kaydına düşülür.
    $shipAdres = trim((string)($o['ship_address'] ?? ''));
    $shipIlce = trim((string)($o['ship_district'] ?? ''));
    if ($shipAdres === '' && $shipIlce === '') {
        $shipAdres = trim((string)($o['caddress'] ?? ''));
        $shipIlce = trim((string)($o['cdistrict'] ?? ''));
    }
    if ($shipAdres !== '' || $shipIlce !== '') {
        $shipEtiket = trim((string)($o['ship_label'] ?? ''));
        echo '<div class="kv"><div>' . e($shipEtiket !== '' ? 'Adres (' . $shipEtiket . ')' : 'Adres')
            . '</div><div>' . e(trim($shipIlce . ($shipIlce !== '' && $shipAdres !== '' ? ' · ' : '') . $shipAdres))
            . '</div></div>';
    }
    // Saha kısayolları (Ara / WhatsApp / Harita).
    // ⚠ nav_quick() müşteri satırındaki `address`/`district` kolonlarını okur;
    //   sipariş tablosunda bu kolonlar YOKTUR — adres KOPYASI ship_address /
    //   ship_district alanlarındadır. Önceki sürümde sipariş satırı doğrudan
    //   verildiği için harita bağlantısı hiç üretilmiyordu (ölçülen: 0 harita
    //   bağlantısı, oysa adres ekranda yazılıydı).
    //   Müşteri bilgisi JOIN ile geliyorsa alanlar doludur; yine de siparişin
    //   kendi adres kopyası önceliklidir (müşteri sonradan adresini değiştirse
    //   bile bu kayıt sabit kalmalıdır).
    $quickSatir = $o;
    if ((string)($quickSatir['address'] ?? '') === '' && $shipAdres !== '') {
        $quickSatir['address'] = $shipAdres;
        $quickSatir['district'] = $shipIlce;
    }
    $quick = nav_quick($quickSatir);
    if ($quick) echo '<div class="btnrow pad">' . $quick . '</div>';
    echo '<div class="kv"><div>Teslim</div><div>' . ($o['pickup_type'] === 'servis' ? 'Servis (kapıdan)' : 'Elden')
        . ' · alım ' . e(tr_date((string)$o['pickup_date']))
        . ' · teslim <b>' . e(tr_date((string)$o['delivery_date'])) . '</b>'
        . ($o['delivery_time'] ? ' ' . e((string)$o['delivery_time']) : '')
        // siparis_tarih() fonksiyonu ve rotası VARDI ama hiçbir form onu
        // çağırmıyordu → teslim tarihi pratikte DEĞİŞTİRİLEMEZDI.
        // Müşteri tarihi değiştirdiğinde saha ekibi eski tarihe göre
        // çalışıyordu. Kapalı siparişte değişiklik yapılabilir (geçmişe
        // dönük düzeltme mümkün olmalı).
        . (can('siparis.guncelle')
            ? ' <a class="btn btn-sm" href="?p=siparis-tarih-form&id=' . $oid . '">✏ Değiştir</a>'
            : '') . '</div></div>';
    if ($o['staff']) echo '<div class="kv"><div>Personel</div><div>' . e($o['staff']) . '</div></div>';
    if ($o['assigned_to']) {
        $st = $db->prepare('SELECT full_name,username FROM users WHERE id=?');
        $st->execute([(int)$o['assigned_to']]);
        $u = $st->fetch();
        if ($u) echo '<div class="kv"><div>Sorumlu</div><div>' . e($u['full_name'] ?: $u['username']) . '</div></div>';
    }
    if ($o['note']) echo '<div class="kv"><div>Not</div><div>' . nl2br(e($o['note'])) . '</div></div>';
    echo '</div>';

    // hizmet satırları (parça kodlu)
    echo '<div class="card"><h2>Hizmetler & Halılar</h2>';
    $types = service_types();
    foreach ($items as $it) {
        $t = $types[(string)$it['type']] ?? [(string)$it['type'], false];
        $det = [];
        if ((float)$it['m2'] > 0) $det[] = e(str_replace('.', ',', (string)$it['m2'])) . ' m²';
        if ((int)$it['qty'] > 1) $det[] = (int)$it['qty'] . ' adet';
        if ($it['width'] > 0 && $it['height'] > 0) {
            $det[] = e(str_replace('.', ',', (string)$it['width'])) . '×' . e(str_replace('.', ',', (string)$it['height'])) . ' m';
        }
        if ($it['descr']) $det[] = e((string)$it['descr']);
        $fotos = 0;
        foreach ($photos as $ph) if ((int)($ph['item_id'] ?? 0) === (int)$it['id']) $fotos++;
        echo '<div class="row" id="it' . (int)$it['id'] . '">'
            . '<span class="row-main"><b>' . e((string)$it['item_code']) . '</b> '
            . '<a href="?p=item-kod&id=' . (int)$it['id'] . '">' . e($t[0]) . '</a>'
            . ($det ? '<br><small>' . implode(' · ', $det) . '</small>' : '')
            . ((int)$it['qty'] > 0 ? ' <span class="badge badge-soft">' . (int)$it['qty'] . ' adet</span>' : '')
            . ($fotos ? ' <a class="badge badge-soft" href="#foto">' . $fotos . ' foto</a>' : '')
            . ((string)$it['note'] !== '' ? '<br><small><i>' . e((string)$it['note']) . '</i></small>' : '')
            . ((string)$it['damage'] !== '' ? '<br><small class="neg">⚠ Hasar: ' . e((string)$it['damage']) . '</small>' : '')
            . '</span>'
            . '<span class="row-side">' . money_tl((int)$it['line_kurus']) . '</span></div>';
    }
    if ((int)$o['discount_kurus'] > 0) {
        echo '<div class="row"><span class="row-main">İndirim</span>'
            . '<span class="row-side neg">-' . money_tl((int)$o['discount_kurus']) . '</span></div>';
    }
    echo '<div class="row total"><span class="row-main"><b>Toplam</b></span>'
        . '<span class="row-side"><b>' . money_tl($toplam) . '</b></span></div>';
    echo '<div class="btnrow pad mt8">'
        . '<a class="btn btn-sm" target="_blank" href="?p=etiket&id=' . $oid . '">🏷 Etiket</a>'
        . '<a class="btn btn-sm" target="_blank" href="?p=fis&id=' . $oid . '">🧾 Fiş</a>'
        . '<a class="btn btn-sm" target="_blank" href="?p=qr&id=' . $oid . '">🔳 QR Etiket</a>'
        . (can('siparis.guncelle') ? '<a class="btn btn-sm" href="?p=siparis-not&id=' . $oid . '">✏ Düzenle</a>' : '')
        . '</div>';
    // Yorum bağlantısı: yalnızca teslim edilmiş siparişte ve bağlantı
    // Ayarlar'da girilmişse görünür. Boşsa hiçbir yerde buton çıkmaz.
    $gYorum = google_yorum_linki();
    if ($gYorum !== '' && (string)$o['status'] === 'teslim') {
        echo '<div class="yorum-kutu">'
            . '<div class="small">⭐ <b>' . e(google_yorum_mesaji()) . '</b></div>'
            . '<a class="btn btn-block btn-wa" style="margin-top:8px" target="_blank" rel="noopener" href="'
            . e($gYorum) . '">⭐ Google\'da yorum yaz</a>'
            . '<div class="small muted" style="margin-top:6px;margin-bottom:0">'
            . 'Bu bağlantıyı <b>müşteriye</b> gönderin (WhatsApp ile). Yorumu müşteri kendi '
            . 'telefonundan yazar.</div>'
            . '</div>';
    }
    echo '</div>';

    // halı adet kontrolü
    echo '<div class="card"><h2>Halı Adedi Kontrolü</h2>'
        . '<div class="kv"><div>Siparişteki</div><div><b>' . $beklenen . '</b> adet</div></div>'
        . '<div class="kv"><div>Teslim edilen</div><div><b>' . $teslimEdilen . '</b> adet</div></div>';
    if (can('siparis.durum') && !in_array((string)$o['status'], ['teslim', 'iptal'], true)) {
        echo '<form method="post" action="?p=siparis-durum" class="mt8">' . csrf_field()
            . '<input type="hidden" name="id" value="' . $oid . '">'
            . '<input type="hidden" name="teslim_adedi" value="' . $teslimEdilen . '">'
            . '<label>Teslim edilen halı adedi<input name="returned_qty" type="number" min="0" value="' . $teslimEdilen . '"></label>'
            . '<button class="btn btn-primary btn-block">Adedi Kaydet</button></form>';
    }
    echo '</div>';

    // fotoğraflar
    echo '<div class="card" id="foto"><h2>📷 Fotoğraflar (' . count($photos) . ')</h2>';
    if (can('foto.yukle')) {
        // data-mim-sikistir: fotocompress.js bu formu submit'te yakalar ve
        // foto[] dosyalarını tarayıcıda JPEG'e çevirip küçülttükten sonra
        // yükler. GD olmadığı için sunucu tarafında küçültme yapılamaz.
        echo '<form method="post" enctype="multipart/form-data" action="?p=siparis"'
            . ' class="photoform" data-mim-sikistir>'
            . csrf_field() . '<input type="hidden" name="foto_yukle" value="1">'
            . '<input type="hidden" name="id" value="' . $oid . '">'
            . '<label>Aşama seç'
            . '<select name="stage" id="mimFotoStage">';
        foreach (PHOTO_STAGES as $sk => $slbl) {
            echo '<option value="' . e($sk) . '">' . e($slbl) . '</option>';
        }
        echo '</select></label>'
            . '<div class="photo-grid">'
            // Kamera ve galeri AYRI butonlar. capture="environment" tek başına
            // yeterli değil: Android'de galeriyi hiç açmıyor. capture'sız ikinci
            // input ikisini de mümkün kılıyor. Aşama yukarıdaki seçimden gelir.
            . '<label class="photo-label photo-cam">📷 Kamera ile çek'
            . '<input type="file" name="foto[]" accept="image/*" capture="environment" multiple hidden></label>'
            . '<label class="photo-label photo-gal">🖼 Galeriden seç'
            . '<input type="file" name="foto[]" accept="image/jpeg,image/png,image/webp" multiple></label>'
            . '</div>'
            . '<label>Fotoğrafları hangi halıya bağla?<select name="item_id"><option value="0">— sipariş geneli —</option>';
        foreach ($items as $it) {
            $t = $types[(string)$it['type']] ?? [(string)$it['type'], false];
            echo '<option value="' . (int)$it['id'] . '">' . e((string)$it['item_code'] . ' · ' . $t[0]) . '</option>';
        }
        echo '</select></label>'
            . '<label>Not<input name="foto_not" placeholder="örn. saçak yıpranmış, köşede leke"></label>'
            . '<p class="muted small">Kamera ile çek ya da galeriden seç — ikisi de çalışır. '
            . 'En fazla ' . (MAX_PHOTO_BYTES / 1048576) . ' MB · JPG/PNG/WEBP.'
            . ' Fotoğraflar yüklenmeden önce telefonunuzda küçültülür ('
            . photo_client_compress_note() . ').</p>'
            . '<button class="btn btn-primary btn-block">📤 Fotoğrafları Yükle</button></form>';
        // fotocompress.js yalnız bu sipariş kartında gerekiyor → sayfaya burada
        // yüklüyoruz; layout_foot()'a koymak her sayfada boşuna indirtirdi.
        echo '<script src="fotocompress.js"></script>';
    }
    if (!empty($_SESSION['foto_hata'])) {
        echo '<div class="alert"><b>Fotoğraf yüklenemedi:</b> ' . e($_SESSION['foto_hata']) . '</div>';
        unset($_SESSION['foto_hata']);
    }
    if (!$photos) echo '<p class="muted">Henüz fotoğraf yok.</p>';
    foreach (['alim', 'islem_oncesi', 'teslim'] as $stage) {
        $grp = array_values(array_filter($photos, fn($x) => (string)$x['stage'] === $stage));
        if (!$grp) continue;
        echo '<h3>' . e(PHOTO_STAGES[$stage]) . ' (' . count($grp) . ')</h3><div class="thumbs">';
        foreach ($grp as $ph) {
            echo '<figure class="thumb"><a target="_blank" rel="noopener" href="?p=foto&id=' . (int)$ph['id'] . '">'
                . '<img loading="lazy" src="?p=foto&id=' . (int)$ph['id'] . '&t=thumb" alt=""></a>'
                . '<figcaption>' . e(tr_date((string)$ph['created_at'])) . ' · ' . e((string)$ph['username'])
                . ($ph['note'] ? '<br>' . e((string)$ph['note']) : '') . '</figcaption>';
            if (is_admin()) {
                echo '<form method="post" action="?p=foto-sil" class="thumb-del">' . csrf_field()
                    . '<input type="hidden" name="id" value="' . (int)$ph['id'] . '">'
                    . '<button class="btn btn-sm btn-danger" onclick="return confirm(\'Fotoğraf silinsin mi?\')">Sil</button></form>';
            }
            echo '</figure>';
        }
        echo '</div>';
    }
    echo '</div>';

    // müşteri takip linki — yönetici kopyalayıp müşteriye gönderir
    $takipUrl = takip_linki((int)$o['id']);
    if ($takipUrl !== '') {
        echo '<div class="card"><h2>🔗 Müşteri Takip Linki</h2>'
            . '<p class="muted small">Müşteri bu bağlantıdan sipariş durumunu izler — '
            . 'telefon, adres ve fiyat görülmez.</p>'
            . '<div class="kv"><div>Bağlantı</div><div style="word-break:break-all">'
            . '<a href="' . e($takipUrl) . '" target="_blank" rel="noopener">' . e($takipUrl) . '</a></div></div>'
            . '<div class="btnrow mt8 no-print">'
            . '<button type="button" class="btn btn-sm" onclick="navigator.clipboard&&navigator.clipboard.writeText('
            . e(json_encode($takipUrl)) . ');this.textContent=\'Kopyalandı ✓\'">📋 Kopyala</button>'
            . '<a class="btn btn-sm btn-wa" target="_blank" rel="noopener" href="https://wa.me/?text='
            . rawurlencode('Siparişinizi buradan takip edebilirsiniz: ' . $takipUrl) . '">WhatsApp ile gönder</a>'
            . '</div></div>';
    }

    // ödeme
    echo '<div class="card"><h2>Ödeme</h2>';
    if ($bakiye > 0) echo '<div class="alert warn">Kalan bakiye: <b>' . money_tl($bakiye) . '</b></div>';
    elseif ($toplam > 0) echo '<div class="alert ok">Tamamı ödendi ✓</div>';
    foreach ($pays as $py) {
        echo '<div class="row"><span class="row-main">' . e(tr_date((string)$py['created_at'], true)) . ' · '
            . e(payment_method_label((string)$py['method'])) . ((string)$py['note'] !== '' ? ' · ' . e((string)$py['note']) : '')
            . ((string)$py['username'] !== '' ? '<br><small>' . e((string)$py['username']) . '</small>' : '')
            . '</span><span class="row-side">' . money_tl((int)$py['amount_kurus']);
        if (is_admin()) {
            echo '<form method="post" action="?p=odeme-sil" onsubmit="return confirm(\'Ödeme kaydı silinsin mi?\')">'
                . csrf_field() . '<input type="hidden" name="id" value="' . (int)$py['id'] . '">'
                . '<button class="btn btn-sm btn-ghost">✕</button></form>';
        }
        echo '</span></div>';
    }
    if (can('odeme.ekle') && $bakiye > 0) {
        echo '<form method="post" action="?p=odeme" class="form payform mt8">' . csrf_field()
            . '<input type="hidden" name="order_id" value="' . $oid . '">'
            . '<label>Tutar<input name="tutar" inputmode="decimal" value="' . e(money_tl($bakiye, false)) . '" required></label>'
            . '<label>Yöntem<select name="method"><option value="nakit">Nakit</option>'
            . '<option value="kart">Kart</option><option value="havale">Havale / IBAN</option></select></label>'
            . '<label>Not<input name="note" placeholder="kapora / kısmi"></label>'
            . '<button class="btn btn-primary btn-block">Ödeme Kaydet</button></form>';
    } elseif (!can('odeme.ekle')) {
        echo '<p class="muted small">Ödeme kaydı girmek için yetkiniz yok — yöneticiniz '
            . 'tarafından verilebilir.</p>';
    }
    echo '</div>';

    // durum geçmişi (kim, ne zaman, nereden nereye)
    echo '<div class="card"><h2>Durum Geçmişi</h2>';
    foreach ($evs as $ev) {
        $from = (string)$ev['from_status'];
        echo '<div class="row"><span class="row-main">'
            . ($from !== '' && $from !== $ev['status']
                ? '<small>' . e(status_labels()[$from] ?? $from) . ' →</small><br>' : '')
            . status_badge((string)$ev['status'])
            . ((string)$ev['note'] !== '' ? ' <small>' . e((string)$ev['note']) . '</small>' : '')
            . ((string)$ev['reason'] !== '' ? ' <small class="neg">Neden: ' . e((string)$ev['reason']) . '</small>' : '')
            . '</span><span class="row-side"><small>' . e(tr_date((string)$ev['created_at'], true))
            . ((string)$ev['username'] !== '' ? '<br>' . e((string)$ev['username']) : '') . '</small></span></div>';
    }
    echo '</div>';
    layout_foot('');
}

/** Kullanıcıya gösterilecek sıkıştırma metni (tek kaynak: lib.php) */
function photo_client_compress_note(): string
{
    $d = mim_photo_compress_defaults();
    return e($d['maxYon'] . ' px, JPEG kalite ' . (int)round($d['kalite'] * 100) . '% · ~'
        . number_format(12 * $d['kalite'] / 100 * 10, 0) . ' kat daha küçük');
}

/** Fotoğraf yükleme (sipariş kartından) */
function foto_yukle_form(): void
{
    need_perm('foto.yukle');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['foto_yukle'])) return;
    $oid = (int)($_POST['id'] ?? 0);
    csrf_check();
    $st = db()->prepare('SELECT id FROM orders WHERE id=?');
    $st->execute([$oid]);
    if (!$st->fetchColumn()) { http_response_code(404); exit('Sipariş yok'); }

    $stage = (string)($_POST['stage'] ?? 'alim');
    if (!isset(PHOTO_STAGES[$stage])) $stage = 'alim';
    $itemId = (int)($_POST['item_id'] ?? 0);
    $note = trim((string)($_POST['foto_not'] ?? ''));

    $files = $_FILES['foto'] ?? null;
    $ok = 0; $errs = [];
    if ($files && is_array($files['name'])) {
        $n = count($files['name']);
        for ($i = 0; $i < $n; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $res = photo_save($oid, [
                'name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i], 'size' => $files['size'][$i] ?? 0,
            ], $stage, $itemId ?: null, $note);
            if ($res['ok']) $ok++; else $errs[] = (string)$res['error'];
        }
    } else {
        $errs[] = 'Dosya seçilmedi.';
    }
    $_SESSION['foto_hata'] = $errs ? implode(' | ', array_slice($errs, 0, 4)) : null;
    if ($ok && !$errs) $_SESSION['foto_hata'] = null;
    audit('foto_toplu', 'orders', $oid, $ok . ' yüklendi, ' . count($errs) . ' hata');
    redirect('siparis', 'id=' . $oid . '#foto');
}

function foto_sil_eylem(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('');
    csrf_check();
    need_perm('siparis.guncelle');
    if (!is_admin()) { http_response_code(403); exit('Yalnız yönetici silebilir.'); }
    $id = (int)($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT order_id FROM photos WHERE id=?');
    $st->execute([$id]);
    $r = $st->fetch();
    photo_delete($id);
    redirect('siparis', 'id=' . (int)($r['order_id'] ?? 0) . '#foto');
}

// ---------------------------------------------------------------- durum değişimi
function siparis_durum(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('');
    csrf_check();
    need_perm('siparis.durum');
    $id = (int)($_POST['id'] ?? 0);
    $yeni = (string)($_POST['durum'] ?? '');

    $st = db()->prepare('SELECT * FROM orders WHERE id=?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) redirect('siparisler');
    $eski = (string)$o['status'];

    // ------------------------------------------------- YALNIZCA ADET KAYDETME
    // Sipariş kartındaki "Halı Adedi Kontrolü" kartındaki "Adedi Kaydet"
    // butonu `durum` alanı GÖNDERMEZ (bilerek: yalnız adedi yazmalıdır).
    // Önceki sürümde bu form sessizce HİÇBİR ŞEY yapmıyordu: `returned_qty`
    // yalnız $yeni==='teslim' dalında okunduğu için kullanıcı adedi yazıp
    // "Adedi Kaydet" dediğinde değer kaydedilmiyor, sonraki "Teslim Edildi"
    // denemesi de "adedi girmelisiniz" uyarısıyla engelleniyordu. Ölçüldü:
    // teslim adedi hiçbir yolla girilemiyordu → teslim mümkün değildi.
    if ($yeni === '' && array_key_exists('teslim_adedi', $_POST)) {
        $girilen = (int)$_POST['returned_qty'];
        $beklenenAdet = (int)$o['received_count'];
        if ($girilen < 0) $girilen = 0;
        db()->prepare('UPDATE orders SET returned_count=?, updated_at=? WHERE id=?')
            ->execute([$girilen, now_dt(), $id]);
        // kalem bazında da eşitlenir: raporlar kalem adetlerinden de okur
        db()->prepare('UPDATE order_items SET returned_qty=? WHERE order_id=? AND received_qty>0')
            ->execute([$girilen, $id]);
        add_event($id, $eski, 'Teslim edilen adet güncellendi: ' . $girilen
            . ($beklenenAdet > 0 ? ' / beklenen ' . $beklenenAdet : ''), $eski);
        audit('siparis_teslim_adedi', 'orders', $id, 'girilen:' . $girilen . ' beklenen:' . $beklenenAdet);
        $_SESSION['flash'] = 'Teslim edilen adet kaydedildi: ' . $girilen
            . ($beklenenAdet > 0 && $girilen !== $beklenenAdet
                ? ' — siparişteki ' . $beklenenAdet . ' adetle uyuşmuyor' : '');
        redirect('siparis', 'id=' . $id);
    }

    if (!isset(status_labels()[$yeni]) || $yeni === $eski) redirect('siparis', 'id=' . $id);

    $force = trim((string)($_POST['force_reason'] ?? ''));
    $izinli = in_array($yeni, allowed_next($eski), true);

    // teslim öncesi halı adedi kontrolü — adet girilmemişse VEYA beklenenle
    // uyuşmuyorsa teslim ettirilmez (halı kaybı sessizce geçmesin)
    $beklenen = (int)$o['received_count'];
    $teslim = isset($_POST['returned_qty']) ? (int)$_POST['returned_qty'] : (int)$o['returned_count'];
    if ($yeni === 'teslim' && $beklenen > 0) {
        if ($teslim === 0) {
            $_SESSION['hata'] = 'Teslim edilmeden önce halı adedini girmelisiniz.';
            redirect('siparis', 'id=' . $id);
        }
        if ($teslim !== $beklenen) {
            $_SESSION['hata'] = 'Adet uyuşmuyor: ' . $beklenen . ' adet bekleniyordu, '
                . $teslim . ' girildi. Fark varsa sipariş kartından düzeltin.';
            redirect('siparis', 'id=' . $id);
        }
    }

    // kural ihlali → yönetici istisna açabilir ama neden zorunlu
    if (!$izinli && !is_admin()) {
        $_SESSION['hata'] = 'Bu durum sırasına uygun değil.';
        redirect('siparis', 'id=' . $id);
    }
    if (!$izinli && is_admin() && $force === '') {
        // Neden soran forma yönlendir. `hedef` olmadan form hangi duruma
        // geçileceğini bilemez → hedefi de gönderiyoruz.
        redirect('siparis', 'id=' . $id . '&istisna=1&hedef=' . urlencode($yeni));
    }

    // teslim edilen adet kaydı
    if ($yeni === 'teslim' && isset($_POST['returned_qty'])) {
        $u = db()->prepare('UPDATE orders SET returned_count=? WHERE id=?');
        $u->execute([$teslim, $id]);
        $s = db()->prepare('UPDATE order_items SET returned_qty=received_qty WHERE order_id=? AND received_qty>0');
        $s->execute([$id]);
    }

    $note = $force !== '' ? ('İstisna geçiş: ' . $force) : '';
    $up = db()->prepare('UPDATE orders SET status=?, updated_at=? WHERE id=?');
    $up->execute([$yeni, now_dt(), $id]);
    add_event($id, $yeni, $note, $eski);
    audit('siparis_durum', 'orders', $id, $eski . ' → ' . $yeni . ($force !== '' ? ' (istisna: ' . $force . ')' : ''));

    // otomatik bildirim — kaydı asla engellemez
    wa_notify_status($id, $yeni);
    // Teslim tamamlandı: memnuniyet anketi + Google yorum hunisi.
    // Kuyruk/anbar hataları sipariş akışını ASLA engellemez (anket_olustur içinde try).
    if ($yeni === 'teslim') anket_olustur($id);
    if (!empty($_SESSION['hata'])) unset($_SESSION['hata']);
    redirect('siparis', 'id=' . $id);
}

function siparis_tarih(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        // POST değilse formu göster (GET ile ?p=siparis-tarih&id=N)
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) { $_GET['id'] = $id; redirect('siparis-tarih-form', 'id=' . $id); }
        redirect('');
    }
    csrf_check();
    need_perm('siparis.guncelle');
    $id = (int)($_POST['id'] ?? 0);
    $yeni = (string)($_POST['delivery_date'] ?? '');
    $saat = (string)($_POST['delivery_time'] ?? '');
    $gerekce = trim((string)($_POST['gerekce'] ?? ''));

    $st = db()->prepare('SELECT * FROM orders WHERE id=?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) redirect('siparisler');
    $eski = (string)$o['delivery_date'];

    // tarih değişikliği geçmişte korunur (denetim kaydı)
    $u = db()->prepare('UPDATE orders SET delivery_date=?, delivery_time=?, previous_delivery_date=?,
        updated_at=? WHERE id=?');
    $u->execute([$yeni, $saat, $eski, now_dt(), $id]);
    add_event($id, (string)$o['status'], 'Teslim tarihi değiştirildi', (string)$o['status'],
        $gerekce !== '' ? $gerekce : ('eski: ' . ($eski !== '' ? tr_date($eski) : '—') . ' → yeni: ' . tr_date($yeni)));
    audit('teslim_tarihi', 'orders', $id, $eski . ' → ' . $yeni . ($gerekce !== '' ? ' · ' . $gerekce : ''));
    redirect('siparis', 'id=' . $id);
}

/** Takip notu / gecikme nedeni */
function siparis_not(): void
{
    need_perm('siparis.guncelle');
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    // CSRF, sipariş aramasından ÖNCE doğrulanır: aksi hâlde geçersiz bir
    // kimlikle yapılan POST, kontrol edilmeden yönlendirme cevabı alır ve
    // CSRF koruması sessizce atlanmış sayılır.
    if ($isPost) csrf_check();

    // Sipariş kimliği POST'tan okunur; sorgu dizesi yalnız geri çağırma/GET
    // bağlantıları içindir. Form gizli `id` alanını gönderir — önce o okunur,
    // aksi hâlde kullanıcının gönderdiği sipariş sessizce yok sayılırdı.
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM orders WHERE id=?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) redirect('siparisler');

    if ($isPost) {
        $note = trim((string)($_POST['note'] ?? ''));
        $reason = (string)($_POST['delay_reason'] ?? '');
        $assigned = (int)($_POST['assigned_to'] ?? 0);

        // Teslim planı alanları formda GÖRÜNÜYOR ama önceki sürümde işleyici
        // bunları hiç okumuyordu: kullanıcı tarihi değiştirip kaydetse bile
        // eski tarih sessizce kalıyordu (denetimde kanıtlandı).
        // siparis_tarih() ile aynı kural uygulanır: eski tarih
        // previous_delivery_date'e yazılır, olay kaydı gerekçeyle düşer.
        // ⚠ Alan POST'ta YOKSA (örn. gecikmiş kartındaki kısa form) mevcut
        //   tarih korunur — yoksa tarih sessizce boşaltılırdı.
        $eskiTarih = (string)$o['delivery_date'];
        $eskiSaat = (string)($o['delivery_time'] ?? '');
        $yeniTarih = array_key_exists('delivery_date', $_POST) ? (string)$_POST['delivery_date'] : $eskiTarih;
        $yeniSaat = array_key_exists('delivery_time', $_POST) ? (string)$_POST['delivery_time'] : $eskiSaat;
        $gerekce = trim((string)($_POST['gerekce'] ?? ''));
        $tarihDegisti = ($yeniTarih !== $eskiTarih || $yeniSaat !== $eskiSaat);

        $st2 = db()->prepare('UPDATE orders SET note=?, delay_reason=?, assigned_to=?,
            delivery_date=?, delivery_time=?, previous_delivery_date=?, updated_at=? WHERE id=?');
        $st2->execute([$note, $reason, $assigned ?: null, $yeniTarih, $yeniSaat,
            $tarihDegisti ? $eskiTarih : (string)($o['previous_delivery_date'] ?? ''), now_dt(), $id]);
        if ($tarihDegisti) {
            add_event($id, (string)$o['status'], 'Teslim tarihi değiştirildi', (string)$o['status'],
                $gerekce !== '' ? $gerekce : ('eski: ' . ($eskiTarih !== '' ? tr_date($eskiTarih) : '—')
                    . ' → yeni: ' . tr_date($yeniTarih)));
            audit('teslim_tarihi', 'orders', $id, $eskiTarih . ' → ' . $yeniTarih
                . ($gerekce !== '' ? ' · ' . $gerekce : ''));
        }
        if ($note !== '' || $reason !== '') {
            add_event($id, (string)$o['status'], 'Not güncellendi', (string)$o['status'], $reason);
        }
        audit('siparis_not', 'orders', $id, $reason);
        redirect('siparis', 'id=' . $id);
    }

    layout_head('Siparişi Düzenle — ' . (string)$o['code']);
    echo '<form method="post" action="?p=siparis-not" class="form">' . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<div class="card">'
        . '<label>Takip / saha notu<textarea name="note" rows="4">' . e((string)$o['note']) . '</textarea></label>'
        . '<label>Gecikme nedeni<select name="delay_reason">';
    foreach (delay_reasons() as $k => $lbl) {
        echo '<option value="' . e($k) . '"' . ((string)$o['delay_reason'] === (string)$k ? ' selected' : '') . '>' . e($lbl) . '</option>';
    }
    echo '</select></label>';
    $staff = db()->query("SELECT id,full_name,username FROM users WHERE active=1 ORDER BY full_name")->fetchAll();
    echo '<label>Sorumlu personel<select name="assigned_to"><option value="">— atanmadı —</option>';
    foreach ($staff as $s) {
        echo '<option value="' . (int)$s['id'] . '"' . ((int)($o['assigned_to'] ?? 0) === (int)$s['id'] ? ' selected' : '')
            . '>' . e($s['full_name'] ?: $s['username']) . '</option>';
    }
    echo '</select></label></div>'
        . '<div class="card"><h2>Teslim Planı</h2>'
        . '<label>Teslim tarihi<input type="date" name="delivery_date" value="' . e((string)$o['delivery_date']) . '"></label>'
        . '<label>Teslim saati<input type="time" name="delivery_time" value="' . e((string)($o['delivery_time'] ?? '')) . '"></label>'
        . '<label>Değişiklik gerekçesi<input name="gerekce" placeholder="örn. yıkama kapasitesi"></label>'
        . '<p class="muted small">Eski tarih denetim geçmişinde korunur.</p></div>'
        . '<button class="btn btn-primary btn-block">Kaydet</button></form>';
    layout_foot('');
}

function siparis_atama(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('');
    csrf_check();
    need_perm('siparis.guncelle');
    $id = (int)($_POST['id'] ?? 0);
    $uid = (int)($_POST['assigned_to'] ?? 0);
    $st = db()->prepare('UPDATE orders SET assigned_to=?, updated_at=? WHERE id=?');
    $st->execute([$uid ?: null, now_dt(), $id]);
    audit('siparis_atama', 'orders', $id, 'personel #' . $uid);
    redirect('siparis', 'id=' . $id);
}

function payment_method_label(string $m): string
{
    return ['nakit' => 'Nakit', 'kart' => 'Kart', 'havale' => 'Havale / IBAN', 'iban' => 'Havale / IBAN'][$m] ?? $m;
}

// ---------------------------------------------------------------- ödeme
/** Teslim tarihi değiştirme formu (?p=siparis-tarih-form)
 *  Kaydetme ?p=siparis-tarih üzerinden yapılır. */
function siparis_tarih_form(): void
{
    need_perm('siparis.guncelle');
    $o = order_or_404();
    $oid = (int)$o['id'];
    $eski = (string)($o['previous_delivery_date'] ?? '');

    layout_head('Teslim Tarihi Değiştir — ' . (string)$o['code']);
    echo '<form method="post" action="?p=siparis-tarih" class="form">' . csrf_field()
        . '<input type="hidden" name="id" value="' . $oid . '">'
        . '<div class="kv"><div>Mevcut teslim tarihi</div><div><b>'
        . e(tr_date((string)$o['delivery_date'])) . '</b>'
        . ($o['delivery_time'] ? ' ' . e((string)$o['delivery_time']) : '') . '</div></div>'
        . '<label>Yeni teslim tarihi<input type="date" name="delivery_date" required value="'
        . e((string)$o['delivery_date']) . '"></label>'
        . '<label>Yeni teslim saati<input type="time" name="delivery_time" value="'
        . e((string)$o['delivery_time']) . '"></label>'
        . '<label>Değişiklik nedeni (müşteriye bildirilebilir)'
        . '<textarea name="gerekce" rows="2" placeholder="örn. müşteri teslim tarihini 2 gün ileri aldı"></textarea></label>'
        . '<button class="btn btn-primary btn-block">Teslim Tarihini Kaydet</button></form>';

    if ($eski !== '') {
        echo '<div class="card"><h2>Önceki Teslim Tarihi</h2>'
            . '<p class="muted">Bu siparişin teslim tarihi daha önce değiştirilmiş. '
            . 'Bir önceki tarih: <b>' . e(tr_date($eski)) . '</b></p>'
            . '<p class="muted small">Geçmişe dönük düzeltme yapıldıysa eski gecikme hesapları '
            . 'raporlarda tutarsız görünebilir; kasa ve rapor geçmişi korunur.</p></div>';
    }
    echo '<a class="btn btn-ghost btn-block" href="?p=siparis&id=' . $oid . '">← Siparişe dön</a>';
    layout_foot();
}
