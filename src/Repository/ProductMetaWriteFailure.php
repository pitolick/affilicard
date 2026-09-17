<?php
declare(strict_types=1);

namespace Affilicard\Repository;

/**
 * 保存した meta が読み戻らなかった——書いたのに入っていなかった——ことを表す例外。
 *
 * **名前が `ProductListingsWriteFailure` でなくなったのは、listings 専用ではなくなった
 * からである。** {@see ProductRepository::saveMeta()} は listings のほかに、要求が実際に
 * 運んでくるメタ（`product_type` / `stock_status` / `extras` / `release_date`）も書いた
 * あとに読み直す。どれが入らなくても運用者の取るべき行動は同じ（原因を取り除く）ので
 * 型は分けず、**入らなかったフィールドを {@see self::unsavedFields()} で運ぶ**。
 *
 * **{@see ProductLockUnavailable} とは別の事象である。** あちらは「書かなかった」で、
 * 運用者がやり直せばそのまま通る（競合相手が抜けるのを待つだけ）。こちらは
 * 「書いたのに入らなかった」で、やり直しても同じ結果になる見込みが高い——別プラグインの
 * `update_post_metadata` フィルタ、壊れた meta 行、DB の書き込み失敗といった、
 * 取り除かない限り消えない原因を指す。**手当てが違うので型も分ける**（REST は前者を
 * 409、こちらを 500 で返す）。
 *
 * **専用の型にして握り潰す範囲を閉じるのは
 * {@see \Affilicard\Upgrade\OffersMigrationWriteFailure} と同じ理由である。** 素の
 * `\RuntimeException` で捕まえると、単体テストの
 * `Mockery\Exception\NoMatchingExpectationException`（これも RuntimeException を継承する）
 * のような無関係な例外まで「保存の失敗」として扱われ、本当のバグが見えなくなる。
 *
 * **post ID を運ぶのは {@see ProductLockUnavailable} と同じ理由である。**
 * {@see ProductRepository::save()} は `wp_insert_post()` が通ってから
 * {@see ProductRepository::saveMeta()} を呼ぶため、**新規作成の途中でこれが投げられた
 * 時点で投稿行は既に存在する**。ID を運ばないと
 * {@see \Affilicard\Rest\ProductsController::create()} は ID を持たない 500 を返すしか
 * なく、呼び出し側は「商品ができたのかどうか」すら知れない——再試行のたびに POST し直し、
 * 同じ商品が増え続ける。ID があれば、呼び出し側は増やす代わりにその商品を PATCH できる。
 */
final class ProductMetaWriteFailure extends \RuntimeException {

	/**
	 * 読み直して入っていなかったフィールド名。
	 *
	 * **これを例外に持たせるのは、書いた側だけが答えを知っているからである。** 以前は
	 * {@see \Affilicard\Rest\ProductsController} が `array( 'listings' )` を固定で返して
	 * いたが、それは**どのフィールドを読み直したかを知らない側が保存の範囲を名乗る**形で、
	 * 検証の範囲が変わるたびに黙って嘘になる。
	 *
	 * @var array<int, string>
	 */
	private array $unsavedFields;

	/**
	 * @param int                $postId        meta を保存できなかった商品の投稿 ID。**新規作成の
	 *                                          途中で投げた場合、この ID の投稿行は既に作成されている**
	 *                                          （呼び出し側はこれを使ってやり直しを PATCH に変えられる）。
	 * @param array<int, string> $unsavedFields 読み直して入っていなかったフィールド名。既定を
	 *                                          `array( 'listings' )` にしてあるのは、listings が
	 *                                          「必ず読み直し、入っていなければ必ず投げる」唯一の
	 *                                          フィールドで、この例外の大半がそれだからである。
	 * @param string             $message       省略時は postId と内訳を含む既定文（テストが型だけでなく
	 *                                          メッセージまで固定できるようにするため、必ず ID を含める）。
	 */
	public function __construct( private int $postId, array $unsavedFields = array( 'listings' ), string $message = '' ) {
		$this->unsavedFields = array_values( $unsavedFields );
		parent::__construct(
			'' !== $message
				? $message
				: sprintf(
					'affilicard: 商品 %d の meta を保存できなかった（書き込んだ値が読み戻らない: %s）。',
					$postId,
					array() === $this->unsavedFields ? '(不明)' : implode( ', ', $this->unsavedFields )
				)
		);
	}

	public function postId(): int {
		return $this->postId;
	}

	/**
	 * 読み直して入っていなかったフィールド名。
	 *
	 * **「ここに無い＝保存された」とは読めない。** {@see ProductRepository::saveMeta()} が
	 * 読み直すのは要求が実際に運んでくるメタだけで、それ以外（`mask_*` /
	 * `schema_version`、および投稿行の `title` / `content` / `status`）は確かめていない。
	 * 意味は「入らなかったと分かっているフィールド」であり、完全な一覧ではない
	 * （理由は {@see ProductRepository::saveMeta()} の PHPDoc）。
	 *
	 * @return array<int, string>
	 */
	public function unsavedFields(): array {
		return $this->unsavedFields;
	}
}
