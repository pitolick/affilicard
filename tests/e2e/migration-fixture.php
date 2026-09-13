<?php
/**
 * E2E 補助スクリプト（Task 16 — offers 移行の実 WP 検証）。`wp eval-file` でコンテナ内実行する。
 *
 * `PluginUpgrade::migrateListingToOffers()` は WP 非依存で PHPUnit 済みだが、
 * 移行バッチ本体（`runOffersMigrationBatch()` → `migrateOneProduct()`）が実際に
 * postmeta を読み書きし、`ProductSchema::sanitizeListings()` の whitelist を
 * 一度も通さず flat な v3 データを壊さずに `offers[]` へ変換できるかは、
 * WP_Mock のスタブでは確認できない。ここでは：
 *
 * 1. `$wpdb->insert()` で `wp_postmeta` に直接書き込み、REST/sanitizeListings を
 *    一切経由しない「素の flat legacy listing」（v3 以前の実データ形）を作る。
 *
 *    **`update_post_meta()` は使わない。** 当初はそれで「sanitize を経由しない生書き込み」
 *    のつもりだったが、実機で確認したところ誤りだった——`affilicard_listings` は
 *    `register_post_meta()` 済みのキーであり、WordPress core の
 *    `add_metadata()`/`update_metadata()`（wp-includes/meta.php）は登録済みキーに対して
 *    **呼び出し経路を問わず** `sanitize_meta()` を通す。つまり `update_post_meta()` で
 *    flat データを書いた**その場で** `ProductSchema::sanitizeListies()` が先回りして
 *    `offers[]` へ正規化してしまい（かつ新語彙の `fetch_status` しか読まない
 *    sanitizeOffers() は旧語彙の `fetch_error` を素通りさせて捨てる）、
 *    `migrateOneProduct()` が読む頃には既に `offers` キーが付いていて
 *    「移行済み」と誤判定され、何も変換されない。v3 以前の実データを模すには
 *    WP のメタ API を一切通さない `$wpdb` 直書き込みが必要（書き込み後に
 *    `wp_cache_delete()` でオブジェクトキャッシュも払う）。
 * 2. `regular_url` を持たず `affiliate_url` だけを持つ listing も 1 件混ぜる
 *    （一度 Critical で修正された「サイレントに消える」退行を検知する固定回帰）。
 * 3. `PluginUpgrade::runOffersMigrationBatch()` を「未完でなくなるまで」ループ実行し、
 *    実際に格納された listings・schema_version・運用向け温存カウンタを読み直す。
 *
 * `runOffersMigrationBatch()` を直接呼ぶのは、`plugins_loaded` 経由のバージョン差分
 * トリガーそのもの（option 比較）は PluginUpgradeTest（PHPUnit/WP_Mock）で既に
 * 検証済みであり、この E2E が確かめたいのは「トリガーが引かれるか」ではなく
 * 「実データに対して変換・保存が正しく起こるか」だから。バッチサイズ（200）は
 * 環境に既存の商品数（このリポジトリでは 2026-09 時点で 185 件超）次第で複数回に
 * 分かれ得るため、`isOffersMigrationPending()` が false になるまでループする。
 *
 * **このファイルには `declare(strict_types=1);` を置けない**（seed.php と同じ理由）。
 * 出力: 1 行 `MIGRATION_JSON:{...}`
 */

use Affilicard\PostType\ProductPostType;
use Affilicard\Upgrade\PluginUpgrade;

// 前回実行分のフィクスチャを掃除する。global-setup は test:e2e 実行のたびに DB を
// リセットしないため、掃除しないと「温存カウンタの差分」の意味が壊れる
// （前回分の温存済み listing まで再度数えてしまうことはないが、商品が際限なく
// 積み上がるのを防ぐため。カウンタ自体は before/after の差分で比較する）。
foreach (
	get_posts(
		array(
			'post_type'      => ProductPostType::POST_TYPE,
			'post_status'    => 'any',
			's'              => 'E2E-MigrationFixture',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		)
	) as $stale_id
) {
	wp_delete_post( (int) $stale_id, true );
}

/**
 * `$repo->save()`/`saveMeta()` は使わない。saveMeta() は無条件に
 * META_SCHEMA_VERSION を SchemaVersion::CURRENT へ更新してしまい、
 * 「移行前は旧バージョンだった」という前提が壊れる。v3 以前のインストールを
 * 模すため、投稿作成は `wp_insert_post()` を使うが、listings メタだけは
 * `$wpdb->insert()` で `wp_postmeta` に直接書き込む（sanitize_meta() を一切経由しない）。
 *
 * @param array<int, array<string, mixed>> $listings
 */
$make_legacy = static function ( string $title, array $listings ): int {
	global $wpdb;

	$id = (int) wp_insert_post(
		array(
			'post_type'   => ProductPostType::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
		)
	);

	// register_post_meta 済みキーは update_post_meta() 経由でも sanitize_meta() を
	// 通ってしまう（wp-includes/meta.php の add_metadata()/update_metadata()）ため、
	// $wpdb で直接 wp_postmeta に INSERT する。ネイティブ配列メタと同じ形
	// （maybe_serialize() したもの）で保存し、get_post_meta() 側の読み出し互換を保つ。
	$wpdb->insert(
		$wpdb->postmeta,
		array(
			'post_id'    => $id,
			'meta_key'   => ProductPostType::META_LISTINGS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- v3 以前の実データを模すための意図的な直書き込み。
			'meta_value' => maybe_serialize( $listings ),
		)
	);
	// このリクエスト内で get_post_meta() が古い値をキャッシュしないよう明示的に払う。
	wp_cache_delete( $id, 'post_meta' );

	return $id;
};

// 通常の flat listing。fetch_error 文言が fetch_status コードへ正しく写像されるかも確認する。
$normal_id = $make_legacy(
	'E2E-MigrationFixture 通常',
	array(
		array(
			'platform'         => 'dmm-books',
			'enabled'          => true,
			'external_id'      => 'legacy-normal',
			'regular_url'      => 'https://example.test/legacy-normal',
			'affiliate_url'    => 'https://example.test/legacy-normal-aff',
			'price'            => '600',
			'list_price'       => '780',
			'badge'            => '23%OFF',
			'fetch_error'      => '該当する商品が見つかりませんでした',
			'last_fetched_at'  => '2026-01-01T00:00:00+00:00',
			'last_verified_at' => '2026-01-02T00:00:00+00:00',
		),
	)
);

// regular_url を持たず affiliate_url のみの flat listing（Critical 回帰の固定テスト）。
$aff_only_id = $make_legacy(
	'E2E-MigrationFixture affiliateのみ',
	array(
		array(
			'platform'      => 'rakuten-kobo',
			'enabled'       => true,
			'external_id'   => 'legacy-aff-only',
			'affiliate_url' => 'https://example.test/legacy-aff-only',
			'price'         => '500',
		),
	)
);

$schema_version_before_normal  = (string) get_post_meta( $normal_id, ProductPostType::META_SCHEMA_VERSION, true );
$schema_version_before_affonly = (string) get_post_meta( $aff_only_id, ProductPostType::META_SCHEMA_VERSION, true );
$preserved_before               = PluginUpgrade::preservedWithoutRegularUrlCount();

// バッチサイズ（200）を超える既存商品がある環境でも完走するまでループする。
$guard = 0;
do {
	PluginUpgrade::runOffersMigrationBatch();
	++$guard;
} while ( PluginUpgrade::isOffersMigrationPending() && $guard < 100 );

$preserved_after = PluginUpgrade::preservedWithoutRegularUrlCount();

$read = static function ( int $id ): array {
	return array(
		'listings'       => get_post_meta( $id, ProductPostType::META_LISTINGS, true ),
		'schema_version' => (string) get_post_meta( $id, ProductPostType::META_SCHEMA_VERSION, true ),
	);
};

echo 'MIGRATION_JSON:' . wp_json_encode(
	array(
		'normal'                       => $read( $normal_id ),
		'affOnly'                      => $read( $aff_only_id ),
		'schemaVersionBeforeNormal'    => $schema_version_before_normal,
		'schemaVersionBeforeAffOnly'   => $schema_version_before_affonly,
		'preservedBefore'              => $preserved_before,
		'preservedAfter'               => $preserved_after,
		'migrationGuardIterations'     => $guard,
	)
) . "\n";
