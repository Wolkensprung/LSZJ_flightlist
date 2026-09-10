<?php
declare(strict_types=1);

namespace LSZJ\Vereinsflieger;

use PDO;

final class FlightPersonResolver
{
    /** @var list<array<string,mixed>> */
    private array $people;

    public function __construct(private PDO $pdo)
    {
        $statement = $this->pdo->query(
            'SELECT id, vf_user_no, display_name, membership_status '
            . 'FROM pilots_master '
            . 'WHERE is_active = 1 '
            . 'ORDER BY display_name, id'
        );

        $this->people = $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Löst einen lokal gespeicherten Namen sicher zu genau einer VF-UID auf.
     *
     * Regeln:
     * 1. Vollständiger exakter Anzeigename, ohne Beachtung der Grossschreibung.
     * 2. Nur wenn die Eingabe kein Komma enthält: eindeutiger Nachname vor dem
     *    Komma im Anzeigenamen.
     * 3. Kein Treffer oder mehrere Treffer sperren den Export.
     *
     * @return array{
     *   input_name:string,
     *   status:string,
     *   match_type:string,
     *   vf_user_no:string,
     *   display_name:string,
     *   candidates:list<array{vf_user_no:string,display_name:string}>,
     *   issue:string
     * }
     */
    public function resolve(mixed $name): array
    {
        $input = $this->normalize((string)$name);

        if ($input === '') {
            return $this->result($input, 'empty');
        }

        $exact = [];
        foreach ($this->people as $person) {
            if ($this->same($input, (string)$person['display_name'])) {
                $exact[] = $person;
            }
        }

        if (count($exact) === 1) {
            return $this->resolved($input, 'exact_display_name', $exact[0]);
        }

        if (count($exact) > 1) {
            return $this->ambiguous($input, 'exact_display_name', $exact);
        }

        if (str_contains($input, ',')) {
            return $this->notFound($input);
        }

        $surnameMatches = [];
        foreach ($this->people as $person) {
            $displayName = $this->normalize((string)$person['display_name']);
            $surname = $this->normalize(explode(',', $displayName, 2)[0]);

            if ($surname !== '' && $this->same($input, $surname)) {
                $surnameMatches[] = $person;
            }
        }

        if (count($surnameMatches) === 1) {
            return $this->resolved(
                $input,
                'unique_surname',
                $surnameMatches[0]
            );
        }

        if (count($surnameMatches) > 1) {
            return $this->ambiguous(
                $input,
                'unique_surname',
                $surnameMatches
            );
        }

        return $this->notFound($input);
    }

    private function resolved(
        string $input,
        string $matchType,
        array $person
    ): array {
        return [
            'input_name' => $input,
            'status' => 'resolved',
            'match_type' => $matchType,
            'vf_user_no' => (string)$person['vf_user_no'],
            'display_name' => (string)$person['display_name'],
            'candidates' => [],
            'issue' => '',
        ];
    }

    private function ambiguous(
        string $input,
        string $matchType,
        array $people
    ): array {
        $candidates = array_map(
            static fn(array $person): array => [
                'vf_user_no' => (string)$person['vf_user_no'],
                'display_name' => (string)$person['display_name'],
            ],
            $people
        );

        return [
            'input_name' => $input,
            'status' => 'ambiguous',
            'match_type' => $matchType,
            'vf_user_no' => '',
            'display_name' => '',
            'candidates' => $candidates,
            'issue' => sprintf(
                'Personenzuordnung für „%s“ ist mehrdeutig (%d Treffer).',
                $input,
                count($candidates)
            ),
        ];
    }

    private function notFound(string $input): array
    {
        return [
            'input_name' => $input,
            'status' => 'not_found',
            'match_type' => '',
            'vf_user_no' => '',
            'display_name' => '',
            'candidates' => [],
            'issue' => sprintf(
                'Keine aktive VF-Person für „%s“ gefunden.',
                $input
            ),
        ];
    }

    private function result(string $input, string $status): array
    {
        return [
            'input_name' => $input,
            'status' => $status,
            'match_type' => '',
            'vf_user_no' => '',
            'display_name' => '',
            'candidates' => [],
            'issue' => '',
        ];
    }

    private function same(string $left, string $right): bool
    {
        $left = $this->lower($this->normalize($left));
        $right = $this->lower($this->normalize($right));

        return $left !== '' && hash_equals($left, $right);
    }

    private function normalize(string $value): string
    {
        $value = html_entity_decode(
            $value,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $value = str_replace("\u{00A0}", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
