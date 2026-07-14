# 認証サブシステム再設計 — account 単位 credentials ＋ SSOT ＋ UX 改善 設計

> 認証情報の保存単位を **provider → account** に変え、複数 provider が 1 つの API アカウントの鍵を共有できるようにする。あわせて認証フィールドのスキーマを **PHP を唯一の正（SSOT）** として設定画面へ注入し、UI を write-only パターン・折り畳み・provider 単位の接続テストへ刷新する。
>
> **確定日: 2026-07-14**（brainstorming セッションで確定）
> **対象バージョン: v2.0.0（MAJOR）**
> **改訂: rev2（2026-07-14 セルフレビュー反映）**

---

## 1. 背景と目的

現状の認証情報は **provider 単位**で保存されている（`affilicard_provider_<code>_credentials`）。しかし現実には:

- 楽天のデベロッパーアカウント鍵（`application_id` / `access_key` / `affiliate_id`）は**アカウント全体の鍵**であり、楽天Kobo・楽天ブックス・楽天トラベル・楽天レシピなど**複数の楽天 API（＝複数 provider）で共有**される。
- affilicard は**公開・再利用プラグイン**であり、「楽天特化ブログで楽天トラベル＋楽天レシピが同居」のような、1 アカウントが複数 provider を支える構成を他ユーザーが取り得る。

provider 単位の保存だと、同じアカウント鍵を provider ごとに二重入力させることになり、モデルとして不正確。

さらに現状の実装には次の問題がある（すべて相互依存するため 1 つの再設計にまとめる）:

1. **マスク値の保存事故**: `CredentialEditor` が起動時にマスク済み値を state に読み込み、保存時に全フィールドを PUT する。1 項目だけ編集して保存すると、他フィールドがマスク文字列で上書きされ本物の鍵が壊れる（dirty 追跡が無いのが根因）。
2. **test-before-save が効かない**: JS は入力中値を POST するが、REST 側 `testConnectionProvider` は body を無視して保存済み値でテストする。保存前の疎通確認・鍵ローテーション対応ができない。
3. **全フィールド一律マスク**: `getMasked` が type を見ず、`allowed_domain`（非秘匿）まで `****` にする。
4. **全フィールド password**: 秘匿でない `application_id` / `affiliate_id` も password 扱い。
5. **スキーマの二重管理**: 認証フィールド定義が PHP（`credentialsSchema()`）と JS（`providers.js` のハードコード定数）の 2 箇所にあり、provider 追加時に JS 更新を忘れると設定画面にフィールドが出ない不具合が実際に発生した。
6. **REST の 2 系統併存**: platform 系ルートが後方互換の死荷重として残る。

**目的**: credentials を **account 単位**に集約し、スキーマを **PHP の SSOT** に一本化し、UI を **write-only ＋ dirty 追跡 ＋ 折り畳み ＋ provider 単位テスト**へ刷新して、上記 1〜6 を構造的に解消する。

---

## 2. 前提・スコープ

### 前提

- **未公開**（他サイトへ未配布）。後方互換は不要で、**クリーンに最良設計**を採る。既存の保存済み credentials（e-comi 本番の楽天キー等）は破棄してよい（**移行しない**）。
- 破壊的変更を含むため **v2.0.0（MAJOR）**（厳密 SemVer）。
- テスト・検証環境: 楽天は Origin 依存 API のため **wp-env（実 WP）** で疎通確認、UI・DMM は Playground。ローカルは CodeRabbit CLI を先行。JS は `npm run test:js` 必須。

### スコープ内

- `AccountInterface` / `AccountRegistry` / 具体 Account（`RakutenAccount` / `DmmAccount`）の新設（`src/Account/`・役割ベース配置）。
- `ProviderInterface` の改修（`accountCode()` 追加、`credentialsSchema()` 撤去。`testConnection()` は残す）。
- vendor transport client（`RakutenClient`・`src/Provider/Rakuten/`）の抽出。
- `AccountCredentials`（`ProviderCredentials` を改名・account 単位化・type-aware status・**required サーバ検証**）。
- REST 再構成（credentials=account 単位 / test-connection=provider 単位・入力中値マージ）。
- SSOT 注入（`window.affilicardAccounts` ＋ `window.affilicardProviders`）と JS 導出（ハードコード廃止）。
- 管理画面 UI 刷新（`PanelBody` 折り畳み・write-only・dirty 追跡・provider 単位テスト・アカウント単位削除）。
- 旧オプションキーのアップグレード削除 ＋ `uninstall.php` 更新。
- テスト（PHP / JS）・CHANGELOG・README・v2.0.0 bump。

### スコープ外（follow-up・第9章）

- 管理画面の**商品検索サブシステム**（別 spec ＋ 競合 WP プラグイン調査を先行）。
- `Crypto` の鍵管理強化（`AFFILICARD_ENCRYPTION_KEY` 定数・任意で CBC→CTR/GCM）。

---

## 3. 現状の構造（把握済み）

- 保存: `ProviderCredentials`（`src/Provider/ProviderCredentials.php`）が `affilicard_provider_<code>_credentials` に `Crypto`（AES-256-CBC・鍵は `wp_salt('auth')` 派生・IV 毎回ランダム）で暗号化 JSON を保存。`get`/`getMasked`/`patch`（PATCH: null=維持 / string=上書き）/`delete`。
- REST: `CredentialsController`（`src/Rest/CredentialsController.php`）が platform 系・provider 系の 2 系統を提供。`testConnectionProvider` は body を無視し保存値でテスト。**JS（`src/Admin/api/credentials.js`）は provider 系ルートのみ使用し、platform 系ルートには消費者がいない（撤去安全・確認済）。**
- Provider: `ProviderInterface`（`code`/`label`/`isAutomatic`/`fetch`/`credentialsSchema`/`testConnection`）。`ManualProvider`（`credentialsSchema()` は空配列を返す）/ `Dmm/DmmProvider`（`api_id`/`affiliate_id`。`fetch` は `ProviderCredentials::get($this->code())` で取得）/ `Rakuten/RakutenProvider`（`application_id`/`access_key`/`affiliate_id`/`allowed_domain`。request 組み立て・正規化を private helper で内包）。`ProviderRegistry`。
- 設定 UI: `Plugin::enqueueSettingsAssets()` が `affilicard-settings`（`build/settings.js`）を enqueue。`Plugin::buildProviderRegistry()` が provider を登録し、`CredentialsController` に注入。`src/Admin/providers.js` が `PROVIDER_OPTIONS`/`CRED_SCHEMAS` を**ハードコード**。`ApiCredentialsPanel.jsx` が「空でないスキーマを持つ provider」を描画し、`CredentialEditor.jsx` が各 provider の認証情報を編集。`PlatformEditor.jsx` が `PROVIDER_OPTIONS` をドロップダウンに使用。
- Uninstall: `src/Uninstall.php::deleteProviderCredentials()` が `$wpdb` の LIKE（`affilicard_provider_%`）で credentials オプションを一括削除済み。

---

## 4. ドメインモデルとインターフェース

### 4-1. 新しい連鎖

```text
platform → provider → account → credentials
```

credentials は **account 単位**で保存。複数 provider が同一 account を参照して鍵を共有する。

### 4-2. Account（新・一級市民）

初期セット:

| account code | label | credentialsSchema（key: type） |
| --- | --- | --- |
| `rakuten` | 楽天 | `application_id`:text / `access_key`:**password** / `affiliate_id`:text / `allowed_domain`:text |
| `dmm` | DMM | `api_id`:**password** / `affiliate_id`:text |
| （将来）`amazon` | Amazon | 承認後に定義（本 spec では未登録） |

秘匿（password＝マスク対象）は `access_key`・`api_id` のみ。他は text。

```php
namespace Affilicard\Account;

interface AccountInterface {
    public function code(): string;              // 'rakuten'
    public function label(): string;             // '楽天'（__() で翻訳）
    /** @return list<array{key:string,label:string,type:'text'|'password',required:bool}> */
    public function credentialsSchema(): array;
}
```

- 配置（役割ベース）: `src/Account/AccountInterface.php` / `AccountRegistry.php` / `RakutenAccount.php` / `DmmAccount.php`。namespace `Affilicard\Account`。
- `AccountRegistry`（`ProviderRegistry` と対称）を新設。`Plugin::buildAccountRegistry()` で `rakuten` / `dmm` を register。
- Account は**認証情報の保有者**（スキーマ＋保存の SSOT）。**testConnection は持たない**（下記 4-4 の理由）。

### 4-3. Provider の改修

```php
interface ProviderInterface {
    public function code(): string;                 // 'rakuten-kobo'
    public function label(): string;                // '楽天Kobo'
    public function isAutomatic(): bool;
    public function accountCode(): ?string;         // 'rakuten'（manual は null）★追加
    public function fetch(string $externalId, array $platformConfig): ?array;
    public function testConnection(array $credentials): array; // ★残す（account creds を受ける）
    // credentialsSchema() は撤去 ★（Account へ移設）
}
```

provider→account 紐付け:

| provider | accountCode |
| --- | --- |
| `manual` | `null`（認証パネルに出ない） |
| `dmm-ebook` | `dmm` |
| `rakuten-kobo` | `rakuten` |

- `manual` は accountCode=null のため、認証パネルに自然に現れない（現行の「空スキーマを filter」より明確）。
- **credentials の取得元**: provider の `fetch()` は現行 `ProviderCredentials::get($this->code())` を **`AccountCredentials::get($this->accountCode())` に変更**する（DMM/楽天とも）。`testConnection()` は REST から渡される account creds を受け取る。
- **キー契約**: account の `credentialsSchema()` のキー（`application_id` 等）が、そのアカウントを使う provider の `fetch`/`testConnection` が参照するキーの**契約**になる。同一 vendor の Account と Provider は同じキー名で co-design する（vendor 内の暗黙結合であり許容）。
- **graceful**: `accountCode()` が null、または未登録アカウントを指す場合、creds は空配列とみなし `fetch()` は null を返す（現行の未設定時挙動と同じ）。

### 4-4. testConnection は provider 所有（＝API 単位）

楽天は API ごとに必要な鍵・権限が異なる:

- 楽天Kobo（openapi）: `application_id` ＋ **`access_key` ＋ Origin(`allowed_domain`)** ＋ `affiliate_id`、専用エンドポイント。
- 楽天トラベル/レシピ（標準 RWS）: `application_id` ＋ `affiliate_id` のみ、別エンドポイント。

同じ楽天アカウント鍵でも「Kobo は通るがトラベルは権限外」が起こり得る。ゆえに **“接続が通るか” を答えられるのは provider（＝API エンドポイント）単位のみ**。したがって:

- **Account = 認証情報の保有者**（スキーマ＋保存）。testConnection は持たない。
- **Provider = accountCode ＋ fetch ＋ testConnection(account creds)**。テストは provider ごとに独立実行し、UI が provider 単位で ✓/✗ を出して権限差を可視化する。
- `ManualProvider` も `testConnection()` を持つが（interface 共通）、account を持たないため UI から呼ばれない。schema は Account へ移したので `credentialsSchema()` のみ interface から撤去する（testConnection は API 単位・schema は account 単位という非対称は意図的）。

### 4-5. vendor transport client の抽出

`RakutenProvider` は「provider の役割」「API 通信」「レスポンス正規化」を 1 クラスに混載している。**API 通信（`toOrigin` / `access_key` ヘッダ / エンドポイント / リクエスト送出）を `RakutenClient`（`src/Provider/Rakuten/RakutenClient.php`）に抽出**し、`RakutenProvider::fetch()` と `RakutenProvider::testConnection()` が共用する。

- 動機は**現時点の凝集性**（単一責任・小さく境界の明確な単位）。将来投機ではない。
- 将来 `RakutenTravelProvider` 等を足す際も同 client を共用でき、その追加は内部リファクタで済む（公開面に非破壊）。
- DMM は通信が薄いため、`DmmClient` 抽出は任意（対称性のために実装計画で判断）。

### 4-6. 将来の非破壊ガード（設計原則）

公開プラグインなので第三者が `ProviderInterface` を実装し得る。**将来のケイパビリティ追加はコア interface の拡張ではなく、オプションの capability interface（`instanceof` 判定）で足す**ことを設計原則として明文化する。例: 商品検索は `SearchableProvider`（`search(query): candidates[]`）をオプション実装させれば既存 provider を壊さず追加できる。

### 4-7. ファイル配置まとめ（役割ベース）

| 新規/変更 | パス | 役割 |
| --- | --- | --- |
| 新規 | `src/Account/AccountInterface.php` | Account 契約 |
| 新規 | `src/Account/AccountRegistry.php` | Account 登録簿 |
| 新規 | `src/Account/RakutenAccount.php` / `DmmAccount.php` | 具体 Account（schema） |
| 新規 | `src/Account/AccountUiList.php` | account の SSOT 注入用ビルダー |
| 新規 | `src/Provider/ProviderUiList.php` | provider の SSOT 注入用ビルダー |
| 新規 | `src/Provider/Rakuten/RakutenClient.php` | 楽天 API transport |
| 改名 | `src/Provider/ProviderCredentials.php` → `src/Account/AccountCredentials.php` | account 単位保存 |
| 変更 | `src/Provider/ProviderInterface.php` ほか各 Provider | accountCode 追加・credentialsSchema 撤去 |
| 新規 | `src/Admin/accounts.js` | ACCOUNTS 導出 |
| 変更 | `src/Admin/providers.js` / `ApiCredentialsPanel.jsx` / `CredentialEditor.jsx`→`AccountCredentialEditor.jsx` / `api/credentials.js` | SSOT 導出・UI 刷新・新ルート |

---

## 5. 保存・マスク・dirty 追跡・required・クリーンアップ

### 5-1. AccountCredentials（`ProviderCredentials` を改名・account 単位化）

- 配置: `src/Account/AccountCredentials.php`（namespace `Affilicard\Account`）。
- 保存キー: `affilicard_account_<accountCode>_credentials`（例 `affilicard_account_rakuten_credentials`）。値は現行同様 `Crypto` で AES 暗号化 JSON（`Crypto` は本 spec では**現状維持**）。
- `get()` / `patch()`（内部 util の PATCH: null=触らない / string=上書き）/ `delete()` は現行ロジックを account キーで踏襲。REST 層（§6）は **dirty な文字列キーのみ**を渡す（null は使わない）。

### 5-2. write-only パターン（秘匿値の標準）

WordPress プラグインの API シークレット標準（WP コア Application Passwords 等）に倣い、**平文シークレットを保存後にブラウザへ返さない**。

- **GET 応答（type-aware）**: `getStatusFor(AccountInterface $account)` を新設し、フィールド毎に返す:
  - text（`application_id`/`affiliate_id`/`allowed_domain`）→ `{ value: "実値", isSet: bool }`
  - password（`access_key`/`api_id`）→ `{ value: "", isSet: bool }`（**値は withhold**、状態のみ）
- **PUT 意味論**: JS は **dirty（変更した）キーだけ**を送る。**キーが含まれれば上書き保存**（text は空文字も保存＝そのフィールドのクリア）、**含まれなければ維持**。password は入力があったときだけ dirty として送られる（未編集は送らない＝維持）。per-field の明示クリアは設けず、アカウント全体のクリアは DELETE（§6・§8-2）。
- **dirty 追跡（主対策・JS 側）**: 触った項目だけ PUT する。未編集の password は再送しない。これにより「マスク文字列で本物の鍵を壊す」現行バグが**構造的に発生し得なくなる**（マスク値がそもそも編集欄に往復しない）。
- password の「設定済み」表示は**状態バッジのみ**（末尾数桁も出さない＝App Passwords 準拠）をデフォルトとする。

> 補足: 従来案の「サーバ側でマスク一致を判定して無視する二重防御」は**採らない**。write-only パターンの方が標準かつ堅牢（シークレットが編集値として往復しないため、事故が原理的に起きない）。

### 5-3. required のサーバ検証

`credentialsSchema()` の `required:true` は**サーバ（PUT）で強制**する。

- 検証対象は **patch 適用後のマージ状態**（保存済み値 ＋ 今回の差分）。これにより「既に完成済みアカウントの 1 項目だけ編集して保存」は失敗せず、**最終状態が不完全なときだけ 400** を返す。
- 400 応答は不足キーを示す（`{ code:'affilicard_missing_required', missing:[...] }`）。
- **完全クリア/削除の逃げ道**: required 検証を通れないため、アカウント単位の「認証情報を削除」操作（option ごと削除＝`AccountCredentials::delete()`）を用意し、これは required 検証を経由しない（UI: §8-2 の削除ボタン、REST: §6 の DELETE）。

### 5-4. 旧キーのクリーンアップ（DB 残渣回避）

移行しない代わりに、**アップグレード時に旧オプションキーを削除**する（version ゲートした upgrade routine）。既存 `Uninstall::deleteProviderCredentials()` と同じ `$wpdb` LIKE 方式を用いる:

- upgrade 時: `affilicard_provider_%` を一括削除（旧 provider 単位 credentials の残渣除去）。
- `uninstall.php`（`Uninstall`）: 既存の `affilicard_provider_%` 削除に加え、**`affilicard_account_%` の削除を追加**する。

---

## 6. REST API

粒度を自然な単位に一本化し、旧ルートは撤去する:

| ルート | メソッド | 動作 |
| --- | --- | --- |
| `/affilicard/v1/accounts/{code}/credentials` | GET | account の creds を **type-aware**（§5-2）で返す（`{value,isSet}`） |
| 〃 | PUT | dirty 値を上書き保存（含まれたキーのみ・text の空文字はクリア）。**required をマージ後状態で検証**（不足なら 400・§5-3）。成功時は GET 相当の status を返す |
| 〃 | DELETE | account の credentials を option ごと削除（required 検証なし・§5-3 の逃げ道） |
| `/affilicard/v1/providers/{code}/test-connection` | POST | **入力中の dirty 値（body）** を保存済み account creds に上書きマージ → `provider.testConnection` 実行（保存前テスト） |

- **撤去**: 旧 `/platforms/{code}/credentials`・`/platforms/{code}/test-connection`・`/providers/{code}/credentials`（credentials CRUD は account へ集約）。**JS 消費者がいないことを確認済**（`api/credentials.js` は provider 系のみ、platform 系は未使用）。
- test-connection が `/providers/{code}` 側なのは、テストが **API（＝provider）単位**だから（§4-4）。provider が自分の `accountCode` を解決し、`body の dirty 値 > 保存済み account creds` の優先順でマージして `testConnection` を実行。**body は保存と同じ dirty 値のみ**（未編集 password は含めない＝保存値に fallback）＝空文字で保存値を潰さない。未知の provider / account は 404。
- 権限は全ルート `manage_options`。
- 対応する JS api クライアント（`src/Admin/api/credentials.js`）を account/provider の新ルートに更新（`fetch`/`update`/`delete` = accounts、`testConnection` = providers）。

---

## 7. SSOT 注入（PHP を唯一の正に）

### 7-1. 原則

認証フィールドのスキーマは **PHP を単一情報源**とし、`wp_add_inline_script` で設定画面へ注入する。JS のハードコード定数は廃止する。「静的スキーマは注入・動的な秘匿値は REST（§6）」という WP ベストプラクティスに沿う。これにより「JS 定数の更新漏れ」不具合（§1-5）を構造的に排除する。

### 7-2. PHP ビルダー（2 本）

- `AccountUiList::build(AccountRegistry): list<array{code,label,credentialsSchema}>` — 認証パネル用（`src/Account/AccountUiList.php`）。
- `ProviderUiList::build(ProviderRegistry): list<array{code,label,isAutomatic,accountCode}>` — provider ドロップダウン用（`src/Provider/ProviderUiList.php`）。

`enqueueSettingsAssets()` の enqueue 直後:

```php
wp_add_inline_script(
    'affilicard-settings',
    'window.affilicardAccounts=' . wp_json_encode( AccountUiList::build( self::buildAccountRegistry() ) ) . ';'
  . 'window.affilicardProviders=' . wp_json_encode( ProviderUiList::build( self::buildProviderRegistry() ) ) . ';',
    'before'
);
```

### 7-3. 注入データ形

```js
// スキーマは account が持つ
window.affilicardAccounts = [
  { code:'rakuten', label:'楽天', credentialsSchema:[
      { key:'application_id', label:'アプリID',            type:'text',     required:true  },
      { key:'access_key',     label:'アクセスキー',         type:'password', required:true  },
      { key:'affiliate_id',   label:'アフィリエイトID',      type:'text',     required:true  },
      { key:'allowed_domain', label:'許可ドメイン(Origin)', type:'text',     required:false } ] },
  { code:'dmm', label:'DMM', credentialsSchema:[
      { key:'api_id',       label:'API ID',          type:'password', required:true },
      { key:'affiliate_id', label:'アフィリエイトID', type:'text',     required:true } ] },
];
// provider は schema を持たず accountCode を持つ
window.affilicardProviders = [
  { code:'manual',       label:'手動入力',  isAutomatic:false, accountCode:null      },
  { code:'dmm-ebook',    label:'DMM ebook', isAutomatic:true,  accountCode:'dmm'     },
  { code:'rakuten-kobo', label:'楽天Kobo',  isAutomatic:true,  accountCode:'rakuten' },
];
```

### 7-4. JS 導出（ハードコード廃止）

```js
// src/Admin/accounts.js
const injected = (typeof window !== 'undefined' && Array.isArray(window.affilicardAccounts))
  ? window.affilicardAccounts : [];
export const ACCOUNTS = injected; // [{code,label,credentialsSchema}]

// src/Admin/providers.js
const p = (typeof window !== 'undefined' && Array.isArray(window.affilicardProviders))
  ? window.affilicardProviders : [];
export const PROVIDER_OPTIONS = p.map((x) => ({ label:x.label, value:x.code })); // 形は不変
export const providerAccount = (code) => p.find((x) => x.code === code)?.accountCode ?? null;
```

- 未定義/非配列は `[]` フォールバック（防御的・テスト容易）。
- `PROVIDER_OPTIONS` の形（`{label,value}`）は不変 → `PlatformEditor.jsx` は実質無改修。
- 未 push の provider 単位 SSOT ドラフト（`feat/provider-ui-schema-ssot`）は**破棄**し、本 account 版で置換する。差分は「注入 global を 2 本に分離／スキーマ所有を Account へ／JS 導出を account キー化」。

---

## 8. 管理画面 UI

### 8-1. ApiCredentialsPanel

`ACCOUNTS` を走査し、各 account を **`PanelBody`（折り畳み）** で表示。`initialOpen` は「未設定（全 required が未 isSet）のアカウントは開く／設定済みは閉じる」を初期規則とする。

```text
■ API 認証
  API 連携を使うアカウントの認証情報を設定します（アカウント単位で共有）。

  ▼ 楽天                                                    〔開〕
  ┌──────────────────────────────────────────────────────────┐
  │ アプリID            [ 1023...                 ]   ← text（実値・編集可）
  │ アクセスキー         [                        ] 👁     ← password（空・設定済みバッジ）
  │ アフィリエイトID     [ 12a3...                 ]   ← text
  │ 許可ドメイン(Origin) [ e-comi.pitolick.com     ]   ← text
  │                                                            │
  │ このアカウントを使う連携:                                  │
  │   ・楽天Kobo      [ 接続テスト ]   ✓ 接続OK               │
  │   ・楽天トラベル   [ 接続テスト ]   ✗ 権限がありません      │
  │                                                            │
  │            [ 認証情報を保存 ]   [ 認証情報を削除 ]          │
  └──────────────────────────────────────────────────────────┘

  ▶ DMM                                                     〔閉〕
```

### 8-2. AccountCredentialEditor（`CredentialEditor` を置換）

- GET `/accounts/{code}/credentials` → 各フィールド `{value,isSet}`。
- **type で描画分岐**:
  - text → `TextControl`（実値・編集可）。
  - password → `TextControl type=password`、**空欄＋「設定済み」バッジ**（`isSet` 時）＋目アイコンで一時表示。
- **dirty 追跡**: 変更キーだけ PUT。未編集 password は再送しない。
- **保存**: 「認証情報を保存」→ PUT。required 不足の 400 はフィールド脇にエラー表示。
- **削除**: 「認証情報を削除」→ DELETE（確認ダイアログ経由）。account の全 credentials を消去（§5-3 の逃げ道・required 検証なし）。
- **provider ごとの接続テスト**: `providerAccount(code) === この account` の provider を列挙し、各「接続テスト」で POST `/providers/{code}/test-connection`（**保存と同じ dirty 値のみ**を送る＝未編集 password は含めず保存値に fallback）→ provider ごとに ✓/✗ ＋メッセージ。
- 保存/削除後は GET を再取得して state を更新。

### 8-3. PlatformEditor

`PROVIDER_OPTIONS`（形不変）をドロップダウンに使うだけ＝実質無改修。

---

## 9. テスト・バージョン・follow-up

### 9-1. テスト（同一 PR 必須・外部 API はモック）

- **PHP（Docker `composer:2`）**:
  - `AccountRegistry` / `AccountUiList`（build）/ `ProviderUiList`（accountCode 付き build）。
  - `AccountCredentials`: `get`・`patch`・`getStatusFor`（type-aware＝password は value 空・text は実値）・`delete`。
  - `CredentialsController`: account GET（マスク）・PUT（マージ後 required 検証で 400／成功で status）・DELETE、provider test-connection（body マージ・404）。
  - `ProviderInterface` 改修に伴う各 Provider（`accountCode()`、`fetch` が `AccountCredentials` を読む、`testConnection` が account creds を受ける）。
  - `RakutenClient` 抽出（request 組み立て・Origin・エラーメッセージ）。
  - upgrade 時の旧キー削除・`Uninstall` の account キー削除。
  - 既存 `ProviderCredentials`/`CredentialsController`/Provider テストの移行。テストは架空プレースホルダ（`sample` / `X` 等）で実在名を書かない。
- **JS（`npm run test:js`・`tests/js/` 配下）**: `accounts.js` / `providers.js` の window 導出（未定義フォールバック含む）/ `AccountCredentialEditor` の dirty 追跡・write-only（secret 非再送）・削除・provider 別テスト。

### 9-2. バージョン = v2.0.0（MAJOR）

`ProviderInterface` の破壊的変更（`credentialsSchema()` 撤去・`accountCode()` 追加）＋新 `AccountInterface` ＋ REST/保存形式の非互換。第三者が実装し得る provider 拡張 IF を壊すため、厳密 SemVer 上 **MAJOR**。未公開のため実害はなく、クリーン設計を採れる。`affilicard.php` の `Version:` ヘッダと `AFFILICARD_VERSION` 定数を **`2.0.0` に同期**（PUC 自動更新検知の条件）。CHANGELOG・README（Account/Provider 追加手順）を更新。

### 9-3. follow-up（記録のみ・別スコープ）

1. **商品検索サブシステム**: 管理画面から PF 検索 → CPT 登録。`SearchableProvider`（オプション capability interface）で非破壊に追加可能。採否は他のアフィリエイト API 系 WP プラグインの検索 UX を調査してから判断（別 spec）。検索が入れば「サンプル検索」が接続テストを兼ね得る。
2. **Crypto 鍵管理強化**: 現状 `wp_salt('auth')` 派生は「ローテ対象 salt に暗号を紐付ける」パターン（Felix Arntz / Google Site Kit が警告）。`AFFILICARD_ENCRYPTION_KEY` 定数を導入し未定義なら `wp_salt('auth')` にフォールバックすると、ゼロ設定の後方互換を保ちつつ salt ローテ耐性＋鍵/暗号文の分離が得られる（鍵は DB に置かない）。任意で CBC→CTR/GCM 化。参考: [Storing Confidential Data in WordPress](https://felix-arntz.me/blog/storing-confidential-data-in-wordpress/) / [WP Trac #64789](https://core.trac.wordpress.org/ticket/64789)。

---

## 10. 完了条件

- `composer test` / `composer lint` / `npm run test:js` / `npm run build` すべて green。
- wp-env（実 WP）で楽天の credentials 保存・provider 単位の接続テスト（Origin 依存）が通ることを確認。Playground で UI（折り畳み・write-only・dirty 追跡・削除）と DMM を確認。退行なし。
- 実装 feature PR は自動マージせず、プレビューでユーザーが確認してからマージ。マージ後タグで `release.yml` が v2.0.0 を Release 公開。
