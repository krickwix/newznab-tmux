<?php

declare(strict_types=1);

namespace Tests\Unit\NNTP;

use App\Services\NNTP\NNTPService;
use DariusIII\NetNntp\Protocol\ResponseCode;

/**
 * Stubs out the NNTP socket so getXOVER() can be exercised against a fixed XOVER response.
 *
 * @see NNTPServiceOverviewFormatCacheTest
 */
final class FakeOverviewFormatNNTPService extends NNTPService
{
    private const string XOVER_LINE = "123456\tExample Post (1/2) yEnc\tposter@example.local\t"
        ."Sat, 01 Aug 2026 17:48:51 UTC\t<part1of2.abc@example.local>\t\t4242\t33\t"
        .'Xref: news alt.binaries.test:123456';

    public function __construct() {}

    /**
     * Mimic parent::getOverview(), which caches the format with 'Number' already prepended.
     */
    public function primeCacheWithNumberInclusiveShape(): void
    {
        $this->_overviewFormatCache = array_merge(['Number' => false], $this->overviewFormatFields());
    }

    /**
     * @return array<string, bool>|null
     */
    public function exposeCache(): ?array
    {
        return $this->_overviewFormatCache;
    }

    /**
     * The fields a real server reports for XOVER, in wire order.
     *
     * @return array<string, bool>
     */
    public function overviewFormatFields(): array
    {
        return [
            'Subject' => false,
            'From' => false,
            'Date' => false,
            'Message-ID' => false,
            'References' => false,
            'Bytes' => false,
            'Lines' => false,
            'Xref' => false,
        ];
    }

    public function getOverviewFormat(bool $_forceNames = true, bool $_full = false): mixed
    {
        $format = $this->overviewFormatFields();

        return $_full ? $format : array_keys($format);
    }

    /**
     * The parent's wide union exists for real protocol errors; this stub only ever returns lines.
     *
     * @phpstan-ignore return.unusedType, return.unusedType, return.unusedType
     */
    public function _getTextResponse(): NNTPService|array|string
    {
        /* @phpstan-ignore return.type */
        return [self::XOVER_LINE];
    }

    protected function _checkConnection(bool $reSelectGroup = true): mixed
    {
        return true;
    }

    protected function _enableCompression(bool $secondTry = false): mixed
    {
        return true;
    }

    protected function _sendCommand(string $cmd): mixed
    {
        return ResponseCode::OverviewFollows->value;
    }
}
