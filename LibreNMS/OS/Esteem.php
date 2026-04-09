<?php
namespace LibreNMS\OS;

use LibreNMS\Device\WirelessSensor;
use LibreNMS\Enum\WirelessSensorType;
use LibreNMS\Interfaces\Discovery\Sensors\WirelessClientsDiscovery;
use LibreNMS\Interfaces\Discovery\Sensors\WirelessFrequencyDiscovery;
use LibreNMS\Interfaces\Discovery\Sensors\WirelessNoiseFloorDiscovery;
use LibreNMS\Interfaces\Discovery\Sensors\WirelessPowerDiscovery;
use LibreNMS\Interfaces\Discovery\Sensors\WirelessRateDiscovery;
use LibreNMS\OS;
use LibreNMS\Interfaces\Discovery\OSDiscovery;
use App\Models\Device;
class Esteem extends OS implements
    WirelessClientsDiscovery,
    WirelessFrequencyDiscovery,
    WirelessNoiseFloorDiscovery,
    WirelessPowerDiscovery,
    WirelessRateDiscovery,
    OSDiscovery
{
    public function discoverOS(Device $device): void
    {
        parent::discoverOS($device); //yaml
    }

    /**
    * Discover wireless frequency.  This is in Hz. Type is frequency.
    * Returns an array of LibreNMS\Device\Sensor objects that have been discovered
    *
    * @return array Sensors
    */
    public function discoverWirelessFrequency()
    {
        $oid = '.1.3.6.1.4.1.32079.2.2.1.6.1'; //EST-MIB::wBandwidth.1

        return [
            new WirelessSensor(WirelessSensorType::Frequency, $this->getDeviceId(), $oid, 'esteem', 1, 'Radio Frequency'),
        ];
    }

    /**
    * Discover wireless client counts. Type is clients.
    * Returns an array of LibreNMS\Device\Sensor objects that have been discovered
    *
    * @return array Sensors
    */
    public function discoverWirelessClients()
    {
        $counts = $this->getCacheByIndex('wirelessPeersNumber', 'EST-MIB');
        if (empty($counts)) {
            return []; //no counts to be had
        }

        $sensors = [];
        $total_oids = [];
        $total = 0;
        foreach ($counts as $index => $count) {
            $oid = '.1.3.6.1.4.1.32079.2.3.' .$index;
            $total_oids[] = $oid;
            $total += $count;

            $sensors[] = new WirelessSensor(
                WirelessSensorType::Clients,
                $this->getDeviceID(),
                $oid,
                'esteem',
                $index,
                $count
            );
        }

        return $sensors;
    }

    /**
     * Discover wireless noise floor. This is in dBm/Hz. Type is noise-floor.
     * Returns an array of LibreNMS\Device\Sensor objects that have been discovered
     *
     * @return array
     */
    public function discoverWirelessNoiseFloor()
    {
        $oid = '.1.3.6.1.4.1.32079.2.4.1.12.1'; //EST-MIB::pNoise.1

        return [
            new WirelessSensor(WirelessSensorType::NoiseFloor, $this->getDeviceId(), $oid, 'esteem', 1, 'Noise Floor'),
        ];
    }

    /**
     * Discover wireless tx or rx power. This is in dBm. Type is power.
     * Returns an array of LibreNMS\Device\Sensor objects that have been discovered
     *
     * @return array
     */
    public function discoverWirelessPower()
    {
        
        $rx_oid = '.1.3.6.1.4.1.32079.2.4.1.11.1'; //EST-MIB::pSignal.1

        return [
            new WirelessSensor(WirelessSensorType::Power, $this->getDeviceId(), $rx_oid, 'esteem-rx', 1, 'Signal Level'),
        ];
    }

    /**
     * Discover wireless rate. This is in bps. Type is rate.
     * Returns an array of LibreNMS\Device\Sensor objects that have been discovered
     *
     * @return array
     */
    public function discoverWirelessRate()
    {
        $rx_oid = '.1.3.6.1.4.1.32079.2.4.1.23.1'; //EST-MIB::pRate.1

        return [
            new WirelessSensor(WirelessSensorType::Rate, $this->getDeviceId(), $rx_oid, 'esteem-rx', 1, 'Rx Rate'),
        ];
    }
}