<?php

namespace Phue\Core\Interfaces;

interface StoppableEventInterface
{
    public function isPropagationStopped(): bool;
}