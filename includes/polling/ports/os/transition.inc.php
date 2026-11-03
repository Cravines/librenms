<?php

/*
 * Name ports "Port 1", "Port 2" on the models that report the port description as
 * ifName and ifDescr. See LibreNMS\OS\Transition::applyCliPortNames().
 *
 * These models leave ifAlias empty, and the poller fills it from ifDescr, so the
 * description has to be moved to ifAlias here or it would be lost. Its trailing dot
 * is removed, and a port with no description (just a dot) uses its name, like v8 firmware.
 */

if ($os->usesCli()) {
    foreach (array_keys($port_stats) as $ifIndex) {
        if (is_numeric($ifIndex)) {
            $alias = ($port_stats[$ifIndex]['ifAlias'] ?? '') ?: ($port_stats[$ifIndex]['ifDescr'] ?? '');
            $port_stats[$ifIndex]['ifAlias'] = rtrim($alias, '.') ?: "Port $ifIndex";
            $port_stats[$ifIndex]['ifName'] = "Port $ifIndex";
            $port_stats[$ifIndex]['ifDescr'] = "Port $ifIndex";
        }
    }
    unset($ifIndex, $alias);
}
