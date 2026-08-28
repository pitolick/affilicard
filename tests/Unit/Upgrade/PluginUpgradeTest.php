<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Upgrade;

use Affilicard\Upgrade\PluginUpgrade;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class PluginUpgradeTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		\Mockery::close();
		parent::tearDown();
	}

	public function test_初回は棚卸し基準日を作成しバージョンを記録する(): void {
		WP_Mock::userFunction( 'get_option' )->with( PluginUpgrade::OPTION_VERSION, '' )->andReturn( '' );
		WP_Mock::userFunction( 'add_option' )
			->once()
			->with( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, \Mockery::type( 'string' ), '', false )
			->andReturn( true );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( PluginUpgrade::OPTION_VERSION, '3.5.0', false );

		PluginUpgrade::maybeUpgrade( '3.5.0' );

		$this->assertConditionsMet();
	}

	public function test_同一バージョンなら何もしない(): void {
		WP_Mock::userFunction( 'get_option' )->with( PluginUpgrade::OPTION_VERSION, '' )->andReturn( '3.5.0' );
		WP_Mock::userFunction( 'add_option' )->never();
		WP_Mock::userFunction( 'update_option' )->never();

		PluginUpgrade::maybeUpgrade( '3.5.0' );

		$this->assertConditionsMet();
	}

	/**
	 * add_option() は「既に存在する」場合も false を返す。既存の基準日が実在することを
	 * get_option() で確認できれば、移行として正常なのでバージョンは進める。
	 */
	public function test_既存の基準日がある場合はバージョンが更新される(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_VERSION, '' )
			->andReturn( '3.4.0' );
		WP_Mock::userFunction( 'add_option' )
			->once()
			->with( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, \Mockery::type( 'string' ), '', false )
			->andReturn( false );
		// add_option が false を返した理由を get_option で確認する。既存の値が実在する
		// （＝「既に存在する」ケース）ことを示す。
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, '' )
			->andReturn( '2026-01-01T00:00:00+00:00' );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( PluginUpgrade::OPTION_VERSION, '3.5.0', false );

		PluginUpgrade::maybeUpgrade( '3.5.0' );

		$this->assertConditionsMet();
	}

	/**
	 * add_option() が false を返し、かつ get_option() でも基準日が実在しないと確認できた
	 * 場合（＝真の保存失敗）は、バージョンを進めてはいけない。進めてしまうと
	 * maybeUpgrade() が次回以降 stored===currentVersion で早期 return し、基準日が
	 * 永久に作られない（＝棚卸しが永久に発動しない）。
	 */
	public function test_基準日の保存に失敗した場合はバージョンを更新せず次回再試行できる(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_VERSION, '' )
			->andReturn( '3.4.0' );
		WP_Mock::userFunction( 'add_option' )
			->once()
			->with( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, \Mockery::type( 'string' ), '', false )
			->andReturn( false );
		// get_option で確認しても基準日が存在しない（＝真の保存失敗）。
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, '' )
			->andReturn( '' );
		WP_Mock::userFunction( 'update_option' )->never();

		PluginUpgrade::maybeUpgrade( '3.5.0' );

		$this->assertConditionsMet();
	}
}
