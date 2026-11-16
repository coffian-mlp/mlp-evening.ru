<?php
namespace LLM;

/** An expired drawing owner may neither call another provider nor publish its result. */
final class DrawingLeaseLost extends \RuntimeException {}
