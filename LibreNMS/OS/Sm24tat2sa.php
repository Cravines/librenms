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
use SnmpQuery;

class Sm24tat2sa extends OS implements OSDiscovery
{
    public function discoverOS(Device $device): void
    {
        parent::discoverOS($device); //yaml
    }
}
