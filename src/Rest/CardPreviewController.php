<?php
declare(strict_types=1);

namespace Affilicard\Rest;

use Affilicard\Renderer\CardHtmlBuilder;
use Affilicard\Repository\ProductRepositoryInterface;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `GET /affilicard/v1/products/{id}/card-preview` の実装。
 *
 * 認証済み（edit_posts）編集者に対し、status を問わず商品をロードして
 * フロントと同一の CardHtmlBuilder で WYSIWYG プレビュー HTML を返す。
 * publish ガードのバイパスはこの認証済みエンドポイント内に閉じる。
 * フロント Block::render の publish ガードは一切変更しない。
 */
final class CardPreviewController {

	public function __construct( private ProductRepositoryInterface $repository ) {}

	public function registerRoutes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/products/(?P<id>\d+)/card-preview',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'preview' ),
					'permission_callback' => array( $this, 'canEditPosts' ),
					'args'                => array(
						'hidePlatforms'     => array(
							'type'    => 'array',
							'default' => array(),
						),
						'onlyPlatforms'     => array(
							'type'    => 'array',
							'default' => array(),
						),
						'ctaLabelOverrides' => array(
							'type'    => 'object',
							'default' => array(),
						),
						'ctaBgColor'        => array(
							'type'    => 'string',
							'default' => '',
						),
						'ctaTextColor'      => array(
							'type'    => 'string',
							'default' => '',
						),
						'cardBgColor'       => array(
							'type'    => 'string',
							'default' => '',
						),
						'cardBorderColor'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'maskBlur'          => array(
							'type' => 'boolean',
						),
						'maskR18'           => array(
							'type' => 'boolean',
						),
						'maskLabel'         => array(
							'type' => 'string',
						),
					),
				),
			)
		);
	}

	public function canEditPosts(): bool {
		return (bool) current_user_can( 'edit_posts' );
	}

	public function preview( WP_REST_Request $request ): WP_REST_Response {
		$id      = (int) $request->get_param( 'id' );
		$product = $this->repository->find( $id );
		if ( null === $product ) {
			return new WP_REST_Response(
				array(
					'code'    => 'affilicard_not_found',
					'message' => __( '商品が見つかりません。', 'affilicard' ),
				),
				404
			);
		}

		$hide = $request->get_param( 'hidePlatforms' );
		$only = $request->get_param( 'onlyPlatforms' );
		$cta  = $request->get_param( 'ctaLabelOverrides' );

		$attributes = array(
			'hidePlatforms'     => is_array( $hide ) ? array_map( 'strval', $hide ) : array(),
			'onlyPlatforms'     => is_array( $only ) ? array_map( 'strval', $only ) : array(),
			'ctaLabelOverrides' => is_array( $cta ) ? $cta : array(),
			'ctaBgColor'        => (string) ( $request->get_param( 'ctaBgColor' ) ?? '' ),
			'ctaTextColor'      => (string) ( $request->get_param( 'ctaTextColor' ) ?? '' ),
			'cardBgColor'       => (string) ( $request->get_param( 'cardBgColor' ) ?? '' ),
			'cardBorderColor'   => (string) ( $request->get_param( 'cardBorderColor' ) ?? '' ),
		);

		if ( null !== $request->get_param( 'maskBlur' ) ) {
			$attributes['maskBlur'] = filter_var( $request->get_param( 'maskBlur' ), FILTER_VALIDATE_BOOLEAN );
		}
		if ( null !== $request->get_param( 'maskR18' ) ) {
			$attributes['maskR18'] = filter_var( $request->get_param( 'maskR18' ), FILTER_VALIDATE_BOOLEAN );
		}
		$mask_label = $request->get_param( 'maskLabel' );
		if ( null !== $mask_label ) {
			$attributes['maskLabel'] = (string) $mask_label;
		}

		$html = ( new CardHtmlBuilder() )->build( $product, $attributes );

		return new WP_REST_Response( array( 'html' => $html ), 200 );
	}
}
