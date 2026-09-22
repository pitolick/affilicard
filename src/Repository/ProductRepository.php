<?php
declare(strict_types=1);

namespace Affilicard\Repository;

use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductPostType;
use Affilicard\Pricing\LegacyOffer;
use Affilicard\Pricing\OfferIdentity;
use Affilicard\Pricing\OfferSelector;
use Affilicard\Pricing\OfferStatusReset;
use Affilicard\Pricing\OfferUrl;
use Affilicard\Rest\ProductSchema;
use Affilicard\Schema\SchemaVersion;
use Affilicard\Settings\GeneralSettings;
use Affilicard\Stock\StockStatus;
use Affilicard\Util\JsonField;
use Affilicard\Util\ScalarField;

/**
 * `affilicard_product` CPT に対する CRUD ラッパ。
 *
 * 値の入出力はすべて配列で行う。listings/extras メタはネイティブ配列メタとして保存する
 * （register_post_meta type=array）。読み出し時は後方互換で旧 JSON 文字列も JsonField で decode する。
 */
final class ProductRepository implements ProductRepositoryInterface {

	/**
	 * 投稿 ID から商品データを取得する。
	 *
	 * @return array{
	 *   id: int,
	 *   slug: string,
	 *   title: string,
	 *   content: string,
	 *   status: string,
	 *   product_type: string,
	 *   stock_status: string,
	 *   release_date: string,
	 *   mask_blur: bool,
	 *   mask_r18: bool,
	 *   mask_label: string,
	 *   extras: array<int, mixed>,
	 *   listings: array<int, mixed>,
	 *   schema_version: string,
	 *   modified: string,
	 * }|null
	 */
	public function find( int $postId ): ?array {
		$post = get_post( $postId );
		if ( null === $post ) {
			return null;
		}
		if ( ! isset( $post->post_type ) || ProductPostType::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$extras_raw   = get_post_meta( $postId, ProductPostType::META_EXTRAS, true );
		$listings_raw = get_post_meta( $postId, ProductPostType::META_LISTINGS, true );

		$extras   = is_string( $extras_raw )
			? JsonField::decode( $extras_raw, array() )
			: ( is_array( $extras_raw ) ? $extras_raw : array() );
		$listings = is_string( $listings_raw )
			? JsonField::decode( $listings_raw, array() )
			: ( is_array( $listings_raw ) ? $listings_raw : array() );

		return array(
			'id'             => (int) $post->ID,
			'slug'           => (string) ( $post->post_name ?? '' ),
			'title'          => (string) ( $post->post_title ?? '' ),
			'content'        => (string) ( $post->post_content ?? '' ),
			'status'         => (string) ( $post->post_status ?? '' ),
			'product_type'   => (string) get_post_meta( $postId, ProductPostType::META_PRODUCT_TYPE, true ),
			'stock_status'   => StockStatus::normalize( (string) get_post_meta( $postId, ProductPostType::META_STOCK_STATUS, true ) ),
			'release_date'   => (string) get_post_meta( $postId, ProductPostType::META_RELEASE_DATE, true ),
			'mask_blur'      => (bool) get_post_meta( $postId, ProductPostType::META_MASK_BLUR, true ),
			'mask_r18'       => (bool) get_post_meta( $postId, ProductPostType::META_MASK_R18, true ),
			'mask_label'     => (string) get_post_meta( $postId, ProductPostType::META_MASK_LABEL, true ),
			'extras'         => $extras,
			'listings'       => $listings,
			'schema_version' => (string) get_post_meta( $postId, ProductPostType::META_SCHEMA_VERSION, true ),
			'modified'       => (string) ( $post->post_modified ?? '' ),
		);
	}

	/**
	 * 外部 ID（platform 別）を元に商品を 1 件検索する。
	 */
	public function findByExternalId( string $platformCode, string $externalId ): ?array {
		$meta_key = ProductPostType::externalIdMetaKey( $platformCode );

		$posts = get_posts(
			array(
				'post_type'      => ProductPostType::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => $meta_key,
						'value'   => $externalId,
						'compare' => '=',
					),
				),
			)
		);

		if ( ! is_array( $posts ) || array() === $posts ) {
			return null;
		}

		$first = $posts[0];
		$id    = is_object( $first ) && isset( $first->ID ) ? (int) $first->ID : (int) $first;

		return $this->find( $id );
	}

	/**
	 * post_name（スラッグ）から商品を 1 件検索する。
	 */
	public function findBySlug( string $slug ): ?array {
		if ( '' === $slug ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'      => ProductPostType::POST_TYPE,
				'post_status'    => 'any',
				'name'           => $slug,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);

		if ( ! is_array( $posts ) || array() === $posts ) {
			return null;
		}

		$first = $posts[0];
		$id    = is_object( $first ) && isset( $first->ID ) ? (int) $first->ID : (int) $first;

		return $this->find( $id );
	}

	/**
	 * 商品データを保存（新規 or 更新）し、post ID を返す。
	 *
	 * @param array<string, mixed> $data
	 */
	public function save( array $data ): int {
		$is_update = isset( $data['id'] ) && (int) $data['id'] > 0;

		$post_args = array(
			'post_type'    => ProductPostType::POST_TYPE,
			'post_title'   => isset( $data['title'] ) ? (string) $data['title'] : '',
			'post_content' => isset( $data['content'] ) ? (string) $data['content'] : '',
			'post_status'  => isset( $data['status'] ) ? (string) $data['status'] : 'publish',
		);

		if ( $is_update ) {
			$post_args['ID'] = (int) $data['id'];
			$post_id         = (int) wp_update_post( $post_args, true );
		} else {
			$post_id = (int) wp_insert_post( $post_args, true );
		}

		if ( $post_id <= 0 ) {
			return 0;
		}

		$this->saveMeta( $post_id, $data );

		return $post_id;
	}

	/**
	 * 指定 platform の listing だけを差し替え、他 listing を保持して原子的に保存する。
	 *
	 * find()→save() の全 listings 上書きは、同一商品の別 platform listing を別 account
	 * group（affilicard-rakuten / affilicard-dmm 等）で並行更新すると後着の save が先着の
	 * 変更を消す（lost update）。ここでは {@see ListingLock} の名前付きロックで
	 * read-modify-write をクリティカルセクション化し、META_LISTINGS をその場で再読込→
	 * 対象 platform の listing だけを $listingFields で丸ごと置換して update_post_meta する
	 * ことで、並行更新された他 platform listing を失わない。external_id は refresh で
	 * 変わらないため extid ミラー同期（syncExternalIdMirror）は呼ばない。
	 *
	 * ロック取得に失敗（0/null）しても RMW は best-effort で続行する（fetch は既に成功済みで、
	 * ロック不能を理由に更新を捨てる方が有害。取得可否は挙動を変えない安全弁）。
	 *
	 * **updateListingOffer() はここと逆に、ロックを取れなければ何も書かず false を返す。**
	 * 非対称なのは呼び出し側の事情が違うためである——あちらは
	 * {@see \Affilicard\Cron\ListingRefresher::refreshOne()} が false を一時失敗として扱い
	 * 再投入するので、書かずに諦めても取得した値は次の試行で保存し直される。こちらには
	 * その再試行を持つ呼び出し側が無く（現状 production からの呼び出しは無い）、false を
	 * 返しても誰も拾い直さないため、書かない方が失うものが大きい。再試行できない
	 * 呼び出し側を増やさない限り、この非対称は保つこと。
	 *
	 * @param array<string, mixed> $listingFields refreshListing() が返すフィールド完全形の listing
	 *                                             （購入リンク配列 offers を含む）。
	 */
	public function updateListing( int $postId, string $platform, array $listingFields ): bool {
		return ListingLock::around(
			$postId,
			static function ( bool $locked ) use ( $postId, $platform, $listingFields ): bool {
				// **$locked は見ない（best-effort）。** ロックを取れなくても
				// read-modify-write へ入る。理由は上の PHPDoc のとおりで、fetch は
				// 既に成功しており、ロック不能を理由に取得済みの値を捨てる方が有害。
				$raw      = get_post_meta( $postId, ProductPostType::META_LISTINGS, true );
				$listings = is_string( $raw )
					? JsonField::decode( $raw, array() )
					: ( is_array( $raw ) ? $raw : array() );

				$found = false;
				foreach ( $listings as $index => $listing ) {
					if ( ! is_array( $listing ) || ( $listing['platform'] ?? '' ) !== $platform ) {
						continue;
					}
					$listings[ $index ] = $listingFields;
					$found              = true;
					break;
				}

				if ( ! $found ) {
					return false;
				}

				update_post_meta( $postId, ProductPostType::META_LISTINGS, array_values( $listings ) );
				return true;
			}
		);
	}

	/**
	 * 指定 platform の listing のうち、身元が一致する購入リンク（offer）1 件だけを
	 * 差し替えて原子的に保存する。
	 *
	 * **価格更新（{@see \Affilicard\Cron\ListingRefresher::refreshOne()}）はこちらを使う。**
	 * updateListing() は呼び出し側が持っている listing 全体で置き換えるため、外部 API の
	 * fetch（数百 ms〜数秒）のあいだに管理画面が購入リンクを追加・削除・並べ替えていると、
	 * その編集が fetch 前の写しで丸ごと巻き戻る（lost update）。ロックの中で listing を
	 * 読み直しても、書き込む値が古ければ意味がない——**古い値を持ち込まない**ことが要点で、
	 * だから渡すのは「今回 fetch した 1 件の offer」だけにする。
	 *
	 * 突き合わせは配列の添字ではなく身元（{@see OfferIdentity}: external_id、空なら
	 * regular_url）で行う。offers は複数の書き込み元から届き、位置は安定しない。
	 *
	 * **一致した最初の 1 件へマージし、「2 件以上一致したら諦める」ガードは置かない。**
	 * 同じ身元の offer が 2 件並ぶことが保存側で起こり得ないためである（spec §3-4
	 * 「同一 listing 内で識別子が重複する offer は後勝ちでマージする」）。この不変条件は
	 * {@see \Affilicard\Rest\ProductSchema::sanitizeOffers()} が強制する——external_id、
	 * 空なら regular_url をキーに束ねて後勝ちで潰しており、これは {@see OfferIdentity::of()}
	 * とまったく同じ規則である。そして META_LISTINGS を書く経路は例外なく
	 * `update_post_meta()` を通り、`update_metadata()` の中の `sanitize_meta()` が
	 * {@see \Affilicard\PostType\ProductMeta::register()} の登録した
	 * `ProductSchema::sanitizeListings` を必ず走らせる（コアの `wp/v2` meta 経路も同じ）。
	 * 身元を 1 つも持たない offer だけは
	 * {@see \Affilicard\Rest\ProductSchema::withLegacyOfferPreservation()} の窓で
	 * 温存され得るが、あの窓を使うのは移行の書き込み 1 回だけで、
	 * {@see \Affilicard\Upgrade\PluginUpgrade::migrateListingToOffers()} が作る offer は
	 * listing あたり高々 1 件である。ガードを足しても到達しないコードになる。
	 *
	 * **身元が見つからなければ何も保存せず false を返す（追加はしない）。** fetch 中に
	 * 管理者が削除した購入リンクをここで復活させると、削除操作が無言で取り消される。
	 * false は呼び出し側で一時失敗として扱われ、リトライ時には「そのとき現存する」
	 * 購入リンクが選び直されるため、この状態は次の試行で自然に解消する。
	 *
	 * 移行前の flat な listing（offers を持たない v3 以前の形）は
	 * {@see LegacyOffer::offersWithFallback()} で offers[] へ写してから突き合わせる
	 * （読み取り側・移行バッチと同じ写像を通す）。
	 *
	 * ロックの流儀（名前・タイムアウト・finally での解放）は {@see ListingLock} が
	 * updateListing() と共通で持つが、**ロックを取れなかったときの扱いだけが逆**である
	 * （取れなければ何も書かず false）。理由は updateListing() の PHPDoc に書いた
	 * 非対称のとおり。だから ListingLock は取得の成否をコールバックへ渡すだけにして、
	 * そこから先の判断は呼び出し側に置いている。
	 *
	 * @param array<string, mixed> $patch          取得が変えたフィールドだけの差分。
	 *                                             ロック内で読み直した購入リンクへマージする。
	 * @param string               $targetIdentity 取得前に確定させたマージ先の identity。
	 *                                             $patch 自身から求めてはならない——取得は
	 *                                             regular_url を上書きしうるため、external_id を
	 *                                             持たない購入リンクでは前後で identity が変わる。
	 */
	public function updateListingOffer( int $postId, string $platform, array $patch, string $targetIdentity ): bool {
		return ListingLock::around(
			$postId,
			static function ( bool $locked ) use ( $postId, $platform, $patch, $targetIdentity ): bool {
				if ( ! $locked ) {
					// **ロック無しで read-modify-write に入らない。** ロックを取れない窓は
					// まさに誰かが同じ meta を書いている窓であり、そこで読み書きすると
					// 管理画面の保存を丸ごと巻き戻す（lost update）。false は
					// ListingRefresher::refreshOne() が一時失敗として扱って再投入するため、
					// 取得した価格は次の試行で保存し直される——取りこぼしにはならない。
					// 全体を置き換える updateListing() が best-effort で続行するのとは
					// 逆の判断（理由は同関数の PHPDoc）。
					return false;
				}

				$raw      = get_post_meta( $postId, ProductPostType::META_LISTINGS, true );
				$listings = is_string( $raw )
					? JsonField::decode( $raw, array() )
					: ( is_array( $raw ) ? $raw : array() );
				// 書き込み前の姿を控える（update_post_meta() の false を「失敗」と
				// 「値が変わらなかった」に切り分けるため。下の保存を参照）。
				$before = $listings;

				foreach ( $listings as $index => $listing ) {
					if ( ! is_array( $listing ) || ( $listing['platform'] ?? '' ) !== $platform ) {
						continue;
					}

					$merged  = array();
					$written = false;
					foreach ( LegacyOffer::offersWithFallback( $listing ) as $existing ) {
						if ( ! $written && is_array( $existing ) && OfferIdentity::of( $existing ) === $targetIdentity ) {
							// **置換ではなくマージする。** $patch は取得が変えたフィールドだけを
							// 持ち、ロック内で読み直した $existing に載せる。こうしないと fetch 中の
							// 並べ替え（display_order）や search_key の編集が古い写しで巻き戻る。
							$merged[] = array_merge( $existing, $patch );
							$written  = true;
							continue;
						}
						$merged[] = $existing;
					}

					if ( ! $written ) {
						// fetch 中に削除された購入リンク。追加し直さず未保存を報告する。
						return false;
					}

					$listing['offers']  = $merged;
					$listings[ $index ] = $listing;
					$next               = array_values( $listings );

					// **update_post_meta() の false を握り潰さない。** 握り潰すと、取得した
					// 価格が保存されていないのに呼び出し側は成功として完了し、再試行もしない
					// （＝サイレントなデータロス）。
					//
					// ただし false は「失敗」と「値が変わらなかった」の両方で返る
					// （WordPress は既存値と一致すると書かずに false を返す）。戻り値だけでは
					// 切り分けられないので、書く前に自分で比較して「変わらない」を先に
					// 除いてから呼ぶ——PluginUpgrade::writeMigratedListings() が読み直しで
					// 同じ切り分けをしているのと同じ趣旨で、こちらは保存前の値を控えて行う
					// （書き込みは sanitize_meta を通るため、読み直した値は渡した値と
					// 一致するとは限らない）。
					if ( $next === $before ) {
						// 書くものが無い＝既に望みの状態。成功として返す。
						return true;
					}

					return false !== update_post_meta( $postId, ProductPostType::META_LISTINGS, $next );
				}

				return false;
			}
		);
	}

	/**
	 * 投稿 ID とデータ配列からメタフィールドのみを保存する。
	 *
	 * `save()` の内部でも呼ばれるが、`save_post` ハンドラから直接呼ぶことも想定している。
	 *
	 * **listings を丸ごと書き換えるのは運用者/API の編集だけである。** 価格更新は
	 * {@see self::updateListingOffer()}（取得が変えたフィールドだけのパッチ）を通るため、
	 * ここへは来ない。だから「身元を書き換えられた購入リンクの取得状態を白紙に戻す」
	 * 判定（{@see OfferStatusReset}）はこの層に置く——保存の共通層
	 * （ProductSchema::sanitizeOffers）に置くと、取得が今書いた fetch_status まで
	 * 消してしまう。
	 *
	 * **その判定は listings の read-modify-write である**——保存前の listings を読み、
	 * 身元の変化を見てから書き戻す。したがって他の 3 つの書き手と同じ
	 * {@see ListingLock} で直列化する。囲むのは listings に関わる読み→変換→書き戻しと
	 * **extid ミラーの同期**で、他のメタ（product_type / extras 等）はロックの外に置く
	 * （別キーで RMW ではないため）。
	 *
	 * **ミラー同期をロックの中へ入れるのは、それも META_LISTINGS の read-modify-write
	 * だからである。** ミラーは listings の写しで、「保存後の listings を読み直す →
	 * 今回の集合に無い値を消す → 足りない値を足す」という複数クエリの操作になる。
	 * ロックの外でやると、2 つの保存が「A 書き込み → B 書き込み → B ミラー → A ミラー」
	 * の順に交差したとき **古い方のスナップショットから作ったミラーが後着で勝つ**。
	 * ミラーは findByExternalId() の索引そのものなので、狂うと自動作成が既存商品を
	 * 見落として重複を作るか、消した ID で既存商品を引いて更新先を間違える。囲んでよい
	 * 範囲も超えない——足すのは meta の読み書きだけで、`wp_update_post()` も第三者の
	 * `save_post` も挟まない。
	 *
	 * **そのミラー同期は meta を読み直してから行う**——書こうとした値ではなく実際に
	 * 保存されている値を映さないと、商品が持っていない external_id で引けるようになる
	 * （理由は同期の直前のコメント）。
	 *
	 * **ロックを取れなければ listings を書かず {@see ProductLockUnavailable} を投げる。**
	 * 以前はここで best-effort に書いていた（{@see self::updateListing()} と同じ側）。
	 * 理由は「再投入する呼び出し側が無く、saveMeta() は void なので失敗を報告する口すら
	 * 無い。書かずに戻ると運用者の編集が無言で消える」——**沈黙の代償についてはそのとおり
	 * だが、代わりに古い読みを書き戻して先着の書き込みを丸ごと消していた**。lost update は
	 * 無言で消える編集より広く、しかも消えたことが誰にも分からない。
	 *
	 * 第三の道を採る——**黙るのをやめる**。書かない、かつ捨てない。報告する口が無いなら
	 * 作ればよい、というのが例外にした理由である（{@see ProductLockUnavailable} の
	 * クラス PHPDoc に、なぜ bool ではなく例外かを書いた）。捕まえた側は運用者へ 409 を
	 * 返すか（REST）、一時失敗として再投入する（自動作成）。
	 *
	 * これが起きるのは稀である——{@see ListingLock::TIMEOUT} は 10 秒なので、取得失敗は
	 * 「同じ 1 商品を 10 秒間ふさぎ続ける競合が実在した」ことを意味する。その頻度なら
	 * 大きな声で失敗してよい。
	 *
	 * **投げる時点で listings 以外のメタは書き終えている（schema_version を除く）。**
	 * 投稿行（title/content/status）も呼び出し元 {@see self::save()} が先に書いている。
	 * どれも別キーの冪等な上書きなので、運用者が同じ内容でやり直せばそのまま通る。
	 * 逆に listings は**一切触っていない**——古い値が無傷で残る、というのがこの例外の
	 * 意味である。**schema_version だけは listings と一緒に見送る**——あれは listings の
	 * 形式を指す刻印で、listings から独立していないためである（保存後に押す理由は
	 * その行のコメント）。
	 *
	 * **書いたのに入らなかった場合も黙らない。** `update_post_meta()` の戻り値は
	 * 「入ったかどうか」を表さない——失敗しても false を返すだけだが、**成功を表す
	 * true も書かずに返り得る**（`update_post_metadata` フィルタの短絡）。だから
	 * 戻り値は見ず、書き込みのたびに読み直して確かめ、入っていなければ
	 * {@see ProductMetaWriteFailure} を投げる（詳細は
	 * {@see self::assertListingsPersisted()}）。**ロック競合とは別の型にする**——
	 * あちらは待てば通るが、こちらは原因を取り除くまで直らない。
	 *
	 * **読み直すのは「要求が実際に運んでくるメタ」だけである。** その一覧が
	 * {@see self::VERIFIED_META}——`product_type` / `stock_status` / `extras` /
	 * `release_date`、それに listings。ここが入らなければ**運用者が送った値が黙って
	 * 消える**ので、成功を返さず {@see ProductMetaWriteFailure} で報告する。
	 *
	 * **確かめないものが 2 種類ある。どちらも「確かめても失われた要求を見つけられない」
	 * からで、手を抜いたのではない。**
	 *
	 * | 確かめないもの | 理由 |
	 * | --- | --- |
	 * | `mask_blur` / `mask_r18` / `mask_label` | **この経路の呼び出し側は 1 つも設定できない**。`ProductSchema::args()` にも `ProductSchema::sanitizeItem()` にも `ProductsController::extractProductData()` にも無く、値は `update()` が既存商品から読み直したもの（＝いま入っている値そのもの）か、作成時の既定（`false` / `''`＝登録済み default と同値）である。短絡フィルタに書き込みを潰されても**保存されている値は要求どおりのまま**なので、読み直しても失われた要求は見つからない。見つかるのは「他プラグインが介入している」という事実だけで、それを理由に保存を 500 にすると、実害の無いサイトの保存が全部止まる。編集の主経路はブロックエディタのサイドバー（コアの `wp/v2` meta）で、そちらはここを通らない |
	 * | `schema_version` | 要求フィールドではなく、listings の形式を指す刻印である。しかも**入らない側が安全**——刻印が無い商品は「未移行」と読まれるだけで、中身は既に新形式なので移行バッチが再訪しても no-op になる。危険なのは逆向き（listings が入っていないのに刻印が押される）で、そちらは並び（listings の保存後に押す）が既に塞いでいる |
	 *
	 * つまり `unsavedFields()` は「入らなかったと分かっているフィールド」であって、
	 * **「ここに無い＝保存された」ではない**（投稿行の title/content/status も
	 * `wp_insert_post()` / `wp_update_post()` の戻り ID しか見ていない）。REST の
	 * `unsaved_fields` もその意味で返す（{@see \Affilicard\Rest\ProductsController}）。
	 *
	 * **入らなかったからといって、そこで書くのをやめない。** 読み直しの結果は溜めて
	 * おき、listings まで書き切ってから投げる。途中で抜けると、いちばん失って困る
	 * listings を「その手前のメタが 1 つ入らなかった」という理由で書かずに終える——
	 * 商品は購入リンクの無いまま残り、報告される未保存フィールドも実際より短くなる。
	 *
	 * @param array<string, mixed> $data
	 * @throws ProductLockUnavailable ロックを取得できず listings を書かなかったとき.
	 * @throws ProductMetaWriteFailure 書き込んだ meta が保存されていなかったとき.
	 */
	public function saveMeta( int $postId, array $data ): void {
		$product_type = isset( $data['product_type'] ) && '' !== (string) $data['product_type']
			? (string) $data['product_type']
			: 'generic';
		$stock_status = StockStatus::normalize(
			isset( $data['stock_status'] ) ? (string) $data['stock_status'] : null
		);
		$extras       = isset( $data['extras'] ) && is_array( $data['extras'] ) ? $data['extras'] : array();
		$listings     = isset( $data['listings'] ) && is_array( $data['listings'] ) ? $data['listings'] : array();
		$release_date = isset( $data['release_date'] ) ? (string) $data['release_date'] : '';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $release_date ) ) {
			$release_date = '';
		}
		$mask_blur  = ! empty( $data['mask_blur'] );
		$mask_r18   = ! empty( $data['mask_r18'] );
		$mask_label = isset( $data['mask_label'] ) ? sanitize_text_field( (string) $data['mask_label'] ) : '';

		// 並びは VERIFIED_META と同じにする。書いた順がそのまま報告の順になる。
		$verified = array(
			'product_type' => $product_type,
			'stock_status' => $stock_status,
			'extras'       => $extras,
			'release_date' => $release_date,
		);

		// **ここで書くのは listings から独立したメタだけである。** どれも別キーの冪等な
		// 上書きで、listings を書けなくても矛盾しない（下のロックで抜けても、運用者が
		// やり直せばそのまま通る）。**schema_version だけは独立していない**ので、
		// この並びには入れず listings の保存後に書く（理由は下）。extras は
		// listings の写しではなく独立した項目なので、ここでよい。
		//
		// **要求が運んでくるメタは書いたあとに読み直す**（{@see self::VERIFIED_META}）。
		// listings と同じ理由で、`update_post_meta()` の戻り値は「入ったかどうか」を
		// 表さないためである（{@see self::assertListingsPersisted()} の表）。
		// **入らなくてもここでは投げず、名前を溜めるだけにする**——途中で抜けると
		// listings を書かずに終わり、いちばん失って困るものを失う。
		$unsaved = array();
		foreach ( self::VERIFIED_META as $field => $meta_key ) {
			$value = $verified[ $field ];
			update_post_meta( $postId, $meta_key, $value );
			if ( ! self::metaPersisted( $postId, $field, $meta_key, $value ) ) {
				$unsaved[] = $field;
			}
		}

		// **mask_* は読み直さない。** この経路の呼び出し側は 1 つも設定できず、ここで
		// 書いているのは「いま入っている値」か登録済み default と同値の既定なので、
		// 短絡フィルタに潰されても要求は失われない（詳細は上の PHPDoc の表）。
		update_post_meta( $postId, ProductPostType::META_MASK_BLUR, $mask_blur );
		update_post_meta( $postId, ProductPostType::META_MASK_R18, $mask_r18 );
		update_post_meta( $postId, ProductPostType::META_MASK_LABEL, $mask_label );

		// **listings は最後に書く。** ロックを取れなければ（あるいは書いた値が読み戻ら
		// なければ）ここで例外を投げて抜けるため、順番がそのまま「何が保存され、何が
		// 保存されなかったか」になる。listings から独立したメタを先に片付けておけば、
		// listings が入らなくても失われるのは listings だけで、しかもそれは古い値のまま
		// 無傷で残る（書きかけで壊れた状態にはならない）。schema_version はこの並びに
		// 入れない——listings の形式を指す刻印で、独立していないためである（around() の
		// 後で押す）。
		ListingLock::around(
			$postId,
			function ( bool $locked ) use ( $postId, $listings, $unsaved ): void {
				if ( ! $locked ) {
					// **古い読みを書き戻さない。** ここから先は
					// 「listings を読む → 変換する → 書き戻す」で、ロックの外でやると
					// 先着（別リクエストの保存・価格更新）の書き込みを丸ごと消す。
					// かといって黙って戻れば運用者の編集が無言で消える。どちらも選ばず、
					// 書かずに**報告する**（理由と伝わり方は上の PHPDoc / 例外クラス側）。
					// **入らなかったフィールドは listings だけとは限らない。** 手前の
					// 読み直しで落ちたものがあれば一緒に載せる——呼び出し側は
					// ここに載ったものだけを送り直すので、落とすと消えたままになる。
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- HTML 出力ではなく PHP の例外メッセージ。組み立ては例外クラス側で、埋め込むのは post ID（int）のみ。
					throw new ProductLockUnavailable( $postId, '', self::withListings( $unsaved ) );
				}

				$next = OfferStatusReset::forIdentityChanges( self::listingsMeta( $postId ), $listings );
				// **戻り値は見ない。必ず読み直して確かめる。** `update_post_meta()` の
				// 戻り値は「入ったかどうか」を表さない——false が「値が変わらなかった」
				// でも返るのと対称に、**true が「1 バイトも書いていない」でも返る**
				// （`update_post_metadata` フィルタの短絡。詳細と根拠は
				// {@see self::assertListingsPersisted()} の表）。false のときだけ
				// 読み直す実装では、その短絡を成功として素通しする。
				update_post_meta( $postId, ProductPostType::META_LISTINGS, $next );
				$persisted = self::assertListingsPersisted( $postId, $next, $unsaved );

				// **ミラーは「書こうとした値」ではなく「実際に入っている値」から作る。**
				// ここで $listings（渡された配列）や、直前に組み立てた $next を使うと、
				// 保存の sanitize が値を変えたときに META_LISTINGS に無い external_id で
				// 商品が引けるようになり、逆に本当に入っている external_id の行が消える。
				// ミラーは findByExternalId() の索引そのもので、狂うと自動作成が既存商品を
				// 誤検出／見落としして重複商品を作る。syncDerivedMeta() が meta を
				// 読み直してから同期しているのと同じ流儀に揃える。
				//
				// **その「実際に入っている値」は直前の検証が読んだものを使い回す。**
				// assertListingsPersisted() は照合のために保存後の meta を読んでいる。
				// ここでもう一度読むと、同じロックの中で同じ行を 2 回引くだけで値は
				// 変わらない（外からは書けない）。返り値を受け取れば 1 回で済む。
				//
				// **読み直しも同期もこのロックの中で行う。** 外へ出すと、読み直しから
				// 複数クエリの同期が終わるまでのあいだに別の保存が挟まり、古い
				// スナップショットから作ったミラーが後着で勝つ（理由は上の PHPDoc）。
				$this->syncExternalIdMirror( $postId, $persisted );
			}
		);

		// **刻印は listings を保存できたあとに押す。** schema_version が指すのは
		// listings の形式であり、listings から独立していない。先に押すと、ロック競合や
		// 書き込み失敗で listings が古い形のまま残った商品まで「移行済み」として記録して
		// しまう。移行バッチ（{@see \Affilicard\Upgrade\PluginUpgrade}）はカーソルが
		// 通り過ぎた商品を再訪しないため、その取り残しは二度と直らない。
		//
		// 上の around() は、ロックを取れなければ ProductLockUnavailable、書いた値が
		// 読み戻らなければ ProductMetaWriteFailure を投げて抜ける。ここへ来たのは
		// listings が実際に入ったときだけである。
		//
		// ロックの外に置くのは {@see self::syncDerivedMeta()} と同じ理由——別キーで
		// read-modify-write ではないため、直列化する必要がない。
		update_post_meta( $postId, ProductPostType::META_SCHEMA_VERSION, SchemaVersion::CURRENT );

		self::assertVerifiedMetaPersisted( $postId, $unsaved );
	}

	/**
	 * listings は入ったが手前のメタが入らなかった場合に報告する。
	 *
	 * 成功として返すと、運用者が送った値（取扱終了・商品タイプ・付加情報・配信日）が
	 * 黙って消えたまま REST が 201/200 を返す。listings が入らなかったときと運用者の
	 * 取るべき行動は同じ（介入している原因を取り除く）なので、同じ型・同じ 500 で
	 * 報告する。
	 *
	 * @param array<int, string> $unsaved 読み直して入っていなかったフィールド名.
	 * @throws ProductMetaWriteFailure 1 つでも入っていなかったとき.
	 */
	private static function assertVerifiedMetaPersisted( int $postId, array $unsaved ): void {
		if ( array() === $unsaved ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- HTML 出力ではなく PHP の例外メッセージ。組み立ては例外クラス側で、埋め込むのは post ID（int）と自前のフィールド名のみ。
		throw new ProductMetaWriteFailure( $postId, $unsaved );
	}

	/**
	 * 保存後に読み直して確かめるメタ（フィールド名 => meta キー）。
	 *
	 * **載せる基準は「要求が実際に運んでくるか」である。** ここが入らなければ運用者が
	 * 送った値が黙って消えるので、成功を返さずに報告する。載せていないメタ
	 * （`mask_*` / `schema_version`）を確かめない理由は {@see self::saveMeta()} の
	 * PHPDoc にある——どちらも「確かめても失われた要求は見つからない」ためで、
	 * 確かめると実害の無いサイトの保存まで 500 にしてしまう。
	 *
	 * listings はこの表に入れない。書く場所（ロックの中）も、入らなかったときの扱い
	 * （即座に投げる）も違うためである。
	 *
	 * @var array<string, string>
	 */
	private const VERIFIED_META = array(
		'product_type' => ProductPostType::META_PRODUCT_TYPE,
		'stock_status' => ProductPostType::META_STOCK_STATUS,
		'extras'       => ProductPostType::META_EXTRAS,
		'release_date' => ProductPostType::META_RELEASE_DATE,
	);

	/**
	 * 未保存フィールドの一覧に listings を足す。
	 *
	 * 並びは「書いた順」——読み手（運用者・呼び出し側）が saveMeta() の並びと
	 * 突き合わせられるようにするためで、listings は必ず最後に書くので最後に来る。
	 *
	 * @param array<int, string> $unsaved listings の手前で入らなかったフィールド名.
	 * @return array<int, string>
	 */
	private static function withListings( array $unsaved ): array {
		$unsaved[] = 'listings';
		return $unsaved;
	}

	/**
	 * 書いたメタが実際に入ったかを読み直して確かめる（**書き込みの戻り値は見ない**）。
	 *
	 * 理由は listings とまったく同じで、根拠はコアの `update_metadata()` にある
	 * （行番号つきの表は {@see self::assertListingsPersisted()}）。要点だけ再掲すると、
	 * `update_post_meta()` は**書かずに true を返し得る**（`update_post_metadata`
	 * フィルタの短絡、WordPress 6.8 の `wp-includes/meta.php` L241-243）ので、
	 * 戻り値を見る実装はその経路を成功として素通しする。
	 *
	 * **比較の相手は「コアが格納する形」である。** コアは `wp_unslash()`（L214）→
	 * `sanitize_meta()`（L215）を通してから書くので、こちらも
	 * {@see \Affilicard\PostType\ProductMeta::register()} が登録した sanitize_callback を
	 * 同じ順で自分で適用する。**この対応は手で揃えている**ので、あちらの
	 * sanitize_callback を変えるときはここも変えること。
	 *
	 * **meta 行が無いときコアは「登録済み default」を返す**（`get_metadata()` L585-592 →
	 * `get_metadata_default()` L699-…、`register_meta()` が `default_post_metadata` へ
	 * 挿す `filter_default_metadata` L1533-1534）。だから短絡フィルタに潰されても、
	 * 書こうとした値が既定値と同じなら読み直しは一致する——**実際に保存されている値が
	 * 要求どおりなのだから、それでよい**。ここが見つけたいのは「要求した値と違う値が
	 * 入っている」ことだけである。
	 *
	 * @param string $field   報告に使うフィールド名（REST の `unsaved_fields` に載る名前）.
	 * @param string $metaKey 読み直す meta キー.
	 * @param mixed  $written 書き込もうとした値.
	 */
	private static function metaPersisted( int $postId, string $field, string $metaKey, $written ): bool {
		$expected  = self::sanitizeAsCoreWould( $field, wp_unslash( $written ) );
		$persisted = get_post_meta( $postId, $metaKey, true );

		if ( is_array( $expected ) ) {
			// 旧 JSON 文字列で入っている商品も読めるようにする（find() と同じ規則）。
			$persisted = is_string( $persisted )
				? JsonField::decode( $persisted, array() )
				: ( is_array( $persisted ) ? $persisted : array() );
			return $persisted === $expected;
		}

		return ( is_scalar( $persisted ) ? (string) $persisted : '' ) === (string) $expected;
	}

	/**
	 * {@see \Affilicard\PostType\ProductMeta::register()} が登録した sanitize_callback を再現する。
	 *
	 * コアの `sanitize_meta()`（`wp-includes/meta.php` L1327-）が格納直前に呼ぶものと
	 * 同じ写像。読み直しの比較相手を作るためだけに使う。
	 *
	 * @param mixed $value wp_unslash() 済みの値.
	 * @return mixed
	 */
	private static function sanitizeAsCoreWould( string $field, $value ) {
		switch ( $field ) {
			case 'product_type':
				return sanitize_key( (string) $value );
			case 'stock_status':
				return sanitize_text_field( (string) $value );
			case 'extras':
				return ProductSchema::sanitizeExtras( $value );
			case 'release_date':
				return ProductSchema::sanitizeReleaseDate( $value );
		}

		// VERIFIED_META に足したのにここへ足し忘れた場合。素通しすると「常に一致」に
		// なって検証が無言で死ぬので、meta には入り得ない形を返して必ず不一致にする
		// （フィールド名が unsaved_fields に載るので、取りこぼしは応答から辿れる）。
		return array( '__affilicard_unmapped_meta__' => $field );
	}

	/**
	 * listings が実際に入ったかを読み直して確かめる（**書き込みの戻り値は見ない**）。
	 *
	 * **`update_post_meta()` の戻り値は「入ったかどうか」を表さない。** コアの
	 * `update_metadata()`（`wp-includes/meta.php`。以下の行番号は WordPress 6.8）を
	 * 読むと、戻り値と実際の書き込みは**両方向に食い違う**——
	 *
	 * | 行 | 戻り値 | 実際に書いたか | 意味 |
	 * | --- | --- | --- | --- |
	 * | L191-203 | false | 書いていない | 引数が不正（object ID が 0 等） |
	 * | L241-243 | **false でも true でも** | **書いていない** | `update_post_metadata` フィルタが短絡した（他プラグインの介入） |
	 * | L247-253 | false | 書いていない | **既存値と同じ**ため何もせず戻った（＝失敗ではない） |
	 * | L315-317 | false | 書けなかった | `$wpdb->update()` が失敗した（＝本当の失敗） |
	 *
	 * **短絡フィルタの行（L241-243）がこの読み直しを無条件にする理由である。** コアは
	 * `wp_unslash()`（L214）→ `sanitize_meta()`（L215）を通した直後に
	 * `apply_filters( "update_{$meta_type}_metadata", null, ... )` を呼び、返り値が
	 * null でなければ `return (bool) $check;` する。**$wpdb には一度も触れていない**——
	 * つまりフィルタが true を返せば、`update_post_meta()` は成功を報告しながら
	 * 1 バイトも書いていない。false のときだけ読み直す実装は、この経路をそのまま
	 * 成功として通す（listings が 1 件も入っていないのに REST は 201/200 を返し、
	 * schema_version には「移行済み」の刻印が押される）。
	 *
	 * さらに meta 行がまだ無い場合は `add_metadata()` へ委ねる（L257-258）。そちらの
	 * 短絡フィルタは `return $check;`（L86-88）で bool へ丸めないため、**偽の meta_id
	 * のような bool ですらない truthy** が `update_post_meta()` の戻り値として返る
	 * （新規商品の最初の listings 保存はこの経路を通る）。`add_metadata()` は
	 * `$wpdb->insert()` の失敗でも false を返す。
	 *
	 * **どの向きの食い違いも戻り値では切り分けられない**ので、戻り値を捨てて保存後の値を
	 * 読み直し、「入っているか」だけで判定する
	 * （{@see \Affilicard\Upgrade\PluginUpgrade::writeMigratedListings()} と同じ流儀。
	 * あちらも戻り値を受け取らずに読み直している）。
	 *
	 * **比較の相手は sanitize 後の形である。** コアは `wp_unslash()`（L214）→
	 * `sanitize_meta()`（L215）を通した値を格納するため、読み直した値は渡した配列と
	 * 一致するとは限らない。META_LISTINGS の sanitize_callback は
	 * {@see \Affilicard\PostType\ProductMeta::register()} が登録した
	 * {@see ProductSchema::sanitizeListings()} なので、同じ 2 段を自分で適用した値と
	 * 突き合わせる。これで「値が変わらなかった」false は読み直しが一致して素通りし
	 * （listings を変えない PATCH は毎回ここを通る）、本当に入らなかったときだけ落ちる。
	 *
	 * **{@see ProductLockUnavailable} とは別の型で投げる。** あちらは「書かなかった・
	 * やり直せば通る」、こちらは「書いたのに入らなかった・原因を取り除くまで直らない」で、
	 * 運用者が取るべき行動が違う（REST は 409 と 500 で返し分ける）。
	 *
	 * **確かめた値をそのまま返す。** 呼び出し元は extid ミラーを「実際に入っている
	 * listings」から作るため、同じ行をもう一度読む必要がある。ここが照合のために既に
	 * 読んでいるので返り値で渡す（同じロックの中なので値は変わらない）。
	 *
	 * @param array<int, mixed>  $next    書き込もうとした listings。
	 * @param array<int, string> $unsaved listings の手前で入らなかったフィールド名（報告に足す）。
	 * @return array<int, mixed> 実際に保存されている listings（＝ $next の sanitize 後の形）。
	 * @throws ProductMetaWriteFailure 書き込んだ値が読み戻らなかったとき.
	 */
	private static function assertListingsPersisted( int $postId, array $next, array $unsaved = array() ): array {
		$expected  = ProductSchema::sanitizeListings( wp_unslash( $next ) );
		$persisted = self::listingsMeta( $postId );
		if ( $persisted === $expected ) {
			// 望みの形が入っている（書けた場合と、「値が変わらなかった」場合の両方）。
			return $persisted;
		}

		// **post ID を渡す。** 例外に載せておかないと、新規作成の途中でここへ来たとき
		// （投稿行は既に作られている）に REST が ID 無しの 500 を返し、呼び出し側は
		// できてしまった商品へ辿り着けない（理由は例外クラスの PHPDoc）。メッセージは
		// 例外クラス側が同じ文面で組み立てる。
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- HTML 出力ではなく PHP の例外メッセージ。組み立ては例外クラス側で、埋め込むのは post ID（int）と自前のフィールド名のみ。
		throw new ProductMetaWriteFailure( $postId, self::withListings( $unsaved ) );
	}

	/**
	 * 保存されている listings meta を読む（配列・旧 JSON 文字列のどちらでも受ける）。
	 *
	 * 保存前の姿と突き合わせたい呼び出し元（{@see self::saveMeta()} と
	 * {@see \Affilicard\Rest\ListingsEditFilter}）が共有する読み取り規則。
	 *
	 * @return array<int, mixed>
	 */
	public static function listingsMeta( int $postId ): array {
		$raw = get_post_meta( $postId, ProductPostType::META_LISTINGS, true );
		if ( is_string( $raw ) ) {
			return JsonField::decode( $raw, array() );
		}
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * 商品を検索し、各 item に thumbnail/price/platform を付与して返す。
	 *
	 * - $term 空: modified 降順の最近商品（ページ処理あり）
	 * - $term 非空: title/content 全文検索 + external_id ミラー OR meta_query の和集合、一意化・modified 降順
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function search( string $term, int $perPage, int $page ): array {
		$term = trim( $term );

		if ( '' === $term ) {
			$posts = get_posts(
				array(
					'post_type'      => ProductPostType::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => $perPage,
					'paged'          => $page,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			);
			return $this->buildSearchResult( is_array( $posts ) ? $posts : array() );
		}

		$by_title = get_posts(
			array(
				'post_type'      => ProductPostType::POST_TYPE,
				'post_status'    => 'any',
				's'              => $term,
				'posts_per_page' => -1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$enabled_codes = array();
		foreach ( PlatformConfig::all() as $platform ) {
			if ( $platform->enabled ) {
				$enabled_codes[] = (string) $platform->code;
			}
		}

		$by_extid = array();
		if ( array() !== $enabled_codes ) {
			$meta_query = array( 'relation' => 'OR' );
			foreach ( $enabled_codes as $code ) {
				$meta_query[] = array(
					'key'     => ProductPostType::externalIdMetaKey( $code ),
					'value'   => $term,
					'compare' => 'LIKE',
				);
			}

			$by_extid = get_posts(
				array(
					'post_type'      => ProductPostType::POST_TYPE,
					'post_status'    => 'any',
					'meta_query'     => $meta_query,
					'posts_per_page' => -1,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			);
		}

		$merged = array();
		foreach ( array_merge( is_array( $by_title ) ? $by_title : array(), is_array( $by_extid ) ? $by_extid : array() ) as $post ) {
			if ( is_object( $post ) && isset( $post->ID ) ) {
				$merged[ (int) $post->ID ] = $post;
			}
		}

		// modified 降順で安定化。
		uasort(
			$merged,
			static fn( $a, $b ) => strcmp( (string) ( $b->post_modified ?? '' ), (string) ( $a->post_modified ?? '' ) )
		);

		$total = count( $merged );
		$page  = max( 1, $page );
		$slice = array_slice( array_values( $merged ), ( $page - 1 ) * $perPage, $perPage );

		return array(
			'items' => array_map( array( $this, 'mapSearchItem' ), $slice ),
			'total' => $total,
		);
	}

	/**
	 * **購入リンクを持つ**先頭の有効 listing から代表価格と platform 名を返す。
	 *
	 * 価格は listing 自体ではなく、OfferSelector::select() が選んだ購入リンク（offer）から
	 * 取る。listing はもはや取得結果を直接持たない。
	 *
	 * **選択結果が 0 件の listing は飛ばして探し続ける。** platform があるだけで打ち切ると、
	 * 購入リンクを 1 件も持たない listing（設定だけを作って URL をまだ入れていない、
	 * 棚卸しで listing の offers を空にした等）が先頭にいるだけで「価格は空・platform は
	 * その listing」を返し、価格を持つ後続の listing が管理画面の商品検索結果へ二度と
	 * 出てこなくなる。価格と platform は必ず同じ listing から取る（別々の listing を
	 * 混ぜると、出ている platform では買えない価格を表示することになる）。
	 *
	 * **どの listing も購入リンクを持たなければ、先頭 listing の platform と空の価格を返す。**
	 * この場合は隠してしまう価格が存在しないので、上記の不整合は起きない。platform は
	 * 「この商品がどのストア向けに登録されているか」という情報として商品検索結果
	 * （ブロック挿入時の候補一覧）に出す価値があるため、従来どおり返す。
	 *
	 * v3 以前の flat な listing（offers 無し）は LegacyOffer::offersWithFallback() 経由で
	 * offers[0] 相当へ変換してから選択に回す。この結果は mapSearchItem() 経由で管理画面の
	 * 商品検索結果へ入るため、これを飛ばすと移行バッチが当該商品へ到達するまでの窓で
	 * 未移行の商品だけ検索結果の価格が空欄になる。
	 *
	 * @return array{price: string, platform: string}
	 */
	public function listingSummary( int $postId ): array {
		$raw      = get_post_meta( $postId, ProductPostType::META_LISTINGS, true );
		$listings = is_string( $raw ) ? JsonField::decode( $raw, array() ) : ( is_array( $raw ) ? $raw : array() );

		$fallback_enabled = GeneralSettings::fallbackOnTerminal();
		$first_platform   = '';

		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$platform = isset( $listing['platform'] ) ? (string) $listing['platform'] : '';
			if ( '' === $platform ) {
				continue;
			}
			if ( '' === $first_platform ) {
				$first_platform = $platform;
			}

			$offers   = LegacyOffer::offersWithFallback( $listing );
			$selected = OfferSelector::select( $offers, $fallback_enabled );
			if ( array() === $selected ) {
				// 購入リンクが 1 件も無い listing。代表にすると後続の価格を隠す。
				continue;
			}

			return array(
				'price'    => trim( ScalarField::string( $selected[0], 'price' ) ),
				'platform' => $platform,
			);
		}
		return array(
			'price'    => '',
			'platform' => $first_platform,
		);
	}

	/**
	 * term 空時の検索結果を構築し wp_count_posts で総件数を取得して返す。
	 *
	 * @param list<object> $posts
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	private function buildSearchResult( array $posts ): array {
		$items = array();
		foreach ( $posts as $post ) {
			if ( is_object( $post ) && isset( $post->ID ) ) {
				$items[] = $this->mapSearchItem( $post );
			}
		}

		$counts = wp_count_posts( ProductPostType::POST_TYPE );
		$total  = 0;
		if ( is_object( $counts ) ) {
			foreach ( get_object_vars( $counts ) as $status_count ) {
				$total += (int) $status_count;
			}
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * WP_Post オブジェクトを検索結果 item 配列にマップする。
	 *
	 * @return array<string, mixed>
	 */
	private function mapSearchItem( object $post ): array {
		$post_id      = (int) ( $post->ID ?? 0 );
		$product_type = get_post_meta( $post_id, ProductPostType::META_PRODUCT_TYPE, true );
		$summary      = $this->listingSummary( $post_id );
		$thumb_url    = get_the_post_thumbnail_url( $post_id, 'thumbnail' );

		return array(
			'id'           => $post_id,
			'title'        => (string) ( $post->post_title ?? '' ),
			'status'       => (string) ( $post->post_status ?? '' ),
			'product_type' => is_string( $product_type ) ? $product_type : '',
			'modified'     => (string) ( $post->post_modified ?? '' ),
			'thumbnail'    => is_string( $thumb_url ) ? $thumb_url : '',
			'price'        => $summary['price'],
			'platform'     => $summary['platform'],
		);
	}

	public function delete( int $postId ): bool {
		$result = wp_delete_post( $postId, true );
		return false !== $result && null !== $result;
	}

	/**
	 * 「affiliate_url が空のまま regular_url のみ持つ」商品の件数を返す（フォールバック表示中の件数）。
	 *
	 * 全商品の listings メタを走査して計数する。
	 */
	public function countFallbackProducts(): int {
		$ids = get_posts(
			array(
				'post_type'      => ProductPostType::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);
		if ( ! is_array( $ids ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $ids as $id ) {
			$listings     = get_post_meta( (int) $id, ProductPostType::META_LISTINGS, true );
			$listings_arr = is_string( $listings ) ? JsonField::decode( $listings, array() ) : ( is_array( $listings ) ? $listings : array() );
			if ( self::hasFallbackListing( $listings_arr ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * REST（core-data）保存後に呼ぶ派生 meta 同期。
	 * listings 配列メタから external_id ミラーと schema_version を再構築する。
	 *
	 * **listings の読みとミラーの同期は {@see ListingLock} の中で行う。** ここも
	 * META_LISTINGS を読んでその写し（extid ミラー）を複数クエリで書き換える
	 * read-modify-write であり、{@see self::saveMeta()} のミラー同期とまったく同じ理由で
	 * 直列化が要る——交差すると古いスナップショットから作ったミラーが後着で勝ち、
	 * findByExternalId() が実在しない external_id で商品を引く／実在する商品を
	 * 見落とす。囲む範囲は meta の読み書きだけで、`wp_update_post()` も第三者の
	 * `save_post` も挟まない（この経路は既に `rest_after_insert` の中＝保存の後にある）。
	 *
	 * **ロックを取れなければミラーを作り直さず false を返す。** 以前は best-effort で
	 * 同期していた。理由は「諦めるとミラーだけが古いまま残り、自動作成が重複商品を作る
	 * 側へ倒れる。再投入する呼び出し側は無く、void なので失敗を報告する口すら無い」
	 * ——**その心配はそのとおりだが、押し通した場合に作られるのは「古いスナップショットから
	 * 組み直したミラー」であって、狂い方は同じである**。しかも先着が正しく作ったミラーを
	 * 巻き戻す（lost update）ぶん、放置より悪い。
	 *
	 * そこで書かずに false を返し、**再投入する呼び出し側を作った**——
	 * {@see DerivedMetaSync}。唯一の本番呼び出し元である `rest_after_insert` の配線が
	 * false を受けて Action Scheduler へ再試行を積む。「void で報告する口が無い」という
	 * 旧理由は、口を作ったことで失効している。
	 *
	 * **同じ商品のロックを既に握った状態からも呼ばれる**
	 * （{@see \Affilicard\Upgrade\PluginUpgrade::migrateOneProductLocked()}）。
	 * {@see ListingLock::around()} は同じ商品の入れ子では取り直さない（再入可能）ため、
	 * 二重に GET_LOCK を撃つことも、解放の対がずれることもない。**その経路では $locked が
	 * 常に true になる**ので、ここで足した分岐は移行の挙動を一切変えない（外側で
	 * 取れなかった場合は移行そのものが先に差し戻される）。
	 *
	 * schema_version の刻印はロックの外に置く（別キーで、read-modify-write ではない）。
	 * **ミラーを見送っても刻印はする**——刻印が指すのは listings の形式であり、それを
	 * 書いたのはコアの保存（この関数の手前）で、既に完了しているからである。
	 *
	 * @return bool ミラーを同期したら true。ロックを取れず見送ったら false
	 *              （呼び出し側は再投入すること）。
	 */
	public function syncDerivedMeta( int $postId ): bool {
		$synced = (bool) ListingLock::around(
			$postId,
			function ( bool $locked ) use ( $postId ): bool {
				if ( ! $locked ) {
					// 古い listings から組み直したミラーで、先着が作った正しいミラーを
					// 巻き戻さない。呼び出し側へ返して再投入させる（理由は上の PHPDoc）。
					return false;
				}

				$listings = get_post_meta( $postId, ProductPostType::META_LISTINGS, true );
				if ( is_string( $listings ) ) {
					$listings = JsonField::decode( $listings, array() );
				} elseif ( ! is_array( $listings ) ) {
					$listings = array();
				}
				$this->syncExternalIdMirror( $postId, $listings );
				return true;
			}
		);

		update_post_meta( $postId, ProductPostType::META_SCHEMA_VERSION, SchemaVersion::CURRENT );

		return $synced;
	}

	/**
	 * 各購入リンク（offer）の external_id を `affilicard_extid_<platform>` meta にミラーする。
	 *
	 * **複数値 meta として保持する。** 1 listing が複数の購入リンクを持つため、単一値で
	 * 上書きすると 1 つしかミラーされず、findByExternalId が後続の購入リンクを引けない。
	 * その結果、自動作成が既存商品を見落として重複商品を作る。
	 *
	 * 再書き込み時、今回の集合に含まれない既存の値は個別に削除する（キー単位で削除すると
	 * 同じ platform の生きている値まで巻き込むため）。
	 *
	 * **listing の形状を offers[] と flat の両方で受け付ける。** saveMeta() 経由の呼び出しは
	 * `$data['listings']`（呼び出し元の生データ）をそのまま渡すため、必ずしも
	 * ProductSchema::sanitizeListings() を経由した offers[] 形状とは限らない。実際
	 * ProductAutoCreator::buildProductData() は listing 直下に flat な external_id を書いて
	 * save() を呼ぶ（sanitizeListings() 非経由）。ここで offers[] のみを見ると、この経路の
	 * external_id が一切ミラーされず findByExternalId が引けなくなり、自動作成が既存商品を
	 * 見落として重複商品を作ってしまう。ProductSchema::sanitizeOffers() が同じ入力に対して
	 * 行っているフォールバック（offers 不在時は listing 自身を単一 offer とみなす）と揃える。
	 *
	 * @param array<int, mixed> $listings
	 */
	private function syncExternalIdMirror( int $postId, array $listings ): void {
		$desired = array();
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$platform = isset( $listing['platform'] ) ? (string) $listing['platform'] : '';
			if ( '' === $platform ) {
				continue;
			}
			$meta_key = ProductPostType::externalIdMetaKey( $platform );

			// **空配列の offers は「購入リンクが無い」であって、旧形式ではない。**
			// 空を旧形式とみなして listing 直下の external_id を拾い直すと、利用者が
			// 購入リンクを全て削除しても findByExternalId() が商品を見つけてしまい、
			// ProductAutoCreator が新しい商品を作れなくなる。
			// 旧形式のフォールバックは offers キー自体が無いときだけ。
			$offers = isset( $listing['offers'] ) && is_array( $listing['offers'] )
				? $listing['offers']
				: array( $listing );

			foreach ( $offers as $offer ) {
				if ( ! is_array( $offer ) ) {
					continue;
				}
				$external_id = ScalarField::string( $offer, 'external_id' );
				if ( '' === $external_id ) {
					continue;
				}
				$desired[ $meta_key ][ $external_id ] = true;
			}
		}

		$this->purgeStaleExternalIdMirror( $postId, $desired );

		foreach ( $desired as $meta_key => $values ) {
			$existing = array_map( 'strval', (array) get_post_meta( $postId, $meta_key, false ) );
			foreach ( array_keys( $values ) as $value ) {
				if ( ! in_array( (string) $value, $existing, true ) ) {
					add_post_meta( $postId, $meta_key, (string) $value, false );
				}
			}
		}
	}

	/**
	 * 既存の extid mirror meta のうち、今回の集合 $desired に含まれない**値**を削除する。
	 *
	 * キー単位で削除すると、同じ platform の生きている値まで巻き込むため、meta キーごとの
	 * 個々の値を比較して不要な値だけを delete_post_meta( $postId, $metaKey, $value ) で消す。
	 *
	 * @param array<string, array<string, true>> $desired meta キーごとに維持したい値の集合。
	 */
	private function purgeStaleExternalIdMirror( int $postId, array $desired ): void {
		$all_meta = get_post_meta( $postId );
		if ( ! is_array( $all_meta ) ) {
			return;
		}

		foreach ( $all_meta as $meta_key => $values ) {
			$meta_key = (string) $meta_key;
			if ( ! ProductPostType::isExternalIdMetaKey( $meta_key ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				$value = (string) $value;
				if ( ! isset( $desired[ $meta_key ][ $value ] ) ) {
					delete_post_meta( $postId, $meta_key, $value );
				}
			}
		}
	}

	/**
	 * 商品キャッシュ用 transient のキー。
	 */
	private function transient_key( int $postId ): string {
		return 'affilicard_product_' . $postId;
	}

	/**
	 * 1 件以上の listing について、OfferSelector::select() が選んだ購入リンク（offer）が
	 * 素の商品 URL へフォールバックしているか判定する（規則は {@see OfferUrl}——
	 * カードの CTA と同じ `esc_url_raw()` 検証を通す）。
	 *
	 * 「アフィリ URL が無いため素の商品 URL を出している」状態はもはや listing 自体の
	 * フィールドではなく、選択された offer の性質である。選択結果が空（0 件）の listing は
	 * 表示するリンク自体が無いため判定対象にしない。
	 *
	 * v3 以前の flat な listing（offers 無し）は LegacyOffer::offersWithFallback() 経由で
	 * offers[0] 相当へ変換してから選択に回す。これを飛ばすと、countFallbackProducts() が
	 * 参照するダッシュボード件数から、移行バッチが到達するまでの窓で未移行の商品が漏れる。
	 *
	 * @param array<int, mixed> $listings
	 */
	private static function hasFallbackListing( array $listings ): bool {
		$fallback_enabled = GeneralSettings::fallbackOnTerminal();

		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$offers   = LegacyOffer::offersWithFallback( $listing );
			$selected = OfferSelector::select( $offers, $fallback_enabled );
			if ( array() === $selected ) {
				continue;
			}

			// 判定は OfferUrl に委ねる（カードの CTA が使うのと同一の規則）。素の空判定だと、
			// affiliate_url が不正で regular_url が正当な offer——カードは regular_url で
			// 描画している＝正真正銘のフォールバック中——をこの件数だけが取りこぼす。
			if ( OfferUrl::isRegularUrlFallback( $selected[0] ) ) {
				return true;
			}
		}
		return false;
	}
}
