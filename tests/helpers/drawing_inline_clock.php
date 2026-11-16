<?php
namespace LLM;
/** Virtual collection/busy waits; provider barriers and process concurrency still use real time. */
function time(): int { return $GLOBALS['drawing_clock'] ?? \time(); }
function sleep(int $seconds): int { $GLOBALS['drawing_clock']=time()+$seconds; return 0; }
