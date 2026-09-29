<?php
namespace DrSpeedAIAS\Scanner;

defined( 'ABSPATH' ) || exit;

use DrSpeedAIAS\Scanner\Strategies\AbstractOptimizerBypass;
use DrSpeedAIAS\Scanner\Strategies\SgOptimizerBypass;
use DrSpeedAIAS\Scanner\Strategies\HummingbirdBypass;

class StrategyFactory {
    public static function for_method( string $method ): AbstractOptimizerBypass {
        return match ( $method ) {
            'sg_optimizer' => new SgOptimizerBypass(),
            'hummingbird'  => new HummingbirdBypass(),
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message thrown for caller to handle (logging or wp_send_json_error), not echoed.
            default => throw new \InvalidArgumentException( "Unknown disable_method: {$method}" ),
        };
    }
}
