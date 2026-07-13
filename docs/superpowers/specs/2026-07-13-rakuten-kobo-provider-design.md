# 楽天Kobo 自動取得 Provider（RakutenProvider）設計

> affilicard に **楽天Kobo 電子書籍検索 API** を使った自動取得 Provider を追加する。価格・書影・作品URL・アフィリエイトURL・配信日を取得し、既存の `DmmProvider` と同じ `ProviderInterface` 契約に沿って実装する。
>
> 楽天は 2026 年に API インフラを刷新し、新ゲートウェイ `openapi.rakuten.co.jp` の認証が厳格化された（`accessKey` 必須＋`Origin`/`Referer` ヘッダー必須）。本 Provider はこの新仕様に対応する。
>
> **確定日: 2026-07-13**（brainstorming セッションで確定・実 API 疎通確認に基づく）

---

## 1. 背景と目的

affilicard は自動取得 Provider を `ProviderInterface`（`code`/`label`/`isAutomatic`/`fetch`/`credentialsSchema`/`testConnection`）で抽象化しており、現状 `ManualProvider`（手動）と `DmmProvider`（DMM Web Service）を持つ。

楽天Kobo は主要な電子書籍ストアであり、**楽天Kobo 電子書籍検索 API** で価格・書影・配信日・アフィリエイトURL を取得できる。これを Provider として実装することで、利用側は楽天Kobo リスティングの価格・書影を自動取得・更新できるようになる。

**楽天の 2026 年 API 刷新で判明した実装上の要点**（実 API で疎通確認済み）:

1. **新ゲートウェイ `openapi.rakuten.co.jp`**（旧 `app.rakuten.co.jp` は新形式 applicationId を受け付けない）。
2. **`accessKey` が必須**（クエリパラメータで送る）。`applicationId` 単独では 400。
3. **`Origin` ヘッダー（許可ドメイン）が必須**。`Referer` だけだと 403 `REQUEST_CONTEXT_BODY_HTTP_REFERRER_MISSING`。`Origin` を付けて初めて 200。許可ドメインは楽天アプリ登録の許可リファラに一致する必要がある。
4. `affiliateId` を付与すると `affiliateUrl`（`hb.afl.rakuten.co.jp` 形式）が返る。

WordPress の `wp_remote_get()` は `headers` 引数で任意ヘッダーを送れるため（`Origin`/`Referer` の送出制約は無い）、affilicard の既存 HTTP 呼び出し方式（`DmmProvider` と同じ）で対応可能。

**目的**: `DmmProvider` を踏襲した単一クラスの `RakutenProvider` を新設し、楽天Kobo の自動取得を最小差分・既存パターン一致で実現する。

---

## 2. スコープ

### スコープ内

- `src/Provider/Rakuten/RakutenProvider.php` 新設（`Affilicard\Provider\Rakuten\RakutenProvider implements ProviderInterface`）。
- `src/Plugin.php` の `buildProviderRegistry()` に `register( new RakutenProvider() )` 追加。
- 設定画面の credentials 入力（`credentialsSchema()` から自動描画される既存機構に載る）。
- テスト（PHPUnit / WP_Mock）追加。CHANGELOG ＋ **v1.9.0** ＋ Release（`affilicard.php` Version ヘッダ同期）。

### スコープ外

- **autoCreate への `release_date` 配線**（現状 `ListingRefresher` は fetch 結果から price/list_price/badge/image_url/regular_url/affiliate_url のみリスティングへ反映し、`release_date` は商品作成時の meta。配信日を商品作成に流す配線は既存 DMM も未対応＝別 follow-up）。
- **共通 HttpClient の抽出**（`DmmProvider` と `RakutenProvider` の `wp_remote_get` 定型を DRY 化する refactor。Provider 2 つでは YAGNI・将来 follow-up）。
- **429 レート制限のリトライ/backoff**（MVP は 429 を失敗として扱う）。
- **AmazonProvider**（PA-API/Creators API 承認後に別途）。
- 利用側プロジェクトのパイプライン改修（affilicard の範囲外）。

---

## 3. API 仕様（実測）

- **エンドポイント**: `https://openapi.rakuten.co.jp/services/api/Kobo/EbookSearch/20170426`
- **必須クエリ**: `applicationId` / `accessKey` ＋ 検索キー1つ以上（`keyword` / `title` / `author` / `publisherName` / `itemNumber` / `koboGenreId`）
- **任意クエリ**: `affiliateId`（付与で `affiliateUrl` 生成）/ `format=json` / `formatVersion=2` / `hits`
- **必須ヘッダー**: `Origin`（許可ドメイン）。`Referer` も併せて送る。
- **レスポンス item の主なフィールド**（`formatVersion=2`）:

| フィールド | 用途 | 例（値の形） |
| --- | --- | --- |
| `title` | タイトル | 文字列 |
| `itemPrice` | 価格 | 数値（円） |
| `itemNumber` | Kobo 商品番号（13桁数値） | `8913122576600` |
| `salesDate` | 配信日 | **和暦風文字列** `YYYY年MM月DD日` |
| `itemUrl` | 作品URL | 文字列 |
| `affiliateUrl` | アフィリエイトURL | `affiliateId` 指定時のみ |
| `largeImageUrl` / `mediumImageUrl` / `smallImageUrl` | 書影 | 文字列 |
| `seriesName` / `author` / `publisherName` | 補助情報 | 文字列 |

- **割引/定価フィールドは無い**（`itemPrice` のみ）＝楽天Kobo は買い切りで、検索 API に定価/割引は含まれない。

---

## 4. 実装設計

### 4-1. Provider 基本

- `code()` = `'rakuten-kobo'`
- `label()` = `'楽天Kobo API'`
- `isAutomatic()` = `true`

### 4-2. credentialsSchema

| key | label | type | required |
| --- | --- | --- | --- |
| `application_id` | アプリID（applicationId） | password | ✅ |
| `access_key` | アクセスキー（accessKey） | password | ✅ |
| `affiliate_id` | アフィリエイトID | password | ✅ |
| `allowed_domain` | 許可ドメイン（Origin。空ならサイトURL） | text | 任意 |

- `affiliate_id` は `affiliateUrl` 生成に必須のため required。
- `allowed_domain` は Origin/Referer の override。空なら `home_url()` を使う（後述）。

### 4-3. Origin/Referer ドメインの解決

```
$domain = trim( $credentials['allowed_domain'] ?? '' );
if ( '' === $domain ) {
    $domain = home_url();  // サイト自身の URL
}
// スキーム付き URL に正規化し、Origin=スキーム+host、Referer=URL+'/' を送る
```

- 既定は WP サイト自身の URL（`home_url()`）。楽天アプリの許可リファラをサイトのドメインで登録していれば設定不要。
- プロキシ/サブドメイン/apex 差異など home_url と登録ドメインが食い違う場合は `allowed_domain` で上書きできる。

### 4-4. externalId の解釈（itemNumber 優先・keyword 代替）

- `preg_match( '/^\d+$/', $externalId )` が真 → クエリ `itemNumber=<externalId>`（Kobo の itemNumber は 13 桁数値＝一意確定）。
- 偽 → クエリ `keyword=<externalId>`（緩い代替。`hits=1` の先頭を採用）。
- `'' === $externalId` → `null`。

### 4-5. fetch() の返却マッピング（normalizeItem）

| 返却 key | 値 |
| --- | --- |
| `title` | `title` |
| `price` | `(string) itemPrice` |
| `list_price` | `''`（割引情報なし） |
| `badge` | `''`（割引情報なし） |
| `image_url` | `largeImageUrl` ‖ `mediumImageUrl` ‖ `smallImageUrl`（先に非空のもの） |
| `regular_url` | `itemUrl` |
| `affiliate_url` | `affiliateUrl` |
| `platform_extras` | `{ release_date, series_name, author, publisher }` |
| `raw` | item 全体 |

- `platform_extras.release_date` = `normalizeDate( salesDate )`：`YYYY年MM月DD日` → `YYYY-MM-DD`（`ProductRepository`/`ProductSchema` が要求する形式）。解析不能なら空文字。
- `platform_extras` の series_name/author/publisher は補助情報として保持（利用は将来 follow-up）。

### 4-6. testConnection

- 中立キーワードで `hits=1` 検索し、Origin/Referer を付与して呼ぶ。
- 判定: WP_Error でない ＋ HTTP 200 ＋ JSON 解釈可 ＋ body に `errors` キーが無い → `ok: true`。
- エラー時は切り分けメッセージ:
  - 403（`REQUEST_CONTEXT_BODY_HTTP_REFERRER_MISSING` 等） → 「許可ドメイン（Origin）が楽天アプリの登録と一致しているか確認してください」
  - 400（accessKey 不足） → 「アクセスキーを確認してください」
  - 429 → 「レート制限に達しました。時間をおいて再試行してください」

### 4-7. エラーハンドリング（fetch は `null` 返却）

以下はいずれも `null`:

- credentials 欠落（application_id / access_key / affiliate_id のいずれか空）
- `externalId` 空
- WP_Error（通信失敗）
- 非 200
- JSON 解釈失敗
- `Items` が空
- body に `errors` キー
- **429（MVP はリトライせず失敗扱い）**

---

## 5. テスト（PHPUnit / WP_Mock・TDD）

- `credentialsSchema()` の shape（4 フィールド・required フラグ）。
- `testConnection()`: 成功（200・正常 body）／各失敗（WP_Error・403・400・429・JSON 不正・`errors` 有り）。
- `fetch()`:
  - **externalId が数値 → `itemNumber` クエリ**、非数値 → `keyword` クエリ（送信 URL を検証）。
  - **`Origin`/`Referer` ヘッダー送出**（`allowed_domain` 空 → home_url()／指定 → その値。`wp_remote_get` の args を検証）。
  - `normalizeItem` マッピング（price／`list_price`・`badge` は空／画像優先順 large→medium→small／affiliate_url／`platform_extras.release_date` の日付正規化）。
  - null 系（creds 欠落・externalId 空・非200・空 Items・`errors`・429）。
- `normalizeDate()` エッジ（`YYYY年MM月DD日` の正常／不正フォーマット→空／空入力→空）。

外部 API はすべて `wp_remote_get` のモックで再現（実通信しない）。

---

## 6. リリース

- 新機能＝**affilicard v1.9.0**。CHANGELOG 追記。
- `affilicard.php` の `Version:` ヘッダをコミット同期（PUC はタグのツリーのヘッダを読むため、未同期だと自動更新が検知されない）。`release.yml` の Version ガードに従う。
- 実装 PR は自動マージせず、PR プレビュー（Playground）で設定画面の credentials 入力・`testConnection` の挙動を確認してからマージ。

---

## 7. 後続（follow-up）

- `platform_extras.release_date` を autoCreate（商品作成）に配線し、配信日を予約カードに反映（DMM も未対応＝共通課題）。
- `DmmProvider`／`RakutenProvider` 共通の HTTP クライアント抽出。
- 429 リトライ/backoff。
- AmazonProvider（PA-API/Creators API 承認後）。
