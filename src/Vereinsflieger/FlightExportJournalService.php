<?php
declare(strict_types=1);
namespace LSZJ\Vereinsflieger;

use PDO;
use RuntimeException;

final class FlightExportJournalService
{
    public function __construct(private PDO $pdo) {}

    public function list(string $from,string $to,string $status='all'): array
    {
        $this->date($from);$this->date($to);
        $allowed=['all','success','failed','pending','reconciliation_required'];
        if(!in_array($status,$allowed,true))throw new RuntimeException('Ungültiger Statusfilter.');
        $sql="SELECT e.id,e.accounting_entry_id,e.operation_id,e.run_id,e.vf_flid,e.api_action,e.status,e.attempted_at,e.succeeded_at,e.error_message,e.reconciliation_note,a.callsign,a.pilot_name,a.attendant_name,a.departure_time,u.display_name AS attempted_by_name FROM vf_flight_exports e JOIN accounting_entries a ON a.id=e.accounting_entry_id LEFT JOIN users u ON u.id=e.attempted_by WHERE DATE(e.attempted_at) BETWEEN ? AND ?";
        $params=[$from,$to];if($status!=='all'){$sql.=' AND e.status=?';$params[]=$status;}$sql.=' ORDER BY e.id DESC LIMIT 500';
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);
        return ['ok'=>true,'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function runs(string $from,string $to): array
    {
        $this->date($from);$this->date($to);$stmt=$this->pdo->prepare("SELECT r.*,u.display_name AS started_by_name FROM vf_flight_export_runs r LEFT JOIN users u ON u.id=r.started_by WHERE r.flight_date BETWEEN ? AND ? ORDER BY r.id DESC LIMIT 200");$stmt->execute([$from,$to]);return ['ok'=>true,'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function date(string $v):void{$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new RuntimeException('Ungültiges Datum.');}
}
