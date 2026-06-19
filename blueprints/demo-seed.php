<?php
/**
 * WordPress Playground プレビュー用のダミーデータ seed。
 * blueprint の runPHP から require される（本番 zip には含まれない）。
 * 冪等: affilicard_demo_seeded オプションで二重実行を防ぐ。
 */

if ( ! class_exists( '\Affilicard\Repository\ProductRepository' ) ) {
	return;
}
if ( get_option( 'affilicard_demo_seeded' ) ) {
	return;
}

$repo = new \Affilicard\Repository\ProductRepository();

$listing = static function ( string $platform, string $aff, string $price, string $badge = '', string $fetched = '2026-04-20T10:30:00+09:00' ): array {
	return array(
		'platform'        => $platform,
		'enabled'         => true,
		'affiliate_url'   => $aff,
		'regular_url'     => '',
		'price'           => $price,
		'badge'           => $badge,
		'last_fetched_at' => $fetched,
	);
};

$p_generic = $repo->save(
	array(
		'title'        => 'サンプル雑貨（汎用・在庫あり）',
		'status'       => 'publish',
		'product_type' => 'generic',
		'stock_status' => 'available',
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-generic', '1,200' ) ),
	)
);

$p_ebook = $repo->save(
	array(
		'title'        => 'サンプル漫画 1巻（電子書籍・複数ストア）',
		'status'       => 'publish',
		'product_type' => 'ebook',
		'stock_status' => 'available',
		'content'      => "<!-- wp:paragraph -->\n<p>架空のダンジョンを舞台にした冒険グルメ漫画のサンプル紹介文です。書影・著者・あらすじ・複数ストアの価格行・税込表記・価格時点フッタの表示確認用ダミーデータ。</p>\n<!-- /wp:paragraph -->",
		'listings'     => array(
			$listing( 'dmm-books', 'https://example.com/dmm', '600', '40%OFF' ),
			$listing( 'amazon-kindle', 'https://example.com/amz', '660', '50%ポイント還元' ),
			$listing( 'rakuten-kobo', 'https://example.com/kobo', '640' ),
		),
		'extras'       => array(
			array(
				'key'   => 'author',
				'label' => '著者',
				'value' => '架空 太郎',
			),
			array(
				'key'   => 'publisher',
				'label' => '出版社',
				'value' => 'サンプル出版社',
			),
			array(
				'key'   => 'isbn',
				'label' => 'ISBN',
				'value' => '978-4-00-000000-0',
			),
		),
	)
);

$p_out = $repo->save(
	array(
		'title'        => 'サンプル（在庫切れ）',
		'status'       => 'publish',
		'product_type' => 'generic',
		'stock_status' => 'out_of_stock',
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-out', '980' ) ),
	)
);

$p_disc = $repo->save(
	array(
		'title'        => 'サンプル（取扱終了）',
		'status'       => 'publish',
		'product_type' => 'generic',
		'stock_status' => 'discontinued',
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-disc', '800' ) ),
	)
);

$block = static function ( int $id, array $attrs = array() ): string {
	$a = array_merge( array( 'productId' => $id ), $attrs );
	return '<!-- wp:affilicard/product-card ' . wp_json_encode( $a ) . ' /-->';
};

$content = implode(
	"\n\n",
	array(
		'<!-- wp:heading --><h2>Affilicard デモ（ダミーデータ）</h2><!-- /wp:heading -->',
		$block(
			$p_ebook,
			array(
				'ctaBgColor'   => '#d72d65',
				'ctaTextColor' => '#ffffff',
			)
		),
		$block(
			$p_generic,
			array(
				'cardBgColor'     => '#f6f7f7',
				'cardBorderColor' => '#c3c4c7',
			)
		),
		$block( $p_out ),
		$block( $p_disc ),
	)
);

$demo_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Affilicard デモ',
		'post_content' => $content,
	)
);

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', (int) $demo_page );
update_option( 'affilicard_demo_seeded', 1 );
