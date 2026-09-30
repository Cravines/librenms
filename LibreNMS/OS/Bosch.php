<?php

/** Bosch.php
 *
 * Bosch *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 */

namespace LibreNMS\OS;

use App\Models\Device;
use App\Models\EntPhysical;
use Illuminate\Support\Collection;
use LibreNMS\Interfaces\Discovery\OSDiscovery;
use LibreNMS\OS;
use SnmpQuery;

class Bosch extends OS implements OSDiscovery
{
    public function discoverOS(Device $device): void
    {
        parent::discoverOS($device); //yaml

        $device->serial = preg_replace('/(?<zero>0)(?<digit>\d)|(?<blank>\s)|(?<end>\X)/', '\\2', $device->serial);
        
            if (!empty($device->version)) {
            $digits = preg_replace('/[^0-9]/', '', $device->version);
            $padded = str_pad($digits, 8, '0', STR_PAD_LEFT);

            $chunks = str_split($padded, 2);

            $device->version = sprintf(
                "%d.%d.00%s",
                (int)($chunks[2] ?? 0),   // Major (Index 2)
                (int)($chunks[3] ?? 0),   // Minor (Index 3)
                $chunks[0] ?? '00'        // Build (Index 0)
            );
        }
    }

    public function discoverEntityPhysical(): Collection
    {
        $inventory = new Collection;
        $response = SnmpQuery::get('BSS-RCP-MIB::serial-number.0');
        $inventory->push(new EntPhysical([
            'entPhysicalIndex' => 1,
            'entPhysicalDescr' => SnmpQuery::get('BSS-RCP-MIB::oem-device-name.0')->value(),
            'entPhysicalClass' => SnmpQuery::get('ENTITY-MIB::entPhysicalClass.1')->value(),
            'entPhysicalName' => SnmpQuery::get('BSS-RCP-MIB::unit-name.0')->value(),
            'entPhysicalModelName' => SnmpQuery::get('BSS-RCP-MIB::oem-device-name.0')->value(),
            'entPhysicalSerialNum' => preg_replace('/(?<zero>0)(?<digit>\d)|(?<blank>\s)|(?<end>\X)/', '\\2', (string) $response->value('BSS-RCP-MIB::serial-number.0')),
            'entPhysicalMfgName' => SnmpQuery::get('BSS-RCP-MIB::manufacturer-name.0')->value(),
            'entPhysicalAlias' => SnmpQuery::get('BSS-RCP-MIB::mac-address.0')->value(),
        ]));

        return $inventory;
    }
}