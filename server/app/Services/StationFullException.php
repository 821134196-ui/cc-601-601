<?php

namespace App\Services;

class StationFullException extends \RuntimeException
{
    public function __construct(public readonly array $alternatives)
    {
        parent::__construct('该站点柜位已满');
    }
}
