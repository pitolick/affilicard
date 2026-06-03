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

WP_Mock::bootstrap();
