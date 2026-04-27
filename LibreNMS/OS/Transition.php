<?php

/**
namespace LibreNMS\OS;

use App\Models\Device;
use App\Models\EntPhysical;
use Illuminate\Support\Collection;
use LibreNMS\Device\Processor;
use LibreNMS\Interfaces\Discovery\OSDiscovery;
use LibreNMS\Interfaces\Discovery\ProcessorDiscovery;
use LibreNMS\Interfaces\Polling\ProcessorPolling;
use LibreNMS\OS;
use LibreNMS\Util\Mac;
use LibreNMS\Util\Oid;
use SnmpQuery;

class Transition extends OS implements ProcessorDiscovery, ProcessorPolling, OSDiscovery
{
    public function discoverOS(Device $device): void
    {
        SnmpQuery::get(Oid::get('sysObjectID'))->then(function ($oid) use ($device) {
            $device->sysObjectID = $oid->value;
        });
        if ($sysObjectID === '.1.3.6.1.4.1.868.2.71'){
            return 'sm8tat2sa';
        }
        elseif ($sysObjectID === '.1.3.6.1.4.1.868.2.72'){
            return 'sm16tat2sa';
        }
        elseif ($sysObjectID === '.1.3.6.1.4.1.868.2.73'){
            return 'sm24tat2sa';
        }
        elseif ($sysObjectID === '.1.3.6.1.4.1.868.2.77.2'){
            return 'sm24tat4xb';
        }
        elseif ($sysObjectID === '.1.3.6.1.4.1.868.2.77.4'){
            return 'sm24tat4xarp';
        }
        elseif ($sysObjectID === '.1.3.6.1.4.1.868.2.80'){
            return 'sispm10403166l';
        }
        else{
            return null;
        }
        $device->hardware = $this->getHardware();
        $device->serial = $this->getSerial();
        $device->save();

    }
} */
