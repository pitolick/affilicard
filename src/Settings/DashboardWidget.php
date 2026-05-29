<?php
declare(strict_types=1);

namespace Affilicard\Settings;

use Affilicard\PostType\ProductPostType;
use Affilicard\Repository\ProductRepository;

/**
 * WP ダッシュボードに Fallback 中の商品数を表示するウィジェット。
 */
final class DashboardWidget {

	public function __construct( private ProductRepository $repository ) {}

	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'addWidget' ) );
	}

	public function addWidget(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'affilicard_fallback_widget',
			__( 'Affilicard: Fallback 中の商品', 'affilicard' ),
			array( $this, 'render' )
		);
	}

	public function render(): void {
		$count = $this->repository->countFallbackProducts();
		if ( 0 === $count ) {
			echo '<p>' . esc_html__( 'Fallback 中の商品はありません。', 'affilicard' ) . '</p>';
			return;
		}

		$list_url = admin_url( 'edit.php?post_type=' . ProductPostType::POST_TYPE );
		printf(
			'<p>%s</p><p><a href="%s">%s</a></p>',
			esc_html(
				sprintf(
					/* translators: %d: count */
					__( 'Fallback 中の商品が %d 件あります。', 'affilicard' ),
					$count
				)
			),
			esc_url( $list_url ),
			esc_html__( '商品一覧で確認', 'affilicard' )
		);
	}
}
