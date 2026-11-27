<?php

declare(strict_types=1);

use Tuna\Event;
use Tuna\ServerException;
use Tuna\TerminatedException;
use Tuna\Tunnel;

require __DIR__ . '/../vendor/autoload.php';

const USAGE = "usage: runner.php http|tcp <addr> [-s SUBDOMAIN] [-d DOMAIN] [-p PORT] [--proxy-protocol N]\n"
    . "                  [--cidr-allow CIDR]... [--cidr-deny CIDR]... [--log-format json]\n";

function usage(): never
{
    fwrite(STDERR, USAGE);
    exit(2);
}

function printEvent(Event $e): void
{
    fwrite(STDOUT, json_encode($e->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

$args = array_slice($argv, 1);
$protocol = array_shift($args);
if ($protocol !== 'http' && $protocol !== 'tcp') {
    usage();
}
$options = [
    'protocol' => $protocol,
    'serverURL' => getenv('TUNA_ADDR') ?: null,
    'location' => getenv('TUNA_LOCATION') ?: null,
    'onEvent' => 'printEvent',
];
$flags = ['-s' => 'subdomain', '-d' => 'domain', '-p' => 'port', '--proxy-protocol' => 'proxyProtocol'];
$lists = ['--cidr-allow' => 'cidrAllow', '--cidr-deny' => 'cidrDeny'];
$addr = null;
while ($args !== []) {
    $arg = array_shift($args);
    $value = null;
    if (str_starts_with($arg, '-') && str_contains($arg, '=')) {
        [$arg, $value] = explode('=', $arg, 2);
    }
    if (isset($flags[$arg]) || isset($lists[$arg]) || $arg === '--log-format') {
        $value ??= array_shift($args) ?? usage();
        if ($arg === '--log-format') {
            $value === 'json' || usage();
        } elseif (isset($lists[$arg])) {
            $options[$lists[$arg]][] = $value;
        } else {
            $options[$flags[$arg]] = $value;
        }
    } elseif ($addr === null && !str_starts_with($arg, '-')) {
        $addr = $arg;
    } else {
        usage();
    }
}
$addr ?? usage();

$tunnel = null;
if (function_exists('pcntl_async_signals')) {
    $stop = static function () use (&$tunnel): void {
        if ($tunnel === null) {
            exit(0);
        }
        $tunnel->close();
    };
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

try {
    $tunnel = Tunnel::forward($options, $addr);
    $tunnel->wait();
} catch (ServerException | TerminatedException) {
    exit(1);
} catch (Throwable $e) {
    printEvent(new Event(Event::ERROR, gmdate('Y-m-d\TH:i:s\Z'), error: $e->getMessage()));
    exit(1);
}
