<?php

declare(strict_types=1);

namespace TokenSqueezer\Exceptions;

class TokenSqueezedException extends \RuntimeException {}
class ParseException extends TokenSqueezedException {}
class ProviderException extends TokenSqueezedException {}
class CacheException extends TokenSqueezedException {}
