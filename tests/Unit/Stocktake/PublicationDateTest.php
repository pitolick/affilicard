<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Stocktake;

use Affilicard\PostType\ProductPostType;
use Affilicard\Stocktake\PublicationDate;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * PublicationDate のテスト。
 *
 * 「最終掲載日」は記事の公開日時ではなく記録時点の現在時刻を記録する（過去日付の記事の
 * 後編集・予約投稿での未来日時とのズレを避けるため）。単調増加（既存値より新しいときだけ
 * 上書き）と、read-only meta ゆえの get() 側の防御的パース（空文字・不正値は null）を検証する。
 */
final class PublicationDateTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	public function test_未設定なら現在時刻を記録する(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ProductPostType::META_LAST_PUBLISHED_AT, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 7, ProductPostType::META_LAST_PUBLISHED_AT, gmdate( 'c', 1000 ) );

		( new PublicationDate() )->touch( 7, 1000 );

		$this->assertConditionsMet();
	}

	public function test_既存値より新しい時刻では上書きする(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ProductPostType::META_LAST_PUBLISHED_AT, true )
			->andReturn( gmdate( 'c', 1000 ) );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 7, ProductPostType::META_LAST_PUBLISHED_AT, gmdate( 'c', 2000 ) );

		( new PublicationDate() )->touch( 7, 2000 );

		$this->assertConditionsMet();
	}

	public function test_既存値より古い時刻では上書きしない(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ProductPostType::META_LAST_PUBLISHED_AT, true )
			->andReturn( gmdate( 'c', 2000 ) );
		WP_Mock::userFunction( 'update_post_meta' )->never();

		( new PublicationDate() )->touch( 7, 1000 );

		$this->assertConditionsMet();
	}

	public function test_既存値と同じ時刻では上書きしない(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ProductPostType::META_LAST_PUBLISHED_AT, true )
			->andReturn( gmdate( 'c', 1000 ) );
		WP_Mock::userFunction( 'update_post_meta' )->never();

		( new PublicationDate() )->touch( 7, 1000 );

		$this->assertConditionsMet();
	}

	public function test_get_は_正当な値をUTCエポック秒で返す(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 7, ProductPostType::META_LAST_PUBLISHED_AT, true )
			->andReturn( gmdate( 'c', 1000 ) );

		$this->assertSame( 1000, ( new PublicationDate() )->get( 7 ) );
	}

	public function test_get_は_空文字と不正値を_null_にする(): void {
		WP_Mock::userFunction( 'get_post_meta' )->andReturn( '' );
		$this->assertNull( ( new PublicationDate() )->get( 7 ) );

		WP_Mock::userFunction( 'get_post_meta' )->andReturn( 'not-a-date' );
		$this->assertNull( ( new PublicationDate() )->get( 7 ) );
	}

	public function test_get_は_未設定false値を_null_にする(): void {
		// get_post_meta は未設定 single meta に対し '' を返すのが通常だが、
		// 呼び出し側の型崩れ（false 等）にも防御的に null を返す。
		WP_Mock::userFunction( 'get_post_meta' )->andReturn( false );
		$this->assertNull( ( new PublicationDate() )->get( 7 ) );
	}
}
