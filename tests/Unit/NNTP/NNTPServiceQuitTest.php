<?php

declare(strict_types=1);

namespace Tests\Unit\NNTP;

use App\Services\NNTP\NNTPService;
use DariusIII\NetNntp\Protocol\ResponseCode;
use PHPUnit\Framework\TestCase;

final class NNTPServiceQuitTest extends TestCase
{
    public function test_forced_quit_closes_socket_without_protocol_disconnect(): void
    {
        $service = new class extends NNTPService
        {
            public bool $disconnectCalled = false;

            public function __construct() {}

            /**
             * @param  resource  $socket
             */
            public function useSocket($socket): void
            {
                $this->_socket = $socket;
            }

            public function disconnect(): mixed
            {
                $this->disconnectCalled = true;

                return true;
            }
        };

        $socket = fopen('php://temp', 'r+');
        $this->assertIsResource($socket);

        $service->useSocket($socket);
        $this->assertTrue($service->doQuit(true));

        $this->assertFalse(is_resource($socket));
        $this->assertFalse($service->disconnectCalled);
    }

    public function test_failed_protocol_quit_closes_socket_before_resetting_state(): void
    {
        $service = new class extends NNTPService
        {
            public function __construct() {}

            /**
             * @param  resource  $socket
             */
            public function useSocket($socket): void
            {
                $this->_socket = $socket;
            }

            public function disconnect(): mixed
            {
                return false;
            }
        };

        $socket = fopen('php://temp', 'r+');
        $this->assertIsResource($socket);

        $service->useSocket($socket);
        $this->assertFalse($service->doQuit());

        $this->assertFalse(is_resource($socket));
    }

    public function test_body_preamble_fetch_selects_requested_group_without_reselecting_empty_previous_group(): void
    {
        $service = new class extends NNTPService
        {
            /** @var list<string> */
            public array $selectedGroups = [];

            public function __construct()
            {
                $this->_configServer = 'primary.example';
                $this->_configAlternateServer = 'alternate.example';
                $this->_currentServer = $this->_configServer;
                $this->_socket = null;
            }

            public function doConnect(bool $compression = true, bool $alternate = false): mixed
            {
                return true;
            }

            public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
            {
                $this->selectedGroups[] = $group;

                if ($group === '') {
                    return $this->throwError('No such news group', 411);
                }

                $this->_currentGroup = $group;
                $this->_selectedGroupSummary = [
                    'group' => $group,
                    'first' => 1,
                    'last' => 2,
                    'count' => 2,
                ];

                return $this->_selectedGroupSummary;
            }

            protected function _sendCommand(string $cmd): mixed
            {
                $this->_socket = fopen('php://temp', 'r+');
                fwrite($this->_socket, "=ybegin part=1 total=2 line=128 size=10 name=example.par2\r\n");
                fwrite($this->_socket, "=ypart begin=1 end=10\r\n");
                rewind($this->_socket);

                return ResponseCode::BodyFollows->value;
            }
        };

        $lines = $service->getYencBodyPreambleLines('alt.binaries.blu-ray', 123, 8);

        $this->assertSame(['alt.binaries.blu-ray'], $service->selectedGroups);
        $this->assertIsArray($lines);
        $this->assertSame('=ybegin part=1 total=2 line=128 size=10 name=example.par2', $lines[0]);
    }
}
