<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Pricing;

use Affilicard\Pricing\ListingEligibility;
use PHPUnit\Framework\TestCase;

/**
 * QueueMaintenance::sweep()・PublishTrigger・Enqueuer::enqueueProductListings()・
 * ListingRefresher::refreshOne() で重複していた listing 対象判定を集約した
 * ListingEligibility の単体テスト。
 */
final class ListingEligibilityTest extends TestCase {

	/** isAutoEligible（enqueue 判定: update_mode=auto && enabled && ( force || auto_update )）。 */
	public function test_isAutoEligible_キー欠落時は全てdefaultでtrueになる(): void {
		$this->assertTrue( ListingEligibility::isAutoEligible( array() ) );
	}

	public function test_isAutoEligible_update_modeがauto以外ならfalse(): void {
		$listing = array(
			'update_mode' => 'manual',
			'enabled'     => true,
			'auto_update' => true,
		);
		$this->assertFalse( ListingEligibility::isAutoEligible( $listing ) );
	}

	public function test_isAutoEligible_enabledがfalseならfalse(): void {
		$listing = array(
			'update_mode' => 'auto',
			'enabled'     => false,
			'auto_update' => true,
		);
		$this->assertFalse( ListingEligibility::isAutoEligible( $listing ) );
	}

	public function test_isAutoEligible_auto_updateがfalseでforceなしならfalse(): void {
		$listing = array(
			'update_mode' => 'auto',
			'enabled'     => true,
			'auto_update' => false,
		);
		$this->assertFalse( ListingEligibility::isAutoEligible( $listing ) );
	}

	public function test_isAutoEligible_auto_updateがfalseでもforcetrueならtrue(): void {
		$listing = array(
			'update_mode' => 'auto',
			'enabled'     => true,
			'auto_update' => false,
		);
		$this->assertTrue( ListingEligibility::isAutoEligible( $listing, true ) );
	}

	public function test_isAutoEligible_全条件を満たせばtrue(): void {
		$listing = array(
			'update_mode' => 'auto',
			'enabled'     => true,
			'auto_update' => true,
		);
		$this->assertTrue( ListingEligibility::isAutoEligible( $listing ) );
	}

	public function test_isAutoEligible_forcetrueでもmanualやdisabledはfalseのまま(): void {
		$this->assertFalse(
			ListingEligibility::isAutoEligible( array( 'update_mode' => 'manual' ), true )
		);
		$this->assertFalse(
			ListingEligibility::isAutoEligible( array( 'enabled' => false ), true )
		);
	}

	/** isEnabledAuto（実行時再チェック: update_mode=auto && enabled のみ。auto_update は無視）。 */
	public function test_isEnabledAuto_キー欠落時はtrue(): void {
		$this->assertTrue( ListingEligibility::isEnabledAuto( array() ) );
	}

	public function test_isEnabledAuto_update_modeがauto以外ならfalse(): void {
		$this->assertFalse( ListingEligibility::isEnabledAuto( array( 'update_mode' => 'manual' ) ) );
	}

	public function test_isEnabledAuto_enabledがfalseならfalse(): void {
		$this->assertFalse( ListingEligibility::isEnabledAuto( array( 'enabled' => false ) ) );
	}

	public function test_isEnabledAuto_auto_updateがfalseでもauto_updateは無視してtrue(): void {
		// force 経路（enqueue 時点では auto_update=false でも対象に含めた listing）を
		// ワーカー実行時に auto_update だけを理由に取りこぼさないための仕様。
		$listing = array(
			'update_mode' => 'auto',
			'enabled'     => true,
			'auto_update' => false,
		);
		$this->assertTrue( ListingEligibility::isEnabledAuto( $listing ) );
	}
}
