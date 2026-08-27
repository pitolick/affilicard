# Changelog

本プロジェクトの主な変更点を記録します。バージョニングは [Semantic Versioning](https://semver.org/lang/ja/) に準拠します。

## [Unreleased]

### Changed

- **価格更新を account 単位のバッチジョブへ変更**: 1 ジョブが複数 listing を担当し、ジョブ内でレート間隔を守りながら順次取得する。従来の 1 listing = 1 ジョブ方式では、Action Scheduler がバッチでジョブをまとめて実行する一方でレート制限が 1 秒に 1 件しか通さないため、大半のジョブが取得せずに完了していた（実測で完了 27,600 件中およそ 595 件しか取得できていなかった）。失敗した listing は従来どおり個別ジョブへ落ち、リトライ・失敗表示はそのまま機能する
- **掃引を分割実行に変更**: 公開商品を一定件数ずつ走査し、続きは次のジョブへ引き継ぐ。商品数が増えても 1 回の実行時間が伸びない

### Added

- **棚卸し**: 記事に掲載されなくなって一定期間が過ぎた商品の自動更新を停止する。既定は 180 日で、一般設定から有効/無効と期間を変更できる。記事を更新すれば対象から戻る
- 商品一覧に「最終掲載日」列を追加（ソート可能）
- 更新キュー画面に最後の掃引時刻を表示。長時間実行されていない場合は警告する
- サーバー cron の判断基準と設定手順を運用ドキュメントに追記し、設定画面から辿れるようにした

### Fixed

- 既に予約済みのジョブと重複した場合に、キューの深さ上限の枠を誤って消費していた問題を修正
- 管理画面が案内する WP-CLI コマンドのフック名が誤っており、実行しても掃引が起動しなかった問題を修正
- 掃引のバッチ投入が unique 重複ではなく本当に失敗した場合でも完走として扱われ、対象 listing が次回の定期掃引まで更新されない問題を修正
- 端数バッチ（バッチサイズ未満のまま走査末尾で流すもの）の投入時にキューの深さ上限の残り容量を確認しておらず、上限を超えて投入され得た問題を修正
- バッチジョブの期限判定が「ジョブ自身の開始時刻」を起点にしており、先行アクションが AS ランナーの時間予算を先に消費している場合に残り時間を過大評価し得た問題を修正。AS ランナーの起動時刻を捕捉して起点にするよう変更した

## [3.4.0] - 2026-08-13

### Added

- 商品カードに計測用の `data-affilicard-*` 属性を出力（CTA に platform / 商品 ID / スラッグ / タイトル、カードのルート要素に商品 ID / スラッグ / タイトル）。タグマネージャからクリックと表示を計測できるようにするためのもので、プラグイン自体は計測イベントを送信しない
- `ProductRepository::find()` の戻り値に `slug` を追加

### Notes

- 値が空の項目は属性ごと出力しない
- 既存のリンク属性（`href` / `rel` / `target`）とカードの見た目に変更はない

## [3.3.1] - 2026-08-11

### 変更

- 依存更新: `squizlabs/php_codesniffer` 3.13.5 → 3.13.6（dev）

## [3.3.0] - 2026-08-06

### 修正

- **商品編集画面の「更新モード」が機能していなかった。** 選択肢が `manual` / `api` だったのに対し、自動更新の対象判定（`ListingEligibility`）は `update_mode === 'auto'` のみを見ていたため、**「API」を選んでも自動更新の対象にならず**「手動」と同じ結果になっていた。UI から自動更新を ON にする手段が存在しない状態だった。
  - 既存 listing の `update_mode` は既定値の `'auto'`（REST が補完）である一方その値は選択肢に無いため、画面には先頭の「手動」が表示されていた（実データと表示の不一致）。
  - UI から追加した listing は `update_mode: 'manual'` 固定で、**追加した時点で自動更新の対象外**だった。
  - 旧 UI で「API」を選んで保存済みの listing（`update_mode: 'api'`）は、自動更新のつもりで設定されたものとして `'auto'` の別表記に扱い救済する。

### 変更

- **listing の自動更新スイッチを「自動更新」トグル（`auto_update`）1 つに集約した。** 「更新モード」セレクトは撤去。トグルはプラットフォームに関わらず常に表示し、`update_mode` は REST フィールドとして互換のため残す（既定 `auto`）。トグル操作時に `update_mode` も `'auto'` へ正規化するため、旧 UI が書いた値は編集した時点で解消される。
  - トグルの help に、OFF でも設定 →「強制一括更新」では更新されること、Provider が手動入力のプラットフォームでは ON でも取得されないことを明記した。
- 設定画面のボタンラベルを **「強制一括更新（取扱終了も含む）」→「強制一括更新（自動更新 OFF も含む）」** に修正した。`stock_status` は自動更新の対象判定に一切使われておらず、`force` が広げるのは `auto_update=false` の listing のみのため、旧ラベルは実態と異なっていた。
- UI から追加する listing の既定を `update_mode: 'auto'` / `auto_update: true` に変更した。

### 移行

- データ移行は不要。REST 経由で作成した listing は `update_mode: 'auto'` / `auto_update: true` のままなので**挙動は変わらない**。自動更新を止めたい listing だけ、商品編集画面で「自動更新」を OFF にする。
- 保存済みの `update_mode` は引き続き判定に使われる。旧 UI で「手動」を選んだ listing は `auto_update` が `true` でも自動更新されない（「API」を選んだ listing は上記のとおり `auto` として扱われる）。トグルを一度操作すれば `auto` へ正規化される。

## [3.2.1] - 2026-08-03

### 修正

- **DMM の価格更新が別の巻のデータで listing を上書きしていた。** `DmmProvider::fetch()` が商品 ID を `cid`（ID 直引き）ではなく **`keyword` に渡していた**ため、DMM の keyword 検索が**シリーズごとに最新巻 1 件だけを返す**仕様に当たり、30 巻の content_id で検索すると 39 巻が返っていた（2026-08-03 実測）。その結果が listing に書き戻され、**価格・表紙・商品 URL・アフィリエイト URL がすべて別の巻のものに置き換わる**（読者は 30 巻のカードから 39 巻を買わされる）。`cid` で直引きするよう修正した。

## [3.2.0] - 2026-08-03

### 追加

- DMM アカウントに **「アフィリエイト ID（リンク埋め込み用）」**（`affiliate_link_id`・必須）を追加。DMM のアフィリエイト ID は用途で 2 つに分かれ、**API リクエストに使えるのは末尾 990〜999 の ID だけ**（DMM 側の制限）、**実際のリンクに載せる `af_id` はサイト単位で発行される別 ID** のため、1 項目では両立できない。

### 修正

- **DMM のアフィリエイトリンクが無効リンク（HTTP 400）になっていた。** `ItemList` は**リクエストに使った `affiliate_id` をそのまま応答の `affiliateURL` に埋めて返す**ため、その値をそのまま採用していた従来実装では `af_id` が API 用 ID になり、`al.dmm.com` がリンクを受け付けなかった。商品 URL と新設のリンク埋め込み用 ID から自前で組み立てるように変更した。
  - 価格更新 Cron は取得値が非空なら `affiliate_url` を上書きするため、手で正しいリンクを登録しても最初の更新で無効リンクに置き換わっていた。
  - リンク埋め込み用 ID が未設定のときは**空文字を返す**。Cron は空の取得値では既存値を保持するので、手で登録した正しいリンクを壊さない（カードは `regular_url` へフォールバックする）。

### 移行

- 設定 → アカウント → DMM を開き、**リンク埋め込み用のアフィリエイト ID を入力して保存する**。必須項目のため未入力だと保存できない。未入力のままでも既存リンクは壊れないが、自動取得したリンクは収益化されない。

## [3.1.1] - 2026-08-01

### Changed

- 開発/ビルド依存を更新: `@wordpress/scripts` 32.6.0 → 33.0.0、`@testing-library/jest-dom` 6.9.1 → 7.0.0、`axios` 1.16.1 → 1.19.0、`shell-quote` 1.8.4 → 1.10.0（Dependabot #93 / #92 / #88 / #95）。
- CI: `actions/setup-node` を 6 → 7 に更新（#90）。

`src/` に変更はありません。`@wordpress/scripts` の更新が `build/` の出力（配布 zip）に影響するため、patch リリースとして反映します。

## [3.1.0] - 2026-07-31

### 追加

- **商品画像を表示しない**グローバル設定（設定 → 一般 →「商品画像を表示しない」・既定はオフ）。有効にするとすべてのカードで商品画像を描画せず、画像カラムごと畳んで本文を全幅にする（`affilicard-card--no-media`）。
  - 「画像がありません」のプレースホルダには落とさない（読み込み失敗に見えるため）。マスク（ぼかし／R18）表示も同時に抑止する。
  - ブロック単位の上書きもできる。ブロックサイドバーの「商品画像」→「サイト設定に従う／表示する／表示しない」（属性 `hideMedia`）。REST のカードプレビューにも転送するので、編集画面と保存後の表示が一致する。デモページに通常表示との対照サンプルを追加。
- `tools/render-preview.php`: WordPress を起動せずカードの HTML を吐き、`assets/card.css` を当てた状態で見え方を確認するための開発用スクリプト。

### 修正

- 在庫切れ・取扱終了のバッジがカード下端に貼り付いていた。これらは CTA を出さないためバッジが本文の最後の要素になり、商品画像も無いと本文がカードの高さをそのまま決めて下辺と接していた。本文の最後が店舗リスト以外のときに下マージンを入れるようにした。

## [3.0.0] - 2026-07-27

### Fixed

- **商品カードの CTA ボタンの並びを、listing の登録順ではなくプラットフォーム設定の「表示順」で決めるようにした**。従来は商品メタ `affilicard_listings` の登録順でそのまま描画していたため、記事生成後に別ストアの listing を追記する運用では追記分が末尾に付き、同一記事内でカードごとにボタン位置が食い違っていた。`CardRenderer` が表示対象 listing を `displayOrder` 昇順（同値は登録順を保つ安定ソート）に並べ替えるようになり、公開済みの記事も再投稿なしで揃う。
- **商品カードのサムネイル（書影）もプラットフォーム設定の「表示順」で選ぶようにした**。表示順の先頭から走査し、書影 URL を持つ最初の listing の画像を採る（無ければ次の listing、どれにも無ければ WordPress のアイキャッチへフォールバック）。CTA ボタンの並びと書影の出所が一致する。

### Added

- **プラットフォーム設定に並べ替え UI を追加**した。各商品タイプタブで、行の左に「カード上で何番目に出るか」を示す順位バッジと ↑ / ↓ ボタンを置き、押すと行が上下に滑って入れ替わる（FLIP アニメーション。`prefers-reduced-motion: reduce` では即座に反映）。↑ / ↓ を押した時点で `displayOrder` を 1..N の連番へ正規化し、「保存」で永続化する。
- 並べ替えリストの直前に説明を追加した。「この順番で商品カードのボタンが上から並びます」「無効なプラットフォームはカードに表示されないため、この順番には含まれません」「順番が意味を持つのはタブ（商品タイプ）の中だけです」「『保存』を押すと、公開済みの記事のカードにも反映されます」の 4 点を明示する。
- 並べ替えボタンにはプラットフォーム名を含む `aria-label` を付け、移動結果を `aria-live="polite"` で通知する。端に到達してボタンが無効化された場合は、同じ行のもう一方のボタンへフォーカスを移す。

### Changed

- 無効なプラットフォームは並べ替えの対象外とし、淡色表示・順位バッジ `—`・↑ / ↓ 非表示にした。`displayOrder` の値自体は保持するため、再有効化すれば元の位置に戻る。
- プラットフォーム設定でどの行も既定では展開しないようにした。従来は先頭の 1 件が自動で開いていたが、並べ替えで先頭が入れ替わるたびに開閉が移動して操作が追いにくいため。

### Removed

- プラットフォーム設定の各アコーディオン内にあった「表示順」の数値入力を撤去した。↑ / ↓ と二重の入力口になり、値が食い違うと並びが壊れるため。
- **破壊的変更**: プラットフォームの `imagePriority`（画像優先度）を撤去した。書影の選択を「表示順」に統合したため役目を失ったもので、設定として存在するのに描画へ効かない値を残さない判断による。`PlatformDefinition` のコンストラクタ引数・プロパティと `GET/PUT /affilicard/v1/platforms` のペイロードからキーが消える。既存インストールのオプションに残った値は読み捨てられ、次回保存時に消える（マイグレーション不要）。「ボタンの並びとは別の優先度で書影を選ぶ」使い分けはできなくなる。

## [2.4.0] - 2026-07-24

### Added

- **価格更新を Action Scheduler ベースの非同期キューに移行**した。手動一括更新・cron・公開/更新イベントは「更新ジョブをキューに投入」するだけで即座に返し、実際の価格取得はバックグラウンドの Action Scheduler ランナーが順次処理する。Action Scheduler はプラグインに bundle し、プラグイン読み込み時に同期 require する。
- **アカウント別レート制限耐性**（`RateLimiter`）を追加。共有 API（アカウント）ごとの最小リクエスト間隔（`ProviderInterface::minRequestIntervalMs()`／楽天=1100ms・DMM=1000ms・手動=0）を **options テーブルの条件付き UPDATE（compare-and-set）でプロセス間アトミックに acquire** し、並行ワーカーでも 1 呼び出しだけが取得する。間隔未経過のジョブはワーカーをブロックせず後ろ倒し再スケジュールする。間隔は 429 バックオフ（指数・上限 1h クランプ）でも延長する。管理画面からアカウント別に上書き可能。
- **鮮度スキップ／再取得クールダウン**: cron 掃引は listing の **`last_fetched_at`（最終試行時刻・成功/失敗問わず記録）が TTL 内なら再投入しない**（`PriceFreshness::needsRefetch()`）。成功 listing は TTL 毎に更新、**恒久的に失敗する listing も TTL 毎に1回だけ再試行**され、毎掃引の連打・キュー肥大を防ぐ（競合の「24h キャッシュ／数時間待つ」に整合）。表示鮮度（`isPriceDisplayable`＝last_verified_at）は不変。
- **再取得の適応リード**: 表示鮮度ゲート（`isPriceDisplayable`）は Amazon Creators API・楽天ウェブサービス・DMM いずれの規約とも整合する「価格は取得後 24h まで表示可」に従い **24h（`priceTtlHours`）を維持**する。一方で `needsRefetch` は **掃引間隔 + バッファ（`PriceFreshness::sweepLeadSeconds`）ぶんだけ表示期限より手前で発火**させ（`Enqueuer` のコンストラクタ `sweepLeadSeconds`）、価格が 24h に達する前に再取得・再確認を完了させる。これにより、再取得と表示期限が同じ 24h でバッファゼロだった従来構成で正常運用中に価格が数時間途切れる問題を解消する（再取得しきい値は過剰な API 呼び出しを避けるため `priceTtlHours` の 1/2 未満には下げない）。
- **トリガーの層構造**: 公開/更新時に記事内商品を force 投入（`PublishTrigger`・`parse_blocks` で解決）、future→publish 昇格・手動更新も enqueue 化。dedup は Action Scheduler ネイティブの `$unique`、優先度は `$priority`（force=0/手動=10/掃引=20）で表現する。
- **AutoCreate の非同期化**: 未登録ブロックのフロント描画時に同期 API を叩く従来動作を廃し、生成ジョブを enqueue するだけにした（描画の同期 HTTP を除去）。
- **キュー管理 UI**（設定→更新キュー）: **アカウント別**（DMM／楽天。認証画面と単位を統一）の pending/in-progress/failed 集計・キュー深さ・pause トグル・アカウント別スロットル/保持期間設定・一括操作（全削除/failed 削除/failed 再試行/pending キャンセル）。AS group は `affilicard-{account}`。各設定に説明文を付け、一般設定と同じスタイルに揃えた。REST は `manage_options`。
- **失敗ハンドリング**: リトライ上限（既定 5 回・指数バックオフ）到達時に**例外を送出して Action Scheduler の "failed" として記録**する（従来は complete 扱いで失敗が不可視だった）。これによりキューパネルの失敗件数・「失敗を再試行」・Scheduled Actions の失敗フィルタが機能する。
- **更新キュー（ジョブ一覧）を affilicard 自身のメニューに埋め込み**（Phase2 §11-3）。商品一覧の子メニューに「更新キュー（ジョブ一覧）」を追加し、Action Scheduler の一覧（Tools→Scheduled Actions と同じ描画・`ActionScheduler_AdminView::render_admin_ui()` を再利用）をそのまま表示する。検索欄を既定で `affilicard` に絞り込み、Tools に移動しなくても affilicard のジョブを確認できるようにした。AS 自体は同梱パッケージに翻訳を含まないため、affilicard 側で用意した限定的な日本語 .mo（`languages/action-scheduler-ja.mo`。プレースホルダ・複数形を含む文字列は誤翻訳リスクのため対象外）を ja ロケール時のみ明示ロードする。AS 管理ビューが読み込めない環境では Tools 側へのリンクにフォールバックする。
- ログ保持期間を Action Scheduler の retention フィルタに連動（完了=時間・失敗=日数、既定 24h/7日）。商品一覧の Fallback 列にキュー待ち/失敗理由を連携（provider エラー文字列は `wp_strip_all_tags`＋`esc_attr` の二重防御）。
- 運用ドキュメント `docs/operations-refresh-queue.md`（サーバ実 cron＋`wp action-scheduler run` 推奨）を追加。
- プラットフォーム設定に**手動→自動取得への切替を促すヒント**を追加。自動 Provider に対応（`eligibleProvider` 非空）しつつ「価格の取得方法」が『手動入力』（`provider === 'manual'`）のままのプラットフォームで、provider トグル直下に `Notice`（warning）を表示し、自動更新には取得方法の切替（＋別途 API 認証情報の登録）が必要な旨を案内する。認証登録済みでも取得が始まらない発見性の低さを解消する。
- カード下部の価格鮮度の免責文言を**日付のみから日付＋時刻（サイトのタイムゾーン）**に変更（`※ YYYY年M月D日 HH:MM時点の価格です`）。日付のみだと「時点」が最大24時間の幅を持ち、規約（Amazon Creators API/楽天/DMM とも価格は取得後24h以内の表示）に照らして期限超過に見え得るため、時刻まで明示して「いつ確認したか」を一意にした。
- **自動更新（`cron_enabled`）の既定を ON** に変更（従来 OFF）。価格を規約準拠の 24h 以内に保つ自動更新は本プラグインの中核で、OFF のままだと価格が順次非表示になるため。新規 install で自動取得プロバイダ未設定なら掃引は何も積まず無害（空回りの WP-Cron イベントのみ）。既に `cron_enabled` を保存済みのサイトは既定変更の影響を受けない（保存値が優先）。
- 既に自動更新を OFF で保存しているサイト向けに、**自動取得プロバイダが設定済みなのに自動更新が無効なとき、affilicard 管理画面に注意通知**（`CronDisabledNotice`）を表示。「設定 → 一般で有効化」リンクと「今後表示しない」（ユーザーメタで永続）を備え、価格が 24h で非表示になる旨を案内する。全て手動運用のサイトでは表示しない。

### Changed

- 手動更新 REST（`/affilicard/v1/refresh`）・product CPT の future→publish 昇格を、同期処理から Action Scheduler enqueue に変更した（`force` パラメータの「auto_update=false も対象」挙動は維持）。
- 内部整理: listing の適格性判定（`update_mode`/`enabled`/`auto_update`）を共有ヘルパ `ListingEligibility` に集約。`RefreshHandler` が worker 実行時に `update_mode`/`enabled` を再チェックし、enqueue 後に無効化・手動化された listing を取りこぼさない（TOCTOU 対策。`force` 用に `auto_update` は見ない）。`last_fetched_at` を実 UTC（`gmdate`）で記録。同期スイープ時代の死コード（`ListingRefresher::run()`/`refreshProduct()` 等）を削除。

### Fixed

- **掃引ジョブの決定的スタガリング**でキュー・チャーンを根本抑制。同一 account の sweep ジョブをランダム jitter ではなく実効レート間隔（`minRequestIntervalMs` と管理画面 override の大きい方）ぶんずつ確定的にずらして積むようにし、複数ジョブが同一レート窓へ集中→`RateLimiter` に弾かれ throttle 再投入される completed アクションのチャーン（Playground 実測「1商品に33回」）を回避する。
- **恒久失敗 listing の give-up マーカー**を追加。**terminal（該当なし・無効 ID）のみ give-up し、transient（API 到達不可・レート制限・保存競合等の一時障害）はリトライして give-up しない**。Provider の取得結果を `FetchResult`（hit=成功／miss=恒久失敗／error=一時失敗）で3値分類し、`WorkOutcome`（SUCCESS／TERMINAL_FAILURE／TRANSIENT_FAILURE）でワーカーの帰結を表す。恒久失敗 listing は即 give-up マーカーを立て `GIVEUP_COOLDOWN`（3 日）掃引でスキップ、fetch 成功でマーカーを消し復旧した listing を通常周期に戻す。一時障害はバックオフでリトライを続け（上限で failed 化はするが give-up マーカーは立てない）、一時障害が続いても価格が 3 日間隠れ続けない（規約上 24h 経過で表示が消える元インシデントを防ぐ）。

## [2.3.0] - 2026-07-21

### Added

- General 設定に **全体更新間隔** `refresh_interval_hours`（既定 3h）を追加し、価格自動更新を「全プラットフォーム一括・単一グローバル間隔」に変更した。`RefreshScheduler` は単一グローバル cron `affilicard_refresh_all` で動作し、自動更新の対象は各プラットフォームの `provider !== 'manual'` から導出する（per-platform の `autoRefresh`・`refreshIntervalHours` は廃止）。カード下部の更新日時表示が全プラットフォームで整合するようになった。
- 一括更新・強制一括更新（General パネル）／プラットフォーム個別更新ボタンに実行フィードバックを追加。実行中はボタンを disabled にして「更新中…」を表示し、完了時に成功/失敗を通知する。通知文言は「価格更新を実行しました。反映結果は各商品の価格・『最終同期』でご確認ください。」とし、全商品の価格取得成功を意味しない旨を明確にした。
- 商品一覧に「最終同期」列を追加。商品ごとに listing の最新 `last_verified_at`（API で価格を確認・同期した時刻）を `wp_date` でローカライズ表示する。手動入力のみの商品は「—」を表示する。
- 既存インストール向けに `eligibleProvider` バックフィル migration を追加（`rakuten-kobo` → `rakuten-kobo`、`dmm-books` → `dmm-ebook`。値が空の場合のみ・専用フラグで一度きり実行）。

### Changed

- プラットフォーム編集の Provider UI を「手動入力 ⇄ 自動取得（対応 Provider 名を表示）」のトグル 1 つに簡素化し、見出しを「価格の取得方法」に変更した。矛盾しうる従来の複数 UI（Provider プルダウン＋更新頻度入力等）を撤廃し、`eligibleProvider` を持たないプラットフォームは手動固定にした。

### Fixed

- `ProductAutoCreator` が自動作成時に `last_verified_at`（UTC）を刻むようにし、`ListingRefresher` と対称になるよう修正した（#87）。

## [2.2.0] - 2026-07-21

### Added

- 楽天Kobo の自動 Provider（`RakutenProvider`）を **title 検索 → URLハッシュ一致同定** 方式へ再設計。楽天 API は listing 保存済みの external_id（URLハッシュ）をキーワードとして再検索できないため、商品タイトルで検索した結果を listing の URL と突き合わせて同一商品を同定するようにした。
- **API 準拠の価格鮮度表示**を追加。価格は API で確認済み・鮮度内（既定 TTL 24h）の listing でのみカードに表示し、確認できない/期限切れの listing は非表示にする。判定ロジックを共有ヘルパ `PriceFreshness::isPriceDisplayable()` に集約し、`CardRenderer`（カード表示ゲート・「※ YYYY年M月D日時点の価格です」免責文言の基準を `last_verified_at` へ変更）と商品一覧の `ProductListColumns`（価格非表示 = 未確認/期限切れ時の警告アイコン）で共用する。`ListingRefresher` は更新成功時に `last_verified_at` を記録するようになった。
- 更新頻度（Cron）を **N時間毎**（既定 3h）で設定できるように変更。`PlatformDefinition` に `priceTtlHours`・`refreshIntervalHours` を追加（`refreshFrequency` を置換）し、`RefreshScheduler` を `refreshIntervalHours` ベースの動的スケジュールに対応させた。設定画面 UI も時間指定の入力に変更。
- `PlatformDefinition` に `eligibleProvider` を追加。対応する自動 Provider を持つプラットフォームは、現在 manual 運用中でも設定画面の Provider 切替候補に自動 Provider が表示されるようになった（`providerOptionsFor`）。既定の楽天Kobo は自動 Provider（`rakuten-kobo`）＋ `eligibleProvider` に変更。

### Changed

- 設定画面の Provider プルダウンを「manual ＋ そのプラットフォームに対応する自動 Provider」のみに絞り込み、platform に無関係な自動 Provider（例: Amazon Kindle に DMM Provider）を誤って割り当てられないようにした（`providerOptionsFor`。credentials 用の全 Provider 一覧は従来どおり温存）（#85）。
- 既定プラットフォーム定義から BookWalker を除去（ebook 4件 → 3件、全 9件 → 8件）。BookWalker は ASP 登録のみで商品カード（レーン1: Amazon/楽天Kobo/DMM）の対象外のため、新規インストール時の既定 seed に含めないようにした。既存サイトの設定には影響しない（#85）。

## [2.1.1] - 2026-07-18

### Changed

- 開発/ビルド依存を更新: `@wordpress/components` 35.0.1 → 36.1.0、`@wordpress/data` 10.48.1 → 10.50.0、`@wordpress/element` 8.1.0 → 8.2.0、`@wordpress/api-fetch` 7.48.1 → 7.50.0、`@wordpress/i18n` 6.22.0 → 6.23.0（Dependabot #70 / #71 / #74 / #73 / #72）。
- CI: `actions/cache` を 5 → 6 に更新（#61）。

いずれも開発/ビルド・CI 依存の更新で、配布 zip の実行時挙動に変更はありません。

## [2.1.0]

### Added

- カード書影を表示中ストアの `imagePriority`（DMM > Amazon > 楽天Kobo）順で各ストア CDN 画像から選ぶようにした。`only-platform` で表示を絞ると書影もそれに追従する。listing に画像が無ければ従来どおり投稿アイキャッチにフォールバック。
- プラットフォーム設定に「画像優先度（imagePriority）」入力を追加（既定 999・後方互換）。

## [2.0.0] - 2026-07-14

### Changed (BREAKING)

- 認証情報の保存単位を provider 単位から **account 単位**（`affilicard_account_<code>_credentials`）へ変更。`ProviderInterface` から `credentialsSchema()` を撤去し `accountCode()` を追加、スキーマは `AccountInterface` へ移設。
- 認証 REST を再構成: credentials は `/accounts/{code}/credentials`（GET/PUT/DELETE）、接続テストは `/providers/{code}/test-connection`（POST・保存前テスト）。旧 platform/provider credentials ルートは撤去。
- 設定画面の認証フィールドを **write-only ＋ dirty 追跡** 化（未編集の秘匿値を再送しない）。required をサーバ検証。認証パネルを account 単位の折り畳み＋provider 単位の接続テストへ刷新。
- Provider スキーマを PHP（`AccountUiList`/`ProviderUiList`）から `window.affilicardAccounts`/`window.affilicardProviders` として注入し、JS のハードコードを廃止。
- 楽天 API transport を `RakutenClient` に分離。

### Notes

- 未公開のため移行は行わない。旧 `affilicard_provider_*` credentials はアップグレード時に削除される。

## [1.9.0] - 2026-07-13

### Added

- 楽天Kobo 電子書籍検索 API を使った自動取得 Provider（`RakutenProvider`）を追加。価格・書影・作品URL・アフィリエイトURL・配信日を取得する。2026 年の楽天 API 刷新（`openapi.rakuten.co.jp`・`accessKey` ヘッダ・`Origin` 必須）に対応。

## [1.8.1] - 2026-07-12

### Changed

- 画像なしプレースホルダの表示ラベルを商品タイプ名（書影/商品画像/キービジュアル）から中立の「画像がありません」に変更（タイプ名の表示は「読み込み失敗」に見えるため）。タイプ名はスクリーンリーダー向けの aria-label（「〜がありません」）として保持。

## [1.8.0] - 2026-07-12

### Added

- 商品カードのメディア枠を product_type ごとのアスペクト比で固定（電子書籍 2:3／汎用・動画配信 1:1・実測ベース）。実画像は `object-fit: contain` で枠内に収め、比率の異なる画像でもレイアウトが崩れない。
- 画像が無いときのプレースホルダを、汎用アイコン＋商品タイプ別ラベル（商品画像／書影／キービジュアル）で意匠化。

### Changed

- メディア枠のアスペクト比固定に伴い、商品カードの書影マークアップを更新（マスクなし画像の従来マークアップから変更）。
- `ProductTypeInterface` に `cardMediaAspectRatio()` を追加。`AbstractProductType` を継承する型（本プラグインの全既定型）は既定値 `1 / 1` を自動で得るため影響なし。`ProductTypeInterface` を直接実装する外部型がある場合は同メソッド（`string` を返す）の追加が必要。

## [1.7.0] - 2026-07-07

### Added

- 商品カードの表紙マスク機能（ぼかし／R18 18+ バッジ＋ぼかし強制／任意ラベル）。商品 meta とブロック属性の両方で設定でき、ブロック属性を優先・未設定は商品 meta を継承する。マスクは時刻非依存で予約表示とは独立。

## [1.6.1] - 2026-06-30

### Fixed

- 予約バッジ（「予約受付中」）と発売日ラベルを横並び（flex）表示に変更。従来は発売日が `<div>` でバッジの下行に折れ返っていたが、`affilicard-card__preorder` flex コンテナでまとめ、発売日を `<span>` に変更することで同一行に横並び表示されるよう修正。
- DMMブックスの CTA ラベルを「この値段で読む →」から「DMMブックスで読む」に統一し、他プラットフォーム（Kindleで読む / 楽天Koboで読む / BookWalkerで読む）と表記を揃えた。

## [1.6.0] - 2026-06-29

### Added

- 商品カードに**予約（発売前）状態**を追加。商品に発売日 `release_date`（`YYYY-MM-DD`）を持たせると、カード描画時に `now < release_date` の場合は「予約受付中」バッジ＋発売日表示＋CTA「予約する」で描画し、**発売日を過ぎると自動的に通常表示へ戻る**（再取得・Cron 不要）。在庫バッジとは別系統で、予約中も CTA は隠さない。CTA ラベルの優先順は block override > listing override > 予約既定「予約する」> platform 既定。
- `release_date` を商品メタ `affilicard_release_date` として永続化（REST CRUD `ProductSchema` ＋ `register_post_meta`／`YYYY-MM-DD` のみ許可）。商品編集 metabox に発売日の日付コントロールを追加。
- 発売日由来の予約判定を行う純粋ヘルパ `Affilicard\Stock\ReleaseDate`（時刻は引数で受け取りテスト可能）。時刻依存は `CardHtmlBuilder` に閉じ、`CardRenderer` は純粋レンダラを維持。

## [1.5.0] - 2026-06-28

### Added

- 商品カードに `onlyPlatforms`（表示プラットフォーム許可リスト）属性を追加。指定した platform の listing のみ描画する（既存 `hidePlatforms` と併用可、未指定なら全表示）。ブロックエディタに選択 UI を追加し、**エディタプレビューにも `onlyPlatforms` を反映**（card-preview REST 経由）。

### Fixed

- 監査で確認した既存バグを修正（#57）:
  - `Uninstall` が現行オプション（`affilicard_platforms` / `affilicard_general` / `affilicard_seeded_at`）と provider credentials を削除せずデータ残留・再インストール seed 不発になる問題を修正。
  - REST の商品 create/update が `publish_posts` を検証せず公開できた問題を修正（公開権限が無ければ `pending` に降格）。
  - `Block` の autoCreate が失敗時に transient ロックを解放せず 5 分間リトライ不能になる問題を修正。
  - extid mirror の再書き込みで stale な `affilicard_extid_*` meta が残り誤 upsert を招く問題を修正。
- 日時フッターが非表示プラットフォーム（`onlyPlatforms`/`hidePlatforms`/URL 無し）の `last_fetched_at` を参照していた不整合を修正し、表示中の listing 集合に揃えた。

## [1.4.1] - 2026-06-21

### Fixed

- 管理画面からの自動更新（plugin-update-checker）が検知されない不具合を修正。PUC はタグ名ではなく**タグにコミットされた `affilicard.php` の `Version:` ヘッダ**を最新版数として採用するため、ソースを `0.1.0` のままビルド時のみ注入する運用では常に「最新は 0.1.0」と誤判定されていた。`affilicard.php` / `package.json` の版数をコミット値として同期し、release ワークフローに「コミット済み版数＝タグ」検証ガードを追加して再発を防止
- 機能内容は v1.4.0 と同一（list_price 取り消し線表示・カードのレスポンシブ重なり/右はみ出し修正）

## [1.4.0] - 2026-06-21

### Added

- 商品カードの価格エリアに通常価格（`list_price`）の取り消し線表示を追加。`list_price` と `price` が共に正の数値で `list_price > price` のとき、`price` の前に取り消し線で通常価格を描画する（割引バッジと併存可）

### Fixed

- 商品カードのレスポンシブ切替をコンテナクエリ化（`container-type` + `@container`）し、サイドバー等でカードが狭いコンテナに入った場合に価格とボタンが重なる・カード右端からはみ出す不具合を修正（判定基準を viewport からカード自身の幅へ）

## [1.3.0] - 2026-06-20

### Added

- 投稿ブロックエディタに公開と同じカードの WYSIWYG プレビュー（認証済み専用 REST `GET affilicard/v1/products/{id}/card-preview` 経由・status 非依存。フロントの publish ガードは不変）
- CTA ラベルのブロック単位上書き（優先順位: ブロック属性 > listing `button_label_override` > プラットフォーム既定）。`block.json` に `ctaLabelOverrides` 属性を追加
- 商品検索の強化: external_id（`affilicard_extid_*` ミラー）の OR 検索、空入力時の最近商品表示、候補のサムネイル＋プラットフォーム＋価格リッチ表示（`__experimentalRenderItem`・不在時テキストフォールバック）

### Changed

- カード描画ロジックを `CardHtmlBuilder` に抽出し、フロント `Block::render` と REST プレビューで共有
- 商品一覧 REST（`/products`）の検索を `ProductRepository::search()` に集約し、各項目に thumbnail/price/platform を付与

## [1.2.0] - 2026-06-20

### Added

- 商品 CPT 登録画面の編集 UI を Gutenberg 右文書サイドバー（`PluginDocumentSettingPanel`）へ移行。商品タイプ・在庫・追加情報・プラットフォーム listing をサイドパネルで編集
- listings/extras をネイティブ配列メタ（`register_post_meta` `type=array` + `show_in_rest` スキーマ）として保存し、Gutenberg core-data（`useEntityProp`）で保存・読み込み
- CPT に `custom-fields` support を追加（`register_post_meta(show_in_rest)` を REST 応答の `meta` として露出させ Gutenberg で保存可能にするため必須）
- 未認証 REST read を拒否する `ProductRestController`（read 系 permission を `edit_posts` 必須に上書き）
- 商品 CPT を `show_in_rest=true` 化（Gutenberg 本文編集を有効化）。本文は `post_content`
- プラットフォーム listing のアコーディオン表示（`Panel`/`PanelBody`）とサイドパネルの余白規律 CSS
- 商品設定の各入力欄にプレースホルダを追加

### Changed

- 商品説明をクラシックエディタから Gutenberg ブロックエディタ（本文）へ
- 派生 meta（external_id ミラー・schema_version）を `rest_after_insert` フックで保存後に同期（autosave/revision はスキップ）

### Removed

- クラシックメタボックス（hidden textarea + `$_POST` 保存）を撤去し、core-data 保存へ全面移行

## [1.1.0] - 2026-06-16

### Added

- 設定画面のプラットフォーム設定を商品タイプ（電子書籍 / VOD …）別のサブタブに分割し、各タイプのプラットフォームを `PanelBody` の折りたたみで表示
- 「API 認証」サブタブを新設し、認証情報を **Provider 単位で 1 回だけ**編集できる `ApiCredentialsPanel` を追加（同一 API を複数プラットフォームで重複入力させない）
- 認証情報の provider 単位 REST ルート `/affilicard/v1/providers/{code}/credentials`（GET/PUT）・`/providers/{code}/test-connection`（POST）
- 設定画面の余白規律 CSS（`assets/admin-settings.css`、`#affilicard-settings-root` スコープ）

### Changed

- 各プラットフォーム編集を `PanelBody` の折りたたみに変更し、API 連携（自動取得）系フィールドをサブセクションへ整理（手動運用を前面に）
- `CredentialEditor` を platform 単位から provider 単位（`providerCode`）に変更し、Provider 定数を `src/Admin/providers.js` に集約
- 一般設定タブのフィールド／ボタン配置に余白規律を適用

## [0.3.2] - 2026-06-15

### Fixed

- 汎用型（書誌ヘッダが無いタイプ）でカードのタイトルが上端に詰まる不具合を修正（`.affilicard-card__body` に上パディングを付与し、先頭要素の上マージンを相殺）

## [0.3.1] - 2026-06-08

### Fixed

- extras の日本語が `著...` のように壊れて保存・表示される不具合を修正（`JsonField::encode` に `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` を付与。`update_post_meta` の `wp_unslash` がバックスラッシュを除去して壊す根本原因に対処）

### Changed

- 商品カードを電子書籍向け本番デザインに刷新：書影 2 カラム（左 160px / SP 全幅）・著者/出版社の書誌ヘッダ・あらすじ・「店名 ｜ ¥価格（税込）＋割引バッジ ｜ CTA」の店舗行（`<ul>/<li>`）・価格時点フッタ（listing の最新 `last_fetched_at` 由来）。CTA はプラットフォーム別ブランド色を維持し、`--affilicard-*` CSS 変数によるテーマ色連携も維持

## [0.3.0] - 2026-06-03

### Added

- Block で `externalId + platform` 指定時、CPT 不在なら Provider 経由で商品を auto-create（`affilicard_autocreate_*` transient で連打抑止、生成は publish）
- プラットフォーム単位の API 価格自動更新設定（PlatformDefinition の `autoRefresh` / `refreshFrequency`=daily/weekly）と、それに応じた WP-Cron `affilicard_refresh_platform`（platform ごとに hook 引数で登録。グローバル `cron_enabled` がマスタースイッチ）
- 価格更新の手動トリガー REST `POST /affilicard/v1/refresh`（全体 / `platform` 別、`force` で取扱終了 listing も更新）と、General 設定の「一括更新」「強制一括更新」ボタン・各 Platform の「今すぐ更新」ボタン
- 予約投稿（future）→ publish 昇格時に listing を最新価格へ refresh（`transition_post_status`）
- `Provider::fetch()` 戻り値に `title`（auto-create 用）／ `GeneralSettings::isCronEnabled()` ／ `ProductRepositoryInterface`

### Notes

- 価格更新（自動 Cron・予約投稿昇格・通常の手動更新）の対象は公開中（publish）商品の `update_mode=auto && auto_update && enabled` listing のみ（非公開はスキップ、`auto_update=false` は更新しない）。「強制一括更新」のみ `auto_update=false` も対象。
- `cron_enabled` の ON/OFF・platform の `autoRefresh`/頻度に応じて WP-Cron を reconcile し、無効化時・プラグイン無効化時に解除

## [0.2.0] - 2026-06-03

### Added

- Gutenberg ブロック `affilicard/product-card`（React 編集 UI + サーバサイド render）
- 純粋・商品タイプ非依存のレンダラ `CardRenderer`（`--affilicard-*` CSS 変数によるテーマカラー連携、在庫切れ/取扱終了時の CTA 抑制、`affiliate_url ?? regular_url` フォールバック、`sanitize_hex_color` による色値検証）
- ブロック編集 UI：商品検索 ComboboxControl + InspectorControls 色設定パネル
- `ProductRepository::findBySlug()`
- 公開フロントでは公開ステータスの商品のみ描画するガード
- CI: リリース時に Git タグからバージョンを `affilicard.php` へ自動注入
- CI: PR ごとにビルド済みプラグインを WordPress Playground でプレビュー（`build/` を git 管理せず CI でビルド）
- CI: wp-env + Playwright による E2E テスト（ブロックのフロント描画＝CTA リンク・色 CSS 変数・在庫切れ時の CTA 抑制を検証）

### Fixed

- メタボックス保存: 商品の PATCH を真の部分更新にし、`title` 必須による 400 エラーと、未送信フィールド（タイトル等）の空文字上書きを修正
- メタボックス: 投稿の「公開／更新」で商品設定も保存されるようにし（独立保存ボタンを廃止）、Publish のみだと metabox データが欠落する問題を解消

## [0.1.0] - 2026-05-29

### Added

- 汎用 CPT `affilicard_product`、Settings（React）、Provider（Manual / DMM）、ProductType（Generic / Ebook）、REST API、在庫ステータス、Fallback 可視化
- WP 公式ディレクトリ非経由の自動更新（plugin-update-checker）と GitHub Release 自動生成 CI
