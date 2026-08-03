<?php
namespace QRBuzz\SmartDestinations;

use QRBuzz\Models\DestinationRule;
use QRBuzz\Utils\DateTimeHelper;

class RuleConditionFormatter {
    public function format(DestinationRule $rule): string {
        $parts = [];
        if ($rule->startsAt || $rule->endsAt) {
            $start = $rule->startsAt ? DateTimeHelper::utcToDisplay($rule->startsAt) : 'Any date';
            $end = $rule->endsAt ? DateTimeHelper::utcToDisplay($rule->endsAt) : 'No end date';
            $parts[] = $start . '–' . $end;
        }
        $days = $rule->dayNumbers();
        if ($days) { $parts[] = $this->days($days); }
        if ($rule->timeStart || $rule->timeEnd) {
            $parts[] = ($rule->timeStart ?: '00:00') . '–' . ($rule->timeEnd ?: '23:59') . ($rule->timeStart && $rule->timeEnd && $rule->timeStart > $rule->timeEnd ? ' (overnight)' : '');
        }
        return $parts ? implode(' · ', $parts) : 'No conditions — always matches';
    }

    private function days(array $days): string {
        $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        sort($days);
        if ($days === [1,2,3,4,5]) { return 'Monday–Friday'; }
        if ($days === [6,7]) { return 'Saturday and Sunday'; }
        if ($days === [1,2,3,4,5,6,7]) { return 'Every day'; }
        $labels = array_map(static fn(int $day): string => $names[$day], $days);
        return count($labels) === 1 ? $labels[0] : implode(', ', array_slice($labels, 0, -1)) . ' and ' . end($labels);
    }
}
