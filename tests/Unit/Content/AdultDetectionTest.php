<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Content;

use Affilicard\Content\AdultDetection;
use PHPUnit\Framework\TestCase;

final class AdultDetectionTest extends TestCase {

	public function test_どれかのストアが成人向けと判定すれば_そのストアを返す(): void {
		$product = array(
			'listings' => array(
				array(
					'platform' => 'dmm-books',
					'offers'   => array( array( 'adult' => '0' ) ),
				),
				array(
					'platform' => 'amazon-kindle',
					'offers'   => array(
						array( 'adult' => '' ),
						array( 'adult' => '1' ),
					),
				),
			),
		);

		$this->assertSame( array( 'amazon-kindle' ), AdultDetection::detectedBy( $product ) );
	}

	public function test_無効にしたlistingの判定も数え_同じストアは1度だけ返す(): void {
		$product = array(
			'listings' => array(
				array(
					'platform' => 'amazon-kindle',
					'enabled'  => false,
					'offers'   => array( array( 'adult' => '1' ) ),
				),
				array(
					'platform' => 'amazon-kindle',
					'offers'   => array( array( 'adult' => '1' ) ),
				),
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array( array( 'adult' => true ) ),
				),
			),
		);

		$this->assertSame( array( 'amazon-kindle', 'rakuten-kobo' ), AdultDetection::detectedBy( $product ) );
	}

	public function test_未確認と非該当だけなら空(): void {
		$product = array(
			'listings' => array(
				array(
					'platform' => 'amazon-kindle',
					'offers'   => array( array( 'adult' => '0' ), array( 'price' => '500' ) ),
				),
				'壊れた listing',
			),
		);

		$this->assertSame( array(), AdultDetection::detectedBy( $product ) );
		$this->assertSame( array(), AdultDetection::detectedBy( array() ) );
	}

	/**
	 * @dataProvider normaliseCases
	 * @param mixed $input
	 */
	public function test_入力のゆれを3値に揃える( $input, string $expected ): void {
		$this->assertSame( $expected, AdultDetection::normalise( $input ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function normaliseCases(): array {
		return array(
			'true'    => array( true, '1' ),
			'1'       => array( 1, '1' ),
			'"1"'     => array( '1', '1' ),
			'"true"'  => array( ' TRUE ', '1' ),
			'false'   => array( false, '0' ),
			'0'       => array( 0, '0' ),
			'"0"'     => array( '0', '0' ),
			'"false"' => array( 'false', '0' ),
			'空'       => array( '', '' ),
			'null'    => array( null, '' ),
			'不明な文字列'  => array( 'yes', '' ),
			'配列'      => array( array( 1 ), '' ),
		);
	}
}
