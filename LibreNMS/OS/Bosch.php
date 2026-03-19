<?php

/**
 * Bosch.php
 *
 * Bosch
 *
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

use LibreNMS\OS;
use App\Models\Device;
use LibreNMS\Interfaces\Discovery\OSDiscovery;
use LibreNMS\Util\StringHelpers;
use SnmpQuery;

class Bosch extends OS implements OSDiscovery
{  
      
    public function discoverOS(Device $device): void
    {
        parent::discoverOS($device); //yaml
        
        $response = SnmpQuery::get('BSS-RCP-MIB::serial-number.0');

        $device->serial = preg_replace('/(?<zero>0)(?<digit>\d)|(?<blank>\s)|(?<end>\X)/', '\\2', $response->value('BSS-RCP-MIB::serial-number.0')) ?: null;
    }
}
/**        $pattern = '/(?<zero>0)(?<digit>\d)(?<blank>\ )/';
        $replacement = '\\2';
        $subject = $response; */