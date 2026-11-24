<?php
declare(strict_types=1);

namespace Affilicard\Provider\Amazon;

use Affilicard\Account\AccountCredentials;
use Affilicard\Provider\BatchFetchingProvider;
use Affilicard\Provider\DisplayPolicy;
use Affilicard\Provider\DisplayPolicyProvider;
use Affilicard\Provider\FetchResult;
use Affilicard\Provider\ProviderInterface;

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

	public function __construct( private ?AmazonCreatorsClient $client = null ) {}

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

		foreach ( array_chunk( array_keys( $valid ), self::MAX_BATCH_SIZE ) as $chunk ) {
			$this->prefetched = array_merge( $this->prefetched, $this->fetchMany( $chunk, $credentials ) );
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
		return new DisplayPolicy(
			__( '価格および発送可能時期は表示された日付/時刻の時点のものであり、変更される場合があります。本商品の購入においては、購入の時点でAmazon.co.jpに表示されている価格および発送可能時期の情報が適用されます。', 'affilicard' ),
			__( '本サイト上で表示されるコンテンツの一部は、アマゾンジャパン合同会社またはその関連会社により提供されたものです。これらのコンテンツは「現状有姿」で提供されており、随時変更または削除される場合があります。', 'affilicard' ),
			24
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
		$res = $client->getItems( $credentials, array( self::TEST_ASIN ), array( 'itemInfo.title' ) );
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
	 * 1 リクエスト（最大 10 ASIN）で取得し、ASIN ごとの結果を返す。
	 *
	 * @param list<string>          $asins 正規化・検証済みの ASIN
	 * @param array<string, string> $credentials
	 * @return array<string, FetchResult>
	 */
	private function fetchMany( array $asins, array $credentials ): array {
		$res = $this->client()->getItems( $credentials, $asins, self::RESOURCES );

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

		$offer = self::chooseOffer( $item );
		if ( null !== $offer ) {
			$amount = $offer['price']['money']['amount'] ?? null;
			if ( self::isAmount( $amount ) ) {
				$data['price'] = self::formatAmount( $amount );
				$data          = array_merge( $data, self::savings( $offer, (float) $amount ) );
			}
		}

		$data['image_url'] = self::stringAt( $item, array( 'images', 'primary', 'large', 'url' ) );

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
		$contributors = $item['itemInfo']['byLineInfo']['contributors'] ?? null;
		if ( ! is_array( $contributors ) ) {
			return '';
		}
		$names = array();
		foreach ( $contributors as $contributor ) {
			if ( ! is_array( $contributor ) || 'author' !== ( $contributor['roleType'] ?? '' ) ) {
				continue;
			}
			$name = isset( $contributor['name'] ) && is_string( $contributor['name'] ) ? trim( $contributor['name'] ) : '';
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		return implode( '、', array_values( array_unique( $names ) ) );
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
