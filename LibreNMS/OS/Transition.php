<?php

namespace LibreNMS\OS;

use App\Models\Device;
use App\Models\EntPhysical;
use Illuminate\Support\Collection;
use LibreNMS\Device\Processor;
use LibreNMS\Interfaces\Discovery\OSDiscovery;
use LibreNMS\Interfaces\Discovery\ProcessorDiscovery;
use LibreNMS\Interfaces\Polling\ProcessorPolling;
use LibreNMS\OS;
use SnmpQuery;

class Transition extends OS implements ProcessorDiscovery, ProcessorPolling, OSDiscovery
{
    /** CPU OID detected for this device.*/
    private ?string $procOid = null;

    public function discoverOS(Device $device): void
    {
        parent::discoverOS($device);
    }

    /** Find the CPU-load OID supported by this device.*/
    private function getProcessorOid(): ?string
    {
        if ($this->procOid !== null) {
            return $this->procOid;
        }
        $oids = [
            'SM24TAT4XB-MIB::sm24tat4xbSystemInfoCPULoad.0',
            'SM48TAT4XARP-MIB::sm48tat4xarpSystemInfoCPULoad.0',
            'SISPM10403166L-MIB::sispm10403166lSystemInfoCPULoad.0',
        ];
        foreach ($oids as $oid) {
            $value = SnmpQuery::get($oid)->value();
            if ($value !== null && $value !== false && $value !== '') {
                $this->procOid = $oid;

                return $this->procOid;
            }
        }

        return null;
    }

    /**
     * Parse the CPU-load string returned by the switch.
     * Example: 100ms:87%, 1s:49%, 10s:42%
     * Returns:
     * [
     *     '100ms' => 87,
     *     '1s'    => 49,
     *     '10s'   => 42,
     * ]
     */
    private function convertProcessorData(array $input): array
    {
        if (empty($input)) {
            return [];
        }
        $value = reset($input);
        if (is_array($value)) {
            $value = reset($value);
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $processors = [];
        foreach (explode(',', $value) as $cpuPart) {
            $cpuPart = trim($cpuPart);
            if ($cpuPart === '') {
                continue;
            }
            $cpuValues = explode(':', $cpuPart, 2);
            if (count($cpuValues) !== 2) {
                continue;
            }
            $cpuName = trim($cpuValues[0]);
            $cpuPerc = trim($cpuValues[1], " %");
            if ($cpuName === '' || !is_numeric($cpuPerc)) {
                continue;
            }
            $processors[$cpuName] = (float) $cpuPerc;
        }

        return $processors;
    }

    /**
     * Discover processors.
     *
     * @return array
     */
    public function discoverProcessors()
    {
        $procOid = $this->getProcessorOid();
        if ($procOid === null) {
            return [];
        }
        $data = snmpwalk_array_num(
            $this->getDeviceArray(),
            $procOid
        );
        if (!is_array($data)) {
            return [];
        }
        $cpuList = $this->convertProcessorData($data);
        if (empty($cpuList)) {
            return [];
        }
        $processors = [];
        $count = 0;
        foreach ($cpuList as $cpuName => $cpuPerc) {
            $processors[] = Processor::discover(
                $this->getName(),
                $this->getDeviceId(),
                $procOid,
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

    /**
     * Poll processor data.
     *
     * @param array $processors
     * @return array
     */
    public function pollProcessors(array $processors)
    {
        if (empty($processors)) {
            return [];
        }
        $procOid = $this->getProcessorOid();
        if ($procOid === null) {
            return [];
        }
        $data = snmpwalk_array_num(
            $this->getDeviceArray(),
            $procOid
        );
        if (!is_array($data)) {
            return [];
        }
        $cpuList = $this->convertProcessorData($data);
        if (empty($cpuList)) {
            return [];
        }
        $results = [];
        foreach ($processors as $processor) {
            $description = (string) $processor['processor_descr'];
            /*
             * Processor descriptions are generated as:
             *
             * CPU 100ms
             * CPU 1s
             * CPU 10s
             */
            $parts = explode(' ', $description, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $cpuName = $parts[1];
            if (!isset($cpuList[$cpuName])) {
                continue;
            }
            $results[$processor['processor_id']] = $cpuList[$cpuName];
        }

        return $results;
    }

    /**
     * Discover physical inventory information.
     */
    public function discoverEntityPhysical(): Collection
    {
        $inventory = new Collection;
        $inventory->push(new EntPhysical([
            'entPhysicalIndex' => 1,
            'entPhysicalDescr' => $this->getFirstValue([
                'SM8TAT2SA-MIB::sm8tat2saSystemInfoSystemDescript.0',
                'SM16TAT2SA-MIB::sm16tat2saSystemInfoSystemDescript.0',
                'SM24TAT2SA-MIB::sm24tat2saSystemInfoSystemDescript.0',
                'SM24TAT4XB-MIB::sm24tat4xbSystemInfoSystemDescript.0',
                'SM48TAT4XARP-MIB::sm48tat4xarpSystemInfoSystemDescript.0',
                'SISPM10403166L-MIB::sispm10403166lSystemInfoSystemDescript.0',
                'ENTITY-MIB::entPhysicalDescr.1',
                'SNMPv2-MIB::sysDescr.0',
            ]),
            'entPhysicalClass' => $this->getFirstValue([
                'ENTITY-MIB::entPhysicalClass.1',
            ]),
            'entPhysicalName' => $this->getFirstValue([
                'SM8TAT2SA-MIB::sm8tat2saSystemInfoSystemName.0',
                'SM16TAT2SA-MIB::sm16tat2saSystemInfoSystemName.0',
                'SM24TAT2SA-MIB::sm24tat2saSystemInfoSystemName.0',
                'SM24TAT4XB-MIB::sm24tat4xbSystemInfoSystemName.0',
                'SM48TAT4XARP-MIB::sm48tat4xarpSystemInfoSystemName.0',
                'SISPM10403166L-MIB::sispm10403166lSystemInfoSystemName.0',
                'ENTITY-MIB::entPhysicalName.1',
                'SNMPv2-MIB::sysName.0',
            ]),
            'entPhysicalHardwareRev' => $this->getFirstValue([
                'SM8TAT2SA-MIB::sm8tat2saSystemInfoHardwareVersion.0',
                'SM16TAT2SA-MIB::sm16tat2saSystemInfoHardwareVersion.0',
                'SM24TAT2SA-MIB::sm24tat2saSystemInfoHardwareVersion.0',
                'SM24TAT4XB-MIB::sm24tat4xbSystemInfoHardwareVersion.0',
                'SM48TAT4XARP-MIB::sm48tat4xarpSystemInfoHardwareVersion.0',
                'SISPM10403166L-MIB::sispm10403166lSystemInfoHardwareVersion.0',
                'ENTITY-MIB::entPhysicalHardwareRev.1',
            ]),
            'entPhysicalFirmwareRev' => $this->getFirstValue([
                'SM8TAT2SA-MIB::sm8tat2saSystemInfoFirmwareVersion.0',
                'SM16TAT2SA-MIB::sm16tat2saSystemInfoFirmwareVersion.0',
                'SM24TAT2SA-MIB::sm24tat2saSystemInfoFirmwareVersion.0',
                'SM24TAT4XB-MIB::sm24tat4xbSystemInfoFirmwareVersion.0',
                'SM48TAT4XARP-MIB::sm48tat4xarpSystemInfoFirmwareVersion.0',
                'SISPM10403166L-MIB::sispm10403166lSystemInfoFirmwareVersion.0',
                'ENTITY-MIB::entPhysicalFirmwareRev.1',
            ]),
            'entPhysicalSoftwareRev' => $this->getFirstValue([
                'SM8TAT2SA-MIB::sm8tat2saSystemInfoMechanicalVersion.0',
                'SM16TAT2SA-MIB::sm16tat2saSystemInfoMechanicalVersion.0',
                'SM24TAT2SA-MIB::sm24tat2saSystemInfoMechanicalVersion.0',
                'SM24TAT4XB-MIB::sm24tat4xbSystemInfoMechanicalVersion.0',
                'SM48TAT4XARP-MIB::sm48tat4xarpSystemInfoMechanicalVersion.0',
                'SISPM10403166L-MIB::sispm10403166lSystemInfoMechanicalVersion.0',
                'ENTITY-MIB::entPhysicalSoftwareRev.1',
            ]),
            'entPhysicalModelName' => $this->getFirstValue([
                'SM8TAT2SA-MIB::sm8tat2saSystemInfoModelName.0',
                'SM16TAT2SA-MIB::sm16tat2saSystemInfoModelName.0',
                'SM24TAT2SA-MIB::sm24tat2saSystemInfoModelName.0',
                'SM24TAT4XB-MIB::sm24tat4xbSystemInfoModelName.0',
                'SM48TAT4XARP-MIB::sm48tat4xarpSystemInfoModelName.0',
                'SISPM10403166L-MIB::sispm10403166lSystemInfoModelName.0',
                'ENTITY-MIB::entPhysicalModelName.1',
            ]),
            'entPhysicalSerialNum' => $this->getFirstValue([
                'SM8TAT2SA-MIB::sm8tat2saSystemInfoSeriesNumber.0',
                'SM16TAT2SA-MIB::sm16tat2saSystemInfoSeriesNumber.0',
                'SM24TAT2SA-MIB::sm24tat2saSystemInfoSeriesNumber.0',
                'SM24TAT4XB-MIB::sm24tat4xbSystemInfoSeriesNumber.0',
                'SM48TAT4XARP-MIB::sm48tat4xarpSystemInfoSeriesNumber.0',
                'SISPM10403166L-MIB::sispm10403166lSystemInfoSeriesNumber.0',
                'ENTITY-MIB::entPhysicalSerialNum.1',
            ]),
            'entPhysicalMfgName' => 'Transition',
            'entPhysicalAlias' => $this->getFirstValue([
                'SM8TAT2SA-MIB::sm8tat2saSystemInfoHostMACAddress.0',
                'SM16TAT2SA-MIB::sm16tat2saSystemInfoHostMACAddress.0',
                'SM24TAT2SA-MIB::sm24tat2saSystemInfoHostMACAddress.0',
                'SM24TAT4XB-MIB::sm24tat4xbSystemInfoHostMACAddress.0',
                'SM48TAT4XARP-MIB::sm48tat4xarpSystemInfoHostMACAddress.0',
                'SISPM10403166L-MIB::sispm10403166lSystemInfoHostMACAddress.0',
                'ENTITY-MIB::entPhysicalAlias.1',
            ]),
        ]));

        return $inventory;
    }

    /**
     * Return the first non-empty SNMP value from a list of OIDs.
     */
    private function getFirstValue(array $oids): mixed
    {
        foreach ($oids as $oid) {
            $value = SnmpQuery::get($oid)->value();
            if ($value !== null && $value !== false && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}