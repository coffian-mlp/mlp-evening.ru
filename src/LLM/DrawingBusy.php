<?php
namespace LLM;

/** A competing drawing owns the shared execution lock; retry without generating. */
final class DrawingBusy extends \RuntimeException {}
