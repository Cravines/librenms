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
use LibreNMS\Interfaces\Discovery\OSDiscovery;
use SnmpQuery;

class Sm24tat4xb extends OS implements ProcessorDiscovery, ProcessorPolling, OSDiscovery
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
            return[];
	}

	$processors = [];
	$count = 0;
	foreach ($this->convertProcessorData($data) as $cpuName => $cpuPerc) {
	    $processors [] = Processor::discover(
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
    function process_syslog($entry, $update)
    {
        global $dev_cache;

        foreach (LibrenmsConfig::get('syslog_filter') as $bi) {
            if (str_contains((string) $entry['msg'], $bi)) {
                return $entry;
            }  
        }

        $entry['host'] = preg_replace('/^::ffff:/', '', (string) $entry['host']);
        $syslog_xlate = LibrenmsConfig::get('syslog_xlate');
        if (! empty($syslog_xlate[$entry['host']])) {
            $entry['host'] = $syslog_xlate[$entry['host']];
        }
        $entry['device_id'] = get_cache($entry['host'], 'device_id');
        if ($entry['device_id']) {
            $os = get_cache($entry['host'], 'os');
            $hostname = get_cache($entry['host'], 'hostname');

            if (LibrenmsConfig::get('enable_syslog_hooks') && is_array(LibrenmsConfig::getOsSetting($os, 'syslog_hook'))) {
                foreach (LibrenmsConfig::getOsSetting($os, 'syslog_hook') as $v) {
                    $syslogprogmsg = $entry['program'] . ': ' . $entry['msg'];
                    if ((isset($v['script'])) && (isset($v['regex'])) && preg_match($v['regex'], $syslogprogmsg)) {
                        shell_exec(escapeshellcmd($v['script']) . ' ' . escapeshellarg((string) $hostname) . ' ' . escapeshellarg((string) $os) . ' ' . escapeshellarg($syslogprogmsg) . ' >/dev/null 2>&1 &');
                    }
                }
            }

            if (in_array($os, ['ios', 'iosxe', 'catos'])) {
                // multipart message
                if (str_contains((string) $entry['msg'], ':')) {
                    $matches = [];
                    $timestamp_prefix = '([\*\.]?[A-Z][a-z]{2} \d\d? \d\d:\d\d:\d\d(.\d\d\d)?( [A-Z]{3})?: )?';
                    $program_match = '(?<program>%?[A-Za-z\d\-_]+(:[A-Z]* %[A-Z\d\-_]+)?)';
                    $message_match = '(?<msg>.*)';
                    if (preg_match('/^' . $timestamp_prefix . $program_match . ': ?' . $message_match . '/', (string) $entry['msg'], $matches)) {
                        $entry['program'] = $matches['program'];
                        $entry['msg'] = $matches['msg'];
                    }
                    unset($matches);
                } else {
                    // if this looks like a program (no groups of 2 or more lowercase letters), move it to program
                    if (! preg_match('/[(a-z)]{2,}/', (string) $entry['msg'])) {
                        $entry['program'] = $entry['msg'];
                        unset($entry['msg']);
                    }
                }
            } elseif ($os == 'linux' and get_cache($entry['host'], 'version') == 'Point') {
                // Cisco WAP200 and similar
                $matches = [];
                if (preg_match('#Log: \[(?P<program>.*)\] - (?P<msg>.*)#', (string) $entry['msg'], $matches)) {
                    $entry['msg'] = $matches['msg'];
                    $entry['program'] = $matches['program'];
                }

                unset($matches);
            } elseif ($os == 'linux') {
                $matches = [];
                // pam_krb5(sshd:auth): authentication failure; logname=root uid=0 euid=0 tty=ssh ruser= rhost=123.213.132.231
                // pam_krb5[sshd:auth]: authentication failure; logname=root uid=0 euid=0 tty=ssh ruser= rhost=123.213.132.231
                if (empty($entry['program']) and preg_match('#^(?P<program>([^(:]+\([^)]+\)|[^\[:]+\[[^\]]+\])) ?: ?(?P<msg>.*)$#', (string) $entry['msg'], $matches)) {
                    $entry['msg'] = $matches['msg'];
                    $entry['program'] = $matches['program'];
                } elseif (empty($entry['program']) and ! empty($entry['facility'])) {
                    // SYSLOG CONNECTION BROKEN; FD='6', SERVER='AF_INET(123.213.132.231:514)', time_reopen='60'
                    // pam_krb5: authentication failure; logname=root uid=0 euid=0 tty=ssh ruser= rhost=123.213.132.231
                    // Disabled because broke this:
                    // diskio.c: don't know how to handle 10 request
                    // elseif($pos = strpos($entry['msg'], ';') or $pos = strpos($entry['msg'], ':')) {
                    // $entry['program'] = substr($entry['msg'], 0, $pos);
                    // $entry['msg'] = substr($entry['msg'], $pos+1);
                    // }
                    // fallback, better than nothing...
                    $entry['program'] = $entry['facility'];
                }

                unset($matches);
            } elseif ($os == 'procurve') {
                $matches = [];
                if (preg_match('/^(?P<program>[A-Za-z]+): {2}(?P<msg>.*)/', (string) $entry['msg'], $matches)) {
                    $entry['msg'] = $matches['msg'] . ' [' . $entry['program'] . ']';
                    $entry['program'] = $matches['program'];
                }
                unset($matches);
            } elseif ($os == 'zywall') {
                // Zwwall sends messages without all the fields, so the offset is wrong
                $msg = preg_replace('/" /', '";', stripslashes($entry['program'] . ':' . $entry['msg']));
                $msg = str_getcsv((string) $msg, ';', escape: '\\');
                $entry['program'] = null;
                foreach ($msg as $param) {
                    [$var, $val] = explode('=', (string) $param);
                    if ($var == 'cat') {
                        $entry['program'] = str_replace('"', '', $val);
                    }
                }
                $entry['msg'] = implode(' ', $msg);
            }//end if

            if (! isset($entry['program'])) {
                $entry['program'] = $entry['msg'];
                unset($entry['msg']);
            }

            $entry['program'] = strtoupper((string) $entry['program']);
            $entry = array_map(trim(...), $entry);

            if ($update) {
                dbInsert(
                    [
                        'device_id' => $entry['device_id'],
                        'program' => $entry['program'],
                        'facility' => $entry['facility'],
                        'priority' => $entry['priority'],
                        'level' => $entry['level'],
                        'tag' => $entry['tag'],
                        'msg' => $entry['msg'],
                        'timestamp' => $entry['timestamp'],
                    ],
                    'syslog'
                );
            }

            unset($os);
        }//end if

        return $entry;
    }//end process_syslog()
}
