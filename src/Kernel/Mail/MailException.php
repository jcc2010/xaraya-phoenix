<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Mail;

use RuntimeException;

/** A message could not be handed to the transport. */
final class MailException extends RuntimeException {}
