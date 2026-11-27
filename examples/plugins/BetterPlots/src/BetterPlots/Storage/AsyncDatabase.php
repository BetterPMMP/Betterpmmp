<?php
declare(strict_types=1);

namespace BetterPlots\Storage;

final class AsyncDatabase{
    private DatabaseWorker $worker;
    private int $sequence = 0;
    private bool $ready = false;
    private bool $closing = false;
    private bool $failed = false;
    private float $restartAt = 0.0;
    private int $restarts = 0;
    private ?string $failure = null;

    private array $callbacks = [];

    public function __construct(private string $dsn, private string $username = '', private string $password = ''){
        $this->worker = new DatabaseWorker($dsn, $username, $password);
        if(!$this->worker->start(\pmmp\thread\Thread::INHERIT_NONE)){
            throw new \RuntimeException('Datenbank-Thread konnte nicht starten.');
        }
    }

    public function isReady(): bool{ return $this->ready && !$this->closing && !$this->failed; }
    public function hasFailed(): bool{ return $this->failed; }
    public function error(): ?string{ return $this->failure; }

    public function submit(array $request, \Closure $callback): int{
        if(!$this->isReady()){ throw new \RuntimeException('Datenbank ist nicht bereit.'); }
        if(count($this->callbacks) >= 256){ throw new \OverflowException('Datenbankwarteschlange ist voll.'); }
        if(!in_array($request['op'] ?? null, ['get', 'scan', 'transaction','export-snapshot','read-snapshot','compact','legacy-read'], true)){ throw new \InvalidArgumentException('Ungültige Datenbankoperation.'); }
        $id = ++$this->sequence;
        $request['id'] = $id;
        $raw = json_encode($request, JSON_THROW_ON_ERROR);
        if(strlen($raw) > 4194304){ throw new \OverflowException('Datenbankbatch ist zu groß.'); }
        $this->callbacks[$id] = $callback;
        $this->enqueue($raw);
        return $id;
    }

    private function enqueue(string $raw): void{
        $this->worker->requests->synchronized(function() use ($raw): void{
            $this->worker->requests[] = $raw;
            $this->worker->requests->notify();
        });
    }

    public function poll(float $budgetMs = 2.0): int{
        if(!is_finite($budgetMs) || $budgetMs <= 0.0 || $budgetMs > 20.0){ throw new \InvalidArgumentException('Ungültiges Poll-Budget.'); }
        $end = hrtime(true) + (int) ($budgetMs * 1000000);
        $count = 0;
        while(hrtime(true) < $end && ($raw = $this->worker->responses->shift()) !== null){
            $reply = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if(!is_array($reply)){ throw new \RuntimeException('Ungültige Datenbankantwort.'); }
            if(isset($reply['locale_error']['template'],$reply['locale_error']['parameters'])&&is_string($reply['locale_error']['template'])&&is_array($reply['locale_error']['parameters'])){
                $reply['error']=\BetterPlots\Text::defer($reply['locale_error']['template'],$reply['locale_error']['parameters']);
            }
            if(($reply['id'] ?? null) === 0){
                $this->ready = ($reply['ok'] ?? false) === true;
                $this->failed = !$this->ready;
                $this->failure = $this->ready ? null : (string)($reply['error'] ?? 'database_open_failed');
                if($this->ready){ $this->restarts = 0; }
            }else{
                $id = $reply['id'] ?? -1;
                $callback = is_int($id) ? ($this->callbacks[$id] ?? null) : null;
                if(is_int($id)){ unset($this->callbacks[$id]); }
                if($callback !== null){ $callback($reply); }
            }
            ++$count;
        }
        if(!$this->worker->isRunning() && !$this->closing && $this->worker->responses->count() === 0){
            $this->failed = true;
            $this->ready = false;
            $this->failure ??= 'worker_stopped';
            $callbacks = $this->callbacks;
            $this->callbacks = [];
            foreach($callbacks as $id => $callback){ $callback(['id' => $id, 'ok' => false, 'error' => 'worker_stopped']); }

            if($this->restartAt === 0.0){ $this->restartAt = microtime(true) + min(30, 2 ** min(5, $this->restarts++)); }
            if(microtime(true) >= $this->restartAt){
                if(!$this->worker->isJoined()){ $this->worker->join(); }
                $this->worker = new DatabaseWorker($this->dsn, $this->username, $this->password);
                $this->restartAt = 0.0;
                if(!$this->worker->start(\pmmp\thread\Thread::INHERIT_NONE)){ $this->failure = 'worker_start_failed'; }
            }
        }
        return $count;
    }

    public function beginClose(): void{
        if($this->closing){ return; }
        $this->closing = true;
        $this->enqueue(json_encode(['id' => ++$this->sequence, 'op' => 'close'], JSON_THROW_ON_ERROR));
    }

    public function isClosed(): bool{
        return $this->closing && !$this->worker->isRunning();
    }

    public function reap(): bool{
        if(!$this->isClosed()){ return false; }
        if(!$this->worker->isJoined()){ $this->worker->join(); }
        return true;
    }
}
