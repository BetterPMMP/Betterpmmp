<?php
declare(strict_types=1);

namespace BetterPlots\Storage;

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;

final class DatabaseWorker extends Thread{

    public ThreadSafeArray $requests;

    public ThreadSafeArray $responses;

    public function __construct(private string $dsn, private string $username = '', private string $password = ''){
        $this->requests = new ThreadSafeArray();
        $this->responses = new ThreadSafeArray();
    }

    public function run(): void{
        $pdo = null;
        try{
            $pdo = new \PDO($this->dsn, $this->username, $this->password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if($driver === 'sqlite'){
                $pdo->exec('PRAGMA busy_timeout=3000');
                $pdo->exec('PRAGMA journal_mode=WAL');
                $pdo->exec('PRAGMA synchronous=FULL');
                $pdo->exec('CREATE TABLE IF NOT EXISTS betterplots_records (record_key TEXT PRIMARY KEY COLLATE BINARY, revision INTEGER NOT NULL, payload TEXT NOT NULL)');
            }elseif($driver === 'mysql'){
                $pdo->exec('CREATE TABLE IF NOT EXISTS betterplots_records (record_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, revision BIGINT NOT NULL, payload LONGTEXT NOT NULL) ENGINE=InnoDB');
            }else{
                throw new \RuntimeException('unsupported_driver');
            }
            $this->responses[] = json_encode(['id' => 0, 'ok' => true, 'driver' => $driver], JSON_THROW_ON_ERROR);
        }catch(\Throwable $e){

            $code = $e instanceof \PDOException ? (string)($e->errorInfo[1] ?? $e->getCode()) : get_class($e);
            $this->responses[] = json_encode(['id'=>0,'ok'=>false,'error'=>'database_open_failed ('.$code.')'], JSON_THROW_ON_ERROR);
            return;
        }
        while(true){
            $raw = $this->requests->synchronized(function(): string{
                while(($raw = $this->requests->shift()) === null){ $this->requests->wait(); }
                return $raw;
            });
            $id = -1;
            try{
                $request = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                if(!is_array($request) || !isset($request['id'], $request['op']) || !is_int($request['id'])){
                    throw new \InvalidArgumentException('invalid_request');
                }
                $id = $request['id'];
                if($request['op'] === 'close'){
                    $pdo = null;
                    $this->responses[] = json_encode(['id' => $id, 'ok' => true, 'closed' => true], JSON_THROW_ON_ERROR);
                    return;
                }
                $value = $this->execute($pdo, $request, $driver);
                $reply = ['id' => $id, 'ok' => true, 'value' => $value];
            }catch(\Throwable $e){
                $reply = ['id' => $id, 'ok' => false, 'error' => (($request['op']??'')==='legacy-read'||$e instanceof \InvalidArgumentException) ? $e->getMessage() : 'database_operation_failed'];
                if($e instanceof LocalizedException){$reply['locale_error']=['template'=>$e->template,'parameters'=>$e->parameters];}
            }
            $this->responses[] = json_encode($reply, JSON_THROW_ON_ERROR);
        }
    }

    private function execute(\PDO $pdo, array $request, string $driver): mixed{
        if($request['op']==='legacy-read'){require_once __DIR__.'/LegacyReader.php';return LegacyReader::read($request['source']);}
        if($request['op']==='compact'){
            if($driver==='sqlite'){$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');$pdo->exec('VACUUM');}
            else{$pdo->query('OPTIMIZE TABLE betterplots_records')->fetchAll();}
            return ['compacted'=>true];
        }
        if($request['op']==='read-snapshot'){
            $path=$request['path']??'';if(!is_string($path)||!str_ends_with($path,'.bplot')||!is_file($path)||filesize($path)>8388608){throw new \InvalidArgumentException('invalid_import_file');}
            $json=gzdecode((string)file_get_contents($path),33554432);if($json===false){throw new \InvalidArgumentException('invalid_gzip');}
            $data=json_decode($json,true,64,JSON_THROW_ON_ERROR);
            if(!is_array($data)||($data['format']??null)!=='BetterPlots/1'||!is_array($data['manifest']??null)||!is_array($data['chunks']??null)||count($data['chunks'])>128){throw new \InvalidArgumentException('invalid_snapshot_format');}
            return $data;
        }
        if($request['op']==='export-snapshot'){
            $id=$request['snapshot']??'';$path=$request['path']??'';
            if(!is_string($id)||!preg_match('/^[a-f0-9]{24}$/D',$id)||!is_string($path)||!str_ends_with($path,'.bplot')){throw new \InvalidArgumentException('invalid_export');}
            $stmt=$pdo->prepare('SELECT payload FROM betterplots_records WHERE record_key=?');$stmt->execute(['bp/snapshot/'.$id]);$manifest=json_decode((string)$stmt->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
            if(!is_array($manifest)||!($manifest['complete']??false)){throw new \InvalidArgumentException('incomplete_snapshot');}$chunks=[];
            foreach($manifest['parts'] as $part){$stmt->execute(['bp/snapshot-chunk/'.$id.'/'.$part]);$chunks[$part]=json_decode((string)$stmt->fetchColumn(),true,64,JSON_THROW_ON_ERROR);}
            $json=json_encode(['format'=>'BetterPlots/1','manifest'=>$manifest,'chunks'=>$chunks],JSON_THROW_ON_ERROR);
            if(strlen($json)>33554432){throw new \InvalidArgumentException('export_too_large');}
            $dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)){throw new \RuntimeException('export_directory');}
            $tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';$data=gzencode($json,6);
            if(file_put_contents($tmp,$data,LOCK_EX)!==strlen($data)){throw new \RuntimeException('export_write');}
            if(!rename($tmp,$path)){unlink($tmp);throw new \RuntimeException('export_rename');}
            return ['bytes'=>strlen($data),'sha256'=>hash('sha256',$data)];
        }
        if($request['op'] === 'get'){
            $key = $this->key($request['key'] ?? null);
            $stmt = $pdo->prepare('SELECT record_key,revision,payload FROM betterplots_records WHERE record_key=?');
            $stmt->execute([$key]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        if($request['op'] === 'scan'){
            $prefix = $this->key($request['prefix'] ?? null);
            $after = $request['after'] ?? '';
            $limit = $request['limit'] ?? 100;
            if(!is_string($after) || !is_int($limit) || $limit < 1 || $limit > 500){ throw new \InvalidArgumentException('invalid_page'); }

            $stmt = $pdo->prepare('SELECT record_key,revision,payload FROM betterplots_records WHERE record_key>=? AND record_key<? AND record_key>? ORDER BY record_key LIMIT ' . $limit);
            $stmt->execute([$prefix, $prefix . chr(127), $after]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        if($request['op'] !== 'transaction'){ throw new \InvalidArgumentException('unknown_operation'); }
        $changes = $request['changes'] ?? null;
        if(!is_array($changes) || !array_is_list($changes) || count($changes) < 1 || count($changes) > 256){ throw new \InvalidArgumentException('invalid_batch'); }
        $normalized = [];
        foreach($changes as $change){
            if(!is_array($change)){ throw new \InvalidArgumentException('invalid_change'); }
            $key = $this->key($change['key'] ?? null);
            $expected = $change['expected'] ?? null;
            if(!is_int($expected) || $expected < 0 || $expected === PHP_INT_MAX || !array_key_exists('payload', $change)){
                throw new \InvalidArgumentException('invalid_revision');
            }
            $payload = $change['payload'];
            if($payload !== null && (!is_string($payload) || strlen($payload) > 1048576)){
                throw new \InvalidArgumentException('invalid_payload');
            }
            if($payload !== null){ json_decode($payload, true, 64, JSON_THROW_ON_ERROR); }
            if(isset($normalized[$key])){ throw new \InvalidArgumentException('duplicate_key'); }
            $normalized[$key] = [$expected, $payload];
        }
        ksort($normalized, SORT_STRING);
        if($driver === 'sqlite'){ $pdo->exec('BEGIN IMMEDIATE'); }
        else{ $pdo->beginTransaction(); }
        $active = true;
        try{
            $versions = [];
            foreach($normalized as $key => [$expected, $payload]){
                $select = $pdo->prepare('SELECT revision FROM betterplots_records WHERE record_key=?' . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
                $select->execute([$key]);
                $revision = $select->fetchColumn();
                if(($revision === false ? 0 : (int) $revision) !== $expected){
                    if($driver === 'sqlite'){ $pdo->exec('ROLLBACK'); }
                    else{ $pdo->rollBack(); }
                    $active = false;
                    return ['committed' => false, 'conflict' => $key];
                }
                if($payload === null){

                    $payload = 'null';
                }
                $next = $expected + 1;
                if($revision === false){
                    $write = $pdo->prepare('INSERT INTO betterplots_records(record_key,revision,payload) VALUES(?,?,?)');
                    $write->execute([$key, $next, $payload]);
                }else{
                    $write = $pdo->prepare('UPDATE betterplots_records SET revision=?,payload=? WHERE record_key=?');
                    $write->execute([$next, $payload, $key]);
                }
                $versions[$key] = $next;
            }
            if($driver === 'sqlite'){ $pdo->exec('COMMIT'); }
            else{ $pdo->commit(); }
            $active = false;
            return ['committed' => true, 'revisions' => $versions];
        }catch(\Throwable $e){
            if($active){
                if($driver === 'sqlite'){ $pdo->exec('ROLLBACK'); }
                else{ $pdo->rollBack(); }
            }
            throw $e;
        }
    }

    private function key(mixed $key): string{
        if(!is_string($key) || preg_match('/^[\x21-\x7e]{1,190}$/D', $key) !== 1){
            throw new \InvalidArgumentException('invalid_key');
        }
        return $key;
    }
}
