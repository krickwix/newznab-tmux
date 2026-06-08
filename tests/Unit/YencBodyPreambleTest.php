<?php

namespace Tests\Unit;

use App\Services\Binaries\YencBodyPreamble;
use PHPUnit\Framework\TestCase;

class YencBodyPreambleTest extends TestCase
{
    public function test_extracts_multipart_yenc_metadata_from_body_prefix(): void
    {
        $metadata = YencBodyPreamble::fromLines([
            '=ybegin part=104 total=634 line=128 size=454033408 name=Y7FouDJBgKrPFCGpz4wp.part070.rar',
            '=ypart begin=73830401 end=74547200',
        ]);

        $this->assertSame('Y7FouDJBgKrPFCGpz4wp.part070.rar', $metadata?->name);
        $this->assertSame(104, $metadata->part);
        $this->assertSame(634, $metadata->total);
        $this->assertSame(454033408, $metadata->size);
        $this->assertSame('"Y7FouDJBgKrPFCGpz4wp.part070.rar" (104/634) yEnc', $metadata->toSyntheticSubject());
        $this->assertSame(70, $metadata->collectionFileNumber());
    }

    public function test_rejects_text_without_required_ybegin_name_and_size(): void
    {
        $this->assertNull(YencBodyPreamble::fromLines([
            'discussion about =ybegin in a message body',
            '=ybegin name=missing-size.bin',
        ]));
    }
}
