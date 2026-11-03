<?php

namespace LibreNMS\OS;

use App\Facades\LibrenmsConfig;
use App\Facades\PortCache;
use App\Models\Device;
use App\Models\EntPhysical;
use App\Models\Ipv4Mac;
use App\Models\Processor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LibreNMS\Discovery\Neighbors\Neighbor;
use LibreNMS\Discovery\Neighbors\NeighborParser;
use LibreNMS\Enum\LldpPortIdSubtype;
use LibreNMS\Interfaces\Discovery\ArpTableDiscovery;
use LibreNMS\Interfaces\Discovery\OSDiscovery;
use LibreNMS\Interfaces\Discovery\ProcessorDiscovery;
use LibreNMS\Interfaces\Polling\ProcessorPolling;
use LibreNMS\Modules\ArpTable;
use LibreNMS\OS;
use LibreNMS\Util\Mac;
use SnmpQuery;

class Transition extends OS implements ArpTableDiscovery, ProcessorDiscovery, ProcessorPolling, OSDiscovery
{
    /** Models whose firmware (v1.04) needs the CLI for FDB and LLDP data. */
    private const CLI_MODELS = [
        '.1.3.6.1.4.1.868.2.71', // SM8TAT2SA
        '.1.3.6.1.4.1.868.2.72', // SM16TAT2SA
        '.1.3.6.1.4.1.868.2.73', // SM24TAT2SA
    ];

    /** @var array<string, string|null>|null CLI output by command, kept for one discovery run */
    private ?array $cliOutput = null;

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
            'TN-SM24DP4XA-FUNCTION-MIB::sm24dp4xaSystemInfoCPULoad.0',
            'SM48TAT4XARP-MIB::sm48tat4xarpSystemInfoCPULoad.0',
            'SISPM10403166L-MIB::sispm10403166lSystemInfoCPULoad.0',
        ];
        foreach ($oids as $oid) {
            $value = SnmpQuery::get($oid)->value();
            if ($value !== '') {
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
        if (! is_string($value) || $value === '') {
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
            $cpuPerc = trim($cpuValues[1], ' %');
            if ($cpuName === '' || ! is_numeric($cpuPerc)) {
                continue;
            }
            $processors[$cpuName] = (float) $cpuPerc;
        }

        return $processors;
    }

    /**
     * Discover processors.
     *
     * @return Collection<int, Processor>
     */
    public function discoverProcessors(): Collection
    {
        $procOid = $this->getProcessorOid();
        if ($procOid === null) {
            return collect();
        }
        $data = SnmpQuery::get($procOid)->value();
        if ($data === '') {
            return collect();
        }
        $cpuList = $this->convertProcessorData([$data]);
        if (empty($cpuList)) {
            return collect();
        }
        $processors = collect();
        $count = 0;
        foreach ($cpuList as $cpuName => $cpuPerc) {
            $processors->push(new Processor([
                'processor_type' => $this->getName(),
                'processor_oid' => $procOid,
                'processor_index' => $count,
                'processor_descr' => 'CPU ' . $cpuName,
                'processor_precision' => 1,
                'hrDeviceIndex' => null,
                'processor_perc_warn' => 100,
                'processor_usage' => $cpuPerc,
            ]));
            $count++;
        }

        return $processors;
    }

    /**
     * Poll processor data. Descriptions are generated as "CPU 100ms", "CPU 1s" and "CPU 10s".
     * Usage stays null on a processor that could not be polled.
     *
     * @param  Collection<int, Processor>  $processors
     * @return Collection<int, Processor>
     */
    public function pollProcessors(Collection $processors): Collection
    {
        $procOid = $this->getProcessorOid();
        if ($procOid === null) {
            return $processors;
        }

        $cpuList = $this->convertProcessorData([SnmpQuery::get($procOid)->value()]);

        foreach ($processors as $processor) {
            $cpuName = explode(' ', (string) $processor->processor_descr, 2)[1] ?? '';
            $processor->processor_usage = $cpuList[$cpuName] ?? null;
        }

        return $processors;
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
                'TN-SM24DP4XA-FUNCTION-MIB::sm24dp4xaSystemInfoSystemDescript.0',
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
                'TN-SM24DP4XA-FUNCTION-MIB::sm24dp4xaSystemInfoSystemName.0',
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
                'TN-SM24DP4XA-FUNCTION-MIB::sm24dp4xaSystemInfoHardwareVersion.0',
                'SM48TAT4XARP-MIB::sm48tat4xarpSystemInfoHardwareVersion.0',
                'SISPM10403166L-MIB::sispm10403166lSystemInfoHardwareVersion.0',
                'ENTITY-MIB::entPhysicalHardwareRev.1',
            ]),
            'entPhysicalFirmwareRev' => $this->getFirstValue([
                'SM8TAT2SA-MIB::sm8tat2saSystemInfoFirmwareVersion.0',
                'SM16TAT2SA-MIB::sm16tat2saSystemInfoFirmwareVersion.0',
                'SM24TAT2SA-MIB::sm24tat2saSystemInfoFirmwareVersion.0',
                'SM24TAT4XB-MIB::sm24tat4xbSystemInfoFirmwareVersion.0',
                'TN-SM24DP4XA-FUNCTION-MIB::sm24dp4xaSystemInfoFirmwareVersion.0',
                'SM48TAT4XARP-MIB::sm48tat4xarpSystemInfoFirmwareVersion.0',
                'SISPM10403166L-MIB::sispm10403166lSystemInfoFirmwareVersion.0',
                'ENTITY-MIB::entPhysicalFirmwareRev.1',
            ]),
            'entPhysicalSoftwareRev' => $this->getFirstValue([
                'SM8TAT2SA-MIB::sm8tat2saSystemInfoMechanicalVersion.0',
                'SM16TAT2SA-MIB::sm16tat2saSystemInfoMechanicalVersion.0',
                'SM24TAT2SA-MIB::sm24tat2saSystemInfoMechanicalVersion.0',
                'SM24TAT4XB-MIB::sm24tat4xbSystemInfoMechanicalVersion.0',
                'TN-SM24DP4XA-FUNCTION-MIB::sm24dp4xaSystemInfoMechanicalVersion.0',
                'SM48TAT4XARP-MIB::sm48tat4xarpSystemInfoMechanicalVersion.0',
                'SISPM10403166L-MIB::sispm10403166lSystemInfoMechanicalVersion.0',
                'ENTITY-MIB::entPhysicalSoftwareRev.1',
            ]),
            'entPhysicalModelName' => $this->getFirstValue([
                'SM8TAT2SA-MIB::sm8tat2saSystemInfoModelName.0',
                'SM16TAT2SA-MIB::sm16tat2saSystemInfoModelName.0',
                'SM24TAT2SA-MIB::sm24tat2saSystemInfoModelName.0',
                'SM24TAT4XB-MIB::sm24tat4xbSystemInfoModelName.0',
                'TN-SM24DP4XA-FUNCTION-MIB::sm24dp4xaSystemInfoModelName.0',
                'SM48TAT4XARP-MIB::sm48tat4xarpSystemInfoModelName.0',
                'SISPM10403166L-MIB::sispm10403166lSystemInfoModelName.0',
                'ENTITY-MIB::entPhysicalModelName.1',
            ]),
            'entPhysicalSerialNum' => $this->getFirstValue([
                'SM8TAT2SA-MIB::sm8tat2saSystemInfoSeriesNumber.0',
                'SM16TAT2SA-MIB::sm16tat2saSystemInfoSeriesNumber.0',
                'SM24TAT2SA-MIB::sm24tat2saSystemInfoSeriesNumber.0',
                'SM24TAT4XB-MIB::sm24tat4xbSystemInfoSeriesNumber.0',
                'TN-SM24DP4XA-FUNCTION-MIB::sm24dp4xaSystemInfoSerialNumber.0',
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
                'TN-SM24DP4XA-FUNCTION-MIB::sm24dp4xaSystemInfoHostMACAddress.0',
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
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function usesCli(): bool
    {
        return in_array($this->getDevice()->sysObjectID, self::CLI_MODELS, true);
    }

    /**
     * Discover neighbors from the CLI on models whose SNMP LLDP data is unreliable.
     * Falls back to SNMP when the CLI gives nothing, because an empty result would
     * make the discovery-protocols module delete this device's existing links.
     */
    public function discoverNeighbors(): Collection
    {
        if ($this->usesCli()) {
            $this->applyCliPortNames();
            $neighbors = $this->parseCliNeighbors((string) $this->cliCommand('show lldp neighbors'));

            if ($neighbors->isNotEmpty()) {
                return $neighbors;
            }
        }

        return parent::discoverNeighbors();
    }

    /**
     * Name ports "Port 1", "Port 2" like v8 firmware. These models report the port description
     * as ifName and ifDescr too, and the description stays in ifAlias. Its trailing dot is
     * removed, and a port with no description (just a dot) uses its name, like v8 firmware.
     *
     * The ports module writes the raw SNMP names back on every discovery, and has no hook for
     * this OS without editing core. This runs after it, as discovery-protocols depends on ports,
     * so the poller (includes/polling/ports/os/transition.inc.php) finds nothing to change.
     */
    private function applyCliPortNames(): void
    {
        DB::table('ports')
            ->where('device_id', $this->getDeviceId())
            ->whereNotNull('ifIndex')
            ->update([
                'ifName' => DB::raw("CONCAT('Port ', ifIndex)"),
                'ifDescr' => DB::raw("CONCAT('Port ', ifIndex)"),
                'ifAlias' => DB::raw("COALESCE(NULLIF(TRIM(TRAILING '.' FROM ifAlias), ''), CONCAT('Port ', ifIndex))"),
            ]);
    }

    /**
     * Parse "show lldp neighbors" output, which is one block of "Key : Value" lines per
     * neighbor. Local ports are printed as "Port 3", which is ifIndex 3 on these switches.
     *
     * @return Collection<int, Neighbor>
     */
    public function parseCliNeighbors(string $output): Collection
    {
        $neighbors = new Collection;

        foreach (preg_split('/^-{5,}\s*$/m', $output) as $block) {
            $fields = [];
            foreach (preg_split('/\r?\n/', $block) as $line) {
                if (preg_match('/^([A-Za-z ]+?)\s*:\s*(.*?)\s*$/', $line, $m)) {
                    $fields[$m[1]] = $m[2];
                }
            }

            if (! preg_match('/^Port (\d+)$/', $fields['Local Interface'] ?? '', $local)) {
                continue;
            }

            $portId = $fields['Port ID'] ?? '';
            $portMac = NeighborParser::parseMac($this->cliMac($portId));

            $neighbors->push(new Neighbor(
                protocol: 'lldp',
                localPortId: PortCache::getIdFromIfIndex((int) $local[1], $this->getDeviceId()),
                sysName: NeighborParser::parseName($fields['System Name'] ?? ''),
                sysDescr: NeighborParser::parseText($fields['System Description'] ?? ''),
                managementIp: preg_match('/^(\S+) \(IPv[46]\)/', $fields['Management Address'] ?? '', $ip) ? $ip[1] : null,
                chassisMac: NeighborParser::parseMac($this->cliMac($fields['Chassis ID'] ?? '')),
                portId: $portMac ?? $portId,
                portIdSubtype: $portMac !== null ? LldpPortIdSubtype::MacAddress : LldpPortIdSubtype::Local,
                portDescr: NeighborParser::parseText($fields['Port Description'] ?? ''),
                portMac: $portMac,
            ));
        }

        return $neighbors;
    }

    /**
     * Implementing ArpTableDiscovery replaces the default IP-MIB discovery for every model,
     * so models with working SNMP call it directly. It is private in the core module, and
     * this avoids both editing core and keeping a copy of it here.
     *
     * The sync deletes anything missing from the result, so a failed CLI session returns
     * the existing rows to keep them.
     *
     * @return Collection<int, Ipv4Mac>
     */
    public function discoverArpTable(): Collection
    {
        if (! $this->usesCli()) {
            return (new \ReflectionMethod(ArpTable::class, 'discoverArpFromIpMib'))->invoke(new ArpTable, $this->getDevice());
        }

        $output = $this->cliCommand('show ip arp');

        return $output === null ? $this->getDevice()->macs : $this->parseCliArp($output);
    }

    /**
     * Parse "show ip arp" output, one entry per line:
     *   192.168.12.5 via VLAN1:54-b2-03-08-fc-e8
     * These models have no port for the VLAN interface, so each entry uses the port the
     * FDB table learned its mac on. Entries not in the FDB table are skipped, as ipv4_mac
     * rows need a port.
     *
     * @return Collection<int, Ipv4Mac>
     */
    public function parseCliArp(string $output): Collection
    {
        preg_match_all('/^(\d+\.\d+\.\d+\.\d+)\s+via\s+\S+:((?:[0-9a-f]{2}-){5}[0-9a-f]{2})\s*$/mi', $output, $matches, PREG_SET_ORDER);

        $fdb = $this->getDevice()->portsFdb()->pluck('port_id', 'mac_address');
        $arp = new Collection;

        foreach ($matches as [, $ip, $rawMac]) {
            $mac = Mac::parse(str_replace('-', ':', $rawMac))->hex();

            if (! isset($fdb[$mac])) {
                Log::debug("Transition CLI: no FDB port for ARP entry $ip ($mac), skipping");
                continue;
            }

            $arp->push(new Ipv4Mac([
                'port_id' => $fdb[$mac],
                'mac_address' => $mac,
                'ipv4_address' => $ip,
                'context_name' => '',
            ]));
        }

        return $arp;
    }

    /**
     * Get the output of a CLI command for this discovery run. The first call logs in once,
     * runs every command, logs out, and keeps the output for the other discovery modules,
     * which all share this object. Credentials come from the fdb_cli settings in config.php.
     *
     * @return string|null the command output, or null if it could not be retrieved
     */
    public function cliCommand(string $command): ?string
    {
        $this->cliOutput ??= $this->cliFetchAll();

        return $this->cliOutput[$command] ?? null;
    }

    /**
     * @return string the command that lists the MAC address table
     */
    public function cliFdbCommand(): string
    {
        return (string) LibrenmsConfig::get('fdb_cli.command', 'show mac address-table');
    }

    /**
     * Run all CLI commands in one session of the switch's interactive shell.
     *
     * @return array<string, string|null> output by command, null for commands that failed
     */
    private function cliFetchAll(): array
    {
        // phpseclib comes from other packages' dependencies, so its major version can change.
        // Versions 2 to 4 share this API, only the namespace differs.
        $class = Collection::make(['phpseclib4\Net\SSH2', 'phpseclib3\Net\SSH2', 'phpseclib\Net\SSH2'])
            ->first(fn (string $candidate) => class_exists($candidate));

        if ($class === null) {
            Log::warning('Transition CLI: no phpseclib SSH2 class found');

            return [];
        }

        $target = $this->getDevice()->pollerTarget();
        $output = [];
        $ssh = null;

        try {
            Log::debug("Transition CLI: logging in to $target");
            $ssh = new $class($target, 22, 15);

            if (! $ssh->login((string) LibrenmsConfig::get('fdb_cli.user'), (string) LibrenmsConfig::get('fdb_cli.pass'))) {
                Log::warning("Transition CLI: login failed for $target");

                return [];
            }

            // exec() hangs until timeout on these switches, so use the interactive shell.
            // The CLI has no working "terminal length 0", but has not paged this output.
            // A prompt is one word on its own line. The banner's "Press <Enter>" must not count.
            $ssh->setTimeout(20);
            $banner = (string) $ssh->read('/(?:^|\n)[^\s<>]+[#>]\s*$/', $class::READ_REGEX);
            $promptName = trim(Str::afterLast($banner, "\n"));

            if ($ssh->isTimeout() || $promptName === '') {
                Log::warning("Transition CLI: no prompt from $target");

                return [];
            }

            foreach ([$this->cliFdbCommand(), 'show lldp neighbors', 'show ip arp'] as $command) {
                // Match the exact prompt, as output can contain # or > at the end of a read.
                // Not anchored to a line start, as control characters can come before the prompt.
                $ssh->write($command . "\n");
                $result = (string) $ssh->read('/' . preg_quote($promptName, '/') . '\s*$/', $class::READ_REGEX);

                if ($ssh->isTimeout()) {
                    // the shell may still be busy with this command, so the next one can't be trusted
                    Log::warning("Transition CLI: '$command' timed out on $target, waiting for " . json_encode($promptName) . ', received ' . strlen($result) . ' bytes ending ' . json_encode(substr($result, -60)));
                    break;
                }

                if (str_contains($result, 'Syntax error')) {
                    Log::warning("Transition CLI: '$command' rejected by $target");
                    continue;
                }

                $output[$command] = $result;
            }
        } catch (\Throwable $e) {
            Log::warning("Transition CLI: $target: " . $e->getMessage());
        } finally {
            $this->cliLogout($ssh);
        }

        return $output;
    }

    /**
     * Log out so the switch frees the session. Disconnecting alone appears to leave it
     * held, which ends in "too many users" on the switch.
     */
    private function cliLogout(?object $ssh): void
    {
        if ($ssh === null) {
            return;
        }

        try {
            if ($ssh->isAuthenticated()) {
                $ssh->setTimeout(3);
                $ssh->write("exit\n");
                $ssh->read();
            }
            $ssh->disconnect();
        } catch (\Throwable $e) {
            Log::debug('Transition CLI: logout: ' . $e->getMessage());
        }
    }

    /**
     * The CLI prints macs as 00-07-5F-F8-53-39. Returns null for anything else, like a port number.
     */
    private function cliMac(string $value): ?string
    {
        return preg_match('/^(?:[0-9A-Fa-f]{2}-){5}[0-9A-Fa-f]{2}$/', $value) ? str_replace('-', ':', $value) : null;
    }
}
