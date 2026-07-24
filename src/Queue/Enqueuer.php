<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Platform\PlatformDefinition;
use Affilicard\Pricing\ListingEligibility;
use Affilicard\Pricing\PriceFreshness;
use Affilicard\Provider\ProviderRegistry;

/**
 * Action Scheduler へのジョブ投入を一元化するラッパー。
 *
 * すべてのトリガー（手動更新・強制更新・自動作成・掃引 Cron）はこのクラス経由で
 * AS にジョブを積む。dedup は AS ネイティブの $unique=true、順序は $priority
 * （force=0 > manual=10 > sweep=20）、account（認証情報の共有単位。楽天/DMM 等。
 * 認証画面と一致）別に group を分けてレート制御・監視をしやすくする（v2.4.0:
 * provider コード単位から account コード単位へ統一。レート制限は provider ではなく
 * 共有 API＝account 単位でかかるため）。掃引専用の鮮度スキップと depth cap・jitter も
 * ここに集約する。
 *
 * enqueueForced/enqueueManual/enqueueSweep/enqueueAutoCreate/reschedule* は、呼び出し側が
 * 既に provider→account を解決済みの account コードを渡す契約（このクラス自身は
 * provider/account の対応表を持たない）。唯一の例外は enqueueProductListings() で、
 * platform 一覧を横断して provider→account を解決する必要があるため、コンストラクタで
 * 受け取る ProviderRegistry を内部で使う。
 */
final class Enqueuer {

	public const HOOK_REFRESH    = 'affilicard_refresh_listing';
	public const HOOK_AUTOCREATE = 'affilicard_autocreate';
	public const PRIORITY_FORCE  = 0;
	public const PRIORITY_MANUAL = 10;
	public const PRIORITY_SWEEP  = 20;

	/**
	 * throttle/backoff の自己再投入（rescheduleRefresh/rescheduleAutoCreate）に加える
	 * jitter の最大秒数。同一 account を奪い合う listing 群が jitter 無しだと寸分違わず
	 * 同一タイムスタンプへ再集結し、thundering herd（症状1: ignored 誘発）＋ claim 順
	 * （action_id ASC）による先着 listing の恒久的な独占（症状2: 他の listing が
	 * performWork に到達できず failed に絶対到達しない）を招く。enqueueSweep の
	 * jitter と同じ考え方を自己再投入にも適用し、負けた listing を時間分散させる。
	 */
	public const RESCHEDULE_JITTER_SECONDS = 60;

	/**
	 * enqueueSweep() が listing 毎に as_get_scheduled_actions を叩かないための
	 * インスタンス内 memo。sweep 1 回（＝この Enqueuer インスタンスの生存期間）の
	 * 間だけ有効で、null は「未クエリ」を表す。
	 *
	 * @var int|null
	 */
	private ?int $depthMemo = null;

	/**
	 * A（決定的スタガリング）用の account 別カーソル。account コード→次に sweep ジョブを
	 * 積む unix 秒。enqueueSweep が積むたびに accountIntervalSeconds ぶん前進させる。
	 * depthMemo と同様 sweep 1 回（＝この Enqueuer インスタンスの生存期間）だけ有効。
	 *
	 * @var array<string, int>
	 */
	private array $accountCursor = array();

	/**
	 * @param list<string>       $accountCodes 深さ集計を affilicard-{account} group 別に限定する
	 *              account コード（例: ['rakuten', 'dmm']）。空配列（既定）の場合は
	 *              後方互換のため queueDepth() が group='' の全 pending 件数にフォールバックする
	 *              （I1: 他プラグインの pending も巻き込む旧挙動。呼び出し側が account を渡せない
	 *              既存インスタンス化を壊さないための互換パス）。
	 * @param ProviderRegistry   $providerRegistry enqueueProductListings() が platform の
	 *          provider コードから account コードを解決するために使う（v2.4.0）。他の
	 *          enqueue 系・reschedule 系メソッドは呼び出し側解決済みの account を受け取るため使わない。
	 * @param int                $sweepLeadSeconds enqueueSweep() の再取得判定（needsRefetch）を表示期限
	 *                       （priceTtlHours=24h）より前倒しで発火させるリード秒数。PriceFreshness::sweepLeadSeconds
	 *                       （掃引間隔 + バッファ）で算出して Plugin の掃引配線から渡す。表示 TTL は変えず再取得
	 *                       だけ早め、価格が期限に達する前に再確認を終わらせる。既定 0 は前倒しなし（従来挙動）。
	 * @param array<string, int> $accountIntervalSeconds account コード→sweep ジョブ間の最小秒数
	 *                     （実効レート間隔 = ceil(effectiveIntervalMs/1000)）。値 > 0 の account は
	 *                     enqueueSweep が sweep ジョブを間隔ぶんずつ確定的にずらして積む（A: 決定的
	 *                     スタガリング）。ランダム jitter だと複数ジョブが同一レート窓に落ちて RateLimiter に
	 *                     弾かれ throttle 再投入（completed アクションのチャーン。Playground「1商品33回」）
	 *                     を招くため、予め間隔分だけ離してレート衝突を根本回避する。既定 空配列＝間隔不明で
	 *                     従来 jitter にフォールバック。
	 */
	public function __construct(
		private int $depthCap = 500,
		private int $maxJitterSeconds = 300,
		private array $accountCodes = array(),
		private ProviderRegistry $providerRegistry = new ProviderRegistry(),
		private int $sweepLeadSeconds = 0,
		private array $accountIntervalSeconds = array()
	) {}

	public function group( string $account ): string {
		return 'affilicard-' . $account;
	}

	/**
	 * 強制更新（ユーザーが「今すぐ更新」を明示的に押した場合）。
	 *
	 * 既存の同一ジョブを unschedule してから即時 priority 0 で積み直す。
	 *
	 * force は args に force=true を持たせ、sweep/manual と同一 base args
	 * （{post_id, platform}）ではない別 unique キーで積む。sweep/manual の unique=true
	 * ジョブが in-progress（claim 済み実行中）のとき、base args のまま force を
	 * unique=true で積むと as_schedule_single_action が in-progress を重複とみなして
	 * ドロップ→force が失われるため。as_unschedule_all_actions は pending のみ取り消し
	 * in-progress は残すので、base args の掃除だけでは in-progress との衝突を避けられない。
	 *
	 * run 時に鮮度スキップは存在しない（PriceFreshness::needsRefetch は enqueueSweep 時のみ・
	 * refreshOne は必ず fetch する）ため、force の実行時挙動は sweep と同一＝「必ず fetch」で、
	 * force が確実に積まれることだけ保証すればよい（ハンドラ側は force を見ない）。
	 */
	public function enqueueForced( int $postId, string $platform, string $account ): void {
		$baseArgs  = array(
			'post_id'  => $postId,
			'platform' => $platform,
		);
		$forceArgs = array(
			'post_id'  => $postId,
			'platform' => $platform,
			'force'    => true,
		);
		$group     = $this->group( $account );

		// base args（sweep/manual）の pending を取り消す。
		as_unschedule_all_actions( self::HOOK_REFRESH, $baseArgs, $group );
		// 先行 pending force を取り消し二重積みを防ぐ。
		as_unschedule_all_actions( self::HOOK_REFRESH, $forceArgs, $group );
		as_schedule_single_action( time(), self::HOOK_REFRESH, $forceArgs, $group, true, self::PRIORITY_FORCE );
	}

	/**
	 * 手動更新（画面操作起点だが強制ではない通常トリガー）。
	 */
	public function enqueueManual( int $postId, string $platform, string $account ): void {
		$args = array(
			'post_id'  => $postId,
			'platform' => $platform,
		);

		as_schedule_single_action( time(), self::HOOK_REFRESH, $args, $this->group( $account ), true, self::PRIORITY_MANUAL );
	}

	/**
	 * 掃引 Cron 起点の更新。鮮度内（fresh）ならスキップし、depth cap 到達時も
	 * スキップする（force/manual はこのガードの対象外）。積んだら true を返す。
	 *
	 * 再取得判定にはコンストラクタの $sweepLeadSeconds（表示期限より前倒しで再取得を
	 * 発火させるリード）を渡す。表示 TTL（priceTtlHours=24h＝規約上の表示上限）は変えず
	 * 再取得だけ早め、価格が期限に達する前に再確認を終わらせて正常運用での途切れを防ぐ。
	 *
	 * スケジュール時刻（$when）: $accountIntervalSeconds にその account の実効レート間隔が
	 * 与えられている場合は決定的スタガリングを行い、同一 account の sweep ジョブを間隔ぶんずつ
	 * 確定的にずらして積む。ランダム jitter だと複数ジョブが同一レート窓に落ちて RateLimiter に
	 * 弾かれ throttle 再投入（completed アクションのチャーン。Playground「1商品33回」）を招くため、
	 * 予め間隔分だけ離してレート衝突を根本回避する。間隔が不明（未指定・0）な account は従来 jitter。
	 *
	 * @param array<string, mixed> $listing
	 */
	public function enqueueSweep( int $postId, string $platform, string $account, ?PlatformDefinition $def, array $listing, int $nowTs ): bool {
		if ( ! PriceFreshness::needsRefetch( $listing, $def, $nowTs, $this->sweepLeadSeconds ) ) {
			return false;
		}
		if ( $this->currentDepth() >= $this->depthCap ) {
			return false;
		}

		$args = array(
			'post_id'  => $postId,
			'platform' => $platform,
		);

		$intervalSec = (int) ( $this->accountIntervalSeconds[ $account ] ?? 0 );
		if ( $intervalSec > 0 ) {
			// 決定的スタガリング: 同一 account の sweep ジョブを実効レート間隔ぶんずつ確定的に
			// ずらして積む。ランダム jitter だと複数が同一レート窓に落ちて RateLimiter に弾かれ
			// throttle 再投入（completed アクションのチャーン。Playground「1商品33回」）を招くため、
			// 予め間隔分だけ離してレート衝突を根本回避する。
			$base                            = max( time(), $this->accountCursor[ $account ] ?? 0 );
			$when                            = $base;
			$this->accountCursor[ $account ] = $base + $intervalSec;
		} else {
			$when = time() + wp_rand( 0, $this->maxJitterSeconds ); // 間隔不明時は従来 jitter
		}

		as_schedule_single_action( $when, self::HOOK_REFRESH, $args, $this->group( $account ), true, self::PRIORITY_SWEEP );
		++$this->depthMemo;
		return true;
	}

	/**
	 * 自動作成（未登録商品の初回作成）。即時 priority 0 で積む。
	 */
	public function enqueueAutoCreate( string $platform, string $account, string $externalId ): void {
		$args = array(
			'platform'    => $platform,
			'external_id' => $externalId,
		);

		as_schedule_single_action( time(), self::HOOK_AUTOCREATE, $args, $this->group( $account ), true, self::PRIORITY_FORCE );
	}

	/**
	 * throttle/backoff によるハンドラの自己再投入。
	 *
	 * unique=false: ハンドラ実行中の自分自身が in-progress として重複判定されるため、
	 * unique=true だと backoff/throttle の再投入が必ずスキップされてしまう。単一ワーカー
	 * （AS claim による single-flight）実行中の 1 回だけ呼ばれるので false でも増殖しない。
	 *
	 * jitter: $whenSec に wp_rand(0, RESCHEDULE_JITTER_SECONDS) を加算する。同一 account を
	 * 奪い合う listing が jitter 無しだと寸分違わず同一タイムスタンプへ再集結し、claim 順
	 * （action_id ASC）で先着 listing が account throttle を独占し続けてしまう（他の listing
	 * が performWork に到達できず backoff/failed 化が機能しない）ため、負けた listing を
	 * 時間分散させて全員が順にスロットを獲得できるようにする。
	 */
	public function rescheduleRefresh( int $whenSec, int $postId, string $platform, string $account ): void {
		as_schedule_single_action(
			$whenSec + wp_rand( 0, self::RESCHEDULE_JITTER_SECONDS ),
			self::HOOK_REFRESH,
			array(
				'post_id'  => $postId,
				'platform' => $platform,
			),
			$this->group( $account ),
			false,
			self::PRIORITY_MANUAL
		);
	}

	/**
	 * throttle/backoff による AutoCreateHandler の自己再投入。
	 *
	 * unique=false: rescheduleRefresh と同様、実行中の自分自身が in-progress として
	 * 重複判定されてしまうため false（単一ワーカー実行中の 1 回だけ呼ばれるので増殖しない）。
	 * jitter は rescheduleRefresh と同じ理由・同じ定数を使う。
	 */
	public function rescheduleAutoCreate( int $whenSec, string $platform, string $account, string $externalId ): void {
		as_schedule_single_action(
			$whenSec + wp_rand( 0, self::RESCHEDULE_JITTER_SECONDS ),
			self::HOOK_AUTOCREATE,
			array(
				'platform'    => $platform,
				'external_id' => $externalId,
			),
			$this->group( $account ),
			false,
			self::PRIORITY_FORCE
		);
	}

	/**
	 * 商品の ELIGIBLE な auto listing を enqueue する共通ヘルパー。
	 *
	 * ELIGIBLE 判定は QueueMaintenance::sweep()/PublishTrigger と同一
	 * （update_mode=auto && enabled(既定true) && ( $force || auto_update(既定true) ) &&
	 * platform 定義が既知）。`$force` は auto_update=false（ユーザーが手動上書き中）の
	 * listing も対象に含めるかどうかのみを制御する（管理画面「強制更新」ボタン用。旧
	 * ListingRefresher::run($force) の force 挙動を踏襲）。
	 * `$manual` は積み方の選択のみを表す: false は force（enqueueForced・priority 0。
	 * 予約投稿の future→publish 昇格や記事公開/更新等のイベント駆動）、true は手動ボタン
	 * （enqueueManual・priority 10）。$force と $manual は直交する（$force は対象の広さ、
	 * $manual は積み方）。掃引（sweep）はここでは扱わない（鮮度スキップ・depth cap・
	 * jitter を伴う別経路のため enqueueSweep を直接使う）。
	 *
	 * platform の provider コードはコンストラクタで受け取った ProviderRegistry で account
	 * コードへ解決する。account が解決できない（provider が未登録、または accountCode()
	 * が null＝手動系）listing は enqueue できないためスキップする。
	 *
	 * @param array<string, mixed> $product Repository::find() の戻り
	 * @return int enqueue した listing 件数
	 */
	public function enqueueProductListings( int $postId, array $product, bool $manual, bool $force = false ): int {
		$listings = is_array( $product['listings'] ?? null ) ? $product['listings'] : array();

		$count = 0;
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$platform = (string) ( $listing['platform'] ?? '' );
			$def      = PlatformConfig::find( $platform );
			if ( null === $def ) {
				continue;
			}

			if ( ! ListingEligibility::isAutoEligible( $listing, $force ) ) {
				continue;
			}

			$account = $this->providerRegistry->get( $def->provider )?->accountCode();
			if ( null === $account ) {
				continue;
			}

			if ( $manual ) {
				$this->enqueueManual( $postId, $platform, $account );
			} else {
				$this->enqueueForced( $postId, $platform, $account );
			}
			++$count;
		}

		return $count;
	}

	/**
	 * enqueueSweep() 専用の深さ参照。sweep は 1 商品 1 listing ずつ多数回呼ばれるため、
	 * 呼び出しの都度 as_get_scheduled_actions（DB クエリ）する queueDepth() を使うと
	 * O(N) クエリになってしまう。インスタンス内で 1 度だけクエリして memo し、以降は
	 * enqueue 成功のたびに +1 する（cap 到達判定は sweep 内で引き続き正しく効く）。
	 * 公開 API の queueDepth() は他の呼び出し元向けに常に最新値を返す契約を保つため、
	 * memo とは独立に毎回クエリする。
	 */
	private function currentDepth(): int {
		if ( null === $this->depthMemo ) {
			$this->depthMemo = $this->queueDepth();
		}
		return $this->depthMemo;
	}

	/**
	 * pending 状態の AS ジョブ件数。depth cap 判定に使う。
	 *
	 * accountCodes が渡されていれば affilicard-{account} group 別に集計して合算する
	 * （I1: WooCommerce 等、無関係な他プラグインの pending action を depth cap
	 * backstop に巻き込まない）。accountCodes が空（既定）の場合のみ、後方互換として
	 * group='' の全 pending 件数にフォールバックする。
	 */
	public function queueDepth(): int {
		if ( array() === $this->accountCodes ) {
			$ids = as_get_scheduled_actions(
				array(
					'status'   => 'pending',
					'per_page' => $this->depthCap + 1,
					'group'    => '',
				),
				'ids'
			);

			return is_array( $ids ) ? count( $ids ) : 0;
		}

		$total = 0;
		foreach ( $this->accountCodes as $accountCode ) {
			$ids = as_get_scheduled_actions(
				array(
					'status'   => 'pending',
					'per_page' => -1,
					'group'    => $this->group( $accountCode ),
				),
				'ids'
			);

			$total += is_array( $ids ) ? count( $ids ) : 0;
		}

		return $total;
	}
}
