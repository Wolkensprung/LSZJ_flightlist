<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/api_authenticated_actor.php';
require_once __DIR__ . '/vf_api_config.php';
require_once __DIR__ . '/Vereinsflieger/FlightPayloadBuilder.php';
require_once __DIR__ . '/Vereinsflieger/FlightRestClient.php';
require_once __DIR__ . '/Vereinsflieger/FlightPersonResolver.php';
require_once __DIR__ . '/Vereinsflieger/FlightExportService.php';
require_once __DIR__ . '/Vereinsflieger/FlightSpecialCaseGuard.php';

use LSZJ\Vereinsflieger\FlightExportService;
use LSZJ\Vereinsflieger\FlightPersonResolver;
use LSZJ\Vereinsflieger\FlightRestClient;
use LSZJ\Vereinsflieger\FlightSpecialCaseGuard;

const VF_BATCH_MAX_FLIGHTS = 10;

try {
    $actor = api_authenticated_actor(['ADMIN']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'Methode nicht erlaubt.'], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    csrf_require_valid(isset($input['csrf_token']) ? (string)$input['csrf_token'] : null);

    $pdo = db();
    $service = new FlightExportService(
        $pdo,
        new FlightRestClient(vf_api_config()),
        new FlightPersonResolver($pdo)
    );
    $guard = new FlightSpecialCaseGuard();
    $action = (string)($input['action'] ?? 'preview');

    if ($action === 'preview') {
        $preview = $service->preview(
            (string)($input['from'] ?? date('Y-m-d')),
            (string)($input['to'] ?? date('Y-m-d'))
        );
        $preview['items'] = apply_special_case_guard(
            is_array($preview['items'] ?? null) ? $preview['items'] : [],
            $guard
        );
        $preview['sendable_count'] = count(array_filter(
            $preview['items'],
            static fn(array $item): bool => ($item['send_allowed'] ?? false) === true
        ));
        $preview['blocked_count'] = count($preview['items']) - $preview['sendable_count'];
        $preview['batch_max_flights'] = VF_BATCH_MAX_FLIGHTS;
        json_response($preview);
    }

    if ($action === 'send_one') {
        require_export_enabled();
        $entryId = positive_id($input['entry_id'] ?? null);
        assert_special_case_sendable($service, $pdo, $guard, $entryId);
        json_response([
            'ok' => true,
            'result' => $service->sendOne(
                $entryId,
                (int)$actor['id'],
                ($input['confirm_single_test'] ?? false) === true
            ),
        ]);
    }

    if ($action === 'send_batch') {
        require_export_enabled();
        if (($input['confirm_controlled_batch'] ?? false) !== true) {
            json_response(['ok' => false, 'error' => 'Der kontrollierte Mehrfachexport wurde nicht bestätigt.'], 422);
        }
        $entryIds = normalize_entry_ids($input['entry_ids'] ?? []);
        if ($entryIds === []) {
            json_response(['ok' => false, 'error' => 'Es wurde kein Flug ausgewählt.'], 422);
        }
        if (count($entryIds) > VF_BATCH_MAX_FLIGHTS) {
            json_response([
                'ok' => false,
                'error' => sprintf('Pro kontrolliertem Stapel sind höchstens %d Flüge erlaubt.', VF_BATCH_MAX_FLIGHTS),
            ], 422);
        }

        $items = [];
        $successful = 0;
        $failed = 0;
        foreach ($entryIds as $entryId) {
            try {
                assert_special_case_sendable($service, $pdo, $guard, $entryId);
                $result = $service->sendOne($entryId, (int)$actor['id'], true);
                $items[] = [
                    'entry_id' => $entryId,
                    'ok' => true,
                    'flid' => (string)($result['flid'] ?? ''),
                    'error' => '',
                ];
                $successful++;
            } catch (Throwable $error) {
                $items[] = [
                    'entry_id' => $entryId,
                    'ok' => false,
                    'flid' => '',
                    'error' => limit_text($error->getMessage(), 1000),
                ];
                $failed++;
            }
        }
        json_response([
            'ok' => true,
            'result' => [
                'requested' => count($entryIds),
                'successful' => $successful,
                'failed' => $failed,
                'items' => $items,
            ],
        ]);
    }

    json_response(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
} catch (Throwable $error) {
    error_log('VF flight export: ' . $error->getMessage());
    json_response(['ok' => false, 'error' => $error->getMessage()], 422);
}

/** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
function apply_special_case_guard(array $items, FlightSpecialCaseGuard $guard): array
{
    foreach ($items as &$item) {
        $special = $guard->inspect($item);
        $item['special_case'] = $special;
        if ($special['issues'] !== []) {
            $item['issues'] = array_values(array_unique(array_merge(
                is_array($item['issues'] ?? null) ? $item['issues'] : [],
                $special['issues']
            )));
            $item['send_allowed'] = false;
        }
    }
    unset($item);
    return $items;
}

function assert_special_case_sendable(
    FlightExportService $service,
    PDO $pdo,
    FlightSpecialCaseGuard $guard,
    int $entryId
): void {
    $statement = $pdo->prepare('SELECT DATE(departure_time) FROM accounting_entries WHERE id = ?');
    $statement->execute([$entryId]);
    $date = $statement->fetchColumn();
    if (!is_string($date) || $date === '') {
        throw new RuntimeException('Flugdatum konnte nicht geladen werden.');
    }
    $preview = $service->preview($date, $date);
    $found = null;
    foreach (($preview['items'] ?? []) as $item) {
        if ((int)($item['entry_id'] ?? 0) === $entryId) {
            $found = $item;
            break;
        }
    }
    if ($found === null) {
        throw new RuntimeException('Der Flug ist seit der Vorschau nicht mehr sendbar.');
    }
    $special = $guard->inspect($found);
    if ($special['issues'] !== []) {
        throw new RuntimeException('Sonderfall gesperrt: ' . implode(' ', $special['issues']));
    }
}

function require_export_enabled(): void
{
    if (getenv('VF_FLIGHT_EXPORT_ENABLED') !== '1') {
        json_response([
            'ok' => false,
            'error' => 'Der Versand ist serverseitig gesperrt. VF_FLIGHT_EXPORT_ENABLED=1 fehlt.',
        ], 423);
    }
}

function positive_id(mixed $value): int
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value <= 0) {
        throw new RuntimeException('Ungültige Flug-ID.');
    }
    return (int)$value;
}

/** @return list<int> */
function normalize_entry_ids(mixed $values): array
{
    if (!is_array($values)) {
        throw new RuntimeException('entry_ids muss eine Liste sein.');
    }
    $ids = [];
    foreach ($values as $value) {
        $id = positive_id($value);
        $ids[$id] = $id;
    }
    return array_values($ids);
}

function limit_text(string $value, int $length): string
{
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $length, 'UTF-8')
        : substr($value, 0, $length);
}
