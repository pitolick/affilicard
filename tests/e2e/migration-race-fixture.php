<?php

declare(strict_types=1);

/**
 * E2E 補助スクリプト: 「移行が到達する前に通常の価格更新が同じ商品へ書き込む」を作る。
 * `wp eval-file --use-include` でコンテナ内実行する。
 *
 * 移行が約束しているのは「身元（`regular_url` / `external_id`）を 1 つも持たない
 * 購入リンクも温存し、対象商品を名指しで管理画面に通知する」——運用者が通常 URL を
 * 足すための猶予を作ることである（CHANGELOG 4.0.0 の Fixed／既知の制限）。
 *
 * ところが listing がまだ v3 の flat な形のまま残っている商品へ通常の価格更新が
 * 先に届くと、その保存が flat → `offers[]` の変換を兼ねてしまう。変換は
 * `ProductSchema::sanitizeOffers()` を通り、移行だけが外している
 * `withLegacyOfferPreservation()` の窓が無いため、身元を持たない購入リンクは
 * **その場で消える**。しかも温存カウンタは 1 つも増えないので、通知も出ない。
 *
 * 引数（`$args[0]`）:
 *   - `seed`   … フィクスチャを作り、価格更新を 2 件積む
 *   - `read`   … 結果を読む
 *
 * 出力: 1 行 `RACE_JSON:{...}`
 */

use Affilicard\PostType\ProductPostType;
use Affilicard\Queue\Enqueuer;
use Affilicard\Upgrade\PluginUpgrade;

global $wpdb;

$ids_option = 'affilicard_e2e_race_fixture_ids';
$mode       = isset( $args[0] ) ? (string) $args[0] : 'read';

if ( 'seed' === $mode ) {
	foreach (
		get_posts(
			array(
				'post_type'      => ProductPostType::POST_TYPE,
				'post_status'    => PluginUpgrade::MIGRATION_POST_STATUSES,
				's'              => 'E2E-RaceFixture',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		) as $stale_id
	) {
		wp_delete_post( (int) $stale_id, true );
	}

	/**
	 * v3 以前の実データを模す（`$wpdb` 直書き込みの理由は migration-fixture.php 参照）。
	 *
	 * @param array<int, array<string, mixed>> $listings
	 */
	$make_legacy = static function ( string $title, array $listings ) use ( $wpdb ): int {
		$id = (int) wp_insert_post(
			array(
				'post_type'   => ProductPostType::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $id,
				'meta_key'   => ProductPostType::META_LISTINGS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- v3 以前の実データを模すための意図的な直書き込み。
				'meta_value' => maybe_serialize( $listings ),
			)
		);
		wp_cache_delete( $id, 'post_meta' );
		return $id;
	};

	$ids = array(
		// 身元を 1 つも持たない flat listing（手入力で affiliate_url だけ入れた v3 の実データ形）。
		'no_identity' => $make_legacy(
			'E2E-RaceFixture 身元なし',
			array(
				array(
					'platform'      => 'rakuten-kobo',
					'enabled'       => true,
					'affiliate_url' => 'https://example.test/race-no-identity',
					'price'         => '400',
				),
			)
		),
		// 対照群: external_id を持つ flat listing。身元があるので通常の保存でも消えない
		// （これが消えるなら別の退行である。対照が無いと「保存経路が全部壊れている」のか
		// 「身元なしだけが落ちる」のかを切り分けられない）。
		'with_id'     => $make_legacy(
			'E2E-RaceFixture 身元あり',
			array(
				array(
					'platform'      => 'rakuten-kobo',
					'enabled'       => true,
					'external_id'   => 'race-with-id',
					'affiliate_url' => 'https://example.test/race-with-id',
					'price'         => '500',
				),
			)
		),
	);

	update_option( $ids_option, $ids, false );
	delete_option( PluginUpgrade::OPTION_MIGRATION_PRESERVED_WITHOUT_REGULAR_URL );
	delete_option( PluginUpgrade::OPTION_MIGRATION_PRESERVED_POST_IDS );

	// **移行は積まない（カーソルも立てない）。** この spec が見たいのは
	// 「移行がまだ到達していない商品に通常の価格更新が届いたらどうなるか」で、
	// 移行を pending にすると AS の非同期ランナーが先に変換してしまい、
	// 結果が実行タイミング次第で揺れる。実インストールでは、移行の走査が
	// この商品へ届くまでのあいだ、まさにこの状態が続く。
	delete_option( PluginUpgrade::OPTION_MIGRATION_CURSOR );

	// 通常の価格更新（手動トリガー）を 2 件積む。ここから先は本物の経路——
	// AS のランナー → RefreshHandler → ListingRefresher::refreshOne() →
	// ProductRepository::updateListingOffer() → update_post_meta（sanitize_meta 経由）。
	$enqueuer = new Enqueuer();
	foreach ( $ids as $id ) {
		$enqueuer->enqueueManual( (int) $id, 'rakuten-kobo', 'rakuten-kobo' );
	}

	echo 'RACE_JSON:' . wp_json_encode( array( 'ids' => $ids ) ) . "\n";
	return;
}

$ids      = get_option( $ids_option, array() );
$ids      = is_array( $ids ) ? $ids : array();
$products = array();
foreach ( $ids as $key => $post_id ) {
	$post_id  = (int) $post_id;
	$listings = get_post_meta( $post_id, ProductPostType::META_LISTINGS, true );
	$listings = is_array( $listings ) ? $listings : array();

	// 「購入リンクが何件あるか」を移行前後で同じ数え方にする（flat な listing は 1 件相当）。
	$links = 0;
	foreach ( $listings as $listing ) {
		if ( ! is_array( $listing ) ) {
			continue;
		}
		if ( isset( $listing['offers'] ) && is_array( $listing['offers'] ) ) {
			$links += count( $listing['offers'] );
			continue;
		}
		if ( '' !== (string) ( $listing['regular_url'] ?? '' ) || '' !== (string) ( $listing['affiliate_url'] ?? '' ) ) {
			++$links;
		}
	}

	$products[ $key ] = array(
		'postId'   => $post_id,
		'links'    => $links,
		'listings' => $listings,
	);
}

echo 'RACE_JSON:' . wp_json_encode(
	array(
		'products'  => $products,
		'preserved' => PluginUpgrade::preservedWithoutRegularUrlCount(),
	)
) . "\n";
