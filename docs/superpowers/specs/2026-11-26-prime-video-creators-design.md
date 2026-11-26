# Prime Video を Amazon Creators API で自動取得する（4.6.0）

## 背景

- Kindle は 4.x で Amazon Creators API（`amazon-creators`）の自動取得に対応した。設計（2026-11-24）の時点では Prime Video の ASIN が `ItemNotAccessible` を返したため対象外にしていた
- 2026-11 の調査で、`ItemNotAccessible` は **代表でない ASIN**（同じ作品の別の入口）で起きることが分かった。`searchItems`（`searchIndex: AmazonVideo`）が返す ASIN は `getItems` で取れる
- バージョン違い（4.3.0 の `variant_label`）は、価格の自動更新が listing ごとに 1 件しか取らないため、2 つ目以降のバージョンの価格が 24 時間で消える（4.3.0 の設計書 §5 で「Prime Video の自動取得を入れるときにあわせて直す」とした点）

## 実測（2026-11-26・フィルムログの認証情報・10 作品）

| 作品 | 価格（`offersV2.listings[].price`） | 備考 |
| --- | --- | --- |
| ジュラシック・ワールド／新たなる支配者 | 1,500（カート）／400／3,000／価格なし | 有料のみ |
| シン・ゴジラ | 0（カート）／2,500／500／価格なし | 見放題 |
| ターミネーター | 0（カート）／価格なし | 見放題 |
| バットマン オリジナル・ムービー（吹替版） | 価格なし（`UNAVAILABLE`「このタイトルは現在ご利用いただけません」） | 配信停止 |

- 価格に、購入・レンタル・画質のラベルは付かない（`type`・`condition` も返らない）
- ¥0 はプライム会員の見放題。カートを取っている出品は、見放題なら ¥0、そうでなければ購入価格（最安ではない）
- `itemInfo.classifications` は `binding: Prime Video`・`productGroup: Video On Demand` を返す
- 書影・`detailPageURL`（トラッキング ID 付き）・監督・出演は返る

## 決めたこと

### 1. 取得は `amazon-creators` を共通で使い、応答の種類で動画を見分ける

`AmazonProvider::normalizeItem()` が `isVideo()`（`productGroup` が `Video On Demand` か `binding` が `Prime Video`）で分け、動画は `normalizeVideoItem()` で埋める。

| 応答 | `price` | `price_from` | `plan_label` |
| --- | --- | --- | --- |
| ¥0 の出品がある | 空 | 空 | 「見放題」 |
| 有料の価格だけ（2 種類以上） | 最安値 | `1` | 空 |
| 有料の価格が 1 種類 | その価格 | 空 | 空 |
| 価格が無い | 空 | 空 | 空 |

- 割引・ポイントの欄は空にする（動画には返らない。古い値を残さない）
- `prime-video` の既定と既存インストールに `eligibleProvider: amazon-creators` を補う（Kindle と同じ一度きりの補完。`provider` は手動のまま）

### 2. 提供形態は取得のたびに上書きする

- `ListingRefresher` のパッチに `plan_label` と `price_from` を加える。**Provider が返したときだけ** 上書きする（書籍は返さないので手入力の値が残る）
- API が確かめた提供形態（`last_verified_at` を持つ購入リンク）は、価格と同じく TTL のあいだだけカードに出す（`PriceFreshness::isVerifiedFresh()`）。取得が止まったまま古い「見放題」を出し続けないため（ライセンスの (g)・(n)）。手入力はいつでも出す
- API が返した提供形態を出すカードには、価格を出さない行でも提供元の表記を付ける（ライセンスの (q)）

### 3. バージョン違いをすべて更新する

更新キューの単位は (商品, platform) のまま、1 回の更新でバージョンごとの代表 1 件ずつを取り直す。

- 選び方は `OfferSelector::selectForRefresh()`（＝カードと同じ `selectPerVariant()`）。`ListingRefresher`・掃引（`QueueMaintenance`）・繰り上がり（`OfferPromotionTrigger`）はここだけを呼ぶ
- 結果のまとめ方（`ListingRefresher::combineOutcomes()`）

| 結果 | outcome |
| --- | --- |
| 1 件でも一時失敗・保存失敗 | 一時失敗（全件を取り直す） |
| 全件が恒久失敗 | 恒久失敗（give-up マーカー） |
| 成功と恒久失敗が混ざる | 成功（恒久失敗のバージョンは `fetch_status=terminal` で価格を出さない。行と購入ボタンは残り、代わりのリンクがあれば繰り上がる） |
| 自動取得の対象外（外部 ID 無し）| 数えない。全件が対象外なら一時失敗（従来どおり） |

- give-up の判定（`RefreshHandler::isGivenUpAll()`）は全バージョンが恒久失敗のときだけ止める
- 掃引は `PriceFreshness::anyNeedsRefetch()`（どれか 1 つでも古ければ積む）
- まとめ取得できる Provider（Amazon）は、`fetchTargetExternalIds()` が全バージョンの ID を返すので、`BatchRefreshHandler` の先取りで 1 リクエストにまとまる。先取りは 1 リクエストに載る件数（10）までにする（`prefetch()` は複数のリクエストを間を空けずに送るため）
- **レート制限の枠は呼び出しごとに取る。** ハンドラ（`ThrottledActionHandler`・`BatchRefreshHandler`）が取るのは 1 件目の枠だけで、2 件目以降（まとめ取得できない Provider と、Amazon の 11 件目以降）は `ListingRefresher` が叩く直前に `RateLimiter::tryAcquire()` で取る。`BatchRefreshHandler` は、先取りに枠を使ったとき・先取りの結果だけで処理するとき（この listing のために枠を取っていない）に `refreshOne( …, $firstSlotUsed = true )` を渡し、`ListingRefresher` は最初の呼び出しから枠を取る（対象 ID から推し量らない。先取りと再読込の間に対象が変わりうるため）。間隔はハンドラと同じ有効間隔（Provider の下限と管理画面の上書きの大きいほう）。取れなければ叩かずに一時失敗にする
  - 以前の「ハンドラが interval × 件数を見込む」は、枠が取った時刻しか記録しないため、ジョブの最後の呼び出しと次のジョブとの間隔を守れなかった（CodeRabbit #207 の指摘）
- `BatchRefreshHandler` の所要時間の見込みは、叩く回数（まとめ取得できない Provider は件数、できる Provider は 1 ＋ 10 件を超えた分）に比例させる

## 検討した案

| 論点 | 案 | 採否 |
| --- | --- | --- |
| 動画の見分け方 | **A. 応答の種類で見分ける** | 採用。認証・上限・まとめ取得・表示の決まりをそのまま使える |
| | B. Prime Video 専用の Provider を作る | 共通部分の切り出しと、設定の取得方法の選択肢が 1 つ増える |
| | C. ストア名で切り替える | まとめ取得はストアをまたいで束ねるため、ストア名を渡す仕組みから作り直しになる |
| バージョンの更新 | **i. 単位は (商品, platform) のまま全バージョンを取る** | 採用。変更が選び方に閉じる |
| | ii. 単位を購入リンクにする | キュー・give-up・管理画面の表示まで作り直しになる |

## この設計が破綻する変更

- **Amazon が種類（classifications）の書き方を変える**: 動画と見分けられず、書籍の作り方になる（カートを取っている出品の価格＝見放題なら ¥0 を出す）。ストア名を Provider に渡せるようにして、ストアでも判定する
- **1 つのストアに 11 件以上のバージョンを並べる**: Amazon でも 1 回の更新が 2 リクエストになる（上限内だが件数に比例）
- **レンタルと購入を分けて出したくなる**: API がラベルを返さないので、この方式では作れない（商品ページを読む別の経路が要る）
- **DMM・楽天でバージョン違いが増える**: 1 回の更新がバージョンの数だけ最小間隔を待つ。多くなるなら購入リンク単位のキュー（案 ii）を考える
