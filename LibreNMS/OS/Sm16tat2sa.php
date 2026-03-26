<?php

/**
 * Sm16tat2sa.php
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

class Sm16tat2sa extends OS implements OSDiscovery
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
            'entPhysicalDescr' => SnmpQuery::get('SM16TAT2SA-MIB::sm16tat2saSystemInfoSystemDescript.0')->value(),
            'entPhysicalClass' => SnmpQuery::get('ENTITY-MIB::entPhysicalClass.1')->value(),
            'entPhysicalName' => SnmpQuery::get('SM16TAT2SA-MIB::sm16tat2saSystemInfoSystemName.0')->value(),
            'entPhysicalModelName' => SnmpQuery::get('SM16TAT2SA-MIB::sm16tat2saSystemInfoModelName.0')->value(),
            'entPhysicalSerialNum' => SnmpQuery::get('SM16TAT2SA-MIB::sm16tat2saSystemInfoSeriesNumber.0')->value(),
            'entPhysicalMfgName' => 'Transition',
        ]));

        return $inventory;
    }
}