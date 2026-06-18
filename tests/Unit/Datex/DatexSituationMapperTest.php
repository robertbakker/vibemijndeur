<?php

declare(strict_types=1);

namespace Tests\Unit\Datex;

use App\Roadworks\Datex\DatexSituationMapper;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

class DatexSituationMapperTest extends TestCase
{
    private function situation(string $inner, string $info = 'real'): SimpleXMLElement
    {
        $xml = <<<XML
        <sit:situation xmlns:sit="http://datex2.eu/schema/3/situation"
                       xmlns:com="http://datex2.eu/schema/3/common"
                       xmlns:loc="http://datex2.eu/schema/3/locationReferencing"
                       xmlns:nle="http://datex2.eu/schema/3/nlExtensions"
                       xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" id="NDW03_42">
          <sit:overallSeverity>medium</sit:overallSeverity>
          <sit:headerInformation><com:informationStatus>{$info}</com:informationStatus></sit:headerInformation>
          {$inner}
        </sit:situation>
        XML;

        return new SimpleXMLElement($xml);
    }

    public function test_maps_maintenance_with_rerouting(): void
    {
        $inner = <<<XML
        <sit:situationRecord xsi:type="sit:MaintenanceWorks" id="r1">
          <sit:probabilityOfOccurrence>certain</sit:probabilityOfOccurrence>
          <sit:source><com:sourceName><com:values><com:value lang="nl">RWS Zuid</com:value></com:values></com:sourceName></sit:source>
          <sit:validity><com:validityTimeSpecification>
            <com:overallStartTime>2026-07-20T03:00:00Z</com:overallStartTime>
            <com:overallEndTime>2026-07-22T03:00:00Z</com:overallEndTime>
          </com:validityTimeSpecification></sit:validity>
          <sit:cause><sit:causeType>roadMaintenance</sit:causeType></sit:cause>
          <sit:locationReference xsi:type="loc:PointLocation"><loc:pointByCoordinates><loc:pointCoordinates>
            <loc:latitude>51.39906</loc:latitude><loc:longitude>6.127533</loc:longitude>
          </loc:pointCoordinates></loc:pointByCoordinates></sit:locationReference>
          <nle:roadworkStatus>running</nle:roadworkStatus>
          <nle:roadworkHindranceClass>hindranceClass1</nle:roadworkHindranceClass>
          <com:informationStatus>real</com:informationStatus>
        </sit:situationRecord>
        <sit:situationRecord xsi:type="sit:ReroutingManagement" id="r2">
          <sit:alternativeRoute><loc:locationContainedInItinerary><loc:location xsi:type="loc:LinearLocation">
            <loc:gmlLineString srsName="WGS 84"><loc:posList>51.399 6.127 51.398 6.128</loc:posList></loc:gmlLineString>
          </loc:location></loc:locationContainedInItinerary></sit:alternativeRoute>
          <com:informationStatus>real</com:informationStatus>
        </sit:situationRecord>
        XML;

        $m = (new DatexSituationMapper())->map($this->situation($inner));

        $this->assertNotNull($m);
        $this->assertSame('NDW03_42', $m->sourceId);
        $this->assertSame('WORK', $m->kind);
        $this->assertSame('medium', $m->severity);
        $this->assertSame('running', $m->status);
        $this->assertSame('hindranceClass1', $m->hindrance);
        $this->assertSame('RWS Zuid', $m->roadAuthority);
        $this->assertSame('2026-07-20T03:00:00Z', $m->startDate);
        $this->assertSame('Point', $m->point['type']);
        $this->assertEqualsWithDelta(6.127533, $m->point['coordinates'][0], 1e-6);
        $this->assertEqualsWithDelta(51.39906, $m->point['coordinates'][1], 1e-6);

        $det = $m->document['detours'][0];
        $this->assertSame('LineString', $det['geometry']['type']);
        $this->assertEqualsWithDelta(6.127, $det['geometry']['coordinates'][0][0], 1e-6);
        $this->assertEqualsWithDelta(51.399, $det['geometry']['coordinates'][0][1], 1e-6);
    }

    public function test_skips_non_real_records(): void
    {
        $inner = <<<XML
        <sit:situationRecord xsi:type="sit:MaintenanceWorks" id="r1"/>
        XML;

        $this->assertNull((new DatexSituationMapper())->map($this->situation($inner, 'test')));
    }

    public function test_public_event_maps_to_event_kind(): void
    {
        $inner = <<<XML
        <sit:situationRecord xsi:type="sit:PublicEvent" id="r1">
          <com:informationStatus>real</com:informationStatus>
          <sit:cause><sit:causeType>publicEvent</sit:causeType></sit:cause>
        </sit:situationRecord>
        XML;

        $m = (new DatexSituationMapper())->map($this->situation($inner));
        $this->assertSame('EVENT', $m->kind);
    }
}
