<?php
/**
 * Kurage 訪問看護ナビ ― 1ファイルPHP。
 *
 * 訪問看護ステーションの名前で検索してきた人（ケアマネや病院に名前を渡された家族）に、
 * その1軒のことを公開データで答える。ポートもデーモンも要らず、レンタルサーバーに置ける。
 *
 *   /khokan.php/                     入口（名前・住所で探す）
 *   /khokan.php/s/<事業所番号>        事業所ページ（18,436軒）
 *   /khokan.php/n/<名前の芯>          同じ名前のステーション一覧（ひまわり49軒…を切り分ける）
 *   /khokan.php/area/<都道府県コード>  都道府県→市区町村→一覧
 *   /khokan.php/near?q=<住所>         住所から近い順（国土地理院の住所検索）
 *   /khokan.php/seido/                制度の引き表（医療保険/介護保険・要介護度別の限度額）
 *   /khokan.php/sitemap.xml  /khokan.php/llms.txt
 *
 * データは khokan_data/khokan.sqlite（scripts/build_db.py が厚労省の CSV から作る）。
 * 画面は数を数えない。件数・時点は全部 DB の meta から出す。
 * この CSV に無いもの（24時間対応・精神科・従業者数・空き状況）は出さない。
 */
declare(strict_types=1);
mb_internal_encoding('UTF-8');

const SITE     = 'https://kurage.exbridge.jp';
const BASE     = SITE . '/khokan.php';
const NAME     = 'Kurage 訪問看護ナビ';
const DATA_DIR = __DIR__ . '/khokan_data';
const GSI      = 'https://msearch.gsi.go.jp/address-search/AddressSearch';

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function u(string $p): string { return BASE . $p; }

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DATA_DIR . '/khokan.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA query_only=1');
        $pdo->exec('PRAGMA busy_timeout=8000');
    }
    return $pdo;
}
function meta(): array {
    static $m = null;
    if ($m === null) { $m = []; foreach (db()->query('SELECT k,v FROM meta') as $r) { $m[$r['k']] = $r['v']; } }
    return $m;
}
function n(string|int|null $v): string { return number_format((int)$v); }

// ---- 都道府県コード（CSV の先頭2桁）----
const PREFS = ['01'=>'北海道','02'=>'青森県','03'=>'岩手県','04'=>'宮城県','05'=>'秋田県','06'=>'山形県','07'=>'福島県','08'=>'茨城県','09'=>'栃木県','10'=>'群馬県','11'=>'埼玉県','12'=>'千葉県','13'=>'東京都','14'=>'神奈川県','15'=>'新潟県','16'=>'富山県','17'=>'石川県','18'=>'福井県','19'=>'山梨県','20'=>'長野県','21'=>'岐阜県','22'=>'静岡県','23'=>'愛知県','24'=>'三重県','25'=>'滋賀県','26'=>'京都府','27'=>'大阪府','28'=>'兵庫県','29'=>'奈良県','30'=>'和歌山県','31'=>'鳥取県','32'=>'島根県','33'=>'岡山県','34'=>'広島県','35'=>'山口県','36'=>'徳島県','37'=>'香川県','38'=>'愛媛県','39'=>'高知県','40'=>'福岡県','41'=>'佐賀県','42'=>'長崎県','43'=>'熊本県','44'=>'大分県','45'=>'宮崎県','46'=>'鹿児島県','47'=>'沖縄県'];

// ---- 住所→座標（国土地理院）。同じ住所は30日キャッシュして API を叩きすぎない ----
function geocode(string $q): ?array {
    $q = trim(mb_substr($q, 0, 80));
    if ($q === '') { return null; }
    $dir = DATA_DIR . '/geo'; if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $f = $dir . '/' . md5($q) . '.json';
    if (is_file($f) && filemtime($f) > time() - 86400 * 30) { $c = json_decode((string)file_get_contents($f), true); if ($c) { return $c; } }
    $ch = curl_init(GSI . '?q=' . rawurlencode($q));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT => 'khokan/1.0 (kurage.exbridge.jp)']);
    $raw = curl_exec($ch); curl_close($ch);
    $items = $raw ? json_decode($raw, true) : null;
    if (!$items) { return null; }
    // 施設名だと別地方の似た住所が先頭に来ることがあるので、入力を含む候補を優先
    usort($items, function ($a, $b) use ($q) {
        $ta = $a['properties']['title'] ?? ''; $tb = $b['properties']['title'] ?? '';
        return [str_contains($tb, $q), str_starts_with($tb, $q), -mb_strlen($tb)] <=> [str_contains($ta, $q), str_starts_with($ta, $q), -mb_strlen($ta)];
    });
    $it = $items[0];
    $r = ['lat' => (float)$it['geometry']['coordinates'][1], 'lon' => (float)$it['geometry']['coordinates'][0], 'label' => $it['properties']['title'] ?? $q];
    @file_put_contents($f, json_encode($r, JSON_UNESCAPED_UNICODE));
    return $r;
}
function km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $r = 6371.0; $dl = deg2rad($lat2 - $lat1); $dn = deg2rad($lon2 - $lon1);
    $a = sin($dl / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dn / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
/** 座標の周りのステーションを近い順に。まず矩形で絞ってから距離を計算（18,436件を毎回は測らない） */
function nearby(float $lat, float $lon, int $limit, float $box = 0.15, string $exclude = '', array $filter = []): array {
    $sql = 'SELECT * FROM stations WHERE lat BETWEEN ? AND ? AND lon BETWEEN ? AND ?';
    $args = [$lat - $box, $lat + $box, $lon - $box, $lon + $box];
    if ($exclude !== '') { $sql .= ' AND no<>?'; $args[] = $exclude; }
    foreach (['sun', 'sat', 'holiday'] as $k) { if (!empty($filter[$k])) { $sql .= " AND $k=1"; } }
    $st = db()->prepare($sql); $st->execute($args);
    $rows = [];
    foreach ($st as $r) { $r['km'] = km($lat, $lon, (float)$r['lat'], (float)$r['lon']); $rows[] = $r; }
    usort($rows, fn($a, $b) => $a['km'] <=> $b['km']);
    return array_slice($rows, 0, $limit);
}
function days_ja(array $r): string {
    $d = [];
    if ($r['weekday']) { $d[] = '平日'; } if ($r['sat']) { $d[] = '土'; } if ($r['sun']) { $d[] = '日'; } if ($r['holiday']) { $d[] = '祝'; }
    return $d ? implode('・', $d) : '（記載なし）';
}
function tel_link(string $t): string { $d = preg_replace('/[^0-9+]/', '', $t); return $d ? '<a href="tel:' . h($d) . '">' . h($t) . '</a>' : h($t); }


// 地方厚生局「届出受理指定訪問看護事業所名簿」の受理番号（略称表より）。ここに無い番号は画面に出さない。
const NOTICE_LABELS = [
    '訪看23' => '24時間対応体制加算（イ）', '訪看24' => '24時間対応体制加算（ロ）',
    '訪看10' => '精神科訪問看護基本療養費（精神科訪問看護ができる）', '訪看25' => '特別管理加算（医療処置の多い方の受け入れ）',
    '訪看27' => '精神科複数回訪問加算', '訪看28' => '精神科重症患者支援管理連携加算',
    '訪看26' => '専門の研修を受けた看護師の配置', '訪看32' => '機能強化型訪問看護管理療養費1', '訪看33' => '機能強化型訪問看護管理療養費2',
    '訪看34' => '機能強化型訪問看護管理療養費3', '訪看35' => '訪問看護医療DX情報活用加算', '訪看36' => '専門管理加算', '訪看37' => '遠隔死亡診断補助加算',
];
function notice_for(string $no): ?array {
    try { $st = db()->prepare('SELECT * FROM notices WHERE no=?'); $st->execute([$no]); $r = $st->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
    catch (Throwable $e) { return null; }   // notices テーブルが無い版の DB でも動く
}
// ---- 画面の骨格 ----
function page(string $title, string $desc, string $url, string $body, array $ld = [], string $h1 = ''): void {
    $m = meta();
    $ld[] = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => NAME, 'url' => BASE . '/',
             'publisher' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/'],
             'potentialAction' => ['@type' => 'SearchAction', 'target' => BASE . '/near?q={q}', 'query-input' => 'required name=q']];
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title><meta name="description" content="' . h($desc) . '">';
    echo '<link rel="canonical" href="' . h($url) . '">';
    echo '<meta property="og:type" content="website"><meta property="og:site_name" content="' . h(NAME) . '"><meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '"><meta property="og:url" content="' . h($url) . '"><meta property="og:image" content="' . SITE . '/images/ogp/khokan.png"><meta name="twitter:card" content="summary_large_image">';
    echo '<meta name="color-scheme" content="light">';
    foreach ($ld as $j) { echo '<script type="application/ld+json">' . json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>'; }
    echo '<style>
:root{--teal:#0a9a8f;--navy:#12202f;--ink:#1f2d36;--sub:#5b6b70;--line:#d9e2e6;--bg:#f6f9fa}
*{box-sizing:border-box}body{margin:0;background:#fff;color:var(--ink);font:15px/1.7 -apple-system,"Hiragino Sans","Noto Sans JP",sans-serif}
a{color:var(--teal);overflow-wrap:anywhere;word-break:break-all}.wrap{max-width:960px;margin:0 auto;padding:0 16px;overflow-wrap:anywhere}
.tag{white-space:normal}
header.top{border-bottom:1px solid var(--line);background:#fff}header.top .wrap{display:flex;align-items:center;justify-content:space-between;min-height:56px;gap:12px;flex-wrap:wrap}
header.top a.brand{color:var(--navy);text-decoration:none;font-weight:800;font-size:17px}header.top nav a{margin-left:14px;font-size:13px;color:var(--sub);text-decoration:none}
h1{font-size:24px;line-height:1.35;margin:22px 0 8px;color:var(--navy)}h2{font-size:18px;margin:28px 0 10px;color:var(--navy);border-left:4px solid var(--teal);padding-left:10px}
.lead{color:var(--sub);margin:0 0 14px}.panel{background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:16px;margin:12px 0}
form.s{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px}form.s input{min-width:0;font-size:16px;padding:10px 12px;border:1px solid var(--line);border-radius:8px}
.btn{display:inline-block;background:var(--teal);color:#fff;border:0;border-radius:8px;padding:10px 16px;font-weight:700;text-decoration:none;cursor:pointer;white-space:normal;max-width:100%}
.btn.sub{background:#fff;color:var(--teal);border:1px solid var(--teal)}
.tbl{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:14px}th,td{border:1px solid var(--line);padding:8px 10px;text-align:left;vertical-align:top}th{background:var(--bg);white-space:nowrap}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}.grid a{display:block;background:#fff;border:1px solid var(--line);border-radius:8px;padding:8px 10px;text-decoration:none;color:var(--ink);min-width:0}
.grid a b{color:var(--navy)}.grid a small{color:var(--sub)}
.st{border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin:8px 0;background:#fff}.st b{font-size:16px}.st .m{color:var(--sub);font-size:13px}
.kv th{width:11em}.note{font-size:13px;color:var(--sub)}.tag{display:inline-block;font-size:12px;border:1px solid var(--line);border-radius:999px;padding:1px 8px;margin-right:4px;background:#fff}
.warn{background:#fff7e6;border:1px solid #f0d9a8;border-radius:10px;padding:12px 14px;margin:12px 0}
footer{margin-top:40px;border-top:1px solid var(--line);padding:20px 0;font-size:13px;color:var(--sub)}footer p{margin:6px 0}
@media(max-width:640px){h1{font-size:20px}form.s{grid-template-columns:1fr}.kv th{width:auto;display:block;border-bottom:0}.kv td{display:block}}
</style></head><body>';
    echo '<header class="top"><div class="wrap"><a class="brand" href="' . u('/') . '">' . h(NAME) . '</a><nav><a href="' . u('/near') . '">住所から探す</a><a href="' . u('/area/') . '">都道府県から</a><a href="' . u('/seido/') . '">制度の引き表</a></nav></div></header>';
    echo '<main class="wrap">' . ($h1 !== '' ? '<h1>' . $h1 . '</h1>' : '') . $body . '</main>';
    echo '<footer><div class="wrap">';
    echo '<p>出典：' . h($m['source_name'] ?? '') . '（<a href="' . h($m['source_url'] ?? '') . '" rel="noopener">' . h($m['source_url'] ?? '') . '</a>）を加工して作成。データ時点 ' . h($m['data_vintage'] ?? '') . '（' . n($m['count'] ?? 0) . '事業所）。住所の座標変換は国土地理院 地名検索API。</p>';
    echo '<p>掲載内容は公開データの転記です。<b>空き状況・受け入れの可否・料金は載っていません。</b>必ず事業所へ電話でご確認ください。このページは案内であり、医療・介護の助言ではありません。</p>';
    echo '<p><a href="https://exbridge.jp/politech/?ref=kurage-khokan" rel="noopener">住民の困りごとから探す</a> ・ <a href="https://exbridge.jp/solution/kaigo.html?ref=kurage-khokan" rel="noopener">介護事業所のITコスト</a> ・ <a href="' . SITE . '/kseido.php/?ref=khokan" rel="noopener">Kurage 制度ナビ</a> ・ <a href="' . SITE . '/krefuge.php/?ref=khokan" rel="noopener">避難所マップ</a> ・ <a href="https://exbridge.jp/" rel="noopener">株式会社エクスブリッジ</a></p>';
    echo '<p><a href="https://kappstore.exbridge.jp/app.php?id=2bdf59a8795a50e8&amp;ref=khokan" rel="noopener">このサイトの一式をオンプレミスで導入する（商品ページ）</a></p>';
    echo '</div></footer>';
    echo '<img src="' . SITE . '/simpletrack.php?t=img&url=' . rawurlencode($url) . '&ref=' . rawurlencode($_GET['ref'] ?? '') . '" width="1" height="1" alt="" aria-hidden="true" style="position:absolute;left:-9999px">';
    echo '</body></html>';
}
function station_card(array $r, bool $withKm = false): string {
    $s = '<div class="st"><b><a href="' . u('/s/' . rawurlencode($r['no'])) . '">' . h($r['name']) . '</a></b>';
    if ($withKm && isset($r['km'])) { $s .= ' <span class="tag">約' . number_format($r['km'], 1) . 'km</span>'; }
    $s .= '<div class="m">' . h($r['pref'] . $r['city']) . '　' . h($r['address']) . ($r['address2'] !== '' ? ' ' . h($r['address2']) : '') . '</div>';
    $s .= '<div class="m">' . tel_link($r['tel']) . '　利用可能曜日: ' . h(days_ja($r)) . '</div></div>';
    return $s;
}
function not_found(string $what): void { http_response_code(404); page('見つかりません | ' . NAME, '', BASE . '/', '<p>' . h($what) . 'は見つかりませんでした。</p><p><a class="btn" href="' . u('/') . '">入口へ戻る</a></p>', [], '見つかりません'); exit; }

// ---- ルーティング（PATH_INFO）----
$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '' && !str_ends_with($_SERVER['REQUEST_URI'] ?? '', '/') && !str_contains($_SERVER['REQUEST_URI'] ?? '', '?')) {
    header('Location: ' . BASE . '/', true, 302); exit;   // 301は使わない
}
$seg = array_values(array_filter(explode('/', $path), fn($x) => $x !== ''));
$m = meta();

// ---- sitemap / llms ----
if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $lm = $m['data_vintage'] ?? date('Y-m-d');
    foreach (array_keys(PREFS) as $pc) { echo '<sitemap><loc>' . h(u('/sitemap-' . $pc . '.xml')) . '</loc><lastmod>' . $lm . '</lastmod></sitemap>'; }
    echo '<sitemap><loc>' . h(u('/sitemap-names.xml')) . '</loc><lastmod>' . $lm . '</lastmod></sitemap></sitemapindex>'; exit;
}
if (preg_match('#^/sitemap-(\d{2}|names)\.xml$#', $path, $mm)) {
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $lm = $m['data_vintage'] ?? date('Y-m-d');
    if ($mm[1] === 'names') {
        echo '<url><loc>' . h(u('/')) . '</loc><lastmod>' . $lm . '</lastmod></url><url><loc>' . h(u('/area/')) . '</loc><lastmod>' . $lm . '</lastmod></url><url><loc>' . h(u('/seido/')) . '</loc><lastmod>' . $lm . '</lastmod></url>';
        foreach (db()->query("SELECT name_core FROM stations WHERE name_core<>'' GROUP BY name_core HAVING COUNT(*)>=2") as $r) { echo '<url><loc>' . h(u('/n/' . rawurlencode($r['name_core']))) . '</loc><lastmod>' . $lm . '</lastmod></url>'; }
    } else {
        echo '<url><loc>' . h(u('/area/' . $mm[1] . '/')) . '</loc></url>';
        $st = db()->prepare('SELECT no FROM stations WHERE pref_code=?'); $st->execute([$mm[1]]);
        foreach ($st as $r) { echo '<url><loc>' . h(u('/s/' . rawurlencode($r['no']))) . '</loc><lastmod>' . $lm . '</lastmod></url>'; }
    }
    echo '</urlset>'; exit;
}
if ($path === '/llms.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "# " . NAME . "\n\n訪問看護ステーション" . n($m['count'] ?? 0) . "軒（全国）を、事業所番号ごとのページで案内する。名前で検索してきた人が、同じ名前の別のステーションと取り違えないように、住所・電話・利用可能曜日・法人名を並べる。住所から近い順の検索と、医療保険/介護保険・要介護度別の限度額の引き表つき。\n\n- 入口: " . BASE . "/\n- 事業所ページ: " . BASE . "/s/<事業所番号>\n- 同名一覧: " . BASE . "/n/<名前>\n- 住所から: " . BASE . "/near?q=<住所>\n- 制度: " . BASE . "/seido/\n\n出典: " . ($m['source_name'] ?? '') . " " . ($m['source_url'] ?? '') . "（データ時点 " . ($m['data_vintage'] ?? '') . "）。空き状況・料金は含まない。\n運営: 株式会社エクスブリッジ https://exbridge.jp/\n"; exit;
}

// ---- 事業所ページ ----
if (($seg[0] ?? '') === 's' && isset($seg[1])) {
    $st = db()->prepare('SELECT * FROM stations WHERE no=?'); $st->execute([$seg[1]]); $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { not_found('事業所番号 ' . $seg[1]); }
    $same = [];
    // 同名は同じ都道府県を先に。全部並べると「ひなた」で36軒・縦8,600pxになるので、画面には8軒まで。残りは /n/ へ
    if ($r['name_core'] !== '') { $q = db()->prepare('SELECT * FROM stations WHERE name_core=? AND no<>? ORDER BY (pref_code=?) DESC, pref_code, city'); $q->execute([$r['name_core'], $r['no'], $r['pref_code']]); $same = $q->fetchAll(PDO::FETCH_ASSOC); }
    $near = ($r['lat'] !== null) ? nearby((float)$r['lat'], (float)$r['lon'], 8, 0.05, $r['no']) : [];
    $title = $r['name'] . '（' . $r['pref'] . $r['city'] . '）の訪問看護ステーション｜住所・電話・利用可能曜日';
    $desc = $r['name'] . 'は' . $r['pref'] . $r['city'] . 'の訪問看護ステーション。' . $r['address'] . '、電話 ' . $r['tel'] . '、利用可能曜日 ' . days_ja($r) . '。'
          . ($same ? '同じ名前のステーションが他に' . count($same) . '軒あります。' : '') . '運営 ' . $r['corp'] . '。';
    $body = '<p class="lead">' . h($r['pref'] . $r['city']) . 'の訪問看護ステーション。' . ($same ? '<b>同じ名前のステーションが他に' . count($same) . '軒</b>あります。住所と電話で、ケアマネジャーや病院から聞いた1軒かどうかを確かめてください。' : '') . '</p>';
    $body .= '<div class="panel tbl"><table class="kv">'
        . '<tr><th>事業所名</th><td>' . h($r['name']) . ($r['name_kana'] ? '<br><span class="note">' . h($r['name_kana']) . '</span>' : '') . '</td></tr>'
        . '<tr><th>住所</th><td>' . h($r['address']) . ($r['address2'] !== '' ? '<br>' . h($r['address2']) : '')
        . ($r['lat'] !== null ? '<br><a href="https://maps.gsi.go.jp/#16/' . h((string)$r['lat']) . '/' . h((string)$r['lon']) . '/" rel="noopener" target="_blank">地理院地図で見る</a> ・ <a href="https://www.google.com/maps/search/?api=1&query=' . h((string)$r['lat']) . ',' . h((string)$r['lon']) . '" rel="noopener" target="_blank">Googleマップ</a>' : '') . '</td></tr>'
        . '<tr><th>電話</th><td>' . tel_link($r['tel']) . ($r['fax'] !== '' ? '　FAX ' . h($r['fax']) : '') . '</td></tr>'
        . '<tr><th>利用可能曜日</th><td>' . h(days_ja($r)) . ($r['days_note'] !== '' ? '<br><span class="note">' . h($r['days_note']) . '</span>' : '') . '</td></tr>'
        . '<tr><th>運営法人</th><td>' . h($r['corp']) . ($r['corp_no'] !== '' ? '<br><span class="note">法人番号 ' . h($r['corp_no']) . '</span>' : '') . '</td></tr>'
        . '<tr><th>事業所番号</th><td>' . h($r['no']) . '</td></tr>'
        . ($r['url'] !== '' ? '<tr><th>公式サイト</th><td><a href="' . h($r['url']) . '" rel="nofollow noopener" target="_blank">' . h($r['url']) . '</a></td></tr>' : '')
        . ($r['note'] !== '' ? '<tr><th>備考</th><td>' . h($r['note']) . '</td></tr>' : '')
        . '</table></div>';
    $nt = notice_for($r['no']);
    if ($nt) {
        $codes = array_filter(explode(',', $nt['codes']));
        $body .= '<h2>厚生局への届出（' . h($nt['asof']) . ' 現在）</h2><div class="panel">'
            . '<p>医療保険で訪問看護を行うときの届出です。<b>届出があれば、その体制を取っている</b>ことを意味します（届出が無い項目は「取っていない」ではなく「この名簿に無い」です）。</p>';
        $has24 = in_array('訪看23', $codes, true) || in_array('訪看24', $codes, true);
        $body .= '<p>' . ($has24 ? '<span class="tag" style="border-color:var(--teal);color:var(--teal)">24時間対応体制の届出あり</span>' : '<span class="tag">24時間対応体制の届出はこの名簿に無い</span>')
            . (in_array('訪看10', $codes, true) ? ' <span class="tag" style="border-color:var(--teal);color:var(--teal)">精神科訪問看護の届出あり</span>' : '')
            . (in_array('訪看25', $codes, true) ? ' <span class="tag" style="border-color:var(--teal);color:var(--teal)">特別管理加算の届出あり</span>' : '') . '</p>';
        $rows_ = [];
        foreach ($codes as $c) { if (isset(NOTICE_LABELS[$c])) { $rows_[] = '<tr><td>' . h($c) . '</td><td>' . h(NOTICE_LABELS[$c]) . '</td></tr>'; } }
        if ($rows_) { $body .= '<div class="tbl"><table><tr><th>受理番号</th><th>内容</th></tr>' . implode('', $rows_) . '</table></div>'; }
        $body .= '<p class="note">出典：' . h($nt['bureau']) . 'ホームページ「届出受理指定訪問看護事業所名簿」（<a href="' . h($nt['bureau_url']) . '" rel="noopener">' . h($nt['bureau_url']) . '</a>）を加工して作成。電話番号で厚生労働省の事業所データと突き合わせています。ステーションコード ' . h($nt['station_code']) . '。</p></div>';
    }
    $body .= '<div class="warn"><b>空き状況・受け入れ可否・料金は、公開データに含まれていません。</b>利用を考えている方は、この電話番号へ直接お問い合わせください。' . ($nt ? '' : '24時間対応や精神科訪問看護の有無も、事業所に確認してください。') . '</div>';
    if ($same) {
        $body .= '<h2>同じ名前の別のステーション（' . count($same) . '軒）</h2><p class="note">名前が同じでも運営法人も場所も別です。取り違えの原因になるので並べています。</p>';
        foreach (array_slice($same, 0, 8) as $s) { $body .= station_card($s); }
        if (count($same) > 8) { $body .= '<p class="note">ほか' . (count($same) - 8) . '軒。</p>'; }
        $body .= '<p><a class="btn sub" href="' . u('/n/' . rawurlencode($r['name_core'])) . '">「' . h($r['name_core']) . '」の全' . (count($same) + 1) . '軒を都道府県別に見る</a></p>';
    }
    if ($near) { $body .= '<h2>近くの訪問看護ステーション</h2>'; foreach ($near as $s) { $body .= station_card($s, true); } }
    $body .= '<h2>訪問看護を使うときの制度</h2><div class="panel"><p>医療保険で使うか介護保険で使うか、要介護度ごとに月にどれだけ使えるか（区分支給限度基準額）は<a href="' . u('/seido/') . '">制度の引き表</a>にまとめています。</p></div>';
    $body .= '<p><a class="btn" href="' . u('/near?q=' . rawurlencode($r['address'])) . '">この住所の近くを探す</a> <a class="btn sub" href="' . u('/area/' . $r['pref_code'] . '/' . rawurlencode($r['city'])) . '">' . h($r['city']) . 'の一覧</a></p>';
    $ld = [['@context' => 'https://schema.org', '@type' => 'MedicalBusiness', 'name' => $r['name'], 'telephone' => $r['tel'],
            'address' => ['@type' => 'PostalAddress', 'addressRegion' => $r['pref'], 'addressLocality' => $r['city'], 'streetAddress' => $r['address'] . ' ' . $r['address2']],
            'url' => BASE . '/s/' . $r['no']] + ($r['lat'] !== null ? ['geo' => ['@type' => 'GeoCoordinates', 'latitude' => (float)$r['lat'], 'longitude' => (float)$r['lon']]] : [])
            + ($r['url'] !== '' ? ['sameAs' => $r['url']] : []),
           ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
              ['@type' => 'ListItem', 'position' => 1, 'name' => NAME, 'item' => BASE . '/'],
              ['@type' => 'ListItem', 'position' => 2, 'name' => $r['pref'], 'item' => BASE . '/area/' . $r['pref_code'] . '/'],
              ['@type' => 'ListItem', 'position' => 3, 'name' => $r['name'], 'item' => BASE . '/s/' . $r['no']]]]];
    page($title . ' | ' . NAME, $desc, BASE . '/s/' . $r['no'], $body, $ld, h($r['name']));
    exit;
}

// ---- 同名一覧 ----
if (($seg[0] ?? '') === 'n' && isset($seg[1])) {
    $core = rawurldecode($seg[1]);
    $st = db()->prepare('SELECT * FROM stations WHERE name_core=? ORDER BY pref_code, city, name'); $st->execute([$core]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { not_found('「' . $core . '」という名前のステーション'); }
    $title = '「' . $core . '」という訪問看護ステーションは全国に' . count($rows) . '軒｜住所で見分ける';
    $body = '<p class="lead">名前が同じでも、運営法人も場所も別々です。ケアマネジャーや病院から聞いた1軒を、市区町村と住所で確かめてください。</p>';
    $byPref = []; foreach ($rows as $r) { $byPref[$r['pref']][] = $r; }
    foreach ($byPref as $p => $rs) { $body .= '<h2>' . h($p) . '（' . count($rs) . '軒）</h2>'; foreach ($rs as $r) { $body .= station_card($r); } }
    page($title . ' | ' . NAME, '「' . $core . '」を名前に含む訪問看護ステーションは全国に' . count($rows) . '軒。都道府県・市区町村・住所・電話で見分けられます。', BASE . '/n/' . rawurlencode($core), $body, [], '「' . h($core) . '」という名前の訪問看護ステーション（' . count($rows) . '軒）');
    exit;
}

// ---- 都道府県 / 市区町村 ----
if (($seg[0] ?? '') === 'area') {
    if (!isset($seg[1])) {
        $cnt = []; foreach (db()->query('SELECT pref_code, COUNT(*) c FROM stations GROUP BY pref_code') as $r) { $cnt[$r['pref_code']] = (int)$r['c']; }
        $body = '<p class="lead">都道府県ごとの訪問看護ステーション数（データ時点 ' . h($m['data_vintage'] ?? '') . '）。</p><div class="grid">';
        foreach (PREFS as $pc => $pn) { $body .= '<a href="' . u('/area/' . $pc . '/') . '"><b>' . h($pn) . '</b><br><small>' . n($cnt[$pc] ?? 0) . '軒</small></a>'; }
        $body .= '</div>';
        page('都道府県別 訪問看護ステーション一覧（全国' . n($m['count'] ?? 0) . '軒） | ' . NAME, '都道府県ごとの訪問看護ステーションの数と一覧。', BASE . '/area/', $body, [], '都道府県から探す');
        exit;
    }
    $pc = $seg[1]; if (!isset(PREFS[$pc])) { not_found('都道府県コード ' . $pc); }
    $pn = PREFS[$pc];
    if (!isset($seg[2])) {
        $st = db()->prepare('SELECT city, COUNT(*) c FROM stations WHERE pref_code=? GROUP BY city ORDER BY city_code'); $st->execute([$pc]);
        $body = '<p class="lead">市区町村ごとの数。</p><div class="grid">';
        foreach ($st as $r) { $body .= '<a href="' . u('/area/' . $pc . '/' . rawurlencode($r['city'])) . '"><b>' . h($r['city']) . '</b><br><small>' . n($r['c']) . '軒</small></a>'; }
        $body .= '</div>';
        $tot = db()->prepare('SELECT COUNT(*) FROM stations WHERE pref_code=?'); $tot->execute([$pc]);
        page($pn . 'の訪問看護ステーション一覧（' . n($tot->fetchColumn()) . '軒・市区町村別） | ' . NAME, $pn . 'の訪問看護ステーションを市区町村別に。住所・電話・利用可能曜日つき。', BASE . '/area/' . $pc . '/', $body, [], h($pn) . 'の訪問看護ステーション');
        exit;
    }
    $city = rawurldecode($seg[2]);
    $st = db()->prepare('SELECT * FROM stations WHERE pref_code=? AND city=? ORDER BY name_kana, name'); $st->execute([$pc, $city]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { not_found($pn . $city); }
    $sun = count(array_filter($rows, fn($r) => $r['sun']));
    $body = '<p class="lead">' . count($rows) . '軒。うち日曜も利用可能なのは' . $sun . '軒。<a href="' . u('/near?q=' . rawurlencode($pn . $city)) . '">住所を入れて近い順に並べる</a>こともできます。</p>';
    foreach ($rows as $r) { $body .= station_card($r); }
    page($pn . $city . 'の訪問看護ステーション' . count($rows) . '軒｜住所・電話・利用可能曜日 | ' . NAME, $pn . $city . 'の訪問看護ステーション' . count($rows) . '軒の住所・電話・利用可能曜日。日曜対応は' . $sun . '軒。', BASE . '/area/' . $pc . '/' . rawurlencode($city), $body, [], h($pn . $city) . 'の訪問看護ステーション（' . count($rows) . '軒）');
    exit;
}

// ---- 住所から近い順 ----
if (($seg[0] ?? '') === 'near') {
    $q = trim((string)($_GET['q'] ?? ''));
    $filter = ['sun' => !empty($_GET['sun']), 'sat' => !empty($_GET['sat'])];
    $form = '<form class="s" method="get" action="' . u('/near') . '"><input type="text" name="q" value="' . h($q) . '" placeholder="例: 名古屋市千種区今池5丁目" required><button class="btn" type="submit">近い順に並べる</button></form>'
          . '<p class="note"><label><input type="checkbox" name="sun" form="f0"' . ($filter['sun'] ? ' checked' : '') . '> 日曜も利用可</label>　<label><input type="checkbox" name="sat"' . ($filter['sat'] ? ' checked' : '') . '> 土曜も利用可</label>（チェックしてから再度「近い順」を押してください）</p>';
    // チェックボックスを同じフォームに入れる（上のformにidを付けて紐づけ）
    $form = str_replace('<form class="s"', '<form id="f0" class="s"', $form);
    $form = str_replace('name="sat"', 'name="sat" form="f0"', $form);
    $body = '<div class="panel">' . $form . '</div>';
    if ($q !== '') {
        $g = geocode($q);
        if (!$g) { $body .= '<p>「' . h($q) . '」の場所が特定できませんでした。市区町村名から書いてみてください。</p>'; }
        else {
            $rows = nearby($g['lat'], $g['lon'], 30, 0.15, '', $filter);
            $body .= '<p class="lead">「' . h($g['label']) . '」の近くから' . count($rows) . '軒。直線距離で並べています（道のりではありません）。</p>';
            if (!$rows) { $body .= '<p>この条件では周囲約15kmに該当がありませんでした。</p>'; }
            foreach ($rows as $r) { $body .= station_card($r, true); }
        }
    } else {
        $body .= '<p class="lead">住所を入れると、周囲の訪問看護ステーションを近い順に並べます。市区町村名だけでも探せます。</p>';
    }
    page(($q !== '' ? '「' . $q . '」の近くの訪問看護ステーション | ' : '住所から近い訪問看護ステーションを探す | ') . NAME, '住所を入れると、周囲の訪問看護ステーションを近い順に。日曜・土曜対応で絞り込めます。', BASE . '/near', $body, [], '住所から近い訪問看護ステーションを探す');
    exit;
}

// ---- 制度の引き表 ----
if (($seg[0] ?? '') === 'seido') {
    $body = '<p class="lead">訪問看護は、同じサービスでも「医療保険で使う」か「介護保険で使う」かで、回数や自己負担の決まり方が変わります。ここでは国の決まりだけを引けるようにしています。個別の判断は、ケアマネジャーか主治医、事業所にご相談ください。</p>';
    $body .= '<h2>どちらの保険で使うか</h2><div class="panel tbl"><table>'
        . '<tr><th>状況</th><th>使う保険</th><th>備考</th></tr>'
        . '<tr><td>65歳以上（または40〜64歳で特定疾病）で<b>要介護・要支援の認定がある</b></td><td><b>介護保険</b>が優先</td><td>ケアプランに組み込む。月の上限は下の「区分支給限度基準額」</td></tr>'
        . '<tr><td>要介護認定が<b>ない</b>（年齢を問わず）</td><td><b>医療保険</b></td><td>主治医の訪問看護指示書が要る。原則 週3日まで</td></tr>'
        . '<tr><td>認定があっても、<b>厚生労働大臣が定める疾病等</b>（末期がん、ALS、パーキンソン病関連疾患の一部 など）</td><td><b>医療保険</b></td><td>週3日の制限が外れる。介護保険の限度額とは別枠</td></tr>'
        . '<tr><td>急に状態が悪くなり、主治医が<b>特別訪問看護指示書</b>を出した</td><td><b>医療保険</b>（14日間）</td><td>その期間は毎日訪問できる</td></tr>'
        . '<tr><td>精神科訪問看護</td><td><b>医療保険</b></td><td>精神科の主治医の指示書。認知症は原則介護保険側</td></tr>'
        . '</table></div><p class="note">出典: 厚生労働省「訪問看護について」ほか公表資料。要件の細部は改定で変わるため、最新は<a href="https://www.mhlw.go.jp/" rel="noopener">厚生労働省</a>と主治医・事業所で確認してください。</p>';
    $body .= '<h2>介護保険で使うとき、月にどれだけ使えるか（区分支給限度基準額）</h2>'
        . '<p>要介護度ごとに、1か月に介護保険で使える上限が「単位」で決まっています。訪問看護だけでなく、デイサービスや訪問介護もこの枠の中で組みます。</p>'
        . '<div class="panel tbl"><table><tr><th>要介護度</th><th>月の上限（単位）</th><th>1単位10円なら</th></tr>'
        . '<tr><td>要支援1</td><td>5,032</td><td>50,320円</td></tr><tr><td>要支援2</td><td>10,531</td><td>105,310円</td></tr>'
        . '<tr><td>要介護1</td><td>16,765</td><td>167,650円</td></tr><tr><td>要介護2</td><td>19,705</td><td>197,050円</td></tr>'
        . '<tr><td>要介護3</td><td>27,048</td><td>270,480円</td></tr><tr><td>要介護4</td><td>30,938</td><td>309,380円</td></tr><tr><td>要介護5</td><td>36,217</td><td>362,170円</td></tr></table></div>'
        . '<p class="note">1単位の円換算は地域区分（1級地〜7級地・その他）で10円〜11.40円まで違います。上限の範囲内なら自己負担は1〜3割（所得による）。上限を超えた分は全額自己負担。出典: 厚生労働省 区分支給限度基準額（令和元年10月改定・現行）。</p>';
    $body .= '<h2>訪問看護1回の目安（介護保険・単位）</h2><p class="note">訪問看護ステーションからの訪問（要介護の場合）。20分未満・30分未満・30〜60分・60〜90分で単位が分かれ、地域や加算で変わります。具体の額は事業所が出す「重要事項説明書」で確認してください。ここでは断定的な金額を載せません。</p>';
    $body .= '<h2>よくある疑問</h2><div class="panel">'
        . '<p><b>要介護認定を受けていなくても訪問看護は使える？</b><br>使えます。医療保険で、主治医の指示書があれば年齢を問わず利用できます。</p>'
        . '<p><b>要支援1・2でも訪問看護は使える？</b><br>使えます（介護予防訪問看護）。ケアマネジャー（地域包括支援センター）がプランを組みます。</p>'
        . '<p><b>同じ月に医療保険と介護保険の両方は使える？</b><br>原則どちらか一方です。例外は上の表の「厚生労働大臣が定める疾病等」と特別訪問看護指示書の期間。</p>'
        . '</div>';
    page('訪問看護は医療保険か介護保険か｜要介護度別の月の上限（区分支給限度基準額）の引き表 | ' . NAME, '訪問看護をどちらの保険で使うか、要介護1〜5・要支援1〜2ごとの月の上限（単位）、要介護認定なしで使える場合を国の決まりで引く表。', BASE . '/seido/', $body,
        [['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => [
            ['@type' => 'Question', 'name' => '要介護認定を受けていなくても訪問看護は使えますか', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => '使えます。医療保険で、主治医の訪問看護指示書があれば年齢を問わず利用できます。']],
            ['@type' => 'Question', 'name' => '訪問看護は医療保険と介護保険のどちらで使いますか', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => '要介護・要支援の認定がある人は介護保険が優先。認定がない人、厚生労働大臣が定める疾病等の人、特別訪問看護指示書の期間は医療保険です。']]]]],
        '訪問看護の制度の引き表');
    exit;
}

// ---- 入口 ----
$topNames = db()->query("SELECT name_core, COUNT(*) c FROM stations WHERE name_core<>'' GROUP BY name_core HAVING c>=10 ORDER BY c DESC LIMIT 24")->fetchAll(PDO::FETCH_ASSOC);
$body = '<p class="lead">ケアマネジャーや病院から「訪問看護ステーション◯◯」と名前を聞いたとき、その1軒がどこにあって、何曜日に使えて、電話は何番か。全国' . n($m['count'] ?? 0) . '軒を公開データ（データ時点 ' . h($m['data_vintage'] ?? '') . '）から引けます。</p>';
$body .= '<div class="panel"><form class="s" method="get" action="' . u('/near') . '"><input type="text" name="q" placeholder="住所を入れる（例: 名古屋市千種区）" required><button class="btn" type="submit">近い順に並べる</button></form></div>';
$body .= '<h2>同じ名前のステーションが多い名前</h2><p class="note">「ひまわり」「さくら」のような名前は全国に何十軒もあり、別の事業所と取り違えやすい。名前から入ると、住所で見分けられます。</p><div class="grid">';
foreach ($topNames as $r) { $body .= '<a href="' . u('/n/' . rawurlencode($r['name_core'])) . '"><b>' . h($r['name_core']) . '</b><br><small>' . n($r['c']) . '軒</small></a>'; }
$body .= '</div><p class="note">全国で ' . n($m['shared_names'] ?? 0) . ' の名前を ' . n($m['shared_stations'] ?? 0) . ' 軒が共有しています。</p>';
$body .= '<h2>都道府県から</h2><p><a class="btn sub" href="' . u('/area/') . '">47都道府県の一覧へ</a></p>';
$body .= '<h2>制度の引き表</h2><div class="panel"><p>訪問看護を医療保険で使うか介護保険で使うか、要介護1〜5・要支援1〜2ごとの月の上限。<a href="' . u('/seido/') . '">引き表を見る</a></p></div>';
$body .= '<h2>このページでできないこと</h2><div class="warn">空き状況・受け入れ可否・料金は、公開データに含まれていないため載せていません。24時間対応・精神科訪問看護・特別管理加算は、厚生局の届出名簿がある地域（いまは東海北陸6県）だけ「届出の有無」を出しています。事業所へ電話で確認してください。</div>';
page(NAME . '｜訪問看護ステーションを名前・住所から探す（全国' . n($m['count'] ?? 0) . '軒）', '訪問看護ステーション' . n($m['count'] ?? 0) . '軒を名前・住所から。同じ名前の別事業所を住所で見分け、電話・利用可能曜日・運営法人を公開データで確認。医療保険/介護保険の引き表つき。', BASE . '/', $body, [], h(NAME));
