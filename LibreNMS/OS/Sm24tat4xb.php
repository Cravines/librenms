<?php

/**
 * Sm24tat4xb.php
 *
 * Transition Networks
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

use LibreNMS\Device\Processor;
use LibreNMS\Interfaces\Discovery\ProcessorDiscovery;
use LibreNMS\Interfaces\Polling\ProcessorPolling;
use LibreNMS\OS;
use App\Models\Device;
use App\Models\EntPhysical;
use Illuminate\Support\Collection;
use LibreNMS\Util\Mac;
use LibreNMS\Interfaces\Discovery\OSDiscovery;
use SnmpQuery;

class Sm24tat4xb extends OS implements ProcessorDiscovery, ProcessorPolling, OSDiscovery, Mac
{
    public function discoverOS(Device $device): void
    {
        parent::discoverOS($device); //yaml
    }

    private string $procOid = '.1.3.6.1.4.1.868.2.77.2.1.1.1.24.0';

    //OID string value example: 100ms:87%, 1s:49%, 10s:42%
    private function convertProcessorData(array $input)
    {
        $data = [];
        $cpuList = explode(',', (string) reset($input)[0]);
        foreach ($cpuList as $cpuPart) {
            $cpuValues = explode(':', $cpuPart);
            $cpuName = trim($cpuValues[0]);
            $cpuPerc = str_replace('%', '', $cpuValues[1]);
            $data[$cpuName] = $cpuPerc;
        }

        return $data;
    }

    public function discoverProcessors()
    {
        $data = snmpwalk_array_num($this->getDeviceArray(), $this->procOid);
        if ($data === false) {
            return [];
        }

        $processors = [];
        $count = 0;
        foreach ($this->convertProcessorData($data) as $cpuName => $cpuPerc) {
            $processors[] = Processor::discover(
                'sm24tat4xb',
                $this->getDeviceId(),
                $this->procOid,
                $count,
                'CPU ' . $cpuName,
                1,
                $cpuPerc,
                100
            );
            $count++;
        }

        return $processors;
    }

    public function pollProcessors(array $processors)
    {
        $data = snmpwalk_array_num($this->getDeviceArray(), $this->procOid);
        if (get_debug_type($data) != 'array') {
            return [];
        }

        $cpuList = $this->convertProcessorData($data);

        $data = [];
        foreach ($processors as $processor) {
            $processor_id = $processor['processor_id'];
            $key = explode(' ', (string) $processor['processor_descr'])[1];
            $value = $cpuList[$key];
            $data[$processor_id] = $value;
        }

        return $data;
    }

    public function discoverEntityPhysical(): Collection
    {
        $inventory = new Collection;

        $inventory->push(new EntPhysical([
            'entPhysicalIndex' => 1,
            'entPhysicalDescr' => SnmpQuery::get('SM24TAT4XB-MIB::sm24tat4xbSystemInfoSystemDescript.0')->value(),
            'entPhysicalClass' => SnmpQuery::get('ENTITY-MIB::entPhysicalClass.1')->value(),
            'entPhysicalName' => SnmpQuery::get('SM24TAT4XB-MIB::sm24tat4xbSystemInfoSystemName.0')->value(),
            'entPhysicalModelName' => SnmpQuery::get('SM24TAT4XB-MIB::sm24tat4xbSystemInfoModelName.0')->value(),
            'entPhysicalSerialNum' => SnmpQuery::get('SM24TAT4XB-MIB::sm24tat4xbSystemInfoSeriesNumber.0')->value(),
            'entPhysicalMfgName' => 'Transition',
        ]));

        return $inventory;
    }
}
