<?php

declare(strict_types=1);

namespace Tuna\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tuna\Event;
use Tuna\ServerException;
use Tuna\TerminatedException;
use Tuna\TunaException;
use Tuna\Tunnel;

#[RequiresPhpExtension('pcntl')]
final class TunnelTest extends TestCase
{
    private const FAKE = __DIR__ . '/fake-tiny';

    protected function tearDown(): void
    {
        putenv('FAKE_TINY');
        putenv('TUNA_API_KEY');
        putenv('TUNA_SUBDOMAIN');
    }

    private static function start(string $scenario, array $options = [], string $addr = '8080'): Tunnel
    {
        putenv("FAKE_TINY={$scenario}");

        return Tunnel::forward($options + ['binary' => self::FAKE], $addr);
    }

    public function testForwardReturnsOnConnectedAndCloseEndsEvents(): void
    {
        putenv('TUNA_API_KEY=env-key');
        putenv('TUNA_SUBDOMAIN=leaked');
        $seen = [];
        $t = self::start('connected', [
            'subdomain' => 'demo',
            'serverURL' => 'http://127.0.0.1:1',
            'onEvent' => function (Event $e) use (&$seen): void {
                $seen[] = $e->type;
            },
        ]);
        $this->assertSame('https://fake.tuna.am', $t->url());
        $this->assertSame([Event::RECONNECTING, Event::CONNECTED], $seen);

        $types = [];
        foreach ($t->events() as $e) {
            $types[] = $e->type;
            if ($e->type === Event::BANNER && $e->message !== 'stopping') {
                $info = json_decode((string) $e->message, true);
                $this->assertSame(['http', '8080', '--log-format', 'json', '-s', 'demo'], $info['argv']);
                $this->assertSame('env-key', $info['key']);
                $this->assertSame('php-sdk', $info['suffix']);
                $this->assertSame('http://127.0.0.1:1', $info['addr']);
                $this->assertFalse($info['subdomain']);
                $t->close();
            }
        }
        $this->assertSame([Event::RECONNECTING, Event::CONNECTED, Event::BANNER, Event::BANNER], $types);
    }

    public function testPermanentErrorBeforeConnect(): void
    {
        try {
            self::start('error');
            $this->fail('no exception');
        } catch (ServerException $e) {
            $this->assertSame('tuna: invalid API key (request id req-1)', $e->getMessage());
            $this->assertSame('invalid API key', $e->serverMessage);
            $this->assertSame('req-1', $e->requestId);
        }
    }

    public function testTerminated(): void
    {
        $t = self::start('terminated', ['protocol' => 'tcp']);
        $events = [];
        try {
            foreach ($t->events() as $e) {
                $events[] = $e;
            }
            $this->fail('no exception');
        } catch (TerminatedException $e) {
            $this->assertSame('deleted', $e->reason);
            $this->assertSame('tuna: tunnel was deleted', $e->getMessage());
        }
        $this->assertTrue($events[1]->isKeepAliveTimeout());
        $this->assertSame(2.0, $events[1]->retryIn);
        $this->assertSame('tcp://fake.tuna.am:1235', $t->url());
        $this->assertSame(Event::TERMINATED, end($events)->type);
    }

    public function testUsageErrorCarriesStderr(): void
    {
        $this->expectException(TunaException::class);
        $this->expectExceptionMessage('exited with code 2: flag provided but not defined: -x');
        self::start('usage');
    }

    public function testConnectTimeout(): void
    {
        $start = microtime(true);
        try {
            self::start('silent', ['connectTimeout' => 0.5]);
            $this->fail('no exception');
        } catch (TunaException $e) {
            $this->assertStringContainsString('did not come up in 0.5s', $e->getMessage());
        }
        $this->assertLessThan(5, microtime(true) - $start);
    }

    public function testCommandMapsOptions(): void
    {
        [$argv, $env] = Tunnel::command([
            'protocol' => 'tcp',
            'apiKey' => 'secret',
            'port' => 'db',
            'proxyProtocol' => 2,
            'cidrAllow' => ['10.0.0.0/8', '192.168.0.0/16'],
            'cidrDeny' => ['10.1.0.0/16'],
            'location' => 'ru',
        ], '127.0.0.1:5432');
        $this->assertSame([
            'tcp', '127.0.0.1:5432', '--log-format', 'json', '-l', 'ru', '-p', 'db', '--proxy-protocol', '2',
            '--cidr-allow', '10.0.0.0/8', '--cidr-allow', '192.168.0.0/16', '--cidr-deny', '10.1.0.0/16',
        ], $argv);
        $this->assertNotContains('secret', $argv);
        $this->assertSame('secret', $env['TUNA_API_KEY']);
        $this->assertSame('php-sdk', $env['TUNA_CLIENT_SUFFIX']);
        $this->assertArrayNotHasKey('TUNA_ADDR', $env);
    }

    public function testCommandRejectsInvalidOptions(): void
    {
        foreach ([
            [['protocol' => 'udp'], 'unsupported protocol'],
            [['port' => '1'], 'option port is not supported for http'],
            [['protocol' => 'tcp', 'subdomain' => 'x'], 'option subdomain is not supported for tcp'],
            [['apikey' => 'x'], 'unknown option apikey'],
        ] as [$options, $want]) {
            try {
                Tunnel::command($options, '8080');
                $this->fail("no exception for {$want}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($want, $e->getMessage());
            }
        }
    }
}
