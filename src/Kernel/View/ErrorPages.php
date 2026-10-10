<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Exception\HttpException;

/** ErrorHandler's renderHtml hook: the theme's error/{status} (or error/default) page inside the layout. */
final class ErrorPages
{
    public function __construct(private readonly View $view, private readonly bool $debug = false) {}

    /** The themed page, or null to keep ErrorHandler's built-in page. */
    public function render(int $status, string $message, ServerRequestInterface $request): ?string
    {
        if ($this->debug && $status >= 500) {
            return null;
        }
        foreach (["error/{$status}", 'error/default'] as $template) {
            if ($this->view->exists($template)) {
                $reason = HttpException::reason($status);

                return $this->view->page(
                    new Page($template, ['status' => $status, 'reason' => $reason, 'message' => $message], title: "{$status} {$reason}"),
                    $request,
                );
            }
        }

        return null;
    }
}
