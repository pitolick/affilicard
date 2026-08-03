<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Account;

use Affilicard\Account\DmmAccount;
use Affilicard\Account\RakutenAccount;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class AccountsSchemaTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn( $t ) => $t );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_rakuten_account_schema_types(): void {
		$account = new RakutenAccount();
		$this->assertSame( 'rakuten', $account->code() );

		$byKey = array();
		foreach ( $account->credentialsSchema() as $f ) {
			$byKey[ $f['key'] ] = $f['type'];
		}
		$this->assertSame( 'text', $byKey['application_id'] );
		$this->assertSame( 'password', $byKey['access_key'] );
		$this->assertSame( 'text', $byKey['affiliate_id'] );
		$this->assertSame( 'text', $byKey['allowed_domain'] );
	}

	public function test_dmm_account_schema_types(): void {
		$account = new DmmAccount();
		$this->assertSame( 'dmm', $account->code() );

		$byKey = array();
		foreach ( $account->credentialsSchema() as $f ) {
			$byKey[ $f['key'] ] = $f['type'];
		}
		$this->assertSame( 'password', $byKey['api_id'] );
		$this->assertSame( 'text', $byKey['affiliate_id'] );
		// DMM のアフィリエイト ID は用途で 2 つに分かれる（API リクエスト用＝末尾 990〜999 /
		// リンク埋め込み用＝サイト単位）。1 つにまとめると必ずどちらかが壊れる。
		$this->assertSame( 'text', $byKey['affiliate_link_id'] );
	}

	public function test_dmm_account_の全項目が必須(): void {
		$byKey = array();
		foreach ( ( new DmmAccount() )->credentialsSchema() as $f ) {
			$byKey[ $f['key'] ] = $f['required'];
		}
		// リンク埋め込み用 ID が未入力だとアフィリエイト URL を組めず（＝空になり）収益化
		// されないため、任意項目にはしない。
		$this->assertTrue( $byKey['affiliate_link_id'] );
	}
}
