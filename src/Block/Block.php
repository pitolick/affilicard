<?php
declare(strict_types=1);

namespace Affilicard\Block;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Queue\Enqueuer;
use Affilicard\Renderer\CardHtmlBuilder;
use Affilicard\Repository\ProductRepository;
use Affilicard\Repository\ProductRepositoryInterface;

/**
 * Gutenberg block `affilicard/product-card` の登録とサーバサイド render。
 *
 * block.json を単一の属性情報源とし、script/style は build/ 出力を handles 経由で
 * 手動 register する（explicit multi-entry build を変更しないため）。
 */
final class Block {

	private const SCRIPT_HANDLE       = 'affilicard-block';
	private const STYLE_HANDLE        = 'affilicard-card';
	private const EDITOR_STYLE_HANDLE = 'affilicard-block-editor';

	public function __construct(
		private ProductRepositoryInterface $repository,
		private Enqueuer $enqueuer
	) {}

	public static function register_hook(): void {
		$instance = new self( new ProductRepository(), new Enqueuer() );
		add_action( 'init', array( $instance, 'register' ) );
	}

	public function register(): void {
		$asset_file = AFFILICARD_PLUGIN_DIR . 'build/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => AFFILICARD_VERSION,
			);

		wp_register_script(
			self::SCRIPT_HANDLE,
			AFFILICARD_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( self::SCRIPT_HANDLE, 'affilicard' );

		wp_register_style(
			self::STYLE_HANDLE,
			AFFILICARD_PLUGIN_URL . 'assets/card.css',
			array(),
			AFFILICARD_VERSION
		);
		wp_register_style(
			self::EDITOR_STYLE_HANDLE,
			AFFILICARD_PLUGIN_URL . 'assets/block-editor.css',
			array( self::STYLE_HANDLE ),
			AFFILICARD_VERSION
		);

		register_block_type(
			AFFILICARD_PLUGIN_DIR . 'src/Block/block.json',
			array(
				'render_callback'       => array( $this, 'render' ),
				'editor_script_handles' => array( self::SCRIPT_HANDLE ),
				'style_handles'         => array( self::STYLE_HANDLE ),
				'editor_style_handles'  => array( self::EDITOR_STYLE_HANDLE ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render( array $attributes ): string {
		$product = $this->resolveProduct( $attributes );
		if ( null === $product ) {
			return '';
		}

		// 公開フロントでは published 商品のみ描画する（Repository は admin/REST 向けに
		// post_status='any' で検索するため、下書き/非公開/ゴミ箱の漏洩をここで防ぐ）。
		$status = isset( $product['status'] ) ? (string) $product['status'] : '';
		if ( 'publish' !== $status ) {
			return '';
		}

		wp_enqueue_style( self::STYLE_HANDLE );

		return ( new CardHtmlBuilder() )->build( $product, $attributes );
	}

	/**
	 * @param array<string, mixed> $attributes
	 * @return array<string, mixed>|null
	 */
	private function resolveProduct( array $attributes ): ?array {
		$product_id = isset( $attributes['productId'] ) ? (int) $attributes['productId'] : 0;
		if ( $product_id > 0 ) {
			return $this->repository->find( $product_id );
		}

		$slug = isset( $attributes['slug'] ) ? trim( (string) $attributes['slug'] ) : '';
		if ( '' !== $slug ) {
			return $this->repository->findBySlug( $slug );
		}

		$external_id = isset( $attributes['externalId'] ) ? trim( (string) $attributes['externalId'] ) : '';
		$platform    = isset( $attributes['platform'] ) ? trim( (string) $attributes['platform'] ) : '';
		if ( '' !== $external_id && '' !== $platform ) {
			$found = $this->repository->findByExternalId( $platform, $external_id );
			if ( null !== $found ) {
				return $found;
			}
			return $this->autoCreate( $platform, $external_id );
		}

		return null;
	}

	/**
	 * 未登録の externalId+platform を非同期 AutoCreate ジョブとして enqueue する。
	 *
	 * フロント render 中の同期 HTTP 呼び出しを避けるため、ここでは商品を作らず
	 * ジョブを積むだけに留める（実際の生成は AutoCreateHandler が担う）。
	 * カードは今回のビューでは描画されず、次回以降のビューで生成済み商品として解決される。
	 */
	private function autoCreate( string $platform, string $externalId ): ?array {
		$lock_key = 'affilicard_autocreate_' . $platform . '_' . $externalId;
		if ( false !== get_transient( $lock_key ) ) {
			return null;
		}
		set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

		$definition = PlatformConfig::find( $platform );
		if ( null === $definition ) {
			// 未知の platform code は enqueue できないため、ロックを即解放し次回リクエストで再試行できるようにする
			// （解放しないと 5 分間リトライ不能になる）。
			delete_transient( $lock_key );
			return null;
		}

		$this->enqueuer->enqueueAutoCreate( $platform, $definition->provider, $externalId );
		return null;
	}
}
