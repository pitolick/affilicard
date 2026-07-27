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

// 表示順テスト用。listing は displayOrder の逆順（楽天Kobo=3 → DMMブックス=1）で登録し、
// カードの CTA が登録順ではなく displayOrder 順に並ぶことを検証できるようにする。
$display_order_id = $repo->save(
	array(
		'title'        => 'E2E 表示順テスト商品',
		'status'       => 'publish',
		'product_type' => 'ebook',
		'stock_status' => 'available',
		'listings'     => array(
			array(
				'platform'      => 'rakuten-kobo',
				'enabled'       => true,
				'update_mode'   => 'manual',
				'auto_update'   => false,
				'affiliate_url' => 'https://example.com/aff-order-kobo',
				'regular_url'   => '',
				'image_url'     => 'https://example.com/cover-kobo.png',
			),
			array(
				'platform'      => 'dmm-books',
				'enabled'       => true,
				'update_mode'   => 'manual',
				'auto_update'   => false,
				'affiliate_url' => 'https://example.com/aff-order-dmm',
				'regular_url'   => '',
				'image_url'     => 'https://example.com/cover-dmm.png',
			),
		),
	)
);

$available_post = $make_post( array( 'productId' => $available_id, 'ctaBgColor' => '#123456' ) );
$out_post       = $make_post( array( 'productId' => $out_id ) );
$future_post    = $make_post( array( 'productId' => $future_id ) );
$display_order_post = $make_post( array( 'productId' => $display_order_id ) );

echo 'SEED_JSON:' . wp_json_encode(
	array(
		'availablePostId'    => $available_post,
		'outOfStockPostId'   => $out_post,
		'availableProductId' => $available_id,
		'futurePostId'       => $future_post,
		'futureProductId'    => $future_id,
		'draftProductId'     => $draft_id,
		'displayOrderPostId' => $display_order_post,
	)
) . "\n";
