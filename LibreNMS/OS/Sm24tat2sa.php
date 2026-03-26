<?php

/**
 * Sm24tat2sa.php
 *
 * Transition Networks
 */

namespace LibreNMS\OS;

use LibreNMS\OS;
use App\Models\Device;
use LibreNMS\Interfaces\Discovery\OSDiscovery;
use App\Models\EntPhysical;
use Illuminate\Support\Collection;
use LibreNMS\Util\Mac;
use SnmpQuery;

class Sm24tat2sa extends OS implements OSDiscovery
{
    public function discoverOS(Device $device): void
    {
        parent::discoverOS($device); //yaml
    }

    public function discoverEntityPhysical(): Collection
    {
        $inventory = new Collection;

        $inventory->push(new EntPhysical([
            'entPhysicalIndex' => 1,
            'entPhysicalDescr' => SnmpQuery::get('SM24TAT2SA-MIB::sm24tat2saSystemInfoSystemDescript.0')->value(),
            'entPhysicalClass' => SnmpQuery::get('ENTITY-MIB::entPhysicalClass.1')->value(),
            'entPhysicalName' => SnmpQuery::get('SM24TAT2SA-MIB::sm24tat2saSystemInfoSystemName.0')->value(),
            'entPhysicalModelName' => SnmpQuery::get('SM24TAT2SA-MIB::sm24tat2saSystemInfoModelName.0')->value(),
            'entPhysicalSerialNum' => SnmpQuery::get('SM24TAT2SA-MIB::sm24tat2saSystemInfoSeriesNumber.0')->value(),
            'entPhysicalMfgName' => 'Transition',
        ]));

        return $inventory;
    }
}
