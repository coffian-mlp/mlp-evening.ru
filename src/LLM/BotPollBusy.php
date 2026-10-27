<?php
namespace LLM;

/** Retry the update after the active publisher releases its lock; do not advance the cursor. */
final class BotPollBusy extends \RuntimeException {}
