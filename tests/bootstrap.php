<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'AFFILICARD_PLUGIN_DIR' ) ) {
	define( 'AFFILICARD_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'AFFILICARD_PLUGIN_URL' ) ) {
	define( 'AFFILICARD_PLUGIN_URL', 'https://example.com/wp-content/plugins/affilicard/' );
}
if ( ! defined( 'AFFILICARD_VERSION' ) ) {
	define( 'AFFILICARD_VERSION', '0.0.0-test' );
}
if ( ! defined( 'AFFILICARD_PLUGIN_FILE' ) ) {
	define( 'AFFILICARD_PLUGIN_FILE', dirname( __DIR__ ) . '/affilicard.php' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// WP_Mock は did_action()/doing_action() を提供しないため、bundle した Action Scheduler の
// synchronous require（Plugin::bootInstance() → ActionSchedulerLoader::boot()）が正しく動くよう
// 最小スタブを用意する。plugins_loaded は本テストスイートでは一度も do_action() されないため、
// 「まだ発火していない」という実 WP 環境（プラグイン require 時点）と同じ状態を返す。
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $tag ): int { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return 0;
	}
}
if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( ?string $tag = null ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return false;
	}
}

// WP_Mock は rest_sanitize_boolean() を提供しないが、GeneralSettings::sanitize() は設定を
// 読むたびに通るため、モックし忘れたテストが軒並み fatal になる。WP コアと同じ意味論
// （文字列 'false' / '0' だけ false、それ以外は (bool) キャスト）で最小スタブを置く。
if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
	function rest_sanitize_boolean( $value ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		if ( is_string( $value ) ) {
			$value = strtolower( $value );
			if ( in_array( $value, array( 'false', '0' ), true ) ) {
				$value = false;
			}
		}
		return (bool) $value;
	}
}

// Action Scheduler の初期化済みフラグを問い合わせるための最小スタブ。
//
// **本物は vendor に居るが、composer の autoload には載っていない**（AS は自前の
// `action-scheduler.php` から読まれる想定で、`class_exists( 'ActionScheduler' )` は
// 単体テスト環境では常に false になる）。それだと
// `PluginUpgrade::isActionSchedulerReady()` の「初期化済みなら即座に積む」分岐へ
// 一度も入れず、実装の片側がテストから見えなくなる。
//
// シグネチャと戻り値の意味は同梱の本物
// （`vendor/woocommerce/action-scheduler/classes/abstracts/ActionScheduler.php` の
// `is_initialized( $function_name = null )`）に合わせる。本物は引数を渡されたときだけ
// `_doing_it_wrong()` を鳴らすので、こちらも「引数なしでは何も鳴らさない」を守る。
// 既定は false（＝plugins_loaded 相当の未初期化状態）。テストが明示的に true へ
// 倒したときだけ初期化済みとして振る舞う。
//
// **AS の実挙動（積んだ行が本当に DB に入るか、`action_scheduler_init` が実際に
// 発火するか）はここでは確かめられない。** それは tests/e2e/offers-migration-trigger.spec.js
// が実 WordPress 上で見る。
if ( ! class_exists( 'ActionScheduler' ) ) {
	/**
	 * Minimal ActionScheduler stub for unit tests.
	 *
	 * @phpstan-ignore-next-line
	 */
	class ActionScheduler { // phpcs:ignore

		/**
		 * @var bool
		 */
		public static $data_store_initialized = false;

		/**
		 * @param string|null $function_name Function name (unused in the stub).
		 * @return bool
		 */
		public static function is_initialized( $function_name = null ) { // phpcs:ignore
			return self::$data_store_initialized;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {} // @phpstan-ignore-line
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stub for unit tests.
	 *
	 * @phpstan-ignore-next-line
	 */
	class WP_REST_Request {

		/**
		 * @var array<string, mixed>
		 */
		private array $params = array();

		private string $method = 'GET';

		private string $route = '';

		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}

		/**
		 * @return mixed
		 */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * @param mixed $value
		 */
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_params(): array {
			return $this->params;
		}

		/**
		 * @param array<string, mixed> $params
		 */
		public function set_query_params( array $params ): void {
			foreach ( $params as $k => $v ) {
				$this->params[ (string) $k ] = $v;
			}
		}
	}
}

if ( ! class_exists( 'WP_REST_Posts_Controller' ) ) {
	/**
	 * Minimal WP_REST_Posts_Controller stub for unit tests.
	 *
	 * @phpstan-ignore-next-line
	 */
	class WP_REST_Posts_Controller {

		public function __construct( string $post_type = '' ) {}

		/**
		 * @param mixed $request
		 * @return bool
		 */
		public function get_items_permissions_check( $request ) {
			return true;
		}

		/**
		 * @param mixed $request
		 * @return bool
		 */
		public function get_item_permissions_check( $request ) {
			return true;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal WP_REST_Response stub for unit tests.
	 *
	 * @phpstan-ignore-next-line
	 */
	class WP_REST_Response {

		/**
		 * @var mixed
		 */
		public $data;

		public int $status = 200;

		/**
		 * @var array<string, string>
		 */
		private array $headers = array();

		/**
		 * @param mixed $data
		 */
		public function __construct( $data = null, int $status = 200, array $headers = array() ) {
			$this->data    = $data;
			$this->status  = $status;
			$this->headers = $headers;
		}

		/**
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function set_status( int $status ): void {
			$this->status = $status;
		}

		public function header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}

		/**
		 * @return array<string, string>
		 */
		public function get_headers(): array {
			return $this->headers;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal WP_Post stub for unit tests.
	 *
	 * @phpstan-ignore-next-line
	 */
	#[\AllowDynamicProperties]
	class WP_Post {

		public int $ID = 0;

		public string $post_type = 'post';

		public string $post_status = 'publish';

		public string $post_content = '';

		/**
		 * @param array<string, mixed> $data
		 */
		public function __construct( array $data = array() ) {
			foreach ( $data as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

WP_Mock::bootstrap();
