<?php
declare(strict_types=1);

namespace Affilicard\Queue;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Pricing\ListingEligibility;
use Affilicard\Provider\ProviderRegistry;

/**
 * Action Scheduler へのジョブ投入を一元化するラッパー。
 *
 * すべてのトリガー（手動更新・強制更新・自動作成・掃引 Cron）はこのクラス経由で
 * AS にジョブを積む。dedup は AS ネイティブの $unique=true、順序は $priority
 * （force=0 > manual=10 > sweep=20）、account（認証情報の共有単位。楽天/DMM 等。
 * 認証画面と一致）別に group を分けてレート制御・監視をしやすくする（v2.4.0:
 * provider コード単位から account コード単位へ統一。レート制限は provider ではなく
 * 共有 API＝account 単位でかかるため）。バッチ投入時の depth cap 判定もここに集約する。
 *
 * v3.5.0（Task 12）: 掃引の鮮度判定・スタガリングは QueueMaintenance/BatchRefreshHandler
 * 側に移り、per-listing 投入だった enqueueSweep() は廃止した。掃引自体は
 * enqueueSweepTrigger() が積む affilicard_sweep アクション（QueueMaintenance::sweep()
 * を呼ぶ）が起点になる。
 *
 * enqueueForced/enqueueManual/enqueueAutoCreate/reschedule* は、呼び出し側が
 * 既に provider→account を解決済みの account コードを渡す契約（このクラス自身は
 * provider/account の対応表を持たない）。唯一の例外は enqueueProductListings() で、
 * platform 一覧を横断して provider→account を解決する必要があるため、コンストラクタで
 * 受け取る ProviderRegistry を内部で使う。
 */
final class Enqueuer {

	public const HOOK_REFRESH       = 'affilicard_refresh_listing';
	public const HOOK_REFRESH_BATCH = 'affilicard_refresh_batch';
	public const HOOK_AUTOCREATE    = 'affilicard_autocreate';
	public const HOOK_SWEEP         = 'affilicard_sweep';
	public const PRIORITY_FORCE     = 0;
	public const PRIORITY_MANUAL    = 10;
	public const PRIORITY_SWEEP     = 20;

	/**
	 * throttle/backoff の自己再投入（rescheduleRefresh/rescheduleAutoCreate）に加える
	 * jitter の最大秒数。同一 account を奪い合う listing 群が jitter 無しだと寸分違わず
	 * 同一タイムスタンプへ再集結し、thundering herd（症状1: ignored 誘発）＋ claim 順
	 * （action_id ASC）による先着 listing の恒久的な独占（症状2: 他の listing が
	 * performWork に到達できず failed に絶対到達しない）を招く。負けた listing を
	 * 時間分散させるための jitter。
	 */
	public const RESCHEDULE_JITTER_SECONDS = 60;

	/**
	 * @param list<string>     $accountCodes 深さ集計を affilicard-{account} group 別に限定する
	 *            account コード（例: ['rakuten', 'dmm']）。空配列（既定）の場合は
	 *            後方互換のため queueDepth() が group='' の全 pending 件数にフォールバックする
	 *            （I1: 他プラグインの pending も巻き込む旧挙動。呼び出し側が account を渡せない
	 *            既存インスタンス化を壊さないための互換パス）。
	 * @param ProviderRegistry $providerRegistry enqueueProductListings() が platform の
	 *        provider コードから account コードを解決するために使う（v2.4.0）。他の
	 *        enqueue 系・reschedule 系メソッドは呼び出し側解決済みの account を受け取るため使わない。
	 */
	public function __construct(
		private int $depthCap = 500,
		private int $maxJitterSeconds = 300,
		private array $accountCodes = array(),
		private ProviderRegistry $providerRegistry = new ProviderRegistry()
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
	 * run 時に鮮度スキップは存在しない（鮮度判定は QueueMaintenance::sweep() の enqueue 時点のみ・
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
	 *
	 * per-listing の HOOK_REFRESH（sweep からの異常系フォールバック等）と同一 base args
	 * （{post_id, platform}）＋ unique=true のため、pending の sweep 由来ジョブ（priority 20・
	 * 将来スケジュール）が残っていると as_schedule_single_action が新規アクションを作らず、
	 * 手動（priority 10・即時）が繰り上がらない。base args の pending を一度解除してから
	 * 積み直し、手動更新が確実に上書き・即時実行されるようにする。
	 */
	public function enqueueManual( int $postId, string $platform, string $account ): void {
		$args  = array(
			'post_id'  => $postId,
			'platform' => $platform,
		);
		$group = $this->group( $account );

		as_unschedule_all_actions( self::HOOK_REFRESH, $args, $group );
		as_schedule_single_action( time(), self::HOOK_REFRESH, $args, $group, true, self::PRIORITY_MANUAL );
	}

	/**
	 * 掃引（sweep）トリガーを AS アクション（HOOK_SWEEP）として積む。実際の掃引処理は
	 * QueueMaintenance::sweep() が担い、このメソッドは起動用の「開始/継続ジョブ」を
	 * 積むだけ（Task 12 Ruling 3）。group は 'affilicard-sweep'・args は空・priority は
	 * PRIORITY_SWEEP。
	 *
	 * $unique の既定は true: WP-Cron（affilicard_refresh_all）からの開始トリガーが
	 * 同時多重発火しても 1 件に収束させる。QueueMaintenance::sweep() が false（未完走）
	 * を返したときの継続トリガーでは false を渡すこと——実行中の自分自身が in-progress
	 * として unique 判定に一致し、true のままだと必ず抑止されてジョブが痕跡なく消滅する
	 * （rescheduleRefresh 等の自己再投入と同じ理由）。
	 *
	 * @return int action ID。0 は未投入（unique 重複・投入失敗）。
	 */
	public function enqueueSweepTrigger( bool $unique = true ): int {
		return (int) as_schedule_single_action( time(), self::HOOK_SWEEP, array(), $this->group( 'sweep' ), $unique, self::PRIORITY_SWEEP );
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
	 * account 単位のバッチジョブを積む。1 ジョブが複数 listing を担当し、
	 * ハンドラ側がジョブ内でレート間隔を守りながら順次 fetch する。
	 *
	 * per-listing ジョブ（HOOK_REFRESH）は異常系の受け皿として残るため、
	 * ここでは正常系の投入だけを担う。
	 *
	 * $unique の既定は true（sweep 等、新規に投入する呼び出し向け＝同一 items 集合の
	 * 二重投入を防ぐ）。**ハンドラ自身による自己再投入・積み直し（pause 温存・期限超過に
	 * よる未処理分の積み直し）は false を渡すこと**。AS の unique 判定は PENDING/RUNNING
	 * 双方に対して hook + group + args(JSON) の完全一致で挿入を抑止し 0 を返す
	 * （ActionScheduler_DBStore::isActionUnique）。自己再投入は実行中の自分自身と
	 * account・items が一致するため、true のままだと必ず抑止されてジョブが痕跡なく
	 * 消滅する（rescheduleRefresh が同じ理由で unique=false を採っているのと同じ事情）。
	 * 単一ワーカー実行中の 1 回だけ呼ばれるので false でも増殖しない。
	 *
	 * @param list<array{post_id: int, platform: string}> $items
	 * @return int action ID。0 は未投入（items が空・重複・投入失敗）。
	 */
	public function enqueueBatch( string $account, array $items, int $when = 0, bool $unique = true ): int {
		if ( array() === $items ) {
			return 0;
		}

		$args = array(
			'account' => $account,
			'items'   => array_values( $items ),
		);

		return (int) as_schedule_single_action(
			$when > 0 ? $when : time(),
			self::HOOK_REFRESH_BATCH,
			$args,
			$this->group( $account ),
			$unique,
			self::PRIORITY_SWEEP
		);
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
	 * $manual は積み方）。掃引（sweep）はここでは扱わない（鮮度スキップ・depth cap を伴う
	 * 別経路のため QueueMaintenance::sweep() が enqueueBatch を直接使う）。
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
