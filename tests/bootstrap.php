<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {} // @phpstan-ignore-line
}

WP_Mock::bootstrap();
