/* Mim Halı Yıkama — qrgen.js · bağımlılıksız QR kod üretici
 * ISO/IEC 18004'e göre bayt (byte) modlu QR kodlayıcı, sürüm 1–40,
 * L/M/Q/H hata düzeltme. Harici CDN/paket kullanmaz.
 *
 * API:
 *   window.mimQR(el, text [, opts])  — el: canvas | img | element | DOM id
 *     opts.level: 'L'|'M'|'Q'|'H' (varsayılan 'M'), opts.margin, opts.scale
 *   window.mimQR.matrix(text [, level [, mask]]) — test için modül matrisi
 *   Sayfadaki [data-qr="URL"] öğeleri otomatik doldurulur:
 *     <img data-qr> → PNG data URL, <canvas data-qr> → çizim, diğer → SVG.
 */
(function () {
  'use strict';

  /* ---- Reed-Solomon blok tablosu (ISO/IEC 18004 Tablo 13-22) ----
   * Sürüm başına [L, M, Q, H]; düz liste üçlüleri:
   * [blok sayısı, blok toplam kodonisi, blok veri kodonisi, ...] */
  var RS_BLOCKS = [
    [1,26,19], [1,26,16], [1,26,13], [1,26,9],
    [1,44,34], [1,44,28], [1,44,22], [1,44,16],
    [1,70,55], [1,70,44], [2,35,17], [2,35,13],
    [1,100,80], [2,50,32], [2,50,24], [4,25,9],
    [1,134,108], [2,67,43], [2,33,15,2,34,16], [2,33,11,2,34,12],
    [2,86,68], [4,43,27], [4,43,19], [4,43,15],
    [2,98,78], [4,49,31], [2,32,14,4,33,15], [4,39,13,1,40,14],
    [2,121,97], [2,60,38,2,61,39], [4,40,18,2,41,19], [4,40,14,2,41,15],
    [2,146,116], [3,58,36,2,59,37], [4,36,16,4,37,17], [4,36,12,4,37,13],
    [2,86,68,2,87,69], [4,69,43,1,70,44], [6,43,19,2,44,20], [6,43,15,2,44,16],
    [4,101,81], [1,80,50,4,81,51], [4,50,22,4,51,23], [3,36,12,8,37,13],
    [2,116,92,2,117,93], [6,58,36,2,59,37], [4,46,20,6,47,21], [7,42,14,4,43,15],
    [4,133,107], [8,59,37,1,60,38], [8,44,20,4,45,21], [12,33,11,4,34,12],
    [3,145,115,1,146,116], [4,64,40,5,65,41], [11,36,16,5,37,17], [11,36,12,5,37,13],
    [5,109,87,1,110,88], [5,65,41,5,66,42], [5,54,24,7,55,25], [11,36,12,7,37,13],
    [5,122,98,1,123,99], [7,73,45,3,74,46], [15,43,19,2,44,20], [3,45,15,13,46,16],
    [1,135,107,5,136,108], [10,74,46,1,75,47], [1,50,22,15,51,23], [2,42,14,17,43,15],
    [5,150,120,1,151,121], [9,69,43,4,70,44], [17,50,22,1,51,23], [2,42,14,19,43,15],
    [3,141,113,4,142,114], [3,70,44,11,71,45], [17,47,21,4,48,22], [9,39,13,16,40,14],
    [3,135,107,5,136,108], [3,67,41,13,68,42], [15,54,24,5,55,25], [15,43,15,10,44,16],
    [4,144,116,4,145,117], [17,68,42], [17,50,22,6,51,23], [19,46,16,6,47,17],
    [2,139,111,7,140,112], [17,74,46], [7,54,24,16,55,25], [34,37,13],
    [4,151,121,5,152,122], [4,75,47,14,76,48], [11,54,24,14,55,25], [16,45,15,14,46,16],
    [6,147,117,4,148,118], [6,73,45,14,74,46], [11,54,24,16,55,25], [30,46,16,2,47,17],
    [8,132,106,4,133,107], [8,75,47,13,76,48], [7,54,24,22,55,25], [22,45,15,13,46,16],
    [10,142,114,2,143,115], [19,74,46,4,75,47], [28,50,22,6,51,23], [33,46,16,4,47,17],
    [8,152,122,4,153,123], [22,73,45,3,74,46], [8,53,23,26,54,24], [12,45,15,28,46,16],
    [3,147,117,10,148,118], [3,73,45,23,74,46], [4,54,24,31,55,25], [11,45,15,31,46,16],
    [7,146,116,7,147,117], [21,73,45,7,74,46], [1,53,23,37,54,24], [19,45,15,26,46,16],
    [5,145,115,10,146,116], [19,75,47,10,76,48], [15,54,24,25,55,25], [23,45,15,25,46,16],
    [13,145,115,3,146,116], [2,74,46,29,75,47], [42,54,24,1,55,25], [23,45,15,28,46,16],
    [17,145,115], [10,74,46,23,75,47], [10,54,24,35,55,25], [19,45,15,35,46,16],
    [17,145,115,1,146,116], [14,74,46,21,75,47], [29,54,24,19,55,25], [11,45,15,46,46,16],
    [13,145,115,6,146,116], [14,74,46,23,75,47], [44,54,24,7,55,25], [59,46,16,1,47,17],
    [12,151,121,7,152,122], [12,75,47,26,76,48], [39,54,24,14,55,25], [22,45,15,41,46,16],
    [6,151,121,14,152,122], [6,75,47,34,76,48], [46,54,24,10,55,25], [2,45,15,64,46,16],
    [17,152,122,4,153,123], [29,74,46,14,75,47], [49,54,24,10,55,25], [24,45,15,46,46,16],
    [4,152,122,18,153,123], [13,74,46,32,75,47], [48,54,24,14,55,25], [42,45,15,32,46,16],
    [20,147,117,4,148,118], [40,75,47,7,76,48], [43,54,24,22,55,25], [10,45,15,67,46,16],
    [19,148,118,6,149,119], [18,75,47,31,76,48], [34,54,24,34,55,25], [20,45,15,61,46,16]
  ];

  /* Hizalama deseni merkezleri (sürüm 2–40; sürüm 1 boş) */
  var ALIGN_POS = [
    [6,18],[6,22],[6,26],[6,30],[6,34],
    [6,22,38],[6,24,42],[6,26,46],[6,28,50],[6,30,54],[6,32,58],[6,34,62],
    [6,26,46,66],[6,26,48,70],[6,26,50,74],[6,30,54,78],[6,30,56,82],[6,30,58,86],[6,34,62,90],
    [6,28,50,72,94],[6,26,50,74,98],[6,30,54,78,102],[6,28,54,80,106],[6,32,58,84,110],
    [6,30,58,86,114],[6,34,62,90,118],[6,26,50,74,98,122],[6,30,54,78,102,126],
    [6,26,52,78,104,130],[6,30,56,82,108,134],[6,34,60,86,112,138],[6,30,58,86,114,142],
    [6,34,62,90,118,146],[6,30,54,78,102,126,150],[6,24,50,76,102,128,154],
    [6,28,54,80,106,132,158],[6,32,58,84,110,136,162],[6,26,54,82,110,138,166],
    [6,30,58,86,114,142,170]
  ];

  /* Artık bit sayısı (sürüm 1..40) */
  var REMAINDER_BITS = [
    0,7,7,7,7,7,0,0,0,0,0,0,0,3,3,3,3,3,3,3,4,4,4,4,4,4,4,3,3,3,3,3,3,3,0,0,0,0,0,0
  ];

  var LEVELS = { L: 1, M: 0, Q: 3, H: 2 }; // biçim bilgisindeki 2 bitlik gösterim

  /* ---- GF(256) aritmetiği (ürün polinomu 0x11D) ---- */
  var EXP = new Array(512), LOG = new Array(256);
  (function () {
    var x = 1;
    for (var i = 0; i < 255; i++) { EXP[i] = x; LOG[x] = i; x <<= 1; if (x & 0x100) x ^= 0x11d; }
    for (var i = 255; i < 512; i++) EXP[i] = EXP[i - 255];
  }());
  function gmul(a, b) { return (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]]; }

  /** n. dereceden Reed-Solomon üreteç polinomu katsayıları */
  function rsGenPoly(n) {
    var poly = [1];
    for (var i = 0; i < n; i++) {
      var next = new Array(poly.length + 1);
      for (var j = 0; j < next.length; j++) {
        next[j] = (poly[j - 1] || 0) ^ (j < poly.length ? gmul(poly[j], EXP[i]) : 0);
      }
      poly = next;
    }
    return poly;
  }

  /** veri kodonilerine n adet RS hata düzeltme kodonisi ekler */
  function rsEncode(data, ecLen) {
    var gen = rsGenPoly(ecLen);
    var res = data.slice().concat(new Array(ecLen));
    for (var i = 0; i < data.length; i++) {
      var f = res[i];
      if (f !== 0) {
        for (var j = 0; j < gen.length; j++) res[i + j] ^= gmul(gen[j], f);
      }
    }
    return res.slice(data.length);
  }

  /* ---- bit tamponu ---- */
  function BitBuf() { this.bytes = []; this.len = 0; }
  BitBuf.prototype.put = function (num, nbits) {
    for (var i = nbits - 1; i >= 0; i--) this.bit((num >>> i) & 1);
  };
  BitBuf.prototype.bit = function (b) {
    var byteI = this.len >> 3;
    if (byteI >= this.bytes.length) this.bytes.push(0);
    if (b) this.bytes[byteI] |= 0x80 >>> (this.len & 7);
    this.len++;
  };

  function utf8Bytes(text) {
    if (typeof TextEncoder !== 'undefined') {
      return Array.prototype.slice.call(new TextEncoder().encode(String(text)));
    }
    // yedek: encodeURIComponent üzerinden UTF-8
    var s = unescape(encodeURIComponent(String(text))), out = [];
    for (var i = 0; i < s.length; i++) out.push(s.charCodeAt(i) & 0xff);
    return out;
  }

  /** blok tablosunu çöz: [{data:[], ecLen}] listesi + toplam veri kodonisi */
  function blockSpec(version, level) {
    var flat = RS_BLOCKS[(version - 1) * 4 + ['L', 'M', 'Q', 'H'].indexOf(level)];
    var blocks = [], totalData = 0;
    for (var i = 0; i < flat.length; i += 3) {
      for (var b = 0; b < flat[i]; b++) { blocks.push({ total: flat[i + 1], data: flat[i + 2] }); totalData += flat[i + 2]; }
    }
    return { blocks: blocks, totalData: totalData };
  }

  function charCountBits(version) { return version <= 9 ? 8 : 16; }

  /** bayt modu segmentini kodlayıp bloklara böl, RS ekle, araya diz */
  function buildCodewords(bytes, version, level) {
    var spec = blockSpec(version, level);
    var bb = new BitBuf();
    bb.put(4, 4);                       // mod göstergesi: 0100 (byte)
    bb.put(bytes.length, charCountBits(version));
    for (var i = 0; i < bytes.length; i++) bb.put(bytes[i], 8);
    // sonlandırıcı (en çok 4 bit)
    for (var t = 0; t < 4 && bb.len < spec.totalData * 8; t++) bb.bit(0);
    while (bb.len % 8 !== 0) bb.bit(0); // bayt sınırına tamamla
    // dolgu kodonileri
    var pads = [0xec, 0x11], pi = 0;
    while (bb.bytes.length < spec.totalData) bb.bytes.push(pads[pi++ % 2]);

    // bloklara böl + her bloğa RS ekle
    var dataBlocks = [], ecBlocks = [], off = 0;
    for (var b2 = 0; b2 < spec.blocks.length; b2++) {
      var n = spec.blocks[b2].data;
      var chunk = bb.bytes.slice(off, off + n); off += n;
      dataBlocks.push(chunk);
      ecBlocks.push(rsEncode(chunk, spec.blocks[b2].total - n));
    }
    // araya dizme: önce tüm blokların verileri, sonra tüm EC
    var out = [], maxData = Math.max.apply(null, dataBlocks.map(function (d) { return d.length; }));
    for (var c = 0; c < maxData; c++) {
      for (var d2 = 0; d2 < dataBlocks.length; d2++) if (c < dataBlocks[d2].length) out.push(dataBlocks[d2][c]);
    }
    var maxEc = Math.max.apply(null, ecBlocks.map(function (d) { return d.length; }));
    for (var c2 = 0; c2 < maxEc; c2++) {
      for (var d3 = 0; d3 < ecBlocks.length; d3++) out.push(ecBlocks[d3][c2]);
    }
    return out;
  }

  /* ---- hata düzeltme biçimi + maske için 15 bitlik biçim bilgisi ---- */
  function formatBits(level, mask) {
    var data = (LEVELS[level] << 3) | mask;
    var rem = data << 10;
    var g = 0x537; // BCH(15,5) üreteci
    while (bitLen(rem) >= 11) rem ^= g << (bitLen(rem) - 11);
    return ((data << 10) | rem) ^ 0x5412; // 0x5412 ile maskelenir
  }
  function bitLen(x) { var n = 0; while (x !== 0) { n++; x >>>= 1; } return n; }

  /** sürüm 7+ için 18 bitlik sürüm bilgisi */
  function versionBits(version) {
    var rem = version << 12, g = 0x1f25;
    while (bitLen(rem) >= 7) rem ^= g << (bitLen(rem) - 7);
    return (version << 12) | rem;
  }

  /* ---- maske koşulları ---- */
  var MASKS = [
    function (r, c) { return (r + c) % 2 === 0; },
    function (r) { return r % 2 === 0; },
    function (r, c) { return c % 3 === 0; },
    function (r, c) { return (r + c) % 3 === 0; },
    function (r, c) { return (Math.floor(r / 2) + Math.floor(c / 3)) % 2 === 0; },
    function (r, c) { return ((r * c) % 2) + ((r * c) % 3) === 0; },
    function (r, c) { return (((r * c) % 2) + ((r * c) % 3)) % 2 === 0; },
    function (r, c) { return (((r + c) % 2) + ((r * c) % 3)) % 2 === 0; }
  ];

  /* ---- maske ceza puanı (N1–N4) ---- */
  function penalty(m, size) {
    var score = 0, r, c, k;
    // N1: 5+ aynı renk ardışık modül
    for (r = 0; r < size; r++) {
      var run = 1;
      for (c = 1; c < size; c++) {
        if (m[r][c] === m[r][c - 1]) { run++; if (c === size - 1 && run >= 5) score += 3 + (run - 5); }
        else { if (run >= 5) score += 3 + (run - 5); run = 1; }
      }
    }
    for (c = 0; c < size; c++) {
      var run2 = 1;
      for (r = 1; r < size; r++) {
        if (m[r][c] === m[r - 1][c]) { run2++; if (r === size - 1 && run2 >= 5) score += 3 + (run2 - 5); }
        else { if (run2 >= 5) score += 3 + (run2 - 5); run2 = 1; }
      }
    }
    // N2: 2×2 aynı renk bloklar
    for (r = 0; r < size - 1; r++) for (c = 0; c < size - 1; c++) {
      if (m[r][c] === m[r][c + 1] && m[r][c] === m[r + 1][c] && m[r][c] === m[r + 1][c + 1]) score += 3;
    }
    // N3: finder benzeri 1011101 deseninin yanında 4 açık modül
    var pat = [true, false, true, true, true, false, true];
    function patAt(get, i, j, dr, dc) {
      for (k = 0; k < 7; k++) { if (get(i + dr * k, j + dc * k) !== pat[k]) return false; }
      return true;
    }
    function light(get, i, j) { return i >= 0 && i < size && j >= 0 && j < size && get(i, j) === false; }
    function getM(i, j) { return (i >= 0 && i < size && j >= 0 && j < size) ? m[i][j] : undefined; }
    for (r = 0; r < size; r++) for (c = 0; c < size; c++) {
      if (patAt(getM, r, c, 0, 1) && ((light(getM, r, c - 1) && light(getM, r, c - 2) && light(getM, r, c - 3) && light(getM, r, c - 4)) ||
          (light(getM, r, c + 7) && light(getM, r, c + 8) && light(getM, r, c + 9) && light(getM, r, c + 10)))) score += 40;
      if (patAt(getM, r, c, 1, 0) && ((light(getM, r - 1, c) && light(getM, r - 2, c) && light(getM, r - 3, c) && light(getM, r - 4, c)) ||
          (light(getM, r + 7, c) && light(getM, r + 8, c) && light(getM, r + 9, c) && light(getM, r + 10, c)))) score += 40;
    }
    // N4: koyu modül oranının %50'den sapması
    var dark = 0;
    for (r = 0; r < size; r++) for (c = 0; c < size; c++) if (m[r][c]) dark++;
    var total = size * size;
    var pct = dark * 100 / total;
    score += Math.floor(Math.abs(pct - 50) / 5) * 10;
    return score;
  }

  /** verilen maskeyle işlenmiş matrisi üretir */
  function makeMatrix(version, level, codewords, maskIdx) {
    var size = 17 + 4 * version;
    var m = [], reserved = [], r, c;
    for (r = 0; r < size; r++) { m.push(new Array(size)); reserved.push(new Array(size)); }

    function set(rw, cl, v) { m[rw][cl] = !!v; reserved[rw][cl] = true; }
    function finder(rr, cc) {
      for (var i = -1; i <= 7; i++) for (var j = -1; j <= 7; j++) {
        var rr2 = rr + i, cc2 = cc + j;
        if (rr2 < 0 || rr2 >= size || cc2 < 0 || cc2 >= size) continue;
        var dark = (i >= 0 && i <= 6 && (j === 0 || j === 6)) ||
                   (j >= 0 && j <= 6 && (i === 0 || i === 6)) ||
                   (i >= 2 && i <= 4 && j >= 2 && j <= 4);
        set(rr2, cc2, dark);
      }
    }
    finder(0, 0); finder(0, size - 7); finder(size - 7, 0);

    // zamanlama desenleri
    for (var i2 = 8; i2 < size - 8; i2++) { if (!reserved[6][i2]) set(6, i2, i2 % 2 === 0); if (!reserved[i2][6]) set(i2, 6, i2 % 2 === 0); }

    // hizalama desenleri
    var pos = ALIGN_POS[version - 2] || [];
    for (var a = 0; a < pos.length; a++) for (var b = 0; b < pos.length; b++) {
      var ar = pos[a], ac = pos[b];
      if (reserved[ar][ac]) continue; // finder üstüne binme
      for (var di = -2; di <= 2; di++) for (var dj = -2; dj <= 2; dj++) {
        var dark2 = Math.max(Math.abs(di), Math.abs(dj)) !== 1;
        set(ar + di, ac + dj, dark2);
      }
    }

    // biçim bilgisi için yer ayır (şimdilik 0 yazılır, sonra gerçek değer)
    for (var f = 0; f < 9; f++) { if (f !== 6) set(8, f, false); set(f, 8, false); }
    for (var f2 = size - 8; f2 < size; f2++) { set(f2, 8, false); set(8, f2 - (size - 8) + 8, false); }
    set(size - 8, 8, true); // koyu modül

    // sürüm bilgisi (7+)
    if (version >= 7) {
      var vb = versionBits(version);
      for (var vi = 0; vi < 18; vi++) {
        var vbit = (vb >> vi) & 1;
        set(Math.floor(vi / 3), vi % 3 + size - 11, vbit);
        set(vi % 3 + size - 11, Math.floor(vi / 3), vbit);
      }
    }

    // gerçek biçim bilgisi
    var fb = formatBits(level, maskIdx);
    for (var fi = 0; fi <= 5; fi++) { set(8, fi, (fb >> fi) & 1); set(fi, 8, (fb >> fi) & 1); }
    set(8, 7, (fb >> 6) & 1); set(8, 8, (fb >> 7) & 1); set(7, 8, (fb >> 8) & 1);
    for (var fi2 = 9; fi2 < 15; fi2++) set(14 - fi2, 8, (fb >> fi2) & 1);
    for (var fi3 = 0; fi3 < 8; fi3++) set(size - 1 - fi3, 8, (fb >> fi3) & 1);
    for (var fi4 = 8; fi4 < 15; fi4++) set(8, size - 15 + fi4, (fb >> fi4) & 1);

    // veri yerleşimi: sağ altan yukarı zigzag, 6. sütun atlanır
    var maskFn = MASKS[maskIdx];
    var inc = -1, row = size - 1, bitIndex = 7, byteIndex = 0;
    for (var col = size - 1; col > 0; col -= 2) {
      if (col === 6) col--;
      while (true) {
        for (var cc = 0; cc < 2; cc++) {
          if (!reserved[row][col - cc]) {
            var dark3 = false;
            if (byteIndex < codewords.length) dark3 = ((codewords[byteIndex] >>> bitIndex) & 1) === 1;
            if (maskFn(row, col - cc)) dark3 = !dark3;
            m[row][col - cc] = dark3;
            bitIndex--;
            if (bitIndex < 0) { byteIndex++; bitIndex = 7; }
          }
        }
        row += inc;
        if (row < 0 || row >= size) { row -= inc; inc = -inc; break; }
      }
    }
    return m;
  }

  function pickVersion(byteLen, level) {
    for (var v = 1; v <= 40; v++) {
      if (byteLen + Math.ceil((4 + charCountBits(v)) / 8) <= blockSpec(v, level).totalData) return v;
    }
    return 0;
  }

  /** text için modül matrisi. level: L/M/Q/H, mask: 0-7 veya null (otomatik) */
  function matrix(text, level, mask) {
    level = LEVELS.hasOwnProperty(level) ? level : 'M';
    var bytes = utf8Bytes(text);
    var version = pickVersion(bytes.length, level);
    if (!version) throw new Error('QR: metin çok uzun (' + bytes.length + ' bayt)');
    var codewords = buildCodewords(bytes, version, level);
    if (mask !== null && mask !== undefined && MASKS[mask]) {
      return { version: version, level: level, mask: mask, size: 17 + 4 * version, modules: makeMatrix(version, level, codewords, mask) };
    }
    var best = -1, bestScore = Infinity, bestM = null;
    for (var k = 0; k < 8; k++) {
      var mm = makeMatrix(version, level, codewords, k);
      var sc = penalty(mm, mm.length);
      if (sc < bestScore) { bestScore = sc; best = k; bestM = mm; }
    }
    return { version: version, level: level, mask: best, size: bestM.length, modules: bestM };
  }

  /* ---- çizim ---- */
  function drawCanvas(canvas, qr, opts) {
    opts = opts || {};
    var margin = opts.margin !== undefined ? opts.margin : 2;
    var scale = opts.scale || Math.max(2, Math.floor(240 / (qr.size + margin * 2)));
    var px = (qr.size + margin * 2) * scale;
    canvas.width = px; canvas.height = px;
    var ctx = canvas.getContext('2d');
    if (!ctx) return false;
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, px, px);
    ctx.fillStyle = '#000';
    for (var r = 0; r < qr.size; r++) for (var c = 0; c < qr.size; c++) {
      if (qr.modules[r][c]) ctx.fillRect((c + margin) * scale, (r + margin) * scale, scale, scale);
    }
    return true;
  }
  function toSvg(qr, opts) {
    opts = opts || {};
    var margin = opts.margin !== undefined ? opts.margin : 2;
    var dim = qr.size + margin * 2, path = '';
    for (var r = 0; r < qr.size; r++) for (var c = 0; c < qr.size; c++) {
      if (qr.modules[r][c]) path += 'M' + (c + margin) + ' ' + (r + margin) + 'h1v1h-1z';
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + dim + ' ' + dim +
      '" shape-rendering="crispEdges" width="' + dim * 8 + '" height="' + dim * 8 + '">' +
      '<rect width="100%" height="100%" fill="#fff"/><path d="' + path + '" fill="#000"/></svg>';
  }

  function resolveEl(el) {
    if (typeof el === 'string') {
      return document.getElementById(el) || document.querySelector(el);
    }
    return el;
  }

  /** ana API: el'e text için QR kod çizer */
  window.mimQR = function (el, text, opts) {
    el = resolveEl(el);
    if (!el || !text) return null;
    var qr;
    try { qr = matrix(text, opts && opts.level); }
    catch (err) {
      if (typeof mimToast === 'function') mimToast('QR: ' + err.message);
      if (window.console) console.error(err.message);
      return null;
    }
    if (el.tagName === 'CANVAS') {
      drawCanvas(el, qr, opts);
    } else if (el.tagName === 'IMG') {
      var cv = document.createElement('canvas');
      if (drawCanvas(cv, qr, opts) && cv.toDataURL) el.src = cv.toDataURL('image/png');
      else el.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(toSvg(qr, opts));
    } else {
      el.innerHTML = toSvg(qr, opts);
    }
    return qr;
  };

  /** test/ileri kullanım için */
  window.mimQR.matrix = matrix;
  window.mimQR.toSvg = toSvg;

  /* ---- [data-qr] öğelerini otomatik doldur ---- */
  function autoFill(root) {
    var list = (root || document).querySelectorAll ? (root || document).querySelectorAll('[data-qr]') : [];
    for (var i = 0; i < list.length; i++) {
      var el = list[i], val = el.getAttribute('data-qr');
      if (val) window.mimQR(el, val);
    }
  }
  window.mimQR.autoFill = autoFill;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { autoFill(); });
  } else {
    autoFill();
  }
})();
