<?php
declare(strict_types=1);
namespace LSZJ\Vereinsflieger;

use PDO;
use RuntimeException;
use Throwable;

final class FlightDayExportService
{
    public const MAX_FLIGHTS = 50;

    public function __construct(
        private PDO $pdo,
        private FlightExportService $flightService,
        private FlightSpecialCaseGuard $guard
    ) {}

    public function preview(string $date): array
    {
        $this->assertDate($date);
        $preview = $this->flightService->preview($date, $date);
        $items = [];
        foreach (($preview['items'] ?? []) as $item) {
            $special = $this->guard->inspect($item);
            $item['special_case'] = $special;
            if ($special['issues'] !== []) {
                $item['issues'] = array_values(array_unique(array_merge(
                    is_array($item['issues'] ?? null) ? $item['issues'] : [],
                    $special['issues']
                )));
                $item['send_allowed'] = false;
            }
            $items[] = $item;
        }
        return [
            'ok' => true,
            'date' => $date,
            'candidate_count' => count($items),
            'sendable_count' => count(array_filter($items, static fn(array $i): bool => ($i['send_allowed'] ?? false) === true)),
            'blocked_count' => count(array_filter($items, static fn(array $i): bool => ($i['send_allowed'] ?? false) !== true)),
            'max_flights' => self::MAX_FLIGHTS,
            'items' => $items,
        ];
    }

    public function run(string $date, array $entryIds, int $actorId, bool $confirmed): array
    {
        $this->assertDate($date);
        if (!$confirmed) throw new RuntimeException('Der Tagesexport wurde nicht bestätigt.');
        $ids = $this->normalizeIds($entryIds);
        if ($ids === []) throw new RuntimeException('Keine Flüge ausgewählt.');
        if (count($ids) > self::MAX_FLIGHTS) throw new RuntimeException('Maximal '.self::MAX_FLIGHTS.' Flüge pro Lauf.');

        $preview = $this->preview($date);
        $sendable = [];
        foreach ($preview['items'] as $item) {
            if (($item['send_allowed'] ?? false) === true) $sendable[(int)$item['entry_id']] = true;
        }
        foreach ($ids as $id) {
            if (!isset($sendable[$id])) throw new RuntimeException("Flug {$id} ist nicht mehr sendbar. Neue Vorschau laden.");
        }

        $runId = $this->startRun($date, count($ids), $actorId);
        $results=[];$success=0;$failed=0;$reconcile=0;
        foreach ($ids as $id) {
            try {
                $result=$this->flightService->sendOne($id,$actorId,true);
                $this->attachLatestLog($id,$runId,'success');
                $results[]=['entry_id'=>$id,'status'=>'success','flid'=>(string)($result['flid']??''),'error'=>''];
                $success++;
            } catch (Throwable $error) {
                $duplicate=DuplicateFlightResult::fromMessage($error->getMessage());
                if ($duplicate['is_duplicate']) {
                    $this->markReconciliation($id,$runId,$duplicate['duplicate_flid'],$error->getMessage());
                    $results[]=['entry_id'=>$id,'status'=>'reconciliation_required','flid'=>$duplicate['duplicate_flid'],'error'=>$error->getMessage()];
                    $reconcile++;
                } else {
                    $this->attachLatestLog($id,$runId,'failed');
                    $results[]=['entry_id'=>$id,'status'=>'failed','flid'=>'','error'=>$this->limit($error->getMessage())];
                    $failed++;
                }
            }
        }
        $this->finishRun($runId,$success,$failed,$reconcile);
        return ['run_id'=>$runId,'requested'=>count($ids),'successful'=>$success,'failed'=>$failed,'reconciliation_required'=>$reconcile,'items'=>$results];
    }

    private function startRun(string $date,int $count,int $actor): int
    {
        $token=$this->uuid();
        $stmt=$this->pdo->prepare("INSERT INTO vf_flight_export_runs(run_token,flight_date,requested_count,started_by) VALUES(?,?,?,?)");
        $stmt->execute([$token,$date,$count,$actor]);
        return (int)$this->pdo->lastInsertId();
    }

    private function finishRun(int $id,int $ok,int $failed,int $reconcile): void
    {
        $status=($failed+$reconcile)>0?'completed_with_errors':'completed';
        $stmt=$this->pdo->prepare("UPDATE vf_flight_export_runs SET status=?,successful_count=?,failed_count=?,reconciliation_count=?,completed_at=NOW() WHERE id=?");
        $stmt->execute([$status,$ok,$failed,$reconcile,$id]);
    }

    private function attachLatestLog(int $entryId,int $runId,string $status): void
    {
        $stmt=$this->pdo->prepare("UPDATE vf_flight_exports SET run_id=? WHERE accounting_entry_id=? AND status=? AND run_id IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$runId,$entryId,$status]);
    }

    private function markReconciliation(int $entryId,int $runId,string $flid,string $message): void
    {
        $stmt=$this->pdo->prepare("SELECT id FROM vf_flight_exports WHERE accounting_entry_id=? AND status='failed' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$entryId]);$id=$stmt->fetchColumn();
        if ($id) {
            $update=$this->pdo->prepare("UPDATE vf_flight_exports SET run_id=?,status='reconciliation_required',vf_flid=?,reconciliation_note=? WHERE id=?");
            $update->execute([$runId,$flid?:null,$this->limit($message),(int)$id]);
        }
    }

    private function normalizeIds(array $values): array
    {
        $ids=[];foreach($values as $value){if(filter_var($value,FILTER_VALIDATE_INT)===false||(int)$value<=0)throw new RuntimeException('Ungültige Flug-ID.');$ids[(int)$value]=(int)$value;}return array_values($ids);
    }
    private function assertDate(string $date): void
    {
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new RuntimeException('Ungültiges Flugdatum.');
    }
    private function uuid(): string
    {
        $d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
    }
    private function limit(string $v): string{return function_exists('mb_substr')?mb_substr($v,0,1000,'UTF-8'):substr($v,0,1000);}
}
