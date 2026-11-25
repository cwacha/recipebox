<?php

declare(strict_types=1);

namespace App\Http;

use Closure;

/**
 * Sends newline delimited JSON events while a long running action is still working, so a page can show its
 * progress. The producer receives an emitter; every event is written and flushed immediately.
 */
final class StreamedJsonResponse extends Response
{
    /** @param Closure(Closure(array<string, mixed>): void): void $producer */
    public function __construct(private readonly Closure $producer)
    {
        parent::__construct('', 200, [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Cache-Control' => 'no-store',
            // a buffering proxy (nginx) must hand every event to the browser at once
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function send(): void
    {
        $this->sendHeaders();

        // PHP's own output buffering (php.ini output_buffering, zlib, ...) would hold the events back
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        ($this->producer)($this->emitter());
        flush();
    }

    /** Runs the action without sending anything, for tests. @return list<array<string, mixed>> */
    public function events(): array
    {
        $events = [];
        ($this->producer)(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        return $events;
    }

    /** @return Closure(array<string, mixed>): void */
    private function emitter(): Closure
    {
        return static function (array $event): void {
            echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
            flush();
        };
    }
}
