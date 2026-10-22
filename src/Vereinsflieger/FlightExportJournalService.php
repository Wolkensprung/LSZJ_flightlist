<?php
declare(strict_types=1);

namespace LSZJ\Vereinsflieger;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class FlightExportJournalService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function list(
        string $from,
        string $to,
        string $status = 'all'
    ): array {
        $this->assertDateRange($from, $to);

        $allowedStatuses = [
            'all',
            'success',
            'failed',
            'pending',
            'reconciliation_required',
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException('Ungültiger Statusfilter.');
        }

        $sql = "
            SELECT
                e.id,
                e.accounting_entry_id,
                e.operation_id,
                e.run_id,
                e.vf_flid,
                e.api_action,
                e.status,
                e.http_status,
                e.attempted_at,
                e.succeeded_at,
                e.error_message,
                e.reconciliation_note,
                a.callsign,
                a.pilot_name,
                a.attendant_name,
                a.departure_time,
                DATE(a.departure_time) AS flight_date,
                u.display_name AS attempted_by_name
            FROM vf_flight_exports e
            INNER JOIN accounting_entries a
                    ON a.id = e.accounting_entry_id
            LEFT JOIN users u
                   ON u.id = e.attempted_by
            WHERE DATE(a.departure_time) BETWEEN ? AND ?
        ";

        $parameters = [$from, $to];

        if ($status !== 'all') {
            $sql .= ' AND e.status = ?';
            $parameters[] = $status;
        }

        $sql .= ' ORDER BY a.departure_time DESC, e.id DESC LIMIT 500';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return [
            'ok' => true,
            'date_basis' => 'flight_date',
            'items' => $statement->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function runs(string $from, string $to): array
    {
        $this->assertDateRange($from, $to);

        $statement = $this->pdo->prepare(
            "SELECT
                r.*,
                u.display_name AS started_by_name
             FROM vf_flight_export_runs r
             LEFT JOIN users u
                    ON u.id = r.started_by
             WHERE r.flight_date BETWEEN ? AND ?
             ORDER BY r.id DESC
             LIMIT 200"
        );
        $statement->execute([$from, $to]);

        return [
            'ok' => true,
            'date_basis' => 'flight_date',
            'items' => $statement->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function assertDateRange(string $from, string $to): void
    {
        $this->assertDate($from);
        $this->assertDate($to);

        if ($to < $from) {
            throw new RuntimeException('Bis darf nicht vor Von liegen.');
        }
    }

    private function assertDate(string $value): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('Ungültiges Datum.');
        }
    }
}
