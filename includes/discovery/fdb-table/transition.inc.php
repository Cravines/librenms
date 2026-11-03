<?php

/*
 * FDB discovery over SSH/CLI.
 *
 * Loaded by includes/discovery/fdb-table.inc.php for devices with the transition OS.
 *
 * Contract: populate $insert[$vlan_db_id][$mac] = ['port_id' => $port_id];
 *   - $vlan_db_id is vlans.vlan_id (DB id), 0 if there is no matching VLAN
 *   - $mac is lowercase hex, no separators
 *   - $vlans_dict (vlan number => vlans.vlan_id) is defined by the parent file
 *
 * config.php:
 *   $config['fdb_cli']['user']    = 'admin';
 *   $config['fdb_cli']['pass']    = '...';
 *   $config['fdb_cli']['command'] = 'show mac address-table';   // optional
 */

// Only some models need the CLI. Returning leaves $insert empty, so every other
// model uses the parent file's normal SNMP bridge walk.
if (! $os->usesCli()) {
    d_echo("FDB CLI: not a CLI model, using SNMP\n");

    return;
}

// Map switch port numbers to port_id. ifName and ifDescr hold user-set descriptions
// on these switches ("190-SW1."), so "GigabitEthernet 1/25" is matched to ifIndex 25.
$port_map = \App\Models\Port::where('device_id', $device['device_id'])
    ->where('deleted', 0)
    ->whereNotNull('ifIndex')
    ->pluck('port_id', 'ifIndex');

// Get the MAC table from the CLI. See LibreNMS\OS\Transition::cliCommand().
$output = (string) $os->cliCommand($os->cliFdbCommand());

d_echo("FDB CLI raw output:\n" . $output . "\n");

// Parse. Output format (CRLF line endings, port name contains a space):
//   Type    VID  MAC Address        Block Ports
//   Dynamic 1    48-9E-BD-9E-41-74  No    GigabitEthernet 1/25
//   Static  1    00-C0-F2-9B-F4-40  No    CPU
// The prompt, command echo, header and separator lines never start with Dynamic/Static.
foreach (preg_split('/\r?\n/', $output) as $line) {
    if (! preg_match('/^(Dynamic|Static)\s+(\d+)\s+((?:[0-9A-F]{2}-){5}[0-9A-F]{2})\s+(?:Yes|No)\s+(.+?)\s*$/i', $line, $m)) {
        continue;
    }

    $mac = strtolower(str_replace('-', '', $m[3]));
    // The CPU entry and any other non-port name have no port_id and are skipped.
    $port_id = preg_match('#^GigabitEthernet\s+1/(\d+)$#i', $m[4], $pm) ? ($port_map[(int) $pm[1]] ?? null) : null;

    if ($port_id === null) {
        d_echo("FDB CLI: no port match for '{$m[4]}', skipping $mac\n");
        continue;
    }

    $vlan_id = $vlans_dict[(int) $m[2]] ?? 0;
    $insert[$vlan_id][$mac] = ['port_id' => $port_id];
}

unset($output, $port_map, $line, $m, $pm, $mac, $port_id, $vlan_id);
