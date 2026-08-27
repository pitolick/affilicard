<?php
declare(strict_types=1);

namespace Affilicard\Stocktake;

use Affilicard\PostType\ProductPostType;

/**
 * 最終掲載日（記事の公開・更新で商品が掲載面に載った最後の時刻）の読み書き。
 *
 * 記録するのは「記事の公開日時」ではなく記録時点の現在時刻。公開日時を使うと、
 * 過去日付の記事を後から編集した場合や予約投稿で未来日時が入る場合に実態とずれる。
 * 判定したいのは「最後に掲載面へ手が入ったのはいつか」である（spec §5-1）。
 *
 * meta（ProductPostType::META_LAST_PUBLISHED_AT）は ProductMeta::register() で
 * REST 非露出（show_in_rest=false）＋ auth_callback 拒否として登録する。ここが唯一の書き込み経路になる。
 */
final class PublicationDate {

	/**
	 * 既存値より新しいときだけ書く（単調増加）。複数記事から参照される商品では、
	 * 最後に触れられた（＝最も新しい）時刻を残す。
	 */
	public function touch( int $postId, int $nowTs ): void {
		$current = $this->get( $postId );
		if ( null !== $current && $current >= $nowTs ) {
			return;
		}
		update_post_meta( $postId, ProductPostType::META_LAST_PUBLISHED_AT, gmdate( 'c', $nowTs ) );
	}

	/**
	 * UTC epoch 秒で返す。null・空文字・パース不能はすべて null（無効値）として返す。
	 * `??`（null 合体）だけでは空文字を捕まえられないため、呼び出し側はこの null を
	 * 棚卸し基準日へフォールバックさせる。
	 */
	public function get( int $postId ): ?int {
		$raw = get_post_meta( $postId, ProductPostType::META_LAST_PUBLISHED_AT, true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}
		$ts = strtotime( trim( $raw ) );
		return false === $ts ? null : (int) $ts;
	}
}
