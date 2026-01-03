<?php

namespace LiturgicalCalendar\Api\Http\Logs;

use LiturgicalCalendar\Api\Http\Logs\PrettyLineFormatter;
use LiturgicalCalendar\Api\Router;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Processor\WebProcessor;

class LoggerFactory
{
    /** @var Logger[] $apiLoggers */
    private static array $apiLoggers = [];
    private static string $logsFolder;

    /**
     * Ensure the provided path points to an existing, writable directory.
     *
     * @param string $logsFolder Filesystem path to validate as the logs directory.
     * @throws \InvalidArgumentException If the path is empty, does not exist as a directory, or is not writable.
     */
    private static function validateLogsFolder(string $logsFolder): void
    {
        if (empty($logsFolder) || !is_dir($logsFolder) || !is_writable($logsFolder)) {
            throw new \InvalidArgumentException("Logs folder must be a valid, writable directory: {$logsFolder}");
        }
    }

    /**
     * Determine the filesystem base path used to resolve the logs directory.
     *
     * If Router::$apiFilePath has been initialized, that value is returned;
     * otherwise the package root directory (three levels up from this file) is returned.
     *
     * @return string The resolved base filesystem path for log files.
     */
    private static function getBasePath(): string
    {
        $reflection = new \ReflectionProperty(Router::class, 'apiFilePath');
        if ($reflection->isInitialized(null)) {
            return Router::$apiFilePath;
        }
        // Fall back to package root directory (3 levels up from this file)
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
    }

    /**
     * Determine and return the filesystem path to the logs folder, creating it if necessary.
     *
     * If a path is provided it will be validated and used. If no path is provided the method
     * uses a previously stored value if present; otherwise it derives a default logs directory
     * from the package base path and attempts to create it.
     *
     * @param string|null $logsFolder Optional explicit logs folder path to use.
     * @return string The resolved logs folder path.
     * @throws \RuntimeException If the default logs directory cannot be created.
     * @throws \InvalidArgumentException If the provided or stored logs folder is invalid or not writable.
     */
    private static function resolveLogsFolder(?string $logsFolder): string
    {
        if (is_string($logsFolder)) {
            self::validateLogsFolder($logsFolder);
            self::$logsFolder = $logsFolder;
        } elseif (isset(self::$logsFolder) && is_string(self::$logsFolder)) {
            $logsFolder = self::$logsFolder;
            self::validateLogsFolder($logsFolder);
        } else {
            // Try to get path from Router, fall back to deriving from package directory
            $basePath         = self::getBasePath();
            self::$logsFolder = $basePath . 'logs';
            if (!is_dir(self::$logsFolder)) {
                if (!@mkdir(self::$logsFolder, 0755, true) && !is_dir(self::$logsFolder)) {
                    throw new \RuntimeException('Failed to create logs directory: ' . self::$logsFolder);
                }
            }
            $logsFolder = self::$logsFolder;
        }
        return $logsFolder;
    }

    /**
     * Creates (or retrieves if already created) a Monolog logger instance for the API.
     *
     * @param bool $debug Whether to enable debug level logging.
     * @param string $logName The base name for the log files (e.g., 'api' will create 'api.log', and 'api.json.log' if $includeJsonHandler is true).
     * @param string|null $logsFolder The folder where log files will be stored. If null, defaults to 'logs' directory in project root.
     * @param int $maxFiles The maximum number of log files to keep (for rotation).
     * @param bool $includeJsonHandler Whether to include a JSON formatted log handler.
     * @param bool $includeProcessors Whether to include processors for adding extra context to log entries.
     * @return Logger The configured Monolog logger instance.
     * @throws \InvalidArgumentException If the provided logs folder is invalid or not writable.
     * @throws \RuntimeException If unable to create the logs folder.
     */
    public static function create(
        string $logName = 'api',
        ?string $logsFolder = null,
        int $maxFiles = 30,
        bool $debug = false,
        bool $includeJsonHandler = true,
        bool $includeProcessors = true
    ): Logger {
        if (isset(self::$apiLoggers[$logName]) && self::$apiLoggers[$logName] instanceof Logger) {
            return self::$apiLoggers[$logName];
        }

        // Validate/create logs folder
        $logsFolder = self::resolveLogsFolder($logsFolder);
        $logger     = new Logger('litcalapi');

        // --- Plain text rotating file ---
        $plainHandler   = new RotatingFileHandler("{$logsFolder}/{$logName}.log", $maxFiles, $debug ? Level::Debug : Level::Info);
        $plainFormatter = new PrettyLineFormatter(
            "[%datetime%] %level_name%: %message%\n",
            'Y-m-d H:i:s',
            true,
            true,
            false
        );
        $plainHandler->setFormatter($plainFormatter);
        $logger->pushHandler($plainHandler);

        if ($includeJsonHandler) {
            $jsonHandler   = new RotatingFileHandler("{$logsFolder}/{$logName}.json.log", $maxFiles, $debug ? Level::Debug : Level::Info);
            $jsonFormatter = new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true);
            $jsonHandler->setFormatter($jsonFormatter);
            $logger->pushHandler($jsonHandler);
        }

        if ($includeProcessors) {
            $logger->pushProcessor(new WebProcessor());
            $logger->pushProcessor(new RequestResponseProcessor());
        }

        self::$apiLoggers[$logName] = $logger;
        return $logger;
    }
}
