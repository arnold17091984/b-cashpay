<?php

declare(strict_types=1);

namespace BCashPay\Admin;

/**
 * Simple template renderer.
 * Extracts variables into scope and renders PHP view files.
 */
class View
{
    private static string $viewsDir = '';

    public static function setViewsDir(string $dir): void
    {
        self::$viewsDir = rtrim($dir, '/');
    }

    /**
     * Render a view file wrapped in the layout.
     *
     * @param array<string, mixed> $data Variables to extract into view scope
     */
    public static function render(string $view, array $data = [], bool $withLayout = true): void
    {
        $viewFile = self::$viewsDir . '/' . ltrim($view, '/') . '.php';
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo "View not found: {$view}";
            exit;
        }

        if ($withLayout) {
            $content = self::capture($viewFile, $data);
            $layoutFile = self::$viewsDir . '/layout.php';
            extract($data, EXTR_SKIP);
            include $layoutFile;
        } else {
            extract($data, EXTR_SKIP);
            include $viewFile;
        }
    }

    /**
     * Render a partial view without layout.
     *
     * @param array<string, mixed> $data
     */
    public static function partial(string $view, array $data = []): void
    {
        self::render($view, $data, false);
    }

    /**
     * Capture rendered output of a view into a string.
     *
     * @param array<string, mixed> $data
     */
    public static function capture(string $viewFile, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        include $viewFile;
        return (string) ob_get_clean();
    }

    /**
     * HTML-escape a value for safe output.
     */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Format amount as Japanese yen.
     */
    public static function yen(mixed $amount): string
    {
        return '¥' . number_format((int) $amount);
    }

    /**
     * Format a datetime string for display.
     */
    public static function datetime(mixed $value, string $format = 'Y/m/d H:i'): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
        return $ts !== false ? date($format, $ts) : '-';
    }

    /**
     * Return Bootstrap badge class for a payment link status.
     */
    public static function statusBadge(string $status): string
    {
        return match ($status) {
            'pending'   => 'warning',
            'confirmed' => 'success',
            'expired'   => 'secondary',
            'cancelled' => 'dark',
            'failed'    => 'danger',
            default     => 'secondary',
        };
    }

    /**
     * Return Bootstrap badge class for a scraper status.
     */
    public static function scraperBadge(string $status): string
    {
        return match ($status) {
            'active'        => 'success',
            'setup_pending' => 'warning',
            'inactive'      => 'secondary',
            'paused'        => 'info',
            'login_failed',
            'scrape_failed',
            'error'         => 'danger',
            default         => 'secondary',
        };
    }

    /**
     * Return Bootstrap badge class for a scraper task status.
     */
    public static function taskBadge(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'running'   => 'info',
            'queued'    => 'warning',
            'failed'    => 'danger',
            default     => 'secondary',
        };
    }

    /**
     * Retrieve and clear a flash message from session.
     */
    public static function flash(string $key): ?string
    {
        $value = $_SESSION['flash_' . $key] ?? null;
        unset($_SESSION['flash_' . $key]);
        return $value;
    }

    /**
     * Set a flash message.
     */
    public static function setFlash(string $key, string $message): void
    {
        $_SESSION['flash_' . $key] = $message;
    }

    /**
     * Pagination helper: returns array with page info.
     *
     * @return array{page: int, perPage: int, offset: int, total: int, totalPages: int}
     */
    public static function paginate(int $total, int $page = 1, int $perPage = 20): array
    {
        $page       = max(1, $page);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;

        return compact('page', 'perPage', 'offset', 'total', 'totalPages');
    }
}
