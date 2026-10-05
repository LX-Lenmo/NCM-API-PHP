<?php
/* ============================================================
 *  XHAI NCM Unblock  -  纯 PHP 自动解灰服务（单文件 / 零依赖）
 * ------------------------------------------------------------
 *  作用：网易上 VIP / 无版权 / 下架的歌拿不到播放地址时，
 *        自动拿「歌名 + 歌手」到其他音乐源匹配可播直链。
 *
 *  音源（对齐 UnblockNeteaseMusic 的四条线路）：
 *    酷我 -> 酷狗 -> QQ音乐 -> 咪咕
 *    每个源都是「搜到候选 -> 打分排序 -> 依次试直链」，
 *    某个源取不到就自动换下一个。
 *
 *  用法：
 *    /ncm-unblock.php?id=186016                 （网易歌曲 id）
 *    /ncm-unblock.php?name=晴天&artist=周杰伦
 *    可选：&quality=flac|320k|128k&cookie=<网易Cookie>&dur=秒&probe=1
 *
 *  依赖：PHP >= 7.0 + curl + openssl
 *  许可证：MIT
 * ============================================================ */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

/* ---------- 解灰可调参数 ---------- */
if (!defined('XUB_UA')) define('XUB_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36');
if (!defined('XUB_REAL_IP')) define('XUB_REAL_IP', '116.25.146.177');
if (!defined('XUB_MIN_SCORE')) define('XUB_MIN_SCORE', 0.55);


/* ---------- 解灰可调参数 ---------- */
if (!defined('XUB_UA')) define('XUB_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36');
if (!defined('XUB_REAL_IP')) define('XUB_REAL_IP', '116.25.146.177');
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

/* ---------------- 主流程 ---------------- */
$id      = isset($_GET['id']) ? preg_replace('/[^0-9]/', '', $_GET['id']) : '';
$name    = isset($_GET['name']) ? xub_clean($_GET['name']) : '';
$artist  = isset($_GET['artist']) ? xub_clean($_GET['artist']) : '';
$quality = isset($_GET['quality']) ? $_GET['quality'] : '';
$cookie  = isset($_GET['cookie']) ? $_GET['cookie'] : '';
$dur     = isset($_GET['dur']) ? (int)$_GET['dur'] : 0;

if (!empty($_GET['probe'])) {
    $pn = $name === '' ? '晴天' : $name;
    $pa = $artist === '' ? '周杰伦' : $artist;
    echo json_encode(array('code' => 200, 'sources' => xub_probe($pn, $pa)), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($id === '' && $name === '') {
    echo json_encode(array('code' => 400, 'message' => '缺少参数：请提供 id 或 name'), JSON_UNESCAPED_UNICODE);
    exit;
}

$r = xub_resolve($id === '' ? 0 : (int)$id, $cookie, $name, $artist, $dur, $quality);

if ($r['url'] !== '') {
    echo json_encode(array(
        'code'   => 200,
        'id'     => $id === '' ? null : (int)$id,
        'url'    => str_replace('http://', 'https://', $r['url']),
        'source' => $r['source'],
        'name'   => isset($r['name']) ? $r['name'] : $name,
        'artist' => isset($r['artist']) ? $r['artist'] : $artist,
        'score'  => isset($r['score']) ? $r['score'] : null,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    echo json_encode(array(
        'code'    => 404,
        'message' => isset($r['message']) ? $r['message'] : '未找到可播放音源',
        'query'   => trim($name . ' ' . $artist),
        'tried'   => isset($r['tried']) ? $r['tried'] : array(),
    ), JSON_UNESCAPED_UNICODE);
}
