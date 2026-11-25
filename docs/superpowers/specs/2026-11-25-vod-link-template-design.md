# VOD プラットフォームのリンクテンプレート設計

> プラットフォームに「アフィリエイトリンクの組み立て方（リンクテンプレート）」を持たせ、
> 購入リンクに作品ページの URL（`regular_url`）だけを入れればカードがアフィリエイトリンクを出せるようにする。
> あわせて VOD（動画配信）の既定プラットフォームを、作品単位のアフィリエイトリンクを持てるサービスに入れ替える。
>
> **確定日: 2026-11-25**（利用側フィルムログで ASP 5 社の管理画面を調査し、リンク形式を実測したうえで確定）

---

## 1. 背景と目的

- `VodType` と VOD の既定プラットフォーム（u-next / netflix / hulu / prime-video / danime）は仮置きのままで、
  「そのサービスで作品単位のアフィリエイトリンクが作れるか」を考慮していない。
- 作品単位のリンクを作れるかどうかは ASP とプログラムで決まり、**リンクの形もプログラムごとに違う**（§2）。
  いまの affilicard は購入リンクごとに `affiliate_url` を完成形で持つしかなく、
  ASP の乗り換えやパラメータ変更のたびに全商品を書き換える必要がある。
- **目的**:
  1. プラットフォームにリンクテンプレートを持たせ、`regular_url`（作品ページ）からアフィリエイトリンクを表示時に組み立てる
  2. VOD の既定プラットフォームを「作品単位のリンクを持てるサービス」に揃える

## 2. 一次情報（2026-11-25 に各 ASP の管理画面で確認）

### 2-1. 配信サービスの分類

| 分類 | 意味 | サービス |
| --- | --- | --- |
| A | 作品単位のアフィリエイトリンクを作れる → **affilicard で扱う** | Prime Video / U-NEXT / Hulu / DMM TV |
| B | プログラムはあるがリンク先が入会 LP に固定 → affilicard では扱わない | Disney+ / ABEMA / dアニメストア / WOWOW |
| C | アフィリエイトプログラムが無い → affilicard では扱わない | Netflix / Lemino / FOD / TELASA |

B・C は利用側で「配信情報」としてテキストや入会リンクで扱う（商品カードの責務ではない）。

### 2-2. A のリンク形式（実測）

| サービス | 経路 | リンク形式 | テンプレート化 |
| --- | --- | --- | --- |
| Prime Video | Amazon アソシエイト | `https://www.amazon.co.jp/gp/video/detail/{ASIN}?tag={トラッキングID}` | ✔（クエリ追記） |
| Hulu | アクセストレード 商品リンク | `https://h.accesstrade.net/sp/cc?rk={固定コード}&url={作品URLをエンコード}`。`rk` は作品を変えても同じ。遷移先で計測パラメータが付くことを確認 | ✔（ラップ） |
| Hulu | afb 商品リンク | `https://t.afi-b.com/visit.php?a={リンクごとの ID}&p=...`。作品 URL を含まない | ✗（リンクごとに発行） |
| DMM TV | DMM アフィリエイト リンク作成ツール | `https://al.dmm.com/?lurl={作品URLをエンコード}&af_id={リンク用ID}&ch=link_tool&ch_id=link`（`tv.dmm.com` 配下に対応） | ✔（ラップ） |
| U-NEXT | afb 商品リンク | `https://www.video.unext.jp/title/SIDxxxx` を元に afb が発行。形式は afb の他プログラムと同じくリンクごとの ID と見込む | ✗（作品ごとに上書き） |

- ASP がリンク生成 API を出しているものは無い（afb・A8 の API は成果取得のみ）。
- **ASP 側にはリンクを組み立てる API が無いため、決まった形で組み立てられるかどうかが自動化の可否を決める。**
- アクセストレードの管理画面には「リンクコードの改変禁止」の注記がある。テンプレートが組み立てるのは
  公式ツールの出力と同一の文字列だが、解釈が分かれるため利用側で ASP に確認する。affilicard の設計は
  どちらに転んでも変わらない（§6）。

## 3. スコープ

| 対象 | 内容 |
| --- | --- |
| **本 PR** | `PlatformDefinition` に `linkTemplate` / `linkHosts`、`OfferUrl` のテンプレート解決、呼び出し元 3 箇所、既定 VOD プラットフォームの入れ替え、`VodType` に公開年、設定画面の入力欄、REST の入力検証、テスト一式 |
| 対象外 | VOD の Provider（作品情報の自動取得）、配信終了の棚卸し、カード画像の比率変更、B・C サービスの扱い |

## 4. 設計

### 4-1. 案の比較

| 案 | 内容 | 良い点 | 悪い点 |
| --- | --- | --- | --- |
| **A. 表示時に組み立てる（採用）** | プラットフォームにテンプレートを持たせ、`OfferUrl` が `affiliate_url` → テンプレート → `regular_url` の順で解決する | ASP を替えても設定 1 箇所で全カードに即反映。保存データは作品 URL のままで移行が要らない | `OfferUrl` の引数が増え、呼び出し元 3 箇所を直す |
| B. 保存時に組み立てる | 保存・更新時にテンプレートの結果を `affiliate_url` へ書き込む（`DmmProvider` と同じ） | 表示側は無変更 | ASP 変更で全商品の再保存が要る。手入力の上書きと自動生成の値が区別できない |
| C. サービスごとの Provider | 更新キューに乗せて組み立てる | 既存の仕組みに乗る | VOD は取得する情報が無く、キューを回すだけ無駄 |

### 4-2. `PlatformDefinition` の追加項目

| 項目 | 型 | 既定 | 意味 |
| --- | --- | --- | --- |
| `linkTemplate` | string | `''` | アフィリエイトリンクの組み立て方。空ならテンプレートを使わない |
| `linkHosts` | list\<string\> | `[]` | テンプレートを適用してよい作品 URL のホスト。空ならテンプレートを使わない |

テンプレートの差し込み口は 3 つだけ:

| 差し込み口 | 置き換わる値 |
| --- | --- |
| `{url}` | 作品 URL（`regular_url`）そのまま |
| `{url_enc}` | 作品 URL を `rawurlencode()` したもの |
| `{sep}` | 作品 URL にクエリがあれば `&`、無ければ `?`。クエリが空（`?` で終わる）か `&` で終わっていれば空（`??` を作らない） |

例:

```text
Prime Video : {url}{sep}tag=example-22
Hulu (AT)   : https://h.accesstrade.net/sp/cc?rk=XXXX&url={url_enc}
DMM TV      : https://al.dmm.com/?lurl={url_enc}&af_id=example-001&ch=link_tool&ch_id=link
```

**フラグメント（`#` 以降）**: テンプレートが `{url}` で始まる「クエリ追記型」では、作品 URL をフラグメント抜きで
差し込み、フラグメントは最後に戻す（`#` の後ろにパラメータを足すとクエリにならずサーバへ送られない）。
`{url_enc}` で包む型は作品 URL をフラグメントごと渡す。`{sep}` の判定もフラグメントを除いて行う。

**ホストの照合**: 作品 URL のホストが `linkHosts` のいずれかと一致するか、その末尾が `.` + ホストに一致すれば適用する
（`hulu.jp` は `www.hulu.jp` にも当たる）。スキーム省略の `//host/...` もホストを照合する（PHP の `wp_parse_url()` と同じ）。一致しなければテンプレートを使わず `regular_url` に倒れる。
別サイトの URL を誤って登録したときに、それがアフィリエイトリンクとして出るのを防ぐため。
`linkHosts` を空にしてテンプレートだけ入れても適用しない（誤適用を防ぐ側に倒す）。

### 4-3. URL の解決順（`OfferUrl`）

```text
1. affiliate_url（作品ごとの上書き。リンクごとに ID を発行する ASP 用）
2. linkTemplate で regular_url から組み立てた URL（ホストが linkHosts に一致するときだけ）
3. regular_url
```

- `OfferUrl::ctaHref( array $offer, ?PlatformDefinition $platform = null )` と
  `OfferUrl::isRegularUrlFallback( array $offer, ?PlatformDefinition $platform = null )` にする。
  `null` なら従来どおり（テンプレートを考えない）。
- 2 で決まったものは**フォールバックではない**。3 まで落ちたときだけ、従来どおり一覧の警告列と
  ダッシュボードの件数に出す。テンプレートの無い U-NEXT で上書きを入れ忘れた商品は、ここで見つかる。
- 組み立てた結果も `esc_url_raw()` を通す。空になった（危険スキーム等）ら 3 に倒れる。
- 呼び出し元 3 箇所（`CardRenderer` の 2 箇所・`ProductRepository::hasFallbackListing()`・
  `ProductListColumns::renderFallbackColumn()`）は、listing の `platform` から `PlatformDefinition` を引いて渡す。
  **3 箇所が同じ規則で判定する**という `OfferUrl` の存在理由を保つ。

### 4-4. 入力検証（`PlatformDefinition::fromArray()`）

REST・オプション読み込みの両方がここを通るので、検証はここに置く。

- `linkTemplate`: 前後空白を除去。`https://` または `{url}` で始まり、`{url}` か `{url_enc}` を含むものだけ受け付ける。
  それ以外は空にする（保存はするが使わない＝安全側）。
- `linkHosts`: 文字列の配列。各要素を小文字化・前後空白除去し、スキームやパスが付いていれば落としてホストだけ残す。
  空要素と重複は除く。

### 4-5. 既定プラットフォームの入れ替え（`PlatformConfig::defaults()`）

| code | 表示名 | `linkHosts` | 変更 |
| --- | --- | --- | --- |
| `u-next` | U-NEXT | `video.unext.jp` | ホストを追加 |
| `hulu` | Hulu | `hulu.jp` | ホストを追加 |
| `prime-video` | Prime Video | `amazon.co.jp` | ホストを追加 |
| `dmm-tv` | DMM TV | `tv.dmm.com` | **新規** |
| `netflix` | — | — | **削除**（アフィリエイト無し） |
| `danime` | — | — | **削除**（入会 LP 固定） |

- `linkTemplate` は既定では空。トラッキング ID はサイトごとに違うため、汎用プラグインの既定値には入れない
  （管理者が設定画面で入れる）。
- 既定値が使われるのは初回有効化時の seed だけ。**既に有効化済みのサイト（オプション保存済み）には影響しない**。
  既存サイトで netflix / danime を使っている商品があってもそのまま動く。

### 4-6. `VodType` に公開年

`extrasSchema()` に `release_year`（ラベル「公開年」、`card: 'header'`）を足す。
利用側は作品を「タイトル + 公開年」で同定するため、カードにも出せるようにする。

### 4-7. 設定画面（`PlatformEditor`）

「アフィリエイトリンクの組み立て」小見出しの下に 2 項目を足す:

- リンクテンプレート（`TextControl`）。ヘルプに差し込み口 3 つと例を出す
- 対象ドメイン（カンマ区切りの `TextControl`。入力のたびに配列にして親へ渡す。入力中の文字列は保ち、保存後にサーバが正規化した値が返ったら入力欄へ反映する）

### 4-8. 商品編集画面（`ListingsEditor`）

購入リンクの「フォールバック中」の警告も同じ規則にそろえる。`src/Admin/linkTemplate.js` に PHP と同じ判定
（`isUsableLinkTemplate` / `canApplyLinkTemplate`）を置き、テンプレートで組み立てられる購入リンクには警告の代わりに
「リンクテンプレートで通常 URL から組み立てます」と出す。

## 5. テスト

| 種別 | 内容 |
| --- | --- |
| PHPUnit | `PlatformDefinition` の往復と検証（不正テンプレート・ホスト正規化）／`OfferUrl` の解決順・ホスト照合・`{sep}`・エンコード・危険スキーム・`null` 互換／既定プラットフォーム／呼び出し元 3 箇所がテンプレートを考慮すること／`VodType` の schema |
| Jest | `PlatformEditor` の 2 項目の表示と `onChange`（ドメインのカンマ区切り ↔ 配列）／`linkTemplate.js` の判定／`ListingsEditor` の警告の出し分け |
| E2E（Playwright） | REST で hulu にテンプレートを保存 → 作品 URL だけの VOD 商品をブロックで描画 → CTA の href が組み立てた URL になること。ホスト不一致の URL は `regular_url` のままになること |

## 6. この設計が破綻する条件

- 主力の ASP がリンクごとの ID 発行に寄っていくと、上書き（`affiliate_url`）が例外ではなく標準になる。
  動作はするがテンプレートの価値が薄れる。
- 作品 ID を抜き出して別形式の URL に組み直す必要があるサービスが出たら、3 つの差し込み口では足りない。
  そのときはプラットフォームごとの PHP の組み立てクラス（Provider に近い拡張点）が要る。
- アクセストレードがテンプレートでの組み立てを認めない場合、Hulu は afb か CSV 一括作成の上書き運用になる。
  設計は変わらない（テンプレートを空にして `affiliate_url` を入れるだけ）。
