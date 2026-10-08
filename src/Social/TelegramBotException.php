<?php

namespace Social;

class TelegramBotException extends \RuntimeException
{
    public function __construct(string $message, public bool $uncertain = false)
    {
        parent::__construct($message);
    }
}
