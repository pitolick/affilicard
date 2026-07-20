<?php
/**
 * E2E シードスクリプト。`wp eval-file` でコンテナ内実行する。
 * プラグインの Repository を使うのでシェルクォート問題が発生しない。
 * 出力: 1 行 `SEED_JSON:{...}` （global-setup がこの行を拾う）
 */

$repo = new \Affilicard\Repository\ProductRepository();

$listing = static function ( string $aff ): array {
	return array(
		array(
			'platform'         => 'dmm-books',
			'enabled'          => true,
			'affiliate_url'    => $aff,
			'regular_url'      => '',
			'price'            => '600',
			'last_verified_at' => gmdate( 'c' ),
		),
	);
};

$available_id = $repo->save(
	array(
		'title'        => 'E2E 在庫あり商品',
		'status'       => 'publish',
		'product_type' => 'ebook',
		'stock_status' => 'available',
		'content'      => 'E2E 表示確認用のあらすじダミーテキスト。',
		'extras'       => array(
			array( 'key' => 'author', 'label' => '著者', 'value' => '架空 太郎' ),
			array( 'key' => 'publisher', 'label' => '出版社', 'value' => 'サンプル出版社' ),
			array( 'key' => 'isbn', 'label' => 'ISBN', 'value' => '978-4-00-000000-0' ),
		),
		'listings'     => array(
			array(
				'platform'         => 'dmm-books',
				'enabled'          => true,
				'affiliate_url'    => 'https://example.com/aff-a',
				'regular_url'      => '',
				'price'            => '600',
				'badge'            => '40%OFF',
				'last_fetched_at'  => '2026-04-20T10:30:00+09:00',
				'last_verified_at' => gmdate( 'c' ),
			),
		),
	)
);

$out_id = $repo->save(
	array(
		'title'        => 'E2E 在庫切れ商品',
		'status'       => 'publish',
		'product_type' => 'generic',
		'stock_status' => 'out_of_stock',
		'listings'     => $listing( 'https://example.com/aff-b' ),
	)
);

$draft_id = $repo->save(
	array(
		'title'        => 'E2E 下書き商品',
		'status'       => 'draft',
		'product_type' => 'generic',
		'stock_status' => 'available',
		'listings'     => $listing( 'https://example.com/aff-draft' ),
	)
);

$make_post = static function ( array $attrs ): int {
	return (int) wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'E2E ブロック投稿',
			'post_content' => '<!-- wp:affilicard/product-card ' . wp_json_encode( $attrs ) . ' /-->',
		)
	);
};

// 予約投稿（future）商品。wp_insert_post は future ステータスでも post_date が
// 過去/現在だと publish に変換するため、明示的に未来日時を与えて future を維持する。
// manual listing なので publish 昇格しても外部 API は叩かない。
$future_post_date = gmdate( 'Y-m-d H:i:s', time() + ( 7 * DAY_IN_SECONDS ) );
$future_id        = (int) wp_insert_post(
	array(
		'post_type'     => 'affilicard_product',
		'post_status'   => 'future',
		'post_title'    => 'E2E 予約公開商品',
		'post_date'     => $future_post_date,
		'post_date_gmt' => $future_post_date,
	)
);
$repo->saveMeta(
	$future_id,
	array(
		'product_type' => 'generic',
		'stock_status' => 'available',
		'listings'     => array(
			array(
				'platform'      => 'dmm-books',
				'enabled'       => true,
				'update_mode'   => 'manual',
				'auto_update'   => false,
				'affiliate_url' => 'https://example.com/aff-future',
				'regular_url'   => '',
				'price'         => '700',
			),
		),
	)
);

$available_post = $make_post( array( 'productId' => $available_id, 'ctaBgColor' => '#123456' ) );
$out_post       = $make_post( array( 'productId' => $out_id ) );
$future_post    = $make_post( array( 'productId' => $future_id ) );

// TEMP DEBUG: 価格鮮度ゲートの実入力を CI ログに出す（原因特定用・後で除去）。
$__dbg      = $repo->find( $available_id );
$__dl       = is_array( $__dbg['listings'][0] ?? null ) ? $__dbg['listings'][0] : array();
$__lv       = (string) ( $__dl['last_verified_at'] ?? '(none)' );
$__pf       = \Affilicard\Platform\PlatformConfig::find( 'dmm-books' );
$__ttl      = null !== $__pf ? $__pf->priceTtlHours : -1;
$__disp     = \Affilicard\Pricing\PriceFreshness::isPriceDisplayable( $__dl, $__pf, time() );
echo 'DEBUG_SEED: lv=' . $__lv . ' vt=' . (string) strtotime( $__lv ) . ' now=' . time()
	. ' ttl=' . $__ttl . ' price=' . (string) ( $__dl['price'] ?? '?' )
	. ' pf=' . ( null !== $__pf ? 'set' : 'NULL' ) . ' disp=' . ( $__disp ? 'Y' : 'N' ) . "\n";

echo 'SEED_JSON:' . wp_json_encode(
	array(
		'availablePostId'    => $available_post,
		'outOfStockPostId'   => $out_post,
		'availableProductId' => $available_id,
		'futurePostId'       => $future_post,
		'futureProductId'    => $future_id,
		'draftProductId'     => $draft_id,
	)
) . "\n";
