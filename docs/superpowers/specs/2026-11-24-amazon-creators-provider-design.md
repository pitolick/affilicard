# Amazon Creators API 自動取得 Provider（AmazonProvider）設計

> affilicard に **Amazon Creators API**（PA-API 5 の後継）を使った自動取得 Provider を追加し、
> あわせて Amazon のライセンスが求める**表示上の決まり**をカードに入れる。
>
> **確定日: 2026-11-24**（利用側 e-comi で売上要件を満たし、`getItems` の 200 を実測したうえで確定）

---

## 1. 背景と目的

- Creators API は、アソシエイトの売上要件（直近 30 日の適格販売）を満たすまで `getItems` が
  `403 AssociateNotEligible` を返す。2026-11-24 に要件を満たし、cloud 環境から 200 で
  価格・書影・付与ポイントが返ることを確認した。
- affilicard には DMM / 楽天の Provider はあるが Amazon が無い。`amazon-kindle` platform は
  `provider: 'manual'`・`eligibleProvider: ''` のままで、管理画面から自動取得に切り替えられない。
- **目的**: Amazon Kindle の listing を DMM / 楽天と同じ自動更新キューに乗せ、価格・書影・
  リンクを自動で埋める。表示はアソシエイトのライセンスに準拠させる。

## 2. 一次情報（2026-11-24 に実ブラウザで確認）

| 出典 | 要点 |
| --- | --- |
| [Amazon.co.jp Product Advertising API ライセンス契約](https://affiliate.amazon.co.jp/help/operating/paapilicenseagreement)（Creators API の日本向けライセンス。[License Agreement](https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/license-agreement) から参照される） | (f) 商品関連コンテンツの追加・削除・変更の禁止（テキストの短縮は可）／(g) Amazon に無くなったコンテンツは速やかに削除／(n) 画像の保存・キャッシュ禁止、画像へのリンクと画像以外のコンテンツは最長 24 時間、超えたら直ちに取り直して再表示／(o) 更新が 1 時間に 1 回以下なら価格の横に日時、加えて免責文言（隣接 or リンク・ポップアップ）／(q) テキストを表示するなら提供元の免責文言をはっきり見える所に |
| [API Rates](https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/concepts/api-rates) | 最初の 30 日は 1 TPS / 8,640 TPD。以降は **Creators API 経由の発送売上** 5 セントごとに 1 TPD（TPS は $4,320 ごとに 1、最大 10）。`getItems` に 10 ASIN を入れても 1 トランザクション。30 日連続で適格販売が無いとアクセスを失い、売上発送後 2 日以内に戻る |
| [Best Programming Practices](https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/concepts/best-programming-practices) | トークン（1 時間有効）はプロセス間で共有して使い回す／複数 ASIN をまとめる／返ってきたリンクのパラメータを改変しない |
| [Using cURL](https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/get-started/using-curl) / [Common Request Headers](https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/concepts/common-request-headers-and-parameters) | トークン取得先は認証情報のリージョン（NA 3.1: `api.amazon.com` / EU 3.2: `api.amazon.co.uk` / FE 3.3: `api.amazon.co.jp`）。API は全リージョン `https://creatorsapi.amazon`。`Authorization: Bearer` と `x-marketplace` ヘッダー必須 |
| [OffersV2](https://affiliate.amazon.co.jp/creatorsapi/docs/en-us/api-reference/resources/offersV2) | `savingBasis` は割引があるときだけ返る。`savingBasisTypeLabel` は「基準価格の横に出せるラベル」 |

旧 `webservices.amazon.co.jp/paapi5/documentation/` は S3 の `AccessDenied` を返す（PA-API 廃止に伴う撤去。ボット対策ではない）。

**実測（2026-11-24・e-comi の認証情報）**: トークンは `api.amazon.com` / `api.amazon.co.jp` のどちらでも 200。
Kindle 単巻は `offersV2.listings[].price`・`savingBasis`（`WAS_PRICE` / ラベル「過去価格:」）・`savings.percentage`・
`loyaltyPoints.points` が返る。作品のまとめページ（例 `B09479PJ3T`）は書影とタイトルのみで価格なし。
Prime Video の ASIN は item 単位の `ItemNotAccessible`。

## 3. スコープ

| PR | 内容 | 本番への影響 |
| --- | --- | --- |
| **PR1** | `AmazonAccount`・`AmazonCreatorsClient`・`AmazonProvider`・まとめ取得（§4-3）・`amazon-kindle` の `eligibleProvider` 補完 | なし（`amazon-kindle` は手動のまま。管理者が切り替えるまで何も起きない） |
| **PR2** | 表示上の決まり（§5）：価格の免責文言・提供元の表記・書影 24 時間・基準価格ラベル | Amazon が自動取得の listing を持つカードだけ |

**PR1 と PR2 は同じバージョンでリリースする**。PR1 だけを出すと、管理者が切り替えた時点で
ライセンス非準拠の表示（免責文言なし・期限切れ書影の表示）が始まるため。

対象外（別 PR）: 付与ポイントの表示、成人向けフラグの反映、`dealDetails`（セール期間）の利用。
ただし**これらのためにリクエストを増やさない**（§4-4）。

## 4. PR1: 取得側

### 4-1. 構成

```text
src/Account/AmazonAccount.php                  code 'amazon'
src/Provider/BatchFetchingProvider.php         任意インターフェース（まとめ取得）
src/Provider/Amazon/AmazonCreatorsClient.php   トークン取得・キャッシュ・getItems の HTTP
src/Provider/Amazon/AmazonProvider.php         code 'amazon-creators'。応答→listing フィールドの変換
```

`AmazonAccount::credentialsSchema()`:

| key | 種別 | 必須 | 備考 |
| --- | --- | --- | --- |
| `credential_id` | text | ✓ | Creators API の Credential ID |
| `credential_secret` | password | ✓ | Credential Secret |
| `partner_tag` | text | ✓ | トラッキング ID（例 `xxx-22`） |
| `marketplace` | text | | 空なら `www.amazon.co.jp` |

トークン取得先は `marketplace` のリージョン（§2 の表）から決める。認証情報のバージョンは
通常ストアのホームリージョン＝マーケットプレイスのリージョンと一致するため、入力欄は増やさない。

### 4-2. トークン

- `POST https://{token_host}/auth/o2/token`（JSON: `grant_type=client_credentials` / `client_id` / `client_secret` / `scope=creatorsapi::default`）
- **WP transient に `Crypto` で暗号化して保存**し、`expires_in - 60` 秒まで使い回す。キーには
  credential_id のハッシュを含め、認証情報を差し替えたら古いトークンを使わない。
- `getItems` が 401 を返したら transient を消して 1 回だけ取り直す。

### 4-3. まとめ取得（最大 10 ASIN / リクエスト）

e-comi の本番には商品が 2,793 件あり、Amazon listing はその大半に付く。1 ASIN ずつだと
24 時間ごとの更新で約 2,500 回/日になり、最初の 30 日以降は売上次第で上限を超える。
10 件まとめなら約 250 回/日。

**`ProviderInterface` は変えない**。任意インターフェースを足す:

```php
interface BatchFetchingProvider {
	public function maxBatchSize(): int;                 // Amazon は 10
	/** @param list<string> $externalIds */
	public function prefetch( array $externalIds ): void; // 1 リクエストで取り、結果（hit/miss/error）を ID ごとに覚える
	public function hasPrefetched( string $externalId ): bool;
	public function clearPrefetched(): void;
}
```

- `fetch( $id )` は覚えた結果があればそれを返す（HTTP しない）。無ければ従来どおり 1 件で取る
  （per-listing の `RefreshHandler` 経路・管理画面の「今すぐ更新」）。
- **一時失敗も覚える**。まとめ取得が 429 等で失敗したとき、各 `fetch()` が個別に叩き直すと
  レート制限をすり抜けるため。覚えた一時失敗は既存どおり per-listing へ落ち、backoff に乗る。
- 覚えておくのは **1 ジョブの処理中だけ**（`BatchRefreshHandler::handle()` の開始時と終了時に
  `clearPrefetched()`）。数秒のメモリ保持で、ライセンスの保存期限（24 時間）には触れない。

`BatchRefreshHandler` の変更:

1. account の Provider が `BatchFetchingProvider` なら、各 item の外部 ID を
   `ListingRefresher::fetchTargetExternalIds()`（新設。`targetCount()` と同じ選択ロジックを共有し、
   `targetCount()` はその件数を返すだけにする）で求める。
2. その item の外部 ID がすべて取得済みなら、**レート枠を取らずに** `refreshOne()` する。
3. そうでなければ、従来どおりレート枠を 1 つ取り、**この item から先の最大 `maxBatchSize` 件ぶん**の
   外部 ID をまとめて `prefetch()` してから `refreshOne()` する。
4. 結果（SUCCESS / TRANSIENT / TERMINAL）の扱いは従来と同じ（共通メソッドへ切り出す）。

`QueueMaintenance::batchSizeFor()` は、まとめ取得できる Provider では件数に `maxBatchSize()` を掛ける
（1 ジョブ 22 件 → 220 件。1 ジョブあたりの API 呼び出しは 22 回のまま）。

### 4-4. 1 回のリクエストで取りきる

`getItems` の `resources` は**付与ポイント・成人向けフラグ・セール情報まで最初から指定する**。

```text
images.primary.large
itemInfo.title / itemInfo.byLineInfo / itemInfo.contentInfo / itemInfo.productInfo
offersV2.listings.price / offersV2.listings.isBuyBoxWinner / offersV2.listings.loyaltyPoints
offersV2.listings.dealDetails / offersV2.listings.availability / offersV2.listings.type
```

後続の PR（ポイント表示・成人向けフラグ）は、同じ応答から読むフィールドと保存先を足すだけで、
**リクエストの回数・種類は増やさない**。`raw` は現状どこにも保存されない点に注意（読みたい値は
正規化して listing へ載せる）。

### 4-5. 応答 → listing フィールド

| フィールド | 値 |
| --- | --- |
| `title` | `itemInfo.title.displayValue` |
| `price` | `offersV2.listings[]` の `isBuyBoxWinner` が真のもの（無ければ先頭）の `price.money.amount` |
| `list_price` / `badge` | **PR1 では入れない**（基準価格のラベルを出せるようになる PR2 で入れる） |
| `image_url` | `images.primary.large.url`（ホットリンク。保存しない） |
| `regular_url` | `https://{marketplace}/dp/{ASIN}` |
| `affiliate_url` | `detailPageURL`（タグ付き。**改変しない**） |
| `platform_extras` | `release_date`（`productInfo.releaseDate` の日付部分）・`author`（`byLineInfo.contributors` の Author）・`publisher`（`byLineInfo.manufacturer`） |

**価格と書影は、返らなかったら空文字にする**（既存値を残さない）。`ListingRefresher` は取得成功で
`last_verified_at` を更新するため、残すと古い値が「今確認した値」として表示される。Amazon の値は API からしか
入らず、ライセンス (g) も Amazon に無くなったコンテンツの速やかな削除を求めている。

### 4-6. 結果の分類（FetchResult）

| 状況 | 分類 | 理由 |
| --- | --- | --- |
| 認証情報が未設定 | `error()` | 後で設定され得る |
| トークン取得失敗・通信断・429・5xx・JSON でない | `error()` | 一時的 |
| **403**（`AssociateNotEligible` を含む） | `error()` | 売上要件は 30 日の窓で出入りする。恒久失敗にすると全 listing を give-up し、復帰しても戻らない |
| 200 で items に該当 ASIN あり | `hit()` | 価格が無い（まとめページ等）ときも hit。返った分だけ更新する |
| 200 で該当 ASIN が `errors[]` 側（`ItemNotAccessible` / `InvalidParameterValue` 等）か、どこにも無い | `miss()` | 取り直しても取れない |
| 外部 ID が空 | `miss()` | データ不備 |

`minRequestIntervalMs()` は 1100（1 TPS ＋余裕）。

### 4-7. platform の補完

- `PlatformConfig::defaults()` の `amazon-kindle` に `eligibleProvider: 'amazon-creators'` を足す（`provider` は `manual` のまま）。
- 既存インストールは `defaults()` が効かないため、`amazon-kindle` の `eligibleProvider` が空なら
  `'amazon-creators'` を補完する一度きりの処理を足す（既存の `affilicard_eligible_provider_backfilled`
  は立っているので別フラグ）。

### 4-8. 疎通確認（testConnection）

トークン取得 → `getItems`（既知の ASIN 1 件）まで通す。403 `AssociateNotEligible` は
「売上要件を満たしていない」と分かる文言で返す。

## 5. PR2: 表示側（ライセンス準拠）

Amazon 固有の文言をカードへ直書きせず、Provider が「表示上の決まり」を申告する任意インターフェースを足す:

```php
interface DisplayPolicyProvider {
	public function priceDisclaimer(): string;   // (o) 価格の免責文言
	public function contentAttribution(): string; // (q) 提供元の表記
	public function imageTtlHours(): ?int;       // (n) 書影を表示してよい時間（null＝制限なし）
}
```

カードは listing の platform → `PlatformDefinition::provider` → Provider を引き、申告があれば従う。

| 項目 | 表示 |
| --- | --- |
| (o) 価格の免責文言 | 既存の時点表示の後ろに「詳しくはこちら」を付け、開くと免責文言が出る（`<details>`。JS 不要） |
| (q) 提供元の表記 | その Provider の listing を表示しているカードの下に小さく出す（**カードごとに出す**。1 ページに複数あっても可） |
| (n) 書影 24 時間 | `last_verified_at` から `imageTtlHours()` を過ぎた offer の `image_url` は書影の候補から外す（既存の優先順で他ストアの書影へ切り替わる） |
| 基準価格のラベル | offer に `list_price_label` を新設し、`<ラベル> ~~¥990~~` の形で出す。Amazon は `savingBasisTypeLabel` が返ったときだけ `list_price` / `list_price_label` / `badge`（`savings.percentage` から `N%OFF`）を入れる。DMM の表示は変えない |

## 6. この設計が破綻する条件

1. **10 件まとめでも TPD が足りない**: Creators API 経由の売上が伸びず配分が数百/日を割ると、
   全 listing を 24 時間ごとに更新しきれない。→ 記事に載っている商品だけを更新対象にする等、
   対象の絞り込みが要る。
2. **認証情報のリージョンとマーケットプレイスが食い違う**（例: US で作った認証情報で JP を引く）:
   トークン取得先の推定が外れる。→ `credential_version` の入力欄を足す。
3. **Amazon 以外にも表示上の決まりが増える／文言を言語ごとに変える必要が出る**: `DisplayPolicyProvider`
   で吸収できるが、文言の多言語化は別途。
4. **ライセンスが「キャッシュ期限」を 24 時間より短くする**: `PriceFreshness` の TTL と `imageTtlHours()` を合わせて短くする。
