# 商品カード メディア枠の type 別アスペクト比 ＋ 画像なしプレースホルダ改善 設計

> affilicard の商品カード左カラム（メディア枠）を **product_type ごとの実測アスペクト比**で固定し、実画像は **`object-fit: contain`（全 type 共通）** で枠内にレターボックス収めする。画像が無いときの**プレースホルダを汎用的に作り込む**（現状の薄グレー地＋小さく文字を、type のメディアラベル＋汎用アイコン＋正しい比率に）。
>
> **確定日: 2026-07-12**（brainstorming セッションで確定・実測に基づく）

---

## 1. 背景と目的

利用側プロジェクトの運用検証で、一部の商品（例: 電子書籍の合本版など、外部ストアの決定的画像 URL に実画像が無い商品）がプレースホルダ（無地/縞）になり、カード画像が空に見える事象が確認された。affilicard 側にも次の課題がある:

1. **画像なしプレースホルダが素っ気ない**: 空画像時は `<div class="affilicard-card__media-placeholder">商品画像</div>`（薄グレー地＋10px 文字）のみ。壊れて見え、意図的な意匠に見えない。
2. **メディア枠が product_type を問わず縦 2:3 固定**: `.affilicard-card__media-placeholder { aspect-ratio: 2/3 }` がハードコード。affilicard は汎用アフィリエイト商品プラグイン（電子書籍だけでない）で、商品ジャンルによって画像比率が異なる（漫画＝縦／物販＝正方／映像＝多様）。
3. **実画像の比率がまちまちだとレイアウトが不安定**: 実画像は `width:100%; height:auto` で自然比率描画のため、横長画像だと枠が極端に短く/縦長だと過大になり、隣の本文カラムとの見た目が崩れうる。

**目的**: product_type ごとに**実測に基づくメディア枠アスペクト比**を持たせ、**どんな比率の実画像も `object-fit: contain` で枠内に収め**（切り抜き/歪み/はみ出しを防止）、**画像なし時は汎用的に作り込んだプレースホルダ**を同じ枠比率で描く。affilicard の汎用性を保つ（「書影」は電子書籍 type 固有語に留め、基盤既定は汎用「商品画像」）。

---

## 2. スコープ

### スコープ内

- `src/Types/ProductTypeInterface.php` ＋ `AbstractProductType.php` に `cardMediaAspectRatio(): string` を追加（既定 `1 / 1`）。`EbookType` のみ `2 / 3` に override。
- `src/Renderer/CardHtmlBuilder.php`: `media_aspect_ratio` を renderer options に渡す（`media_label` と同様）。
- `src/Renderer/CardRenderer.php`: メディア枠に type 別アスペクト比を適用し、実画像を `object-fit: contain`（全 type 共通）で描画。画像なしプレースホルダを汎用アイコン＋type の `media_label` に改善。mask/予約/timestamp の既存ロジックは維持。
- `assets/card.css`: プレースホルダのハードコード `aspect-ratio: 2/3` を撤去し、type 駆動のアスペクト枠＋`object-fit: contain`＋プレースホルダ意匠（アイコン/レイアウト）を追加。container-query 縦積みは維持。
- `blueprints/demo-seed.php`（Playground デモ）: ebook / generic / vod の各 type × 「画像あり」「画像なし（＝プレースホルダ）」＋**横長画像**ケースのサンプルを追加し、PR プレビューで見た目・レイアウトを確認できるようにする。
- テスト（PHPUnit）更新・追加。CHANGELOG＋**v1.8.0**＋Release。

### スコープ外

- provider 連携の新規実装（VOD は現状 manual のみ・変更しない）。
- affilicard の REST/ブロックエディタ UI へのアスペクト比設定追加（type 既定で足りる・将来 follow-up）。
- 利用側プロジェクトのパイプライン改修（affilicard の範囲外）。

---

## 3. 実測に基づくアスペクト比（brainstorming セッションで計測）

現状 専用クラスの無い（＝generic に落ちる）商品ジャンルと、各 type の代表画像を実測（Amazon `images/P/<ASIN>` ＋ 公式サイトのキービジュアル・pixel 実測）:

| product_type | 計測サンプル | 実測比率 | 採用アスペクト比 |
| --- | --- | --- | --- |
| **ebook** | 漫画表紙（一貫して縦） | ≈0.67 | **`2 / 3`** |
| **generic** | 食品 500×500・アパレル 500×500・食品箱 428×500（物販は主画像を正方キャンバスに正規化） | ≈1.0 | **`1 / 1`** |
| **vod** | アニメ公式キービジュアル 0.71（縦）／SNS 用キービジュアル 1.0（正方）／Blu-ray/DVD 0.71〜1.41（混在） | 分散大・中央≈0.8〜1.0 | **`1 / 1`**（中立の正方） |

- **VOD は「配信」まで調べても単一比率が存在しない**（縦 0.71〜正方 1.0〜横 1.41）。中央値は正方寄りで、**16:9（1.78）は実測に該当ゼロ**かつ狭いメディアカラムでつぶれるため不採用。中立の `1 / 1` を既定（`AbstractProductType`）のまま使う。
- **アスペクト比はメディア枠（空状態の箱サイズ＋実画像の contain 枠）を決めるだけ**で、実画像は `object-fit: contain` によりどの比率でも枠内に収まる（下記 §4）。

---

## 4. 設計

### 4.1 product_type にアスペクト比を持たせる

`ProductTypeInterface` に追加:

```php
/** カードのメディア枠アスペクト比（CSS aspect-ratio 値。例 "2 / 3" / "1 / 1"）。 */
public function cardMediaAspectRatio(): string;
```

- `AbstractProductType::cardMediaAspectRatio()` = `'1 / 1'`（既定＝generic・vod をカバー）。
- `EbookType::cardMediaAspectRatio()` = `'2 / 3'`（override）。
- `GenericType` / `VodType` は override せず既定 `1 / 1`。

### 4.2 CardHtmlBuilder が renderer へ渡す

`CardHtmlBuilder::build()` の options 組み立てに追加（`media_label` と同じ流儀・null なら既定 `1 / 1`）:

```php
$media_aspect = null !== $type ? $type->cardMediaAspectRatio() : '1 / 1';
// $options に 'media_aspect_ratio' => $media_aspect を追加
```

### 4.3 CardRenderer（純粋維持）

メディア枠 `.affilicard-card__media` を **type 別アスペクト比のインライン style** で固定し、実画像・マスク済み画像・プレースホルダのいずれもその枠に収める:

- **実画像（マスクなし）**: `<img>` に `object-fit: contain`（全 type 共通）を効かせるためのクラス/構造にする。枠は `aspect-ratio` 固定、画像は枠内に contain（レターボックス）。
- **マスク済み画像**: 既存の `.affilicard-card__cover--masked`（blur＋overlay）を同じアスペクト枠内に収める（マスク挙動・R18 バッジ・ラベルは不変）。
- **画像なしプレースホルダ**: 同じアスペクト枠で、**汎用アイコン（内蔵インライン SVG・画像を示す中立の意匠）＋ type の `media_label`**（商品画像／書影／キービジュアル）を中央に描く。
- アスペクト比は `esc_attr` した inline style（`style="aspect-ratio: 2 / 3"` 等）で付与。`media_aspect_ratio` は `AbstractProductType` 由来の固定文字列（外部入力でない）だが、描画時は esc_attr で二重に安全化する。

> **既存「バイト一致」保証の扱い**: 表紙マスク feature はマスクなし `<img>` をバイト一致で維持していたが、本 feature で**メディア枠のアスペクト固定＋`object-fit: contain` を全 type に導入**するため、この保証は**意図的に更新**する（マークアップが変わる）。該当テストは新マークアップに合わせて更新する。

### 4.4 CSS（`assets/card.css`）

- `.affilicard-card__media-placeholder` のハードコード `aspect-ratio: 2 / 3` を**撤去**（type 駆動の inline style に委ねる）。
- メディア枠に `aspect-ratio`（inline）＋実画像/プレースホルダ共通の `object-fit: contain`・`width:100%`・`height:100%` を効かせるスタイルを追加。
- プレースホルダ意匠: 汎用アイコン（中立の画像アイコン）＋ラベル（`media_label`）を中央寄せ・ブランド地色。現状の `#eef1ee` 地・`--affilicard-text-light` 文字を踏襲しつつアイコンを追加。
- container-query（`@container (max-width: 600px)` で 1 カラム縦積み・メディア 100px）の既存挙動は維持。横長実画像も contain で枠内に収まるため、狭幅でも本文カラムがつぶれない。

### 4.5 Playground デモ（`blueprints/demo-seed.php`）

PR プレビュー（pr-preview-build/publish workflow）で見た目を確認できるよう、次のサンプル商品を追加（実在名は使わず架空プレースホルダ）:

- **ebook**: 縦長書影あり／書影なし（＝プレースホルダ「書影」）。
- **generic**: 正方画像あり／画像なし（＝プレースホルダ「商品画像」）／**横長画像**（contain で 1:1 枠に収まることを確認）。
- **vod**: キービジュアルあり／画像なし（＝プレースホルダ「キービジュアル」）。

各サンプルで「メディア枠が type 比率で固定」「実画像が contain で収まる」「プレースホルダが意匠として成立」「隣の本文カラムが崩れない」を目視確認できるようにする。

---

## 5. テスト方針（PHPUnit・Docker）

- `EbookType::cardMediaAspectRatio()` = `'2 / 3'`、`GenericType`/`VodType`（＝既定）= `'1 / 1'` を検証。
- `CardHtmlBuilder` が `media_aspect_ratio` を options に載せる（type 既定・null フォールバック）ことを検証。
- `CardRenderer::render()`:
  - メディア枠に `aspect-ratio: <type 比率>` の inline style が付く。
  - 実画像描画に `object-fit: contain`（全 type 共通）に相当するクラス/style が付く。
  - 画像なし時にプレースホルダが `media_label`（type 依存）＋アイコンを含み、同じアスペクト枠を持つ。
  - mask（blur/R18/label）が枠内で従来どおり描画される（R18→blur 強制・overlay 不変）。
- 既存の「マスクなし `<img>` バイト一致」テストは新マークアップへ更新（意図的変更）。
- 回帰: 既存 PHPUnit/JS を green に保つ。phpcs 0。

---

## 6. リリース

- `CHANGELOG.md` に `## [1.8.0]` を追加（メディア枠 type 別アスペクト比＋contain＋プレースホルダ改善）。
- `affilicard.php` の `Version:` ヘッダ／`AFFILICARD_VERSION`／`package.json` の version を **1.8.0** に同期（PUC はタグ tree の Version ヘッダを読むため必須。[project_affilicard_puc_version_header]）。
- PR → マージ → `v1.8.0` タグ → `release.yml` success → Release 公開 → PUC 検知。
- 利用側プロジェクトは affilicard プラグインが v1.8.0 に自動更新されると、実画像の取れない商品でも意匠化されたプレースホルダで描画される（利用側の「画像の取れない版をカード化しない」運用を将来緩められる＝利用側 follow-up）。

---

## 7. follow-up

- **アスペクト比のブロック属性/商品 meta 上書き**: type 既定で足りるが、個別商品で枠比率を変えたい要望が出たら追加（UI コスト高のため今回不要）。
- **プレースホルダに商品タイトル併記**: 本文にタイトルがあるため今回は media_label＋アイコンに留める。要望があれば追加。
- **利用側の緩和**: v1.8.0 反映後、利用側の「画像の取れない版をカード化しない」運用を「affilicard がプレースホルダ描画（v1.8.0+）」に緩める（利用側プロジェクトの follow-up）。
