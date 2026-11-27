<?php
declare(strict_types=1);
namespace BetterPlots\Storage;

final class Repository{

    private array $records = [];

    private array $revisions = [];
    private bool $loading = false;
    private bool $ready = false;
    private bool $busy = false;
    private array $pendingRecords = [], $pendingRevisions = [];
    private float $nextAttemptAt = 0.0;
    private int $failures = 0, $generation = 0;
    private ?string $loadError = null;
    public function __construct(private AsyncDatabase $db){}
    public function ready(): bool{ return $this->ready && $this->db->isReady(); }
    public function generation(): int{ return $this->generation; }
    public function error(): ?string{ return $this->loadError; }
    public function busy(): bool{ return $this->busy; }

    public function all(string $prefix): array{
        return array_filter($this->records, static fn(string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
    }

    public function get(string $key): ?array{ return $this->records[$key] ?? null; }
    public function tick(): void{
        $this->db->poll();
        if(!$this->db->isReady()&&($this->ready||$this->loading)){
            $this->invalidate('BetterPlots-Datenbankzugriff fehlgeschlagen; Plotdaten werden erneut gelesen.');
        }
        if(!$this->ready && !$this->loading && $this->db->isReady() && microtime(true) >= $this->nextAttemptAt){
            $this->loading = true;
            $this->pendingRecords = $this->pendingRevisions = [];
            $this->loadPage('');
        }
    }
    private function invalidate(string $reason): void{
        $this->ready = $this->loading = false;
        $this->pendingRecords = $this->pendingRevisions = [];
        $this->loadError = $reason;
        $this->nextAttemptAt = microtime(true) + min(30, 2 ** min(5, $this->failures++));
    }
    private function loadPage(string $after): void{
        try{
        $this->db->submit(['op'=>'scan','prefix'=>'bp/','after'=>$after,'limit'=>100], function(array $reply): void{
            try{
            if(($reply['ok'] ?? false) !== true){ throw new \RuntimeException('BetterPlots-Datenbank konnte nicht geladen werden.'); }
            $rows = $reply['value'];
            foreach($rows as $row){
                $key = (string) $row['record_key'];
                $this->pendingRevisions[$key] = (int) $row['revision'];
                $value = json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR);
                if($value !== null){
                    if(!is_array($value)){ throw new \UnexpectedValueException('Ungültiger BetterPlots-Datensatz.'); }
                    $this->pendingRecords[$key] = $value;
                }
            }
            if(count($rows) === 100){ $this->loadPage((string) $rows[99]['record_key']); }
            else{
                $this->records = $this->pendingRecords;
                $this->revisions = $this->pendingRevisions;
                $this->pendingRecords = $this->pendingRevisions = [];
                $this->loading = false;
                $this->ready = true;
                $this->loadError = null;
                $this->failures = 0;
                ++$this->generation;
            }
            }catch(\Throwable $e){ $this->invalidate($e->getMessage()); }
        });
        }catch(\Throwable $e){ $this->invalidate($e->getMessage()); }
    }

    public function commit(array $updates, \Closure $done): void{
        if(!$this->ready() || $this->busy){ throw new \RuntimeException('Eine Änderung läuft bereits. Bitte erneut versuchen.'); }
        if($updates === [] || count($updates) > 256){ throw new \InvalidArgumentException('Ungültige Transaktionsgröße.'); }
        $changes = [];
        foreach($updates as $key => $value){
            if(!str_starts_with($key, 'bp/')){ throw new \InvalidArgumentException('Ungültiger Datensatzschlüssel.'); }
            $changes[] = ['key'=>$key,'expected'=>$this->revisions[$key] ?? 0,'payload'=>$value === null ? null : json_encode($value, JSON_THROW_ON_ERROR)];
        }
        $this->busy = true;
        try{
            $this->db->submit(['op'=>'transaction','changes'=>$changes], function(array $reply) use ($updates, $done): void{
                $ok = ($reply['ok'] ?? false) === true && ($reply['value']['committed'] ?? false) === true;
                if($ok){
                    foreach($updates as $key => $value){
                        $this->revisions[$key] = (int) $reply['value']['revisions'][$key];
                        if($value === null){ unset($this->records[$key]); }else{ $this->records[$key] = $value; }
                    }
                }else{

                    $this->invalidate('BetterPlots-Datenbankzugriff fehlgeschlagen; Plotdaten werden erneut gelesen.');
                }
                $this->busy = false;
                $done($ok);
            });
        }catch(\Throwable $e){ $this->busy = false; $this->invalidate('BetterPlots-Datenbankzugriff fehlgeschlagen; Plotdaten werden erneut gelesen.'); throw $e; }
    }
}
