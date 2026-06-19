<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Settings;

use Affilicard\Repository\ProductRepository;
use Affilicard\Settings\DashboardWidget;
use Mockery;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class DashboardWidgetTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::userFunction( '__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);
		WP_Mock::userFunction( 'esc_html__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);
		WP_Mock::userFunction( 'esc_html' )
			->andReturnUsing(
				static function ( $text ) {
					return (string) $text;
				}
			);
		WP_Mock::userFunction( 'esc_url' )
			->andReturnUsing(
				static function ( $text ) {
					return (string) $text;
				}
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		if ( isset( $GLOBALS['wpdb'] ) ) {
			unset( $GLOBALS['wpdb'] );
		}
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * countFallbackProducts() を指定値に固定するため、$wpdb スタブを設置する。
	 *
	 * @param list<int> $post_ids 返却したい post_id 配列
	 */
	private function stubWpdbWithPostIds( array $post_ids ): void {
		$wpdb           = new \stdClass();
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->prepare  = static function ( string $sql ) {
			return $sql;
		};
		$wpdb->get_col  = static function () use ( $post_ids ) {
			return $post_ids;
		};
		// stdClass にメソッドはバインドできないため Mockery で wpdb 風オブジェクトを構築する。
		$mock           = Mockery::mock( 'wpdb' );
		$mock->postmeta = 'wp_postmeta';
		$mock->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $sql ) {
				return $sql;
			}
		);
		$mock->shouldReceive( 'get_col' )->andReturn( $post_ids );
		$GLOBALS['wpdb'] = $mock;
	}

	public function test_register_hooks_wp_dashboard_setup(): void {
		$widget = new DashboardWidget( new ProductRepository() );

		WP_Mock::expectActionAdded( 'wp_dashboard_setup', array( $widget, 'addWidget' ) );

		$widget->register();

		$this->assertConditionsMet();
	}

	public function test_addWidget_calls_wp_add_dashboard_widget_when_user_can_edit_posts(): void {
		$widget = new DashboardWidget( new ProductRepository() );

		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts' )
			->andReturn( true );

		WP_Mock::userFunction( 'wp_add_dashboard_widget' )
			->once()
			->andReturnUsing(
				function ( $id, $title, $callback ) use ( $widget ) {
					$this->assertSame( 'affilicard_fallback_widget', $id );
					$this->assertSame( 'Affilicard: Fallback 中の商品', $title );
					$this->assertSame( array( $widget, 'render' ), $callback );
				}
			);

		$widget->addWidget();

		$this->assertConditionsMet();
	}

	public function test_addWidget_does_nothing_when_user_lacks_permission(): void {
		$widget = new DashboardWidget( new ProductRepository() );

		WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_posts' )
			->andReturn( false );

		WP_Mock::userFunction( 'wp_add_dashboard_widget' )
			->never();

		$widget->addWidget();

		$this->assertConditionsMet();
	}

	public function test_render_outputs_zero_message_when_count_is_zero(): void {
		WP_Mock::userFunction( 'get_posts' )
			->once()
			->andReturn( array() );

		$widget = new DashboardWidget( new ProductRepository() );

		ob_start();
		$widget->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Fallback 中の商品はありません。', $output );
		$this->assertStringNotContainsString( '<a ', $output );
	}

	public function test_render_outputs_count_message_and_link_when_count_positive(): void {
		WP_Mock::userFunction( 'get_posts' )
			->once()
			->andReturn( array( 1, 2 ) );

		// 両 post_id とも fallback 状態 (affiliate_url='', regular_url 非空)
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 1, \Affilicard\PostType\ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'      => 'a',
						'affiliate_url' => '',
						'regular_url'   => 'https://example.com/1',
					),
				)
			);
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 2, \Affilicard\PostType\ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'      => 'b',
						'affiliate_url' => '',
						'regular_url'   => 'https://example.com/2',
					),
				)
			);

		WP_Mock::userFunction( 'admin_url' )
			->andReturnUsing(
				static function ( $path ) {
					return 'https://wp.example.com/wp-admin/' . $path;
				}
			);

		$widget = new DashboardWidget( new ProductRepository() );

		ob_start();
		$widget->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '2', $output );
		$this->assertStringContainsString( 'edit.php?post_type=affilicard_product', $output );
		$this->assertStringContainsString( '商品一覧で確認', $output );
	}
}
