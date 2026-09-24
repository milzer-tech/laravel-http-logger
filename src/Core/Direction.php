<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core;

/**
 * Which way the HTTP traffic flows, seen from the application.
 */
enum Direction: string
{
    /** A client calls the application (logged by the Laravel middleware). */
    case Incoming = 'incoming';

    /** The application calls an external service (logged by the Saloon plugin). */
    case Outgoing = 'outgoing';
}
