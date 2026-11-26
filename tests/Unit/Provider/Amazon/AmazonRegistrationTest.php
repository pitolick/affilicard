<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Provider\Amazon;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Plugin;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class AmazonRegistrationTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $text ) => $text );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_providerとaccountが登録される(): void {
		$this->assertContains( 'amazon-creators', Plugin::buildProviderRegistry()->codes() );
		$account = Plugin::buildAccountRegistry()->get( 'amazon' );
		$this->assertNotNull( $account );
		$keys = array_column( $account->credentialsSchema(), 'key' );
		$this->assertSame( array( 'credential_id', 'credential_secret', 'partner_tag', 'marketplace' ), $keys );
		$this->assertContains( 'amazon', Plugin::automaticAccountCodes( Plugin::buildProviderRegistry() ) );
	}

	public function test_新規インストールのamazon_kindleは手動のまま自動取得を選べる(): void {
		foreach ( PlatformConfig::defaults() as $definition ) {
			if ( 'amazon-kindle' === $definition->code ) {
				$this->assertSame( 'manual', $definition->provider );
				$this->assertSame( 'amazon-creators', $definition->eligibleProvider );
				return;
			}
		}
		$this->fail( 'amazon-kindle が defaults に無い' );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private static function platform( string $code, array $overrides = array() ): array {
		return array_merge(
			array(
				'code'             => $code,
				'name'             => $code,
				'provider'         => 'manual',
				'displayOrder'     => 2,
				'enabled'          => true,
				'applicableTypes'  => array( 'ebook' ),
				'buttonLabel'      => '読む',
				'brandColor'       => '#ff9900',
				'buttonTextColor'  => '#000000',
				'eligibleProvider' => '',
			),
			$overrides
		);
	}

	public function test_既存インストールのamazon_kindleに一度だけ補完し_他は触らない(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
		WP_Mock::userFunction( 'get_option' )
			->with( Plugin::AMAZON_ELIGIBLE_BACKFILLED_OPTION )
			->andReturn( false );
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					self::platform( 'amazon-kindle' ),
					self::platform( 'mystery-platform' ),
				),
				// 保存後の読み直し（保存が効いたことを確かめてから印を立てる）。
				array(
					self::platform( 'amazon-kindle', array( 'eligibleProvider' => 'amazon-creators' ) ),
					self::platform( 'mystery-platform' ),
				)
			);
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( PlatformConfig::OPTION_KEY, WP_Mock\Functions::type( 'array' ), false )
			->andReturnUsing(
				function ( $key, $value ) {
					$byCode = array_column( $value, null, 'code' );
					$this->assertSame( 'amazon-creators', $byCode['amazon-kindle']['eligibleProvider'] );
					// 自動取得を始めるかは管理者が決める。provider は変えない。
					$this->assertSame( 'manual', $byCode['amazon-kindle']['provider'] );
					$this->assertSame( '', $byCode['mystery-platform']['eligibleProvider'] );
					return true;
				}
			);
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( Plugin::AMAZON_ELIGIBLE_BACKFILLED_OPTION, 1, false )
			->andReturn( true );

		Plugin::backfillAmazonEligibleProvider();

		$this->assertConditionsMet();
	}

	public function test_保存が効いていなければ印を立てず次回に再試行する(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
		WP_Mock::userFunction( 'get_option' )
			->with( Plugin::AMAZON_ELIGIBLE_BACKFILLED_OPTION )
			->andReturn( false );
		// update_option() が黙って失敗し、読み直しても空のまま。
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn( array( self::platform( 'amazon-kindle' ) ) );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( PlatformConfig::OPTION_KEY, WP_Mock\Functions::type( 'array' ), false )
			->andReturn( false );
		WP_Mock::userFunction( 'update_option' )
			->with( Plugin::AMAZON_ELIGIBLE_BACKFILLED_OPTION, 1, false )
			->never();

		Plugin::backfillAmazonEligibleProvider();

		$this->assertConditionsMet();
	}

	public function test_既に値があれば上書きしない(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
		WP_Mock::userFunction( 'get_option' )
			->with( Plugin::AMAZON_ELIGIBLE_BACKFILLED_OPTION )
			->andReturn( false );
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn( array( self::platform( 'amazon-kindle', array( 'eligibleProvider' => 'custom' ) ) ) );
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( Plugin::AMAZON_ELIGIBLE_BACKFILLED_OPTION, 1, false )
			->andReturn( true );

		Plugin::backfillAmazonEligibleProvider();

		$this->assertConditionsMet();
	}

	public function test_補完済みや権限なしなら何もしない(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true, false );
		WP_Mock::userFunction( 'get_option' )
			->with( Plugin::AMAZON_ELIGIBLE_BACKFILLED_OPTION )
			->once()
			->andReturn( 1 );
		WP_Mock::userFunction( 'update_option' )->never();

		Plugin::backfillAmazonEligibleProvider();
		Plugin::backfillAmazonEligibleProvider();

		$this->assertConditionsMet();
	}

	public function test_既存インストールのprime_videoにも一度だけ補完する(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
		WP_Mock::userFunction( 'get_option' )
			->with( Plugin::PRIME_VIDEO_ELIGIBLE_BACKFILLED_OPTION )
			->andReturn( false );
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					self::platform( 'prime-video', array( 'applicableTypes' => array( 'vod' ) ) ),
					self::platform( 'hulu', array( 'applicableTypes' => array( 'vod' ) ) ),
				),
				array(
					self::platform(
						'prime-video',
						array(
							'applicableTypes'  => array( 'vod' ),
							'eligibleProvider' => 'amazon-creators',
						)
					),
					self::platform( 'hulu', array( 'applicableTypes' => array( 'vod' ) ) ),
				)
			);
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( PlatformConfig::OPTION_KEY, WP_Mock\Functions::type( 'array' ), false )
			->andReturnUsing(
				function ( $key, $value ) {
					$byCode = array_column( $value, null, 'code' );
					$this->assertSame( 'amazon-creators', $byCode['prime-video']['eligibleProvider'] );
					$this->assertSame( 'manual', $byCode['prime-video']['provider'] );
					$this->assertSame( '', $byCode['hulu']['eligibleProvider'] );
					return true;
				}
			);
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( Plugin::PRIME_VIDEO_ELIGIBLE_BACKFILLED_OPTION, 1, false )
			->andReturn( true );

		Plugin::backfillPrimeVideoEligibleProvider();

		$this->assertConditionsMet();
	}
}
