<?php
declare(strict_types=1);

namespace Affilicard\Provider\Amazon;

use Affilicard\Account\AccountCredentials;
use Affilicard\Content\AdultDetection;
use Affilicard\Provider\BatchFetchingProvider;
use Affilicard\Provider\DisplayPolicy;
use Affilicard\Provider\DisplayPolicyProvider;
use Affilicard\Provider\FetchResult;
use Affilicard\Provider\ProviderInterface;
use Affilicard\Queue\AccountPause;
use Affilicard\Queue\ApiUsage;

/**
 * Amazon Creators API（PA-API 5 の後継）を使った自動取得 Provider。
 *
 * - `getItems` は 1 リクエストに 10 ASIN まで載り、10 件でも 1 トランザクションと数えられる。
 *   Creators API の 1 日の上限は Creators API 経由の売上に比例して決まるため、
 *   {@see BatchFetchingProvider} でまとめて取得する（設計 §4-3）。
 * - **後続機能（付与ポイント・成人向けフラグ・セール情報）のためにリクエストを増やさない。**
 *   それらの resource も最初から同じ `getItems` に載せる（設計 §4-4）。
 */
final class AmazonProvider implements ProviderInterface, BatchFetchingProvider, DisplayPolicyProvider {

	private const MAX_BATCH_SIZE = 10;

	/** 疎通確認で引く ASIN（Kindle 版『孤独のグルメ【新装版】』）。 */
	private const TEST_ASIN = 'B008ODF84I';

	/**
	 * getItems に載せる resource。付与ポイント・成人向けフラグ・セール情報も最初から含める。
	 *
	 * @var list<string>
	 */
	public const RESOURCES = array(
		'images.primary.large',
		'itemInfo.title',
		'itemInfo.byLineInfo',
		// 動画（Prime Video）かどうかの判定に使う（productGroup「Video On Demand」・binding「Prime Video」）。
		'itemInfo.classifications',
		'itemInfo.contentInfo',
		'itemInfo.productInfo',
		'offersV2.listings.availability',
		'offersV2.listings.dealDetails',
		'offersV2.listings.isBuyBoxWinner',
		'offersV2.listings.loyaltyPoints',
		'offersV2.listings.price',
		'offersV2.listings.type',
	);

	/**
	 * prefetch() で取得した結果（大文字の ASIN => FetchResult）。
	 *
	 * @var array<string, FetchResult>
	 */
	private array $prefetched = array();

	/**
	 * @param \Closure(): int|null $clock 現在時刻（UNIX 秒）。テストで呼び出しの前後の時刻を固定するため。既定は time()。
	 */
	public function __construct( private ?AmazonCreatorsClient $client = null, private ?\Closure $clock = null ) {}

	private function now(): int {
		return null !== $this->clock ? (int) ( $this->clock )() : time();
	}

	public function code(): string {
		return 'amazon-creators';
	}

	public function label(): string {
		return __( 'Amazon Creators API', 'affilicard' );
	}

	public function isAutomatic(): bool {
		return true;
	}

	public function accountCode(): ?string {
		return 'amazon';
	}

	public function minRequestIntervalMs(): int {
		return 1100; // 初期枠 1 TPS ＋余裕
	}

	public function maxBatchSize(): int {
		return self::MAX_BATCH_SIZE;
	}

	/**
	 * @param array<string, mixed> $platformConfig
	 */
	public function fetch( string $externalId, array $platformConfig ): FetchResult {
		$asin = self::normalizeAsin( $externalId );
		if ( isset( $this->prefetched[ $asin ] ) ) {
			return $this->prefetched[ $asin ];
		}
		$credentials = AccountCredentials::get( (string) $this->accountCode() );
		if ( ! self::hasRequiredCredentials( $credentials ) ) {
			// 認証未設定は一時失敗（transient）。後で登録され得るため give-up しない。
			return FetchResult::error();
		}
		if ( ! self::isValidAsin( $asin ) ) {
			// 空・形式違いはデータ不備。リトライで解決しない恒久失敗（terminal）。
			return FetchResult::miss();
		}
		$results = $this->fetchMany( array( $asin ), $credentials );
		return $results[ $asin ];
	}

	/**
	 * @param list<string> $externalIds
	 */
	public function prefetch( array $externalIds ): void {
		$credentials = AccountCredentials::get( (string) $this->accountCode() );
		$hasCreds    = self::hasRequiredCredentials( $credentials );

		$valid = array();
		foreach ( $externalIds as $externalId ) {
			$asin = self::normalizeAsin( (string) $externalId );
			if ( isset( $this->prefetched[ $asin ] ) ) {
				continue;
			}
			if ( ! $hasCreds ) {
				$this->prefetched[ $asin ] = FetchResult::error();
				continue;
			}
			if ( ! self::isValidAsin( $asin ) ) {
				// 形式違いの ASIN を 1 件でも載せると、リクエスト全体が 400（FieldValidationFailed）で
				// 弾かれ、同じ組の正しい ASIN まで取れなくなる（2026-11-24 実測）。送る前に落とす。
				$this->prefetched[ $asin ] = FetchResult::miss();
				continue;
			}
			$valid[ $asin ] = true;
		}

		// 数字だけの ISBN-10（例 4087104472）は配列キーにすると int になる。array_keys() の戻りを
		// 文字列へ戻してから送り（API は文字列のリストを要求する）、結果も array_merge() ではなく
		// キーごとに代入する（array_merge() は int キーを 0 から振り直し、覚えた結果を見失う）。
		$asins = array_map( 'strval', array_keys( $valid ) );
		foreach ( array_chunk( $asins, self::MAX_BATCH_SIZE ) as $chunk ) {
			foreach ( $this->fetchMany( $chunk, $credentials ) as $asin => $result ) {
				$this->prefetched[ (string) $asin ] = $result;
			}
		}
	}

	/**
	 * Amazon.co.jp Product Advertising API ライセンス契約（Creators API の日本向けライセンス）の
	 * 表示要件（設計 2026-11-24 §2・§5）。
	 *
	 * - (o) 価格の横（またはリンク・ポップアップ）に出す免責文言。文言はライセンスの原文どおり
	 * - (q) テキストのコンテンツを表示するときに、はっきり見える所に出す提供元の表記
	 * - (n) 画像へのリンクは最長 24 時間まで
	 */
	public function displayPolicy(): DisplayPolicy {
		// 商品リンク（regular_url）は設定されたマーケットプレイスへ向くため、免責文言が案内する
		// 購入先も同じサイトにする（Amazon.co.jp 固定だと、他のマーケットプレイスでリンク先と食い違う）。
		$marketplace = AmazonCreatorsClient::marketplace( AccountCredentials::get( (string) $this->accountCode() ) );
		$isJapan     = AmazonCreatorsClient::DEFAULT_MARKETPLACE === $marketplace;
		$siteName    = $isJapan ? 'Amazon.co.jp' : (string) preg_replace( '/^www\./', '', $marketplace );
		return new DisplayPolicy(
			sprintf(
				/* translators: %s: Amazon のサイト名（例 Amazon.co.jp） */
				__( '価格および発送可能時期は表示された日付/時刻の時点のものであり、変更される場合があります。本商品の購入においては、購入の時点で%sに表示されている価格および発送可能時期の情報が適用されます。', 'affilicard' ),
				$siteName
			),
			$isJapan
				? __( '本サイト上で表示されるコンテンツの一部は、アマゾンジャパン合同会社またはその関連会社により提供されたものです。これらのコンテンツは「現状有姿」で提供されており、随時変更または削除される場合があります。', 'affilicard' )
				: __( '本サイト上で表示されるコンテンツの一部は、Amazon またはその関連会社により提供されたものです。これらのコンテンツは「現状有姿」で提供されており、随時変更または削除される場合があります。', 'affilicard' ),
			24,
			// 付与ポイントはライセンスの免責文言（価格・発送可能時期）の対象として明記されていないが、
			// 実質の価格に直結するため同じ扱いにする。規定の文言は改変せず、別の 1 文として添える。
			sprintf(
				/* translators: %s: Amazon のサイト名（例 Amazon.co.jp） */
				__( '付与ポイントは表示された日付/時刻の時点のものであり、変更される場合があります。購入の時点で%sに表示されている付与ポイントが適用されます。', 'affilicard' ),
				$siteName
			)
		);
	}

	public function hasPrefetched( string $externalId ): bool {
		return isset( $this->prefetched[ self::normalizeAsin( $externalId ) ] );
	}

	public function clearPrefetched(): void {
		$this->prefetched = array();
	}

	/**
	 * @param array<string, string> $credentials
	 * @return array{ok: bool, message: string}
	 */
	public function testConnection( array $credentials ): array {
		if ( ! self::hasRequiredCredentials( $credentials ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Credential ID・Credential Secret・トラッキングIDを入力してください', 'affilicard' ),
			);
		}
		$client = $this->client();
		if ( ! $client->canAuthenticate( $credentials ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'アクセストークンを取得できませんでした。Credential ID と Credential Secret を確認してください', 'affilicard' ),
			);
		}
		$startedAt = $this->now();
		$res       = $client->getItems( $credentials, array( self::TEST_ASIN ), array( 'itemInfo.title' ) );
		// 接続テストも上限を消費する。結果を数え、200 なら止めている停止を解く（403 から戻ったことを
		// 管理者が接続テストで確かめたとき、パネルと通知に「利用条件を満たしていません」を残さない）。
		// ただし保存済みの認証情報と同じ相手（Credential ID・トラッキング ID・マーケットプレイス）を
		// 試したときだけ。まだ保存していない別のアソシエイトの結果で、動いている account を止めたり、
		// 止まっている account を解いたりしない。
		if ( self::isSameAssociate( $credentials, AccountCredentials::get( (string) $this->accountCode() ) ) ) {
			$this->recordUsage( (string) $this->accountCode(), $startedAt, $this->now(), $res );
		}
		if ( $res['error'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'Amazon Creators API への接続に失敗しました', 'affilicard' ),
			);
		}
		if ( 200 !== $res['code'] ) {
			return array(
				'ok'      => false,
				'message' => self::errorMessage( $res['code'], $res['decoded'] ),
			);
		}
		return array(
			'ok'      => true,
			'message' => __( 'Amazon Creators API への接続に成功しました', 'affilicard' ),
		);
	}

	/**
	 * 2 つの認証情報が同じアソシエイト・同じマーケットプレイスを指すか（呼び出しの上限と利用条件を共有するか）。
	 *
	 * @param array<string, string> $a
	 * @param array<string, string> $b
	 */
	private static function isSameAssociate( array $a, array $b ): bool {
		foreach ( array( 'credential_id', 'partner_tag' ) as $key ) {
			if ( '' === trim( (string) ( $a[ $key ] ?? '' ) ) || trim( (string) ( $a[ $key ] ?? '' ) ) !== trim( (string) ( $b[ $key ] ?? '' ) ) ) {
				return false;
			}
		}
		return AmazonCreatorsClient::marketplace( $a ) === AmazonCreatorsClient::marketplace( $b );
	}

	/**
	 * 1 リクエスト（最大 10 ASIN）で取得し、ASIN ごとの結果を返す。
	 *
	 * @param list<string>          $asins 正規化・検証済みの ASIN
	 * @param array<string, string> $credentials
	 * @return array<string, FetchResult>
	 */
	private function fetchMany( array $asins, array $credentials ): array {
		$account = (string) $this->accountCode();
		$now     = $this->now();
		// 上限（429）・適格販売の不足（403）で止めている間は呼ばない。呼んでも同じ失敗が返るうえ、
		// 1 日の上限を消費して回復を遅らせる（AccountPause 参照）。
		// 別のワーカーが止めた直後でも見落とさないよう、リクエスト内のキャッシュを使わずに読む
		// （呼び出し 1 回につき 1 回だけなので、DB を読むコストは HTTP に比べて小さい）。
		if ( AccountPause::until( $account, $now, true ) > 0 ) {
			return array_fill_keys( $asins, FetchResult::error() );
		}

		$res = $this->client()->getItems( $credentials, $asins, self::RESOURCES );
		$this->recordUsage( $account, $now, $this->now(), $res );

		// 通信断・トークン取得失敗・非 200（403 AssociateNotEligible / 429 / 5xx / 400 等）は
		// すべて一時失敗。特に 403 は、売上要件（直近 30 日）を割ると返り、要件を満たせば
		// 2 日以内に戻る。恒久失敗にすると全 listing を give-up し、復帰しても戻らない。
		if ( $res['error'] || 200 !== $res['code'] || null === $res['decoded'] ) {
			return array_fill_keys( $asins, FetchResult::error() );
		}

		$byAsin = array();
		$items  = $res['decoded']['itemsResult']['items'] ?? array();
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( is_array( $item ) && isset( $item['asin'] ) && is_string( $item['asin'] ) ) {
					$byAsin[ strtoupper( $item['asin'] ) ] = $item;
				}
			}
		}

		$results = array();
		foreach ( $asins as $asin ) {
			// 200 で items に無い＝item 単位のエラー（ItemNotAccessible 等）か該当なし。恒久失敗。
			$results[ $asin ] = isset( $byAsin[ $asin ] )
				? FetchResult::hit( self::normalizeItem( $byAsin[ $asin ], self::marketplaceOf( $credentials ) ) )
				: FetchResult::miss();
		}
		return $results;
	}

	/**
	 * API に届いた呼び出しを日次集計に足し、429 / 403（適格販売の不足）なら account を止め、
	 * 200 なら停止を解く。API から応答を受け取らなかった呼び出し（通信断・認証前の失敗。上限を消費しない）は数えない。
	 *
	 * 停止の起点は応答を受け取った時刻（$respondedAt）。呼ぶ前の時刻を使うと、応答を待つ間に
	 * 短い停止が切れてしまう。停止を解くかどうかはリクエストを送った時刻（$startedAt）で決める
	 * （AccountPause::recordSuccess）。
	 *
	 * @param array{error: bool, code: int, decoded: array<string, mixed>|null, requests?: int} $res
	 */
	private function recordUsage( string $account, int $startedAt, int $respondedAt, array $res ): void {
		$now = $respondedAt;
		// API から応答を受け取った回数だけ数える。最終的に通信断・トークン取得失敗でも、その前に
		// 401 を受けていれば上限を消費しているので数える。
		$requests = (int) ( $res['requests'] ?? ( $res['error'] ? 0 : 1 ) );
		if ( $requests > 0 ) {
			ApiUsage::record( $account, ApiUsage::KIND_CALLS, $now, $requests );
		}
		if ( $res['error'] ) {
			return;
		}
		$code    = $res['code'];
		$decoded = $res['decoded'];
		if ( 429 === $code ) {
			ApiUsage::record( $account, ApiUsage::KIND_RATE_LIMITED, $now );
			$retryAfter = is_array( $decoded ) && is_numeric( $decoded['retryAfterSeconds'] ?? null )
				? (int) $decoded['retryAfterSeconds']
				: null;
			AccountPause::recordRateLimited( $account, $now, $retryAfter );
			return;
		}
		if ( 403 === $code && is_array( $decoded ) && 'AssociateNotEligible' === ( $decoded['reason'] ?? '' ) ) {
			ApiUsage::record( $account, ApiUsage::KIND_NOT_ELIGIBLE, $now );
			AccountPause::recordNotEligible( $account, $now );
			return;
		}
		if ( 200 === $code ) {
			AccountPause::recordSuccess( $account, $startedAt );
		}
	}

	/**
	 * 応答の item を listing のフィールドへ写す。
	 *
	 * **価格と書影は、返らなかったら空文字で返す（既存値を残さない）。** ListingRefresher は取得に
	 * 成功すると last_verified_at を更新するため、古い価格・書影を残すと「今確認した値」として
	 * 表示されてしまう。Amazon の値は API からしか入らず、ライセンスも Amazon に無くなった
	 * コンテンツの速やかな削除を求めている（(g)）。作品のまとめページのように価格が付かない ASIN は、
	 * 価格を出さないのが正しい。
	 *
	 * 基準価格（`list_price`）は、Amazon が返すラベル（「過去価格:」等）が付くときだけ入れる。
	 * 何と比べた価格かを示さない取り消し線は、比較対照価格の表示として誤解を招く（設計 §5）。
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	public static function normalizeItem( array $item, string $marketplace = AmazonCreatorsClient::DEFAULT_MARKETPLACE ): array {
		if ( self::isVideo( $item ) ) {
			return self::normalizeVideoItem( $item, $marketplace );
		}
		$asin = strtoupper( (string) $item['asin'] );
		$data = array(
			'regular_url' => 'https://' . $marketplace . '/dp/' . $asin,
		);

		$title = self::stringAt( $item, array( 'itemInfo', 'title', 'displayValue' ) );
		if ( '' !== $title ) {
			$data['title'] = $title;
		}

		$data['price']     = '';
		$data['image_url'] = '';

		// 基準価格（取り消し線）・ラベル・割引率。返らなければ空にする——セールが終わったあとに
		// 「44%OFF」を残すと、終わった割引を表示し続けることになる（プログラム・ポリシー）。
		$data['list_price']       = '';
		$data['list_price_label'] = '';
		$data['badge']            = '';
		// 付与ポイント（日本のマーケットプレイスだけが返す）。返らなければ空にして、古い値を残さない。
		$data['points'] = '';

		$offer = self::chooseOffer( $item );
		if ( null !== $offer ) {
			$amount = $offer['price']['money']['amount'] ?? null;
			if ( self::isAmount( $amount ) ) {
				$data['price'] = self::formatAmount( $amount );
				$data          = array_merge( $data, self::savings( $offer, (float) $amount ) );
				// 価格と同じ出品のポイントだけを取る（価格の無い出品のポイントは意味を持たない）。
				$points = $offer['loyaltyPoints']['points'] ?? null;
				if ( is_int( $points ) && $points > 0 ) {
					$data['points'] = (string) $points;
				}
			}
		}

		$data['image_url'] = self::stringAt( $item, array( 'images', 'primary', 'large', 'url' ) );

		// 成人向けかどうか（R18 表示の自動判定に使う）。返らなければ未確認にする。
		$adult         = $item['itemInfo']['productInfo']['isAdultProduct']['displayValue'] ?? null;
		$data['adult'] = is_bool( $adult ) ? ( $adult ? AdultDetection::YES : AdultDetection::NO ) : AdultDetection::UNKNOWN;

		// 返ってきたリンクのパラメータは改変しない（帰属が外れて紹介料を失う。Best Programming Practices）。
		$detail = isset( $item['detailPageURL'] ) && is_string( $item['detailPageURL'] ) ? $item['detailPageURL'] : '';
		if ( '' !== $detail ) {
			$data['affiliate_url'] = $detail;
		}

		$data['platform_extras'] = array(
			'release_date' => self::datePart( self::stringAt( $item, array( 'itemInfo', 'productInfo', 'releaseDate', 'displayValue' ) ) ),
			'author'       => self::authors( $item ),
			'publisher'    => self::stringAt( $item, array( 'itemInfo', 'byLineInfo', 'manufacturer', 'displayValue' ) ),
		);
		$data['raw']             = $item;

		return $data;
	}

	/**
	 * 動画（Prime Video）の item か。種類（classifications）で見分ける。
	 *
	 * @param array<string, mixed> $item
	 */
	public static function isVideo( array $item ): bool {
		return 'Video On Demand' === self::stringAt( $item, array( 'itemInfo', 'classifications', 'productGroup', 'displayValue' ) )
			|| 'Prime Video' === self::stringAt( $item, array( 'itemInfo', 'classifications', 'binding', 'displayValue' ) );
	}

	/**
	 * 動画（Prime Video）の item を listing のフィールドへ写す（spec 2026-11-26-prime-video-creators-design.md）。
	 *
	 * Prime Video の出品は 1 つの ASIN に価格が複数返り（購入・レンタル・画質違い）、どれが何かのラベルが
	 * 付かない。書籍と同じく「カートを取っている出品」を使うと、購入価格（高いほう）を出してしまう。
	 *
	 * - ¥0 の出品がある＝プライム会員の見放題。提供形態を「見放題」にし、価格は出さない
	 *   （¥0 と出すと、会員でない読者には誤り）
	 * - 有料の出品だけ＝いちばん安い価格を出す。値が 2 種類以上あれば `price_from` を立て、
	 *   カードで「¥400〜」と出す（どれがレンタルかは分からないので、最低価格としてだけ示す）
	 * - 価格が 1 件も無い（配信停止 `UNAVAILABLE` など）＝価格も提供形態も出さない
	 *
	 * 提供形態（`plan_label`）は毎回この結果で上書きする（見放題に入った・外れたを追うため）。
	 * 返さないと古い「見放題」が残る。
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private static function normalizeVideoItem( array $item, string $marketplace ): array {
		$asin = strtoupper( (string) $item['asin'] );
		$data = array(
			'regular_url'      => 'https://' . $marketplace . '/dp/' . $asin,
			'price'            => '',
			'price_from'       => '',
			'plan_label'       => '',
			'list_price'       => '',
			'list_price_label' => '',
			'badge'            => '',
			'points'           => '',
		);

		$title = self::stringAt( $item, array( 'itemInfo', 'title', 'displayValue' ) );
		if ( '' !== $title ) {
			$data['title'] = $title;
		}

		$amounts  = array();
		$listings = $item['offersV2']['listings'] ?? null;
		if ( is_array( $listings ) ) {
			foreach ( $listings as $listing ) {
				$amount = is_array( $listing ) ? ( $listing['price']['money']['amount'] ?? null ) : null;
				if ( self::isAmount( $amount ) && (float) $amount >= 0 ) {
					$amounts[] = (float) $amount;
				}
			}
		}
		if ( array() !== $amounts ) {
			if ( in_array( 0.0, $amounts, true ) ) {
				$data['plan_label'] = __( '見放題', 'affilicard' );
			} else {
				$lowest        = min( $amounts );
				$data['price'] = self::formatAmount( $lowest );
				if ( count( array_unique( $amounts ) ) > 1 ) {
					$data['price_from'] = '1';
				}
			}
		}

		$data['image_url'] = self::stringAt( $item, array( 'images', 'primary', 'large', 'url' ) );

		$adult         = $item['itemInfo']['productInfo']['isAdultProduct']['displayValue'] ?? null;
		$data['adult'] = is_bool( $adult ) ? ( $adult ? AdultDetection::YES : AdultDetection::NO ) : AdultDetection::UNKNOWN;

		$detail = isset( $item['detailPageURL'] ) && is_string( $item['detailPageURL'] ) ? $item['detailPageURL'] : '';
		if ( '' !== $detail ) {
			$data['affiliate_url'] = $detail;
		}

		$data['platform_extras'] = array(
			'director' => self::contributors( $item, 'director' ),
			'cast'     => self::contributors( $item, 'actor', 5 ),
		);
		$data['raw']             = $item;

		return $data;
	}

	/**
	 * @param mixed $amount
	 */
	private static function isAmount( $amount ): bool {
		return is_int( $amount ) || is_float( $amount ) || ( is_string( $amount ) && is_numeric( $amount ) );
	}

	/**
	 * 基準価格・ラベル・割引率。ラベルが無い、または基準価格が販売価格以下なら何も入れない。
	 *
	 * @param array<string, mixed> $offer
	 * @return array{list_price?: string, list_price_label?: string, badge?: string}
	 */
	private static function savings( array $offer, float $price ): array {
		$basis = $offer['price']['savingBasis'] ?? null;
		if ( ! is_array( $basis ) ) {
			return array();
		}
		$amount = $basis['money']['amount'] ?? null;
		$label  = isset( $basis['savingBasisTypeLabel'] ) && is_string( $basis['savingBasisTypeLabel'] ) ? trim( $basis['savingBasisTypeLabel'] ) : '';
		if ( ! self::isAmount( $amount ) || '' === $label || (float) $amount <= $price ) {
			return array();
		}
		$out        = array(
			'list_price'       => self::formatAmount( $amount ),
			'list_price_label' => $label,
		);
		$percentage = $offer['price']['savings']['percentage'] ?? null;
		if ( is_int( $percentage ) && $percentage > 0 ) {
			$out['badge'] = $percentage . '%OFF';
		}
		return $out;
	}

	/**
	 * カートを取っている出品（isBuyBoxWinner）を優先し、無ければ先頭。
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>|null
	 */
	private static function chooseOffer( array $item ): ?array {
		$listings = $item['offersV2']['listings'] ?? null;
		if ( ! is_array( $listings ) || array() === $listings ) {
			return null;
		}
		foreach ( $listings as $listing ) {
			if ( is_array( $listing ) && true === ( $listing['isBuyBoxWinner'] ?? false ) ) {
				return $listing;
			}
		}
		$first = reset( $listings );
		return is_array( $first ) ? $first : null;
	}

	/**
	 * @param int|float|string $amount
	 */
	private static function formatAmount( $amount ): string {
		$value = (float) $amount;
		return floor( $value ) === $value ? (string) (int) $value : (string) $value;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private static function authors( array $item ): string {
		return self::contributors( $item, 'author' );
	}

	/**
	 * byLineInfo の contributors から、役割（roleType）が一致する人の名前を「、」でつなぐ。
	 *
	 * @param array<string, mixed> $item
	 * @param int                  $limit 0 なら全員。
	 */
	private static function contributors( array $item, string $roleType, int $limit = 0 ): string {
		$contributors = $item['itemInfo']['byLineInfo']['contributors'] ?? null;
		if ( ! is_array( $contributors ) ) {
			return '';
		}
		$names = array();
		foreach ( $contributors as $contributor ) {
			if ( ! is_array( $contributor ) || ( $contributor['roleType'] ?? '' ) !== $roleType ) {
				continue;
			}
			$name = isset( $contributor['name'] ) && is_string( $contributor['name'] ) ? trim( $contributor['name'] ) : '';
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		$names = array_values( array_unique( $names ) );
		if ( $limit > 0 ) {
			$names = array_slice( $names, 0, $limit );
		}
		return implode( '、', $names );
	}

	private static function datePart( string $iso ): string {
		return 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $iso, $m ) ? $m[1] : '';
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<string>         $path
	 */
	private static function stringAt( array $data, array $path ): string {
		$cursor = $data;
		foreach ( $path as $key ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
				return '';
			}
			$cursor = $cursor[ $key ];
		}
		return is_string( $cursor ) ? $cursor : '';
	}

	private static function normalizeAsin( string $externalId ): string {
		return strtoupper( trim( $externalId ) );
	}

	/**
	 * Creators API が受け付ける ASIN / ISBN-10 の形式（400 応答の検証メッセージに載っている正規表現）。
	 */
	private static function isValidAsin( string $asin ): bool {
		return 1 === preg_match( '/^(?:[0-9]{9}[0-9X]|[A-Z][A-Z0-9]{9})$/', $asin );
	}

	/**
	 * @param array<string, string> $credentials
	 */
	private static function hasRequiredCredentials( array $credentials ): bool {
		return ! empty( $credentials['credential_id'] )
			&& ! empty( $credentials['credential_secret'] )
			&& ! empty( $credentials['partner_tag'] );
	}

	/**
	 * @param array<string, string> $credentials
	 */
	private static function marketplaceOf( array $credentials ): string {
		return AmazonCreatorsClient::marketplace( $credentials );
	}

	/**
	 * @param array<string, mixed>|null $decoded
	 */
	private static function errorMessage( int $code, ?array $decoded ): string {
		$reason = is_array( $decoded ) && isset( $decoded['reason'] ) && is_string( $decoded['reason'] ) ? $decoded['reason'] : '';
		if ( 403 === $code && 'AssociateNotEligible' === $reason ) {
			return __( 'Creators API の利用条件（直近 30 日の適格販売）を満たしていません。条件を満たすと自動で使えるようになります', 'affilicard' );
		}
		if ( 429 === $code ) {
			return __( 'レート制限に達しました。時間をおいて再試行してください', 'affilicard' );
		}
		if ( 400 === $code ) {
			return __( 'トラッキングID・マーケットプレイスを確認してください', 'affilicard' );
		}
		/* translators: %d: HTTP status code */
		return sprintf( __( 'Amazon Creators API が HTTP %d を返しました', 'affilicard' ), $code );
	}

	private function client(): AmazonCreatorsClient {
		return $this->client ??= new AmazonCreatorsClient();
	}
}
