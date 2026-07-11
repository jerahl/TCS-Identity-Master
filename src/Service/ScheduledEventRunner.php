<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Claims due scheduled_event rows and dispatches each to a handler keyed by
 * event_type. A handler returns {ok, note}; on ok the event is marked done, on
 * failure (or a thrown exception, or no registered handler) it is marked failed
 * — which retries until MAX_ATTEMPTS, then parks. Handlers are injected, so the
 * dispatch loop unit-tests without any real AD/Google/mail side effects.
 *
 * @phpstan-type Handler callable(array<string,mixed>,string):array{ok:bool,note:string}
 */
final class ScheduledEventRunner
{
    /** @var array<string,callable> */
    private array $handlers;

    /** @param array<string,callable> $handlers event_type => handler */
    public function __construct(private readonly ScheduledEventService $events, array $handlers)
    {
        $this->handlers = $handlers;
    }

    /**
     * Process events due at/before $now.
     *
     * @param callable(string,array<string,mixed>):void|null $log
     * @return array{processed:int, done:int, failed:int, items:list<array{id:int,type:string,outcome:string,note:string}>}
     */
    public function run(string $now, int $limit = 100, ?callable $log = null): array
    {
        $out = ['processed' => 0, 'done' => 0, 'failed' => 0, 'items' => []];

        foreach ($this->events->due($now, $limit) as $event) {
            $out['processed']++;
            $id = (int) $event['id'];
            $type = (string) $event['event_type'];
            $handler = $this->handlers[$type] ?? null;

            if ($handler === null) {
                $this->events->markFailed($id, "No handler registered for '{$type}'.");
                $this->record($out, $id, $type, 'failed', 'no handler', $log);
                continue;
            }

            try {
                $res = $handler($event, $now);
            } catch (\Throwable $e) {
                $this->events->markFailed($id, $e->getMessage());
                $this->record($out, $id, $type, 'failed', $e->getMessage(), $log);
                continue;
            }

            if (!empty($res['ok'])) {
                $this->events->markDone($id);
                $this->record($out, $id, $type, 'done', (string) ($res['note'] ?? ''), $log);
            } else {
                $note = (string) ($res['note'] ?? 'handler returned not-ok');
                $this->events->markFailed($id, $note);
                $this->record($out, $id, $type, 'failed', $note, $log);
            }
        }

        return $out;
    }

    /**
     * @param array{processed:int,done:int,failed:int,items:list<array{id:int,type:string,outcome:string,note:string}>} $out
     * @param callable(string,array<string,mixed>):void|null $log
     */
    private function record(array &$out, int $id, string $type, string $outcome, string $note, ?callable $log): void
    {
        if ($outcome === 'done') {
            $out['done']++;
        } else {
            $out['failed']++;
        }
        $item = ['id' => $id, 'type' => $type, 'outcome' => $outcome, 'note' => $note];
        $out['items'][] = $item;
        if ($log !== null) {
            $log('event', $item);
        }
    }
}
