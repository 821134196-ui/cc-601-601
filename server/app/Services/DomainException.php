<?php

namespace App\Services;

use Throwable;

/**
 * 领域业务异常:控制器层统一转换为 JSON 响应。
 */
class DomainException extends \RuntimeException
{
    /**
     * @param  array<string,mixed>  $extra  附加给响应体的字段(如备选站点列表)
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        public readonly array $extra = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
