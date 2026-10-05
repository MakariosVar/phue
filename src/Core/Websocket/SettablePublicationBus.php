<?php

declare(strict_types=1);

namespace Phue\Core\Websocket;

/**
 * A bus whose read position can be placed, rather than only advanced from where it is.
 *
 * Separate from {@see PublicationBus} because it is only meaningful for a bus with a durable
 * backlog: an in-process bus holds its own queue and is always exactly current, so there is nothing
 * to settle. Making it an interface rather than a method keeps that difference honest instead of
 * leaving a method on the base contract that one implementation cannot answer usefully.
 *
 * @package Phue\Core\Websocket
 */
interface SettablePublicationBus extends PublicationBus
{
    /**
     * Place the read position at the end of what has been published.
     *
     * For a worker that has just started and holds no connections. Everything published before it
     * existed has nobody to reach, so reading it is work with no result — and a read position that
     * asks "anything new?" without ever advancing would go on asking it, never seeing a message
     * published between two of its own polls.
     *
     * @return int How many entries were skipped, for a diagnostic rather than for control flow.
     */
    public function settle(): int;
}