<?php

namespace App\Services;

use App\Handlers\AuditHandler;
use App\Handlers\AuthorizeHandler;
use App\Handlers\CaptureHandler;
use App\Handlers\CreateHandler;
use App\Handlers\ExitHandler;
use App\Handlers\ListHandler;
use App\Handlers\RefundHandler;
use App\Handlers\SettleHandler;
use App\Handlers\SettlementHandler;
use App\Handlers\StatusHandler;
use App\Handlers\VoidHandler;
use App\Parsers\CommandParser;

class PaymentPipelineService
{
    /**
     * Maps command verbs to handler class names.
     *
     * @var array<string, class-string>
     */
    private const COMMAND_MAP = [
        'CREATE' => CreateHandler::class,
        'AUTHORIZE' => AuthorizeHandler::class,
        'CAPTURE' => CaptureHandler::class,
        'VOID' => VoidHandler::class,
        'REFUND' => RefundHandler::class,
        'SETTLE' => SettleHandler::class,
        'SETTLEMENT' => SettlementHandler::class,
        'STATUS' => StatusHandler::class,
        'LIST' => ListHandler::class,
        'AUDIT' => AuditHandler::class,
        'EXIT' => ExitHandler::class,
    ];

    private CommandParser $parser;

    public function __construct(
        private PaymentStorageService $storage,
        private PaymentStateService $stateService,
    ) {
        $this->parser = new CommandParser;
    }

    /**
     * Process a raw input line and return the output string, or null for blank lines.
     */
    public function handle(string $line): ?string
    {
        $tokens = $this->parser->parse($line);

        if ($tokens === null) {
            return null;
        }

        $verb = strtoupper($tokens[0]);

        if (! array_key_exists($verb, self::COMMAND_MAP)) {
            return "[ERROR] Unknown command: {$tokens[0]}";
        }

        $handlerClass = self::COMMAND_MAP[$verb];
        $handler = new $handlerClass($this->storage, $this->stateService);

        return $handler->handle($tokens);
    }
}
