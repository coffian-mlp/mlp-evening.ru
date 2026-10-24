<?php

namespace Social;

class TelegramBotException extends \RuntimeException
{
    public function __construct(string $message, public bool $uncertain = false, public readonly array $diagnostics = [])
    {
        parent::__construct($message);
    }
}
