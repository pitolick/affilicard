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
	 * 指定 platform の listing のうち、身元（external_id、空なら regular_url）が一致する
	 * 購入リンク（offer）1 件だけを差し替えて原子的に保存する。
	 *
	 * 価格更新はこちらを使う。updateListing() は呼び出し側が持っている listing 全体で
	 * 置き換えるため、外部 API の fetch 中に管理画面が購入リンクを追加・削除・並べ替えて
	 * いると、その編集が fetch 前の写しで丸ごと巻き戻る（lost update）。渡すのを「今回
	 * fetch した 1 件」に限ることで、古い値そのものを持ち込まない。
	 *
	 * 該当 platform が無い、または身元が一致する offer が現在の listing に無い（fetch 中に
	 * 削除された）場合は、**追加せず** false を返す。ロックを取れなかったとき・保存自体に
	 * 失敗したときも false を返す（保存できていないのに成功と報告しない）。呼び出し側は
	 * false を一時失敗として扱い、再試行すること。
	 *
	 * **マージ先は $targetIdentity で指定する。$offer 自身から求めてはならない。**
	 * 取得結果は regular_url を上書きしうるため、external_id を持たない購入リンクでは
	 * 取得の前後で identity が変わる。取得後の値で探すと、更新すべき相手を見失う。
	 *
	 * @param array<string, mixed> $patch          取得が変えたフィールドだけの差分。
	 *                                             ロック内で読み直した購入リンクへマージする。
	 * @param string               $targetIdentity 取得前に確定させたマージ先の identity
	 *                                             （{@see \Affilicard\Pricing\OfferIdentity::of()}）。
	 */
	public function updateListingOffer( int $postId, string $platform, array $patch, string $targetIdentity ): bool;

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
