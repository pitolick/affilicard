<?php
/**
 * bundle した Action Scheduler（vendor/woocommerce/action-scheduler、textdomain
 * `action-scheduler`）向けの、限定的な日本語 .po/.mo をこのファイルと同じ languages/ 配下に
 * 生成する。vendor/ は .gitignore 対象で Action Scheduler 自体も翻訳ファイルを同梱していない
 * ため（Composer 配布物には languages/ が含まれない）、affilicard 側で自前の上書き翻訳を
 * 用意し、実行時に `load_textdomain( 'action-scheduler', ... )` で明示ロードする
 * （src/Queue/QueueJobsPage.php 参照）。
 *
 * 対象は「プレースホルダ（%s/%d 等）や複数形（_n/_n_noop）を含まない、
 * 埋め込みジョブ一覧（QueueJobsPage）で実際に描画される文字列」のみに限定する。
 * プレースホルダ・複数形を誤翻訳すると sprintf 崩れや、英語 nplurals=2 と日本語
 * nplurals=1 の不一致バグを作り込むリスクがあるため、意図的に翻訳対象から除外している。
 *
 * 再生成:
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php languages/generate-action-scheduler-ja-mo.php
 *
 * 出力:
 *   languages/action-scheduler-ja.po（人間可読な参照用・実行時には読み込まない）
 *   languages/action-scheduler-ja.mo（実行時に load_textdomain() で読み込むバイナリ）
 *
 * 設計: docs/superpowers/specs/2026-07-22-refresh-queue-design.md §11-3
 */

declare(strict_types=1);

$outDir = __DIR__;

// context なしエントリ: msgid => msgstr
$plain = array(
	// 列見出し（ActionScheduler_ListTable::__construct）
	'Hook'           => 'フック',
	'Status'         => 'ステータス',
	'Arguments'      => '引数',
	'Group'          => 'グループ',
	'Recurrence'     => '繰り返し',
	'Scheduled Date' => '実行予定日時',
	'Log'            => 'ログ',
	'Claim ID'       => 'クレームID',

	// テーブル見出し（table_header。Tools メニュー登録（register_menu）より後に
	// ロードするため、Tools > Scheduled Actions 側のメニュー文言には影響しない）
	'Scheduled Actions' => '登録済みジョブ',

	// 一括操作
	'Delete' => '削除',

	// 行アクション（name/desc）
	'Run'                                                          => '今すぐ実行',
	'Process the action now as if it were run as part of a queue' => 'キュー処理の一部として、このアクションを今すぐ実行します',
	'Cancel'                                                       => 'キャンセル',
	'Cancel the action now to avoid it being run in future'        => '今後実行されないよう、このアクションを今すぐキャンセルします',

	// 検索ボックス
	'Search hook, args and claim ID' => 'フック・引数・クレームIDで検索',

	// ステータスラベル（ActionScheduler_Store::get_status_labels）
	'Pending'     => '保留中',
	'In-progress' => '実行中',
	'Complete'    => '完了',
	'Failed'      => '失敗',
	'Canceled'    => 'キャンセル済み',

	// 繰り返し設定なし（get_recurrence）
	'Non-repeating' => '繰り返しなし',

	// スケジュール種別（get_schedule_display_string）
	'async' => '非同期',

	// extra_tablenav のフィルターボタン（filter_by 未使用のため通常は非表示だが安全に翻訳）
	'Filter' => 'フィルター',
);

// msgctxt 'status labels' 付きエントリ（display_filter_by_status の esc_html_x 呼び出し）
$withContext = array(
	array(
		'context' => 'status labels',
		'msgid'   => 'All',
		'msgstr'  => 'すべて',
	),
	array(
		'context' => 'status labels',
		'msgid'   => 'Past-due',
		'msgstr'  => '期限超過',
	),
);

// ---- .po（参照用・実行時には読み込まない） ----
$po  = "# Japanese translation for Action Scheduler admin UI strings used by affilicard's\n";
$po .= "# embedded queue-jobs page (src/Queue/QueueJobsPage.php). Hand-picked, non-exhaustive:\n";
$po .= "# limited to strings with no printf placeholders and no plural forms, to avoid\n";
$po .= "# introducing sprintf/plural-rule bugs. Regenerate with generate-action-scheduler-ja-mo.php.\n";
$po .= "# See docs/superpowers/specs/2026-07-22-refresh-queue-design.md §11-3.\n";
$po .= "msgid \"\"\n";
$po .= "msgstr \"\"\n";
$po .= "\"Project-Id-Version: Action Scheduler (affilicard bundled ja overlay)\\n\"\n";
$po .= "\"MIME-Version: 1.0\\n\"\n";
$po .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$po .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$po .= "\"Language: ja\\n\"\n";
$po .= "\"Plural-Forms: nplurals=1; plural=0;\\n\"\n\n";

/**
 * .po の文字列リテラルをエスケープする。
 */
function affilicard_as_ja_po_escape( string $s ): string {
	return str_replace( array( '\\', '"', "\n" ), array( '\\\\', '\\"', '\\n' ), $s );
}

/** @var list<array{0: ?string, 1: string, 2: string}> $entries context|null, msgid, msgstr */
$entries = array();
foreach ( $plain as $msgid => $msgstr ) {
	$entries[] = array( null, $msgid, $msgstr );
}
foreach ( $withContext as $row ) {
	$entries[] = array( $row['context'], $row['msgid'], $row['msgstr'] );
}

foreach ( $entries as $entry ) {
	list( $context, $msgid, $msgstr ) = $entry;
	if ( null !== $context ) {
		$po .= 'msgctxt "' . affilicard_as_ja_po_escape( $context ) . "\"\n";
	}
	$po .= 'msgid "' . affilicard_as_ja_po_escape( $msgid ) . "\"\n";
	$po .= 'msgstr "' . affilicard_as_ja_po_escape( $msgstr ) . "\"\n\n";
}

file_put_contents( $outDir . '/action-scheduler-ja.po', $po );

// ---- .mo（実行時ロード用バイナリ・GNU gettext MO format revision 0） ----
// キーは gettext 規約どおり "{context}\x04{msgid}"（context なしはそのまま msgid）。
$table     = array();
$table[''] = "Project-Id-Version: Action Scheduler (affilicard bundled ja overlay)\n"
	. "MIME-Version: 1.0\n"
	. "Content-Type: text/plain; charset=UTF-8\n"
	. "Content-Transfer-Encoding: 8bit\n"
	. "Language: ja\n"
	. "Plural-Forms: nplurals=1; plural=0;\n";

foreach ( $entries as $entry ) {
	list( $context, $msgid, $msgstr ) = $entry;
	$key           = ( null !== $context ) ? $context . "\x04" . $msgid : $msgid;
	$table[ $key ] = $msgstr;
}

ksort( $table, SORT_STRING );

$keys   = array_keys( $table );
$n      = count( $keys );
$ids    = '';
$strs   = '';
$idOff  = array();
$strOff = array();

$offset = 0;
foreach ( $keys as $k ) {
	$idOff[] = array( strlen( $k ), $offset );
	$ids    .= $k . "\0";
	$offset += strlen( $k ) + 1;
}
$offset = 0;
foreach ( $keys as $k ) {
	$v        = $table[ $k ];
	$strOff[] = array( strlen( $v ), $offset );
	$strs    .= $v . "\0";
	$offset  += strlen( $v ) + 1;
}

$headerSize    = 28;
$idTableStart  = $headerSize;
$strTableStart = $idTableStart + 8 * $n;
$idsStart      = $strTableStart + 8 * $n;
$strsStart     = $idsStart + strlen( $ids );

// hash table は使わない（size=0）。WP の MO リーダー（wp-includes/pomo/mo.php
// import_from_reader）は translations_lengths_length を
// `hash_addr - translations_lengths_addr` の引き算で導出するため、hash_addr は
// 「0」ではなく「翻訳長テーブルの直後（=文字列データの開始位置）」を指す必要がある
// （hash_length=0 のときは strings_addr = hash_addr + 0 になる）。
$mo  = pack( 'V', 0x950412de ); // magic
$mo .= pack( 'V', 0 );          // revision
$mo .= pack( 'V', $n );         // number of strings（ヘッダエントリ含む）
$mo .= pack( 'V', $idTableStart );
$mo .= pack( 'V', $strTableStart );
$mo .= pack( 'V', 0 );         // hash table size = 0（未使用）
$mo .= pack( 'V', $idsStart ); // hash table offset = 文字列データ開始位置

foreach ( $idOff as $pair ) {
	$mo .= pack( 'V', $pair[0] ) . pack( 'V', $idsStart + $pair[1] );
}
foreach ( $strOff as $pair ) {
	$mo .= pack( 'V', $pair[0] ) . pack( 'V', $strsStart + $pair[1] );
}
$mo .= $ids;
$mo .= $strs;

file_put_contents( $outDir . '/action-scheduler-ja.mo', $mo );

echo "Generated {$n} entries.\n";
echo 'PO: ' . $outDir . "/action-scheduler-ja.po\n";
echo 'MO: ' . $outDir . '/action-scheduler-ja.mo (' . strlen( $mo ) . " bytes)\n";
