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
			'platform'      => 'dmm-books',
			'enabled'       => true,
			'affiliate_url' => $aff,
			'regular_url'   => '',
			'price'         => '600',
		),
	);
};

$available_id = $repo->save(
	array(
		'title'        => 'E2E 在庫あり商品',
		'status'       => 'publish',
		'product_type' => 'generic',
		'stock_status' => 'available',
		'listings'     => $listing( 'https://example.com/aff-a' ),
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

$available_post = $make_post( array( 'productId' => $available_id, 'ctaBgColor' => '#123456' ) );
$out_post       = $make_post( array( 'productId' => $out_id ) );

echo 'SEED_JSON:' . wp_json_encode(
	array(
		'availablePostId'    => $available_post,
		'outOfStockPostId'   => $out_post,
		'availableProductId' => $available_id,
	)
) . "\n";
