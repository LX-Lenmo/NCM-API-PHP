<?php
/* ============================================================
 *  XHAI NCM API  -  纯 PHP 的网易云音乐 API（单文件 / 零依赖）
 * ------------------------------------------------------------
 *  接口：
 *    GET /ncm-api.php/search?keywords=&limit=&type=
 *    GET /ncm-api.php/song/detail?ids=1,2,3
 *    GET /ncm-api.php/song/url/v1?id=&level=      (播放直链)
 *    GET /ncm-api.php/song/url/match?id=          (解灰)
 *    GET /ncm-api.php/lyric?id=
 *    GET /ncm-api.php/playlist/detail?id=
 *    GET /ncm-api.php/search/hot
 *    GET /ncm-api.php/personalized?limit=         (推荐歌单)
 *    GET /ncm-api.php/top/song                    (热歌榜)
 *    GET /ncm-api.php/toplist
 *    GET /ncm-api.php/login/status?cookie=        (登录态)
 *    GET /ncm-api.php/user/playlist?uid=&cookie=  (我的歌单)
 *    GET /ncm-api.php/login/qr/key | qr/create | qr/check
 *    GET /ncm-api.php/login/cellphone?phone=&password= / captcha
 *
 *  说明：
 *    - 请求会自动带上 NCM_REAL_IP（伪装境内 IP）
 *      网易对部分接口（直链 / 登录）按地区限制，境内 IP 才能放行
 *    - 返回数据中的 http:// 资源地址会自动升级为 https://
 *      避免 HTTPS 页面出现“混合内容”告警
 *    - 播放直链三级容错：官方直链 -> 第三方音源解灰 -> 官方外链
 *
 *  依赖：PHP >= 7.0，curl 扩展、openssl 扩展
 *  许可证：MIT
 * ============================================================ */

/* 伪装成境内 IP（网易对直链/登录类接口按地区放行，可自行替换） */
if (!defined('NCM_REAL_IP')) { define('NCM_REAL_IP', '116.25.146.177'); }

error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$PATH = isset($_SERVER['PATH_INFO']) ? '/' . trim($_SERVER['PATH_INFO'], '/') : '/';
$Q = $_GET;
/* 前端携带的网易云登录凭证（来自平台绑定，由 LMusic 自动回填） */
$CK = isset($Q['cookie']) ? trim((string)$Q['cookie']) : '';
$BASE = 'http://music.163.com';
$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

function ncm_req($url, $post) {
    global $UA, $CK;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $headers = array(
        'User-Agent: ' . $UA,
        'Referer: https://music.163.com/',
        'Accept: application/json, text/plain, */*',
        /* 本服务器在海外，网易按 IP 判地区：带上国内 IP 头才能拿到直链与登录能力 */
        'X-Real-IP: ' . NCM_REAL_IP,
        'X-Forwarded-For: ' . NCM_REAL_IP,
    );
    if ($CK !== '') { $headers[] = 'Cookie: ' . $CK; }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    curl_close($ch);
    return $body === false ? '' : $body;
}
function ncm_loc($url) {
    global $UA;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: ' . $UA, 'Referer: https://music.163.com/'));
    curl_exec($ch);
    $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return $loc ? $loc : '';
}
function ncm_json($s) { $j = json_decode($s, true); return is_array($j) ? $j : null; }
function ncm_out($d) {
    /* 把返回数据里的 http 资源地址升级为 https，避免 HTTPS 页面出现混合内容警告 */
    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $json = str_replace('http://', 'https://', $json);
    echo $json;
    exit;
}
function ncm_id($v) { return preg_replace('/^netease_/', '', trim((string)$v)); }


/* ---------- 解灰可调参数 ---------- */
if (!defined('XUB_UA')) define('XUB_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36');
if (!defined('XUB_REAL_IP')) define('XUB_REAL_IP', NCM_REAL_IP);
if (!defined('XUB_MIN_SCORE')) define('XUB_MIN_SCORE', 0.55);


/* ---------- 解灰可调参数 ---------- */
if (!defined('XUB_UA')) define('XUB_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36');
if (!defined('XUB_REAL_IP')) define('XUB_REAL_IP', NCM_REAL_IP);
if (!defined('XUB_MIN_SCORE')) define('XUB_MIN_SCORE', 0.55);
if (!defined('XUB_ENABLE_GD')) define('XUB_ENABLE_GD', false);
if (!defined('XUB_STATE_SKIP')) define('XUB_STATE_SKIP', false);

/* ==================== 自动解灰（多音源） ==================== */

function xub_get($url, $headers = array(), $timeout = 8, $withHeader = false) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => $withHeader ? 1 : 0,
        CURLOPT_USERAGENT => XUB_UA,
        CURLOPT_HTTPHEADER => $headers,
    ));
    $b = curl_exec($ch);
    $GLOBALS['XUB_HTTP'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $GLOBALS['XUB_ERR'] = curl_error($ch);
    curl_close($ch);
    return $b === false ? '' : $b;
}

function xub_post($url, $body, $headers = array(), $timeout = 8) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => XUB_UA,
        CURLOPT_HTTPHEADER => $headers,
    ));
    $b = curl_exec($ch);
    curl_close($ch);
    return $b === false ? '' : $b;
}

function xub_clean($s) {
    $s = html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/[[:space:]]+/u', ' ', $s);
    return trim($s);
}

function xub_sim($a, $b) {
    $a = mb_strtolower(preg_replace('/[[:punct:][:space:]]+/u', '', (string)$a), 'UTF-8');
    $b = mb_strtolower(preg_replace('/[[:punct:][:space:]]+/u', '', (string)$b), 'UTF-8');
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 1.0;
    $la = mb_strlen($a, 'UTF-8');
    $lb = mb_strlen($b, 'UTF-8');
    if (mb_strpos($a, $b) !== false || mb_strpos($b, $a) !== false) {
        $short = $la < $lb ? $la : $lb;
        $long  = $la < $lb ? $lb : $la;
        return round(0.55 + 0.45 * ($short / $long), 3);
    }
    $ga = array();
    for ($i = 0; $i + 1 < $la; $i++) { $ga[] = mb_substr($a, $i, 2, 'UTF-8'); }
    $gb = array();
    for ($j = 0; $j + 1 < $lb; $j++) { $gb[] = mb_substr($b, $j, 2, 'UTF-8'); }
    if (!$ga) $ga[] = $a;
    if (!$gb) $gb[] = $b;
    $inter = count(array_intersect($ga, $gb));
    $union = count(array_unique(array_merge($ga, $gb)));
    return $union ? ($inter / $union) : 0.0;
}

function xub_score($tName, $tArtist, $name, $artist, $dur = 0, $tdur = 0) {
    $s1 = xub_sim($name, $tName);
    $s2 = ($artist === '' || $tArtist === '') ? 0.6 : xub_sim($artist, $tArtist);
    $sc = $s1 * 0.72 + $s2 * 0.28;
    if ($dur > 0 && $tdur > 0) {
        $diff = $dur > $tdur ? $dur - $tdur : $tdur - $dur;
        if ($diff <= 3) $sc += 0.10;
        elseif ($diff <= 10) $sc += 0.03;
        elseif ($diff > 60) $sc -= 0.15;
    }
    if ($sc > 1.0) $sc = 1.0;
    if ($sc < 0.0) $sc = 0.0;
    return round($sc, 3);
}

/* 音源可用状态（同目录缓存，10 分钟） */
function xub_state_file() {
    return defined('XUB_STATE_FILE') ? XUB_STATE_FILE : (dirname(__FILE__) . '/.ncm-src-state');
}

function xub_state_read() {
    $j = @file_get_contents(xub_state_file());
    $d = $j ? json_decode($j, true) : null;
    return is_array($d) ? $d : array();
}

function xub_mark($src, $ok) {
    $d = xub_state_read();
    $d[$src] = array('ok' => $ok ? 1 : 0, 'ts' => time());
    @file_put_contents(xub_state_file(), json_encode($d));
}

function xub_is_ok($src) {
    $d = xub_state_read();
    if (!isset($d[$src])) return true;
    if (time() - (int)$d[$src]['ts'] > 600) return true;
    return !empty($d[$src]['ok']);
}

/* ---------- 源 0：网易官方（伪装国内 IP，可带登录 Cookie） ---------- */
function xub_official($id, $quality, $cookie) {
    $br = 320000;
    if ($quality === 'flac' || $quality === 'lossless') $br = 999000;
    elseif ($quality === '128k') $br = 128000;
    $h = array('X-Real-IP: ' . XUB_REAL_IP, 'X-Forwarded-For: ' . XUB_REAL_IP, 'Referer: http://music.163.com/');
    if ($cookie !== '') $h[] = 'Cookie: ' . $cookie;
    $u = 'http://music.163.com/api/song/enhance/player/url?ids=%5B' . (int)$id . '%5D&br=' . $br;
    $d = json_decode(xub_get($u, $h), true);
    if ($d && !empty($d['data'][0]['url'])) return $d['data'][0]['url'];
    return '';
}

/* ---------- 网易歌曲信息（歌名 / 歌手 / 时长） ---------- */
function xub_song_info($id) {
    $t = xub_get('http://music.163.com/api/song/detail/?ids=%5B' . (int)$id . '%5D');
    $d = json_decode($t, true);
    if (!$d || empty($d['songs'][0])) return null;
    $s = $d['songs'][0];
    $arts = array();
    if (!empty($s['artists'])) {
        foreach ($s['artists'] as $a) { if (isset($a['name'])) $arts[] = $a['name']; }
    }
    return array(
        'name'   => xub_clean(isset($s['name']) ? $s['name'] : ''),
        'artist' => xub_clean(implode('/', $arts)),
        'dur'    => isset($s['duration']) ? (int)round($s['duration'] / 1000) : 0,
    );
}

/* ---------- 源 1：酷我 ---------- */
function xub_field($body, $key) {
    $k = chr(39) . $key . chr(39) . ':';
    $p = strpos($body, $k);
    if ($p === false) return '';
    $st = $p + strlen($k);
    $q = substr($body, $st, 1);
    if ($q === chr(39) || $q === chr(34)) {
        $e = strpos($body, $q, $st + 1);
        if ($e === false) return '';
        return xub_clean(substr($body, $st + 1, $e - $st - 1));
    }
    $e = strlen($body);
    foreach (array(',', '}') as $sep) {
        $x = strpos($body, $sep, $st);
        if ($x !== false && $x < $e) $e = $x;
    }
    return trim(substr($body, $st, $e - $st));
}

function xub_kuwo_search($kw) {
    $out = array();
    $u = 'http://search.kuwo.cn/r.s?all=' . urlencode($kw)
       . '&ft=music&itemset=web_2013&client=kt&pn=0&rn=30&rformat=json&encoding=utf8';
    $t = xub_get($u);
    if ($t === '' || strpos($t, 'DC_TARGETID') === false) return $out;
    $chunks = explode(chr(39) . 'DC_TARGETID' . chr(39), $t);
    array_shift($chunks);
    foreach ($chunks as $c) {
        $rid = '';
        if (preg_match('/[0-9]{5,}/', $c, $m)) $rid = $m[0];
        $nm = xub_field($c, 'SONGNAME');
        $ar = xub_field($c, 'ARTIST');
        $du = (int)xub_field($c, 'DURATION');
        if ($rid === '' || $nm === '') continue;
        $out[] = array('rid' => $rid, 'name' => $nm, 'artist' => $ar, 'dur' => $du);
    }
    return $out;
}

function xub_kuwo_url($rid, $quality) {
    $fmt = ($quality === 'flac' || $quality === 'lossless') ? 'flac' : 'mp3';
    $u = 'http://antiserver.kuwo.cn/anti.s?type=convert_url&format=' . $fmt . '&response=url&rid=MUSIC_' . $rid;
    $t = xub_get($u, array('User-Agent: okhttp/3.10.0'));
    if (preg_match('/http[^\s]+/', $t, $m)) return trim($m[0]);
    return '';
}

/* ---------- 源 2：酷狗（对齐 unm 实现） ---------- */
function xub_kugou_search($kw) {
    $out = array();
    $u = 'http://songsearch.kugou.com/song_search_v2?keyword=' . urlencode($kw) . '&page=1';
    $d = json_decode(xub_get($u, array('Referer: http://www.kugou.com/')), true);
    if (!$d || empty($d['data']['lists'])) return $out;
    foreach ($d['data']['lists'] as $s) {
        if (empty($s['FileHash'])) continue;
        $out[] = array(
            'hash'   => $s['FileHash'],
            'name'   => xub_clean(isset($s['SongName']) ? $s['SongName'] : ''),
            'artist' => xub_clean(isset($s['SingerName']) ? $s['SingerName'] : ''),
            'dur'    => isset($s['Duration']) ? (int)$s['Duration'] : 0,
        );
    }
    return $out;
}

function xub_kugou_url($hash, $quality) {
    $br = ($quality === 'flac' || $quality === 'lossless') ? 'flac' : 'hq';
    $u = 'http://trackercdn.kugou.com/i/v2/?key=' . md5($hash . 'kgcloudv2') . '&hash=' . $hash
       . '&br=' . $br . '&appid=1005&pid=2&cmd=25&behavior=play';
    $d = json_decode(xub_get($u), true);
    if ($d && !empty($d['url'][0])) return $d['url'][0];
    $d2 = json_decode(xub_get('http://m.kugou.com/app/i/getSongInfo.php?cmd=playInfo&hash=' . $hash, array('User-Agent: Mozilla/5.0')), true);
    if ($d2 && !empty($d2['url'])) return $d2['url'];
    if ($d2 && !empty($d2['backup_url'][0])) return $d2['backup_url'][0];
    return '';
}

/* ---------- 源 3：QQ 音乐（对齐 unm 实现） ---------- */
function xub_qq_search($kw) {
    $out = array();
    $data = array('req' => array(
        'module' => 'music.search.SearchCgiService',
        'method' => 'DoSearchForQQMusicDesktop',
        'param'  => array('query' => $kw, 'num_per_page' => 20, 'page_num' => 1),
    ));
    $u = 'https://u.y.qq.com/cgi-bin/musicu.fcg?data=' . urlencode(json_encode($data));
    $d = json_decode(xub_get($u, array('Referer: https://y.qq.com/')), true);
    if (!$d || empty($d['req']['data']['body']['song']['list'])) return $out;
    foreach ($d['req']['data']['body']['song']['list'] as $s) {
        $singer = array();
        if (!empty($s['singer'])) { foreach ($s['singer'] as $x) { if (isset($x['name'])) $singer[] = $x['name']; } }
        $out[] = array(
            'mid'    => isset($s['mid']) ? $s['mid'] : '',
            'media'  => isset($s['file']['media_mid']) ? $s['file']['media_mid'] : '',
            'name'   => xub_clean(isset($s['name']) ? $s['name'] : ''),
            'artist' => xub_clean(implode('/', $singer)),
            'dur'    => isset($s['interval']) ? (int)$s['interval'] : 0,
        );
    }
    return $out;
}

function xub_qq_url($mid, $media, $quality, $uin = '0') {
    if ($media === '') return '';
    $prefs = ($quality === 'flac' || $quality === 'lossless')
        ? array(array('F000', '.flac'), array('M800', '.mp3'), array('M500', '.mp3'))
        : array(array('M800', '.mp3'), array('M500', '.mp3'));
    foreach ($prefs as $p) {
        $data = array('req_0' => array(
            'module' => 'vkey.GetVkeyServer',
            'method' => 'CgiGetVkey',
            'param'  => array(
                'guid' => '7332953645', 'loginflag' => 1,
                'filename' => array($p[0] . $media . $p[1]),
                'songmid' => array($mid), 'songtype' => array(0),
                'uin' => $uin, 'platform' => '20',
            ),
        ));
        $u = 'https://u.y.qq.com/cgi-bin/musicu.fcg?data=' . urlencode(json_encode($data));
        $d = json_decode(xub_get($u, array('Referer: https://y.qq.com/')), true);
        if ($d && !empty($d['req_0']['data']['midurlinfo'][0]['purl'])) {
            return $d['req_0']['data']['sip'][0] . $d['req_0']['data']['midurlinfo'][0]['purl'];
        }
    }
    return '';
}

/* ---------- 源 4：咪咕（对齐 unm 实现，EVP_BytesToKey + RSA） ---------- */
function xub_migu_sign($obj) {
    $text = json_encode($obj);
    $password = bin2hex(random_bytes(32));
    $salt = random_bytes(8);
    $buf = '';
    $prev = '';
    while (strlen($buf) < 48) {
        $prev = md5($prev . $password . $salt, true);
        $buf .= $prev;
    }
    $key = substr($buf, 0, 32);
    $iv  = substr($buf, 32, 16);
    $ct = openssl_encrypt($text, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    $data = base64_encode('Salted__' . $salt . $ct);
    $pub = "-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC8asrfSaoOb4je+DSmKdriQJKWVJ2oDZrs3wi5W67m3LwTB9QVR+cE3XWU21Nx+YBxS0yun8wDcjgQvYt625ZCcgin2ro/eOkNyUOTBIbuj9CvMnhUYiR61lC1f1IGbrSYYimqBVSjpifVufxtx/I3exReZosTByYp4Xwpb1+WAQIDAQAB
-----END PUBLIC KEY-----";
    $sec = '';
    if (!openssl_public_encrypt($password, $sec, $pub, OPENSSL_PKCS1_PADDING)) return array();
    return array('data' => $data, 'secKey' => base64_encode($sec));
}

function xub_migu_search($kw) {
    $out = array();
    $u = 'http://m.music.migu.cn/migu/remoting/scr_search_tag?keyword=' . urlencode($kw) . '&type=2&rows=20&pgc=1';
    $d = json_decode(xub_get($u, array('Origin: http://music.migu.cn/', 'Referer: http://music.migu.cn/')), true);
    if (!$d || empty($d['musics'])) return $out;
    foreach ($d['musics'] as $s) {
        $out[] = array(
            'cid'    => isset($s['copyrightId']) ? $s['copyrightId'] : '',
            'name'   => xub_clean(isset($s['title']) ? $s['title'] : ''),
            'artist' => xub_clean(isset($s['singerName']) ? $s['singerName'] : ''),
            'dur'    => 0,
        );
    }
    return $out;
}

function xub_migu_url($cid, $quality) {
    if ($cid === '') return '';
    $types = ($quality === 'flac' || $quality === 'lossless') ? array(3, 2, 1) : array(2, 1);
    foreach ($types as $t) {
        $sign = xub_migu_sign(array('copyrightId' => (string)$cid, 'type' => $t));
        if (!$sign) return '';
        $u = 'http://music.migu.cn/v3/api/music/audioPlayer/getPlayInfo?dataType=2&' . http_build_query($sign);
        $d = json_decode(xub_get($u, array('Origin: http://music.migu.cn/', 'Referer: http://music.migu.cn/')), true);
        if ($d && !empty($d['data']['playUrl'])) return $d['data']['playUrl'];
    }
    return '';
}

/* ---------- 打分排序 + 各源尝试 ---------- */
function xub_pick($cands, $name, $artist, $dur) {
    $best = array();
    foreach ($cands as $c) {
        $c['score'] = xub_score($name, $artist, $c['name'], $c['artist'], $dur, $c['dur']);
        $best[] = $c;
    }
    usort($best, function ($x, $y) { return $y['score'] < $x['score'] ? -1 : 1; });
    return $best;
}

function xub_try_kuwo($name, $artist, $dur, $quality) {
    $cands = xub_kuwo_search(trim($name . ' ' . $artist));
    if (!$cands) return null;
    foreach (array_slice(xub_pick($cands, $name, $artist, $dur), 0, 4) as $c) {
        if ($c['score'] < XUB_MIN_SCORE) break;
        $u = xub_kuwo_url($c['rid'], $quality);
        if ($u !== '') return array('url' => $u, 'name' => $c['name'], 'artist' => $c['artist'], 'score' => $c['score']);
    }
    return null;
}

function xub_try_kugou($name, $artist, $dur, $quality) {
    $cands = xub_kugou_search(trim($name . ' ' . $artist));
    if (!$cands) return null;
    foreach (array_slice(xub_pick($cands, $name, $artist, $dur), 0, 4) as $c) {
        if ($c['score'] < XUB_MIN_SCORE) break;
        $u = xub_kugou_url($c['hash'], $quality);
        if ($u !== '') return array('url' => $u, 'name' => $c['name'], 'artist' => $c['artist'], 'score' => $c['score']);
    }
    return null;
}

function xub_try_qq($name, $artist, $dur, $quality) {
    $cands = xub_qq_search(trim($name . ' ' . $artist));
    if (!$cands) return null;
    foreach (array_slice(xub_pick($cands, $name, $artist, $dur), 0, 4) as $c) {
        if ($c['score'] < XUB_MIN_SCORE) break;
        $u = xub_qq_url($c['mid'], $c['media'], $quality, defined('XUB_QQ_UIN') ? XUB_QQ_UIN : '0');
        if ($u !== '') return array('url' => $u, 'name' => $c['name'], 'artist' => $c['artist'], 'score' => $c['score']);
    }
    return null;
}

function xub_try_migu($name, $artist, $dur, $quality) {
    $cands = xub_migu_search(trim($name . ' ' . $artist));
    if (!$cands) return null;
    foreach (array_slice(xub_pick($cands, $name, $artist, $dur), 0, 4) as $c) {
        if ($c['score'] < XUB_MIN_SCORE) break;
        $u = xub_migu_url($c['cid'], $quality);
        if ($u !== '') return array('url' => $u, 'name' => $c['name'], 'artist' => $c['artist'], 'score' => $c['score']);
    }
    return null;
}

/* ---------- 音源顺序（可自行增删） ---------- */
function xub_order() {
    $o = array('kuwo', 'kugou', 'qq', 'migu');
    if (defined('XUB_ENABLE_GD') && XUB_ENABLE_GD) $o[] = 'gd';
    return $o;
}

/* ---------- 总入口：自动解灰 ---------- */
function xub_resolve($id, $cookie = '', $name = '', $artist = '', $dur = 0, $quality = '') {
    $tried = array();
    if ($id > 0) {
        $u = xub_official($id, $quality, $cookie);
        if ($u !== '') return array('url' => $u, 'source' => 'netease', 'tried' => $tried);
        $tried[] = 'netease:fail';
    }
    if ($name === '' && $id > 0) {
        $info = xub_song_info($id);
        if ($info) { $name = $info['name']; $artist = $info['artist']; $dur = $info['dur']; }
    }
    if ($name === '') {
        return array('url' => '', 'source' => '', 'tried' => $tried, 'message' => '无法获取歌曲信息');
    }
    $skipState = defined('XUB_STATE_SKIP') ? XUB_STATE_SKIP : false;
    foreach (xub_order() as $src) {
        if ($skipState && !xub_is_ok($src)) { $tried[] = $src . ':skip'; continue; }
        $r = null;
        if ($src === 'kuwo')       $r = xub_try_kuwo($name, $artist, $dur, $quality);
        elseif ($src === 'kugou')  $r = xub_try_kugou($name, $artist, $dur, $quality);
        elseif ($src === 'qq')     $r = xub_try_qq($name, $artist, $dur, $quality);
        elseif ($src === 'migu')   $r = xub_try_migu($name, $artist, $dur, $quality);
        if ($skipState) xub_mark($src, $r !== null);
        if ($r !== null) {
            $r['source'] = $src;
            $r['tried']  = $tried;
            return $r;
        }
        $tried[] = $src . ':fail';
    }
    return array('url' => '', 'source' => '', 'tried' => $tried, 'message' => '所有音源均未命中');
}
/* ---------- 自检：从当前服务器出口测各音源 ---------- */
function xub_probe($name = '晴天', $artist = '周杰伦') {
    $kw = trim($name . ' ' . $artist);
    $out = array();
    $out['netease'] = xub_official(186016, '', '') !== '' ? 'ok' : 'fail';
    $c = xub_kuwo_search($kw);
    $out['kuwo'] = $c ? (xub_kuwo_url($c[0]['rid'], '') !== '' ? 'ok' : 'search-ok/url-fail') : 'fail';
    $c = xub_kugou_search($kw);
    $out['kugou'] = $c ? (xub_kugou_url($c[0]['hash'], '') !== '' ? 'ok' : 'search-ok/url-fail') : 'fail';
    $c = xub_qq_search($kw);
    $out['qq'] = $c ? (xub_qq_url($c[0]['mid'], $c[0]['media'], '') !== '' ? 'ok' : 'search-ok/url-fail') : 'fail';
    $c = xub_migu_search($kw);
    $out['migu'] = $c ? (xub_migu_url($c[0]['cid'], '') !== '' ? 'ok' : 'search-ok/url-fail') : 'fail';
    return $out;
}
/* 旧版单源解灰（保留兼容） */
function ncm_match_url($id) {
    global $BASE;
    $id = ncm_id($id);
    if ($id === '') { return ''; }
    $j = ncm_json(ncm_req($BASE . '/api/song/enhance/player/url?id=' . rawurlencode($id) . '&ids=' . rawurlencode('[' . $id . ']') . '&br=999000', null));
    if ($j && !empty($j['data'][0]['url'])) { return $j['data'][0]['url']; }
    $d = ncm_json(ncm_req($BASE . '/api/song/detail/?ids=' . rawurlencode('[' . $id . ']'), null));
    $name = ''; $artist = '';
    if ($d && isset($d['songs'][0])) {
        $s = $d['songs'][0];
        $name = isset($s['name']) ? $s['name'] : '';
        if (isset($s['artists'][0]['name'])) { $artist = $s['artists'][0]['name']; }
    }
    if ($name === '') { return ''; }
    $kw = rawurlencode($artist !== '' ? ($name . ' ' . $artist) : $name);
    $list = ncm_json(ncm_req('https://music-api.gdstudio.xyz/api.php?types=search&source=netease&name=' . $kw . '&count=15&pages=1', null));
    if (is_array($list)) {
        foreach ($list as $it) {
            if (!isset($it['id'])) { continue; }
            $itName = isset($it['name']) ? $it['name'] : '';
            if ($itName !== $name && strpos($itName, $name) === false) { continue; }
            $gu = ncm_json(ncm_req('https://music-api.gdstudio.xyz/api.php?types=url&source=netease&id=' . rawurlencode($it['id']) . '&br=999', null));
            if ($gu && !empty($gu['url'])) { return $gu['url']; }
        }
    }
    return '';
}


/* ============ weapi 加密（网易官方加密协议） ============ */
$NCM_MODULUS = '00e0b509f6259df8642dbc35662901477df22677ec152b5ff68ace615bb7b725152b3ab17a876aea8a5aa76d2e417629ec4ee341f56135fccf695280104e0312ecbda92557c93870114af6c9d05c4f7f0c3685b7a46bee255932575cce10b424d813cfe4875d3e82047b97ddef52741d546b8e289dc6935b3ece0462db0a22b8e7';
$NCM_PUBKEY = '010001';
$NCM_NONCE = '0CoJUm6Qyw8W8jud';
$NCM_IV = '0102030405060708';

function ncm_aes($text, $key) {
    global $NCM_IV;
    $d = openssl_encrypt($text, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $NCM_IV);
    return bin2hex($d);
}
function ncm_asn1len($len) {
    if ($len < 0x80) return chr($len);
    $b = '';
    while ($len > 0) { $b = chr($len & 0xFF) . $b; $len >>= 8; }
    return chr(0x80 | strlen($b)) . $b;
}
function ncm_rsa_pem($modHex, $expHex) {
    $NUL = chr(0); $NL = chr(10);
    $m = $NUL . ltrim(hex2bin($modHex), $NUL);
    $e = ltrim(hex2bin($expHex), $NUL);
    $mi = chr(2) . ncm_asn1len(strlen($m)) . $m;
    $ei = chr(2) . ncm_asn1len(strlen($e)) . $e;
    $rsa = $mi . $ei;
    $rsaDer = chr(0x30) . ncm_asn1len(strlen($rsa)) . $rsa;
    $alg = chr(0x30) . chr(0x0d) . chr(0x06) . chr(0x09)
         . chr(0x2a) . chr(0x86) . chr(0x48) . chr(0x86) . chr(0xf7)
         . chr(0x0d) . chr(0x01) . chr(0x01) . chr(0x01)
         . chr(0x05) . chr(0x00);
    $bit = chr(3) . ncm_asn1len(strlen($rsaDer) + 1) . chr(0) . $rsaDer;
    $spki = chr(0x30) . ncm_asn1len(strlen($alg) + strlen($bit)) . $alg . $bit;
    return '-----BEGIN PUBLIC KEY-----' . $NL . chunk_split(base64_encode($spki), 64, $NL) . '-----END PUBLIC KEY-----' . $NL;
}
function ncm_rsa($text) {
    global $NCM_MODULUS, $NCM_PUBKEY;
    $out = '';
    if (!openssl_public_encrypt(strrev($text), $out, ncm_rsa_pem($NCM_MODULUS, $NCM_PUBKEY), OPENSSL_NO_PADDING)) return '';
    return bin2hex($out);
}
function ncm_weapi($obj) {
    global $NCM_NONCE;
    $json = json_encode($obj, JSON_UNESCAPED_UNICODE);
    $secret = 'abcdefghijklmnop';
    $a = ncm_aes($json, $NCM_NONCE);
    $b = ncm_aes($a, $secret);
    return array('params' => $b, 'encSecKey' => ncm_rsa(strrev($secret)));
}
/* 发起 weapi 请求；Set-Cookie 收集到全局变量 */
function ncm_weapi_post($path, $obj) {
    global $UA, $CK;
    $GLOBALS['NCM_LAST_COOKIE'] = '';
    $p = ncm_weapi($obj);
    $csrf = '1234567890abcdef1234567890abcdef';
    $ch = curl_init('https://music.163.com/weapi/' . ltrim($path, '/') . '?csrf_token=' . $csrf);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($p));
    $h = array(
        'User-Agent: ' . $UA,
        'Referer: https://music.163.com/',
        'Origin: https://music.163.com',
        'Content-Type: application/x-www-form-urlencoded',
        'X-Real-IP: ' . NCM_REAL_IP,
        'X-Forwarded-For: ' . NCM_REAL_IP,
    );
    if ($CK !== '') { $h[] = 'Cookie: ' . $CK; } else { $h[] = 'Cookie: __csrf=' . bin2hex(random_bytes(8)); }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $GLOBALS['NCM_LAST_COOKIE'] .= trim(substr($line, 11)) . '; ';
        }
        return strlen($line);
    });
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $hc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $GLOBALS['NCM_LAST_RAW'] = 'http=' . $hc . ' err=' . $err . ' body=' . substr((string)$body, 0, 200);
    return ncm_json($body);
}


switch ($PATH) {

case '/':
    ncm_out(array('code' => 200, 'msg' => 'XHAI NCM API', 'ver' => '1.0', 'time' => date('c')));

case '/search':
    $kw = isset($Q['keywords']) ? $Q['keywords'] : (isset($Q['s']) ? $Q['s'] : '');
    $limit = isset($Q['limit']) ? (int)$Q['limit'] : 30;
    $j = ncm_json(ncm_req($BASE . '/api/search/get?s=' . rawurlencode($kw) . '&type=1&limit=' . $limit . '&offset=0', null));
    if (!$j) ncm_out(array('code' => 502, 'message' => '搜索服务暂不可用'));
    ncm_out($j);

case '/song/detail':
    $ids = isset($Q['ids']) ? $Q['ids'] : '';
    if (is_array($ids)) $ids = implode(',', $ids);
    $list = array_values(array_filter(array_map('ncm_id', explode(',', (string)$ids)), 'strlen'));
    if (!$list) ncm_out(array('code' => 200, 'songs' => array()));
    $idsJson = '[' . implode(',', $list) . ']';
    $j = ncm_json(ncm_req($BASE . '/api/song/detail/?ids=' . rawurlencode($idsJson), null));
    if (!$j) ncm_out(array('code' => 502, 'message' => '详情获取失败'));
    ncm_out($j);

case '/song/url/v1':
case '/song/url':
    $id = ncm_id(isset($Q['id']) ? $Q['id'] : '');
    $br = isset($Q['br']) ? (int)$Q['br'] : 320;
    if ($br < 1000) $br = $br * 1000;
    /* 自动解灰：官方 -> 酷我 -> 酷狗 -> GD，谁先出链用谁 */
    $ck = isset($Q['cookie']) ? $Q['cookie'] : '';
    $r = xub_resolve($id, $ck, '', '', 0, isset($Q['level']) ? $Q['level'] : '');
    $url = $r['url'];
    if (!$url) { $url = 'https://music.163.com/song/media/outer/url?id=' . rawurlencode($id) . '.mp3'; }
    ncm_out(array('code' => 200, 'data' => array(array('id' => (int)$id, 'url' => $url, 'br' => (int)($br / 1000), 'size' => 0, 'level' => 'exhigh', 'freeTrialInfo' => null))));

case '/lyric':
    $id = ncm_id(isset($Q['id']) ? $Q['id'] : '');
    $j = ncm_json(ncm_req($BASE . '/api/song/lyric?id=' . rawurlencode($id) . '&lv=1&kv=1&tv=-1', null));
    if (!$j) ncm_out(array('code' => 200, 'lrc' => array('lyric' => ''), 'tlyric' => array('lyric' => '')));
    ncm_out($j);

case '/playlist/detail':
    $id = ncm_id(isset($Q['id']) ? $Q['id'] : '');
    $j = ncm_json(ncm_req($BASE . '/api/playlist/detail?id=' . rawurlencode($id), null));
    if (!$j) ncm_out(array('code' => 502, 'message' => '歌单获取失败'));
    if (isset($j['result']) && !isset($j['playlist'])) $j['playlist'] = $j['result'];
    $j['code'] = 200;
    ncm_out($j);

case '/search/hot':
    $j = ncm_json(ncm_req($BASE . '/api/search/hot?type=1111', null));
    if (!$j) ncm_out(array('code' => 200, 'result' => array('hots' => array())));
    ncm_out($j);

case '/personalized':
    $limit = isset($Q['limit']) ? (int)$Q['limit'] : 12;
    /* 首选：网易官方推荐歌单接口 */
    $j = ncm_json(ncm_req($BASE . '/api/personalized/playlist?limit=' . $limit, null));
    if ($j && isset($j['result']) && is_array($j['result']) && count($j['result'])) {
        ncm_out(array('code' => 200, 'result' => $j['result']));
    }
    /* 兜底：推荐接口不可用时用榜单填充 */
    $j2 = ncm_json(ncm_req($BASE . '/api/toplist/detail', null));
    $out = array();
    if ($j2 && isset($j2['list']) && is_array($j2['list'])) {
        foreach ($j2['list'] as $it) {
            $out[] = array(
                'id' => isset($it['id']) ? $it['id'] : 0,
                'name' => isset($it['name']) ? $it['name'] : '',
                'picUrl' => isset($it['coverImgUrl']) ? $it['coverImgUrl'] : '',
                'playCount' => isset($it['playCount']) ? $it['playCount'] : 0,
                'trackCount' => isset($it['trackCount']) ? $it['trackCount'] : 0,
            );
        }
    }
    ncm_out(array('code' => 200, 'result' => array_slice($out, 0, $limit)));

case '/top/song':
    $j = ncm_json(ncm_req($BASE . '/api/playlist/detail?id=3778678', null));
    $tracks = array();
    if ($j) {
        if (isset($j['result']['tracks'])) $tracks = $j['result']['tracks'];
        elseif (isset($j['playlist']['tracks'])) $tracks = $j['playlist']['tracks'];
    }
    ncm_out(array('code' => 200, 'data' => $tracks));

case '/toplist':
    $j = ncm_json(ncm_req($BASE . '/api/toplist/detail', null));
    if (!$j) ncm_out(array('code' => 502, 'message' => '榜单获取失败'));
    ncm_out($j);

case '/personalized/newsong':
    $limit = isset($Q['limit']) ? (int)$Q['limit'] : 12;
    $j = ncm_json(ncm_req($BASE . '/api/personalized/newsong?limit=' . $limit, null));
    if (!$j) ncm_out(array('code' => 200, 'result' => array()));
    ncm_out($j);

case '/song/url/match':
    $id = ncm_id(isset($Q['id']) ? $Q['id'] : '');
    $ck = isset($Q['cookie']) ? $Q['cookie'] : '';
    $r = xub_resolve($id, $ck, '', '', 0, '');
    ncm_out(array('code' => 200, 'data' => $r['url']));

case '/unblock/probe':
    ncm_out(array('code' => 200, 'sources' => xub_probe(isset($Q['name']) ? $Q['name'] : '晴天', isset($Q['artist']) ? $Q['artist'] : '周杰伦')));

/* ---- 网易云登录（weapi）---- */
case '/login/qr/key':
    $j = ncm_weapi_post('login/qrcode/unikey', array('type' => 1));
    if (!$j || !isset($j['unikey'])) { ncm_out(array('code' => 502, 'message' => '二维码获取失败，请稍后重试')); }
    ncm_out(array('code' => 200, 'data' => array('code' => 200, 'unikey' => $j['unikey'])));

case '/login/qr/create':
    $key = isset($Q['key']) ? $Q['key'] : '';
    ncm_out(array('code' => 200, 'data' => array('code' => 200, 'qrurl' => 'https://music.163.com/login?codekey=' . $key)));

case '/login/qr/check':
    $key = isset($Q['key']) ? $Q['key'] : '';
    $j = ncm_weapi_post('login/qrcode/client/login', array('key' => $key, 'type' => 1));
    if (!$j) { ncm_out(array('code' => 800, 'message' => '等待扫码')); }
    $out = array('code' => isset($j['code']) ? (int)$j['code'] : 800);
    if (isset($j['message'])) { $out['message'] = $j['message']; }
    $ckc = isset($GLOBALS['NCM_LAST_COOKIE']) ? $GLOBALS['NCM_LAST_COOKIE'] : '';
    if ($ckc !== '' && isset($j['code']) && (int)$j['code'] === 803) { $out['cookie'] = $ckc; }
    ncm_out($out);

case '/login/cellphone':
    $q = array('phone' => isset($Q['phone']) ? $Q['phone'] : '', 'countrycode' => 86, 'rememberLogin' => 'true');
    if (!empty($Q['password'])) { $q['password'] = $Q['password']; }
    if (!empty($Q['captcha'])) { $q['captcha'] = $Q['captcha']; }
    $j = ncm_weapi_post('login/cellphone', $q);
    if (!$j) { ncm_out(array('code' => 502, 'message' => '登录失败，请稍后重试')); }
    $ckc = isset($GLOBALS['NCM_LAST_COOKIE']) ? $GLOBALS['NCM_LAST_COOKIE'] : '';
    if ($ckc !== '' && !isset($j['cookie'])) { $j['cookie'] = $ckc; }
    ncm_out($j);

case '/captcha/sent':
    $j = ncm_weapi_post('sms/captcha/sent', array('cellphone' => isset($Q['phone']) ? $Q['phone'] : '', 'ctcode' => 86));
    if (!$j) { ncm_out(array('code' => 502, 'message' => '发送失败')); }
    ncm_out($j);

case '/login':
    $j = ncm_weapi_post('login', array('username' => isset($Q['username']) ? $Q['username'] : '', 'password' => isset($Q['password']) ? $Q['password'] : '', 'rememberLogin' => 'true'));
    if (!$j) { ncm_out(array('code' => 502, 'message' => '登录失败')); }
    $ckc = isset($GLOBALS['NCM_LAST_COOKIE']) ? $GLOBALS['NCM_LAST_COOKIE'] : '';
    if ($ckc !== '' && !isset($j['cookie'])) { $j['cookie'] = $ckc; }
    ncm_out($j);

case '/login/status':
    if ($CK === '') { ncm_out(array('data' => array('code' => 301, 'account' => null, 'profile' => null))); }
    $j = ncm_json(ncm_req($BASE . '/api/nuser/account/get', null));
    if (!$j) { ncm_out(array('data' => array('code' => 301, 'account' => null, 'profile' => null))); }
    ncm_out(array('data' => array(
        'code' => 200,
        'account' => isset($j['account']) ? $j['account'] : null,
        'profile' => isset($j['profile']) ? $j['profile'] : null,
    )));

case '/user/playlist':
    $uid = isset($Q['uid']) ? (int)$Q['uid'] : 0;
    if ($CK === '') { ncm_out(array('code' => 301, 'message' => '未检测到网易云登录凭证，请先在「我的」里登录网易云')); }
    if (!$uid) { ncm_out(array('code' => 301, 'message' => '缺少 uid')); }
    $j = ncm_json(ncm_req($BASE . '/api/user/playlist?uid=' . $uid . '&limit=100&offset=0', null));
    if (!$j) { ncm_out(array('code' => 502, 'message' => '歌单获取失败')); }
    if (!isset($j['code'])) { $j['code'] = 200; }
    ncm_out($j);

case '/playlist/create':
case '/playlist/delete':
case '/playlist/tracks':
    ncm_out(array('code' => 301, 'message' => '该操作需要网易加密登录支持，暂未开放'));

default:
    ncm_out(array('code' => 404, 'message' => 'Not Found: ' . $PATH));
}
