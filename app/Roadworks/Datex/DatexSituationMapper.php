<?php

declare(strict_types=1);

namespace App\Roadworks\Datex;

use SimpleXMLElement;

final class DatexSituationMapper
{
    private const array NS = [
        'sit' => 'http://datex2.eu/schema/3/situation',
        'com' => 'http://datex2.eu/schema/3/common',
        'loc' => 'http://datex2.eu/schema/3/locationReferencing',
        'nle' => 'http://datex2.eu/schema/3/nlExtensions',
        'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
    ];

    private const array SITUATION_TYPES = ['MaintenanceWorks', 'ConstructionWorks', 'PublicEvent'];

    private const array RESTRICTION_TYPES = ['RoadOrCarriagewayOrLaneManagement', 'SpeedManagement'];

    private const array DETOUR_TYPES = ['ReroutingManagement'];

    public function map(SimpleXMLElement $situation): ?MappedRoadwork
    {
        $this->ns($situation);
        $records = $situation->xpath('sit:situationRecord') ?: [];

        // informationStatus lives at the situation level (sit:headerInformation),
        // not on each record. Skip only when it's explicitly non-'real'.
        $info = $this->text($situation, 'sit:headerInformation/com:informationStatus');
        if ($info !== null && $info !== 'real') {
            return null;
        }

        $primary = null;
        $restrictions = [];
        $detours = [];

        foreach ($records as $rec) {
            $this->ns($rec);
            $type = $this->recordType($rec);
            if (in_array($type, self::SITUATION_TYPES, true)) {
                $primary ??= $rec;
            } elseif (in_array($type, self::RESTRICTION_TYPES, true)) {
                $restrictions[] = $this->feature($rec, $this->lineString($rec));
            } elseif (in_array($type, self::DETOUR_TYPES, true)) {
                $detours[] = $this->feature($rec, $this->lineString($rec));
            }
        }

        if ($primary === null) {
            return null;
        }

        $point = $this->point($primary);
        $kind = $this->recordType($primary) === 'PublicEvent' ? 'EVENT' : 'WORK';

        $document = [
            'situation' => $this->feature($primary, $point),
            'restrictions' => $restrictions,
            'detours' => $detours,
            'attachments' => $this->attachments($situation),
        ];

        return new MappedRoadwork(
            sourceId: (string) $situation['id'],
            kind: $kind,
            severity: $this->text($situation, 'sit:overallSeverity'),
            status: $this->text($primary, './/nle:roadworkStatus') ?? $this->text($primary, './/nle:roadworkPlanningStatus'),
            hindrance: $this->text($primary, './/nle:roadworkHindranceClass'),
            roadAuthority: $this->text($primary, './/sit:source//com:value'),
            startDate: $this->text($primary, './/com:overallStartTime'),
            endDate: $this->text($primary, './/com:overallEndTime'),
            point: $point,
            document: $document,
        );
    }

    /** @param array<string,mixed>|null $geometry */
    private function feature(SimpleXMLElement $rec, ?array $geometry): array
    {
        return [
            'type' => 'Feature',
            'geometry' => $geometry,
            'properties' => [
                'recordType' => $this->recordType($rec),
                'causeType' => $this->text($rec, './/sit:causeType'),
                'causeDescription' => $this->text($rec, './/sit:causeDescription//com:value'),
                'reroutingManagementType' => $this->text($rec, './/sit:reroutingManagementType'),
                'numberOfOperationalLanes' => $this->text($rec, './/sit:numberOfOperationalLanes'),
                'temporarySpeedLimit' => $this->text($rec, './/sit:temporarySpeedLimit'),
            ],
        ];
    }

    private function point(SimpleXMLElement $rec): ?array
    {
        $lat = $this->text($rec, './/loc:pointByCoordinates/loc:pointCoordinates/loc:latitude');
        $lon = $this->text($rec, './/loc:pointByCoordinates/loc:pointCoordinates/loc:longitude');
        if ($lat === null || $lon === null) {
            return null;
        }

        return ['type' => 'Point', 'coordinates' => [(float) $lon, (float) $lat]];
    }

    private function lineString(SimpleXMLElement $rec): ?array
    {
        $this->ns($rec);
        $lists = $rec->xpath('.//loc:gmlLineString/loc:posList') ?: [];
        foreach ($lists as $list) {
            $nums = preg_split('/\s+/', trim((string) $list)) ?: [];
            $coords = [];
            $counter = count($nums);
            for ($i = 0; $i + 1 < $counter; $i += 2) {
                $coords[] = [(float) $nums[$i + 1], (float) $nums[$i]];
            }
            if (count($coords) >= 2) {
                return ['type' => 'LineString', 'coordinates' => $coords];
            }
        }

        return null;
    }

    /** @return list<array{url: string, description: ?string}> */
    private function attachments(SimpleXMLElement $situation): array
    {
        $this->ns($situation);
        $out = [];
        foreach ($situation->xpath('.//com:urlLinkAddress') ?: [] as $a) {
            $out[] = ['url' => (string) $a, 'description' => null];
        }

        return $out;
    }

    private function recordType(SimpleXMLElement $rec): string
    {
        $type = (string) ($rec->attributes(self::NS['xsi'])['type'] ?? '');

        return str_contains($type, ':') ? explode(':', $type)[1] : $type;
    }

    private function text(SimpleXMLElement $el, string $xpath): ?string
    {
        $this->ns($el);
        $hit = $el->xpath($xpath);

        return $hit ? trim((string) $hit[0]) : null;
    }

    private function ns(SimpleXMLElement $el): void
    {
        foreach (self::NS as $prefix => $uri) {
            $el->registerXPathNamespace($prefix, $uri);
        }
    }
}
