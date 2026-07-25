<?php
declare(strict_types=1);

namespace Affilicard\Repository;

/**
 * 商品リポジトリの抽象インターフェース。
 *
 * ProductAutoCreator 等からリポジトリをモック可能にするために導入。
 */
interface ProductRepositoryInterface {

	/**
	 * @param array<string, mixed> $data
	 */
	public function save( array $data ): int;

	/**
	 * 指定 platform の listing だけを差し替え、他 listing を保持して原子的に保存する。
	 *
	 * find()→save() の read-modify-write は全 listings を上書きするため、同一商品の別
	 * platform listing を別 account group で並行更新すると後着の save が先着の変更を消す
	 * （lost update）。このメソッドは MySQL 名前付きロックで RMW をクリティカルセクション化し、
	 * META_LISTINGS をその場で再読込→対象 platform の listing だけを $listingFields で丸ごと
	 * 置換して保存することで、他 platform listing の並行更新を失わない。一致 platform が
	 * 無ければ false。
	 *
	 * @param array<string, mixed> $listingFields refreshListing() が返すフィールド完全形の listing。
	 */
	public function updateListing( int $postId, string $platform, array $listingFields ): bool;

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $postId ): ?array;

	/**
	 * @return array<string, mixed>|null
	 */
	public function findByExternalId( string $platformCode, string $externalId ): ?array;

	/**
	 * @return array<string, mixed>|null
	 */
	public function findBySlug( string $slug ): ?array;

	public function delete( int $postId ): bool;

	/**
	 * 商品を検索し、各 item に thumbnail/price/platform を付与して返す。
	 *
	 * - $term 空: modified 降順の最近商品（ページ処理あり）
	 * - $term 非空: title/content 全文検索 + external_id ミラー OR meta_query の和集合、一意化・modified 降順
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function search( string $term, int $perPage, int $page ): array;
}
