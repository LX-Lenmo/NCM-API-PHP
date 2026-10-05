# NCM API PHP

一个用**纯 PHP 写的网易云音乐 API**，单文件、零第三方依赖。

为日常听歌需求而写，把常用接口（搜索 / 播放 / 歌词 / 歌单 / 榜单 / 推荐）都跑通了，并额外解决了两个很坑的问题：**地区限制**和**混合内容**。

---

## 特性

- **单文件、零依赖** — 只需 PHP + curl + openssl，直接丢到站点目录即可
- **网易原生结构** — 返回的数据就是网易的原始 JSON，前端不用做适配转换
- **地区限制绕过** — 自动带 `X-Real-IP` 伪装境内 IP（直链类接口在国外服务器默认会被拒）
- **三级播放容错** — 官方直链 → 第三方音源匹配（解灰） → 官方外链
- **自动 HTTPS 升级** — 返回数据里的 `http://` 资源自动升为 `https://`，避免 HTTPS 页面的混合内容告警
- **登录态透传** — 请求带 `?cookie=` 参数时会自动转发，支持“我的歌单”等需要登录的接口
- **weapi 加密已实现** — AES-128-CBC + RSA 均为纯 PHP 实现（无需 gmp 扩展）

---

## 环境要求

- PHP >= 7.0
- 扩展：`curl`、`openssl`
- Web 服务器需要支持 `PATH_INFO`（用作接口路由，Nginx/Apache 默认都支持）

---

## 快速开始

1. 把 `ncm-api.php` 上传到你的站点目录
2. 直接访问即可：

```
https://your-domain.com/ncm-api.php/search?keywords=周杰伦&limit=5
```

### 可选：美化 URL

Nginx：

```nginx
location ~ \\.php(/|$) {
    fastcgi_split_path_info ^(.+?\\.php)(/.*)$;
    # 其余交给默认的 PHP 配置即可（宝塔/BT 默认已开启 pathinfo）
}
```

Apache：默认即可（`.htaccess` 无需额外配置）。

---

## 接口列表

所有接口均为 `GET`，路径拼在文件名后：`/ncm-api.php/<接口>`

| 接口 | 参数 | 说明 |
|---|---|---|
| `/search` | `keywords` `limit` `type` | 搜索歌曲 |
| `/song/detail` | `ids`（逗号分隔） | 歌曲详情 |
| `/song/url/v1` | `id` `level` | 播放直链（含解灰容错） |
| `/song/url/match` | `id` | 解灰：返回可播放地址字符串 |
| `/lyric` | `id` | 歌词（含翻译） |
| `/playlist/detail` | `id` | 歌单详情 |
| `/search/hot` | — | 搜索热词 |
| `/personalized` | `limit` | 推荐歌单 |
| `/top/song` | — | 热歌榜 |
| `/toplist` | — | 榜单列表 |
| `/login/status` | `cookie` | 登录态（返回 uid / 昵称） |
| `/user/playlist` | `uid` `cookie` | 我的歌单 |
| `/login/qr/key` | — | 生成二维码 key |
| `/login/qr/create` | `key` | 二维码链接 |
| `/login/qr/check` | `key` | 轮询扫码状态 |
| `/login/cellphone` | `phone` `password`/`captcha` | 手机号登录 |
| `/captcha/sent` | `phone` | 发送短信验证码 |

---

## 返回示例

搜索：

```json
{
  "result": {
    "songs": [
      {
        "id": 186016,
        "name": "晴天",
        "artists": [{ "name": "周杰伦" }],
        "album": { "name": "叶惠美", "picId": 109951165671182684 }
      }
    ]
  }
}
```

播放直链：

```json
{
  "code": 200,
  "data": [
    { "id": 186016, "url": "https://m801.music.126.net/....flac", "br": 999, "level": "exhigh" }
  ]
}
```

---

## 关于两个“坑”

### 1. 地区限制（部署在境外服务器必读）

网易对**播放直链、登录类**接口按来源地区放行。境外服务器直接请求会拿到：

```json
{ "code": 404, "url": null }
```

本项目的做法是自动带上伪造的境内 IP 头：

```php
define('NCM_REAL_IP', '116.25.146.177');
```

你可以替换成任意境内的公网 IP。**注意：这并非万能**，部分接口（尤其登录类）仍可能被网易风控返回空响应 —— 这也是“扫码/手机号登录在境外服务器上经常失败”的原因，建议对登录功能使用境内服务器或直接透传已有 Cookie。

### 2. 混合内容（HTTPS 页面必读）

网易 CDN 返回的封面 / 音频地址默认是 `http://`，在 HTTPS 页面里会触发浏览器安全告警。本项目在输出前统一升级：

```php
$json = str_replace('http://', 'https://', $json);
```

---

## 登录与 Cookie

涉及账号的接口（`/login/status`、`/user/playlist`）通过 `cookie` 参数透传登录态：

```
/ncm-api.php/login/status?cookie=MUSIC_U=xxxxx
/ncm-api.php/user/playlist?uid=123456&cookie=MUSIC_U=xxxxx
```

扫码登录接口（`/login/qr/*`）已实现，但受上述地区风控影响，**建议仅在境内服务器使用**。

---

## 自动解灰（四条线路）

VIP / 无版权 / 下架的歌，API 会**自动换源**拿到能播的直链：

```
官方直链（带伪装 IP + 可选登录 Cookie）
    |-- 成功 -> 返回
    |-- 失败 -> 取「歌名 + 歌手 + 时长」
              |-> 酷我  搜候选 -> 打分排序 -> 依次试直链
              |-> 酷狗  同上
              |-> QQ音乐  同上
              |-> 咪咕    同上
              |-> 全失败 -> 官方外链兜底
```

算法对齐开源项目 **UnblockNeteaseMusic** 的 provider 实现（酷我 / 酷狗 / QQ / 咪咕 四条线路）并且从中提取并移植为纯 PHP。

说明：

- 匹配打分：歌名 0.72 + 歌手 0.28，再按**时长接近度**加减分，低于 0.55 丢弃
- 每个源最多试 4 个候选，取不到就自动换下一个源
- 高音质（`quality=flac`）会优先要无损，拿不到自动退回 mp3
- QQ 音乐未登录时 vkey 可能拿不到（VIP 歌），可配 `define('XUB_QQ_UIN', '你的uin')` 或改用其它线路

### 接口

```
/song/url/v1?id=186016&cookie=<网易Cookie>    播放（自动解灰）
/song/url/match?id=347230                     解灰（只返回直链字符串）
/unblock/probe                                音源自检
```

独立解灰服务：

```
/ncm-unblock.php?id=186016
/ncm-unblock.php?name=晴天&artist=周杰伦&quality=flac
/ncm-unblock.php?probe=1                      音源自检
```

### 地区实测

| 音源 | 境外服务器 | 说明 |
|---|---|---|
| 网易官方（带 `X-Real-IP`） | 部分可用 | 免费歌直链可出，VIP 需登录 Cookie |
| 酷我 | 搜索可用，直链 `IPDeny` | 境内服务器正常 |
| 酷狗 | **可用** | 境外也能解灰 |
| QQ 音乐 | 搜索可用，vkey 需登录 | 境内未登录也可能拿不到 |
| 咪咕 | 境外不可用 | 境内服务器正常 |

所以：

- **境内服务器**：四条线路全通，命中率最高
- **境外服务器**：靠官方（伪装 IP）+ 酷狗，仍能解灰大部分歌曲

部署后先访问一次 `/unblock/probe` 看当前服务器各源状态。

## 免责声明

- 本项目仅供学习与个人研究使用，所有音乐数据均来自网易云音乐的公开接口
- 请勿用于商业用途，请支持正版音乐
- 使用本项目产生的任何法律风险由使用者自行承担

---

## License

[MIT](LICENSE)
