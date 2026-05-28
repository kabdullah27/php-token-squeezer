<?php

declare(strict_types=1);

namespace TokenSqueezer\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \TokenSqueezer\Builders\AnalysisBuilder analyze()
 * @method static array usage()
 * @method static void resetUsage()
 * @method static void configure(array $config)
 *
 * @see \TokenSqueezer\TokenSqueezer
 */
class TokenSqueezer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'token-squeezer';
    }
}
