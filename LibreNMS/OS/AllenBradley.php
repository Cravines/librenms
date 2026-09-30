<?php

/**
 * AllenBradley.php
 */

namespace LibreNMS\OS;

use LibreNMS\OS;
use App\Models\Device;
use LibreNMS\Interfaces\Discovery\OSDiscovery;

class AllenBradley extends OS implements OSDiscovery
{
    public function discoverOS(Device $device): void
    {
        parent::discoverOS($device); //yaml
    }
}
