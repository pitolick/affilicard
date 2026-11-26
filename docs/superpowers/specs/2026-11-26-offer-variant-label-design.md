# 購入リンクのバージョン（variant_label）

> **ステータス**: 設計完了・実装済み（2026-11-26）
> **バージョン**: v4.2.0 → v4.3.0（MINOR・後方互換）
> **先行 spec**: [2026-09-11-listing-purchase-links-design.md](2026-09-11-listing-purchase-links-design.md)（offers 化）／[2026-11-25-vod-link-template-design.md](2026-11-25-vod-link-template-design.md)（リンクテンプレート・`plan_label`）

---

## 1. 背景

Prime Video などでは、同じ作品が**字幕版・吹替版・ディレクターズカット版で別の商品ページ（別の ASIN）**になっていることがある。カードにはどれか 1 つだけでなく、それぞれを並べたい（読者は観たいバージョンを選ぶ）。

listing は v4.0.0 から購入リンク（offers）を複数持てるが、その意味は「**優先順位つきの代わりのリンク**」である（先頭が使えなくなったら次へ切り替える。電子書籍の期間限定版 → 通常版など）。カードに出るのは 1 件だけで、これは今後も必要な動きである。そこで「同時に並べたい購入リンク」と「代わりのリンク」を区別する手段が要る。

## 2. 検討した案

| 案 | 長所 | 短所 |
| --- | --- | --- |
| A. バージョンごとに商品を分ける | 本体の変更が要らない | カードが複数枚になる（要望に合わない） |
| B. listing に「全部表示する」スイッチ | 設定 1 つ | 代わりのリンクまで並ぶ。バージョン名を `plan_label` に混ぜて書くことになる |
| **C. offer に `variant_label` を足す（採用）** | バージョンごとに 1 行。同じバージョンの中は代わりのリンクの動きがそのまま残る。空なら従来と同じ表示 | 項目が 1 つ増える |

## 3. 仕様

### 3-1. データ

offer に `variant_label`（string・既定 `''`）を足す。`ProductSchema::sanitizeOffers()` が `sanitize_text_field` で保存し、v3 以前の flat な listing からの変換（`LegacyOffer::toOffer()`）でも写す。価格更新のパッチは取得した項目だけを書くため、人が入れた `variant_label` は更新で消えない。

### 3-2. 選択（`OfferSelector::selectPerVariant()`）

1. offers を表示順（`display_order` 昇順・同値は出現順）に並べる
2. `variant_label`（前後の空白を除く。空どうしも同じ）でグループに分ける。グループの並びは、各グループの offer が最初に現れた位置
3. 各グループから `OfferSelector::select()` の規則（恒久失敗のフォールバックを含む）で 1 件ずつ選ぶ

`variant_label` を誰も持たない listing は 1 グループなので `select()` と同じ結果になる＝既存の商品の表示は変わらない。JS（`ListingsEditor` の `selectInUseOffers()`）は同じ規則の写しで、「使用中」の印をバージョンごとに付ける。

### 3-3. 表示

- `CardRenderer::visibleListings()` が listing ごとに `selectPerVariant()` の結果を 1 組ずつ返す（同じ listing の組は隣り合う）。URL の無い組は出さない
- 行の価格欄の先頭に `<span class="affilicard-card__variant">字幕版</span>` を置き、その後に `plan_label`・価格を続ける
- 同じ listing の 2 行目以降は `affilicard-card__row--variant-continued`。ストア名は `affilicard-card__sr-only` でスクリーンリーダー向けにだけ残す。次の行が同じ listing なら `affilicard-card__row--variant-next`（区切り線を点線にする）
- CTA に `data-affilicard-variant`（空なら属性ごと出さない）

## 4. 破綻する条件

- **1 作品のバージョンが多い**（字幕・吹替・4K・ディレクターズカット…）とカードが縦に長くなる → 表示数の上限か折りたたみを足す
- **同じバージョンの中で「見放題」と「レンタル」が別の商品ページ**になっているストアが出てきた → 1 バージョン 1 行では足りず、バージョン × 提供形態で分ける必要がある

## 5. 対象外（後続）

- **価格の自動更新は listing ごとに 1 件のまま。** 更新キューは (商品, platform) 単位で `OfferSelector::select()` の 1 件を取りに行く。2 つ目以降のバージョンも更新するには、キューの単位・give-up マーカー・レート制限の枠取りを offer 単位に広げる必要がある。Prime Video の自動取得（Amazon Creators API）を入れるときにあわせて行う
- 管理画面のフォールバック警告（一覧の警告列・ダッシュボードの件数）は、引き続き `select()` の 1 件で判定する
