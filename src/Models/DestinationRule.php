<?php
namespace QRBuzz\Models;

class DestinationRule {

    public int $id;
    public int $qrId;
    public string $name;
    public int $priority;
    public string $status;
    public string $destinationUrl;
    public array $conditions;
    public ?string $startsAt;
    public ?string $endsAt;
    public ?string $daysOfWeek;
    public ?string $timeStart;
    public ?string $timeEnd;
    public string $createdAt;
    public string $updatedAt;

    public static function fromRow(object $row): self {
        $rule = new self();
        $rule->id = (int) $row->id;
        $rule->qrId = (int) $row->qr_id;
        $rule->name = (string) $row->name;
        $rule->priority = (int) $row->priority;
        $rule->status = (string) $row->status;
        $rule->destinationUrl = (string) $row->destination_url;
        $rule->conditions = self::decodeConditions($row->conditions_json ?? null);
        $rule->startsAt = self::nullableString($row->starts_at ?? null);
        $rule->endsAt = self::nullableString($row->ends_at ?? null);
        $rule->daysOfWeek = self::nullableString($row->days_of_week ?? null);
        $rule->timeStart = self::nullableString($row->time_start ?? null);
        $rule->timeEnd = self::nullableString($row->time_end ?? null);
        $rule->createdAt = (string) $row->created_at;
        $rule->updatedAt = (string) $row->updated_at;

        return $rule;
    }

    public function isActive(): bool {
        return $this->status === 'active';
    }

    public function dayNumbers(): array {
        if (!$this->daysOfWeek) {
            return [];
        }

        return array_values(array_filter(array_map('absint', explode(',', $this->daysOfWeek)), static fn(int $day): bool => $day >= 1 && $day <= 7));
    }

    private static function nullableString($value): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function decodeConditions($value): array {
        if (!$value) {
            return [];
        }

        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
