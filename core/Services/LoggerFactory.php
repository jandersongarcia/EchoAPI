<?php

namespace Core\Services;

use Monolog\Logger;
use Monolog\Level;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Processor\UidProcessor;
use Monolog\Processor\IntrospectionProcessor;
use Core\Helpers\PathResolver;
use Core\Services\TelegramNotifier;

class LoggerFactory
{
    public static function create(): Logger
    {
        $logger = new Logger('app');

        // Define timezone global dos logs
        $timezone = new \DateTimeZone($_ENV['TIME_ZONE'] ?? 'UTC');
        $logger->setTimezone($timezone);

        $logPath = PathResolver::logsPath();

        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? null;
        $chatId = $_ENV['TELEGRAM_CHAT_ID'] ?? null;

        if ($botToken && $chatId) {
            $notifier = new TelegramNotifier($botToken, $chatId);
            $logger->pushProcessor(function ($record) use ($notifier) {
                $telegramLevel = \Core\Helpers\LogLevelHelper::getTelegramLogLevel();
                if ($record['level'] >= $telegramLevel->value) {
                    $formattedMessage = "⛔ *{$record['level_name']}* - {$record['message']}\n\n" . json_encode($record['context'], JSON_PRETTY_PRINT);
                    $notifier->send($formattedMessage);
                }
                return $record;
            });
        }

        // Dias de retenção dos logs configuráveis via .env (padrão: 14)
        $envDays = $_ENV['LOG_RETENTION_DAYS'] ?? 14;
        $retentionDays = is_numeric($envDays) ? max((int) $envDays, 1) : 14;

        $logger->pushHandler(
            new RotatingFileHandler("$logPath/app.log", $retentionDays, Level::Info)
        );

        $logger->pushHandler(
            new RotatingFileHandler("$logPath/error.log", $retentionDays, Level::Error)
        );

        $logger->pushProcessor(new UidProcessor());
        $logger->pushProcessor(new IntrospectionProcessor());
        $logger->pushProcessor(function ($record) {
            $record['extra']['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
            $record['extra']['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'CLI';
            return $record;
        });

        return $logger;
    }

}
