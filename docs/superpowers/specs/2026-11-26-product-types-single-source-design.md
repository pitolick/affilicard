# 商品タイプを PHP だけに持たせる（4.5.0）

## 背景

4.4.0 までの点検で次が分かった。

1. 管理画面（React）が商品タイプを「汎用」「電子書籍」の 2 つで直書きしていた（`ProductSettingsPanel` / `ExtrasEditor` / `GeneralPanel` / `PlatformsPanel`）。PHP に動画配信（`VodType`）を足したとき管理画面だけ取り残され、商品編集画面で動画配信を選べなかった
2. 提供形態（`plan_label`、見放題・レンタルなど）は動画配信のための項目なのに、すべての商品タイプの購入リンクに出ていた
3. 設定「デフォルト商品タイプ」は保存されるだけで、どこにも使われていなかった（保存時の検証も `generic` / `ebook` の直書き）
4. 商品編集画面のストアの選択肢は、商品タイプと関係なく全ストアが同じ並びで出ていた

## 決めたこと

### タイプの定義は PHP だけ

- `ProductTypeUiList::build()` が `{ code, label, extrasSchema: [{ key, label, placeholder }], offerFields }` の一覧を作り、設定画面と商品編集画面に `window.affilicardProductTypes` として埋め込む（Provider の `window.affilicardProviders` と同じやり方）
- JS は `src/Admin/productTypes.js` だけがこれを読む。呼び出しのたびに読む（モジュール読込時に固めない）。注入が無いときは「汎用」だけにする（全タイプを JS に書き戻すと、同じ取り残しが起きるため）
- PHP と JS のテストは同じ `tests/fixtures/product-types.json` を使う。PHP 側（`ProductTypeUiListTest`）が実際の出力と突き合わせるので、タイプを変えて fixture を直し忘れると PHP のテストが落ちる
- 埋め込む JSON は `Plugin::inlineJson()`（`JSON_HEX_TAG` など）で逃がす

### タイプ固有の購入リンクの項目は `offerFields()`

- `ProductTypeInterface::offerFields(): list<string>`。既定（`AbstractProductType`）は空、`VodType` が `array( 'plan_label' )`
- カード: `CardHtmlBuilder` が商品タイプの `offerFields()` を `offer_fields` として `CardRenderer` に渡す。渡されないとき（`CardRenderer` を直接使う呼び出し）は従来どおり出す
- 管理画面: タイプが使わない項目は入力欄を出さない。ただし値が入っているときは「このタイプではカードに表示されません」と添えて出し、消せるようにする
- データ（REST・保存）はタイプで値を捨てない。タイプを戻せば元の表示に戻る

### 既定の商品タイプ（`DefaultProductType`）

| 作り方 | タイプ |
| --- | --- |
| 商品編集画面で新しく作る（auto-draft） | 設定の値（登録に無ければ汎用）。`wp_insert_post` で auto-draft のときだけ meta に書く |
| ストアの URL から自動で作る（`ProductAutoCreator`） | ストアの対象タイプに設定の値があればそれ、無ければ対象タイプの先頭 |
| REST で `product_type` を省く | `generic` のまま（外から作る処理の動きを設定ひとつで変えない） |

meta の登録時の default（`generic`）は変えない。変えると、タイプを持たない既存の商品の見え方まで変わるため。auto-draft の判定は `metadata_exists()` で行う（`get_post_meta()` は登録時の default を返すので、行の有無が分からない）。

### ストアの選択肢

- 商品タイプ向けのストアを先に、ほかは「（電子書籍 向け）」を添えて後ろに並べる。タイプ向けでないストアを選ぶと注意を出す
- 選べなくはしない。カードもタイプでストアを絞らない（動画配信の商品に原作の電子書籍を載せる使い方があるため）

## 検討した案

| 案 | 長所 | 短所 |
| --- | --- | --- |
| **A. PHP から注入（採用）** | 定義が 1 か所。既存の Provider と同じやり方 | 注入を忘れた画面では汎用だけになる（fallback で壊れはしない） |
| B. REST `/product-types` を足して取りに行く | 画面ごとの注入が要らない | 読み込み待ちの状態が増える。項目が 1 つの画面で何度も要る |
| C. JS に 3 タイプを書き、PHP とテストで突き合わせる | 変更が小さい | 定義が 2 か所に残り、今回と同じ取り残しが起きうる |

提供形態については、`plan_label` を `VodType` の extras に移す案も考えたが、購入リンク（offer）ごとに違う値（同じ作品で見放題とレンタル）を持つ項目なので offer に残し、出し分けだけをタイプで決めた。

## この設計が破綻する変更

- **タイプ固有の offer 項目が増え、入力の形も違う**（数値・選択肢など）: `offerFields()` が名前の一覧だけでは足りなくなる。項目ごとのスキーマ（`extrasSchema()` と同じ形）に広げる
- **サイトがタイプを足せるようにする**（フィルタ）: `Plugin::buildProductTypeRegistry()` を毎回作り直している所（`GeneralSettings` の検証・自動作成・auto-draft）を、1 つのレジストリを引く形にまとめる必要がある
- **REST の既定タイプも設定に従わせたい**: 利用側の投稿スクリプトの挙動が変わるので、タイプを必ず指定させる（省略を 400 にする）方向で考える
- **タイプ向けでないストアをカードでも隠したい**: 今は管理画面の注意だけ。カードで絞るなら、原作を載せる使い方のための例外（ブロック属性など）が要る
