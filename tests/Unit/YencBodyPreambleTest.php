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
        $this->assertTrue($metadata->isUsefulForCollection());
    }

    public function test_extensionless_random_yenc_name_is_not_useful_for_collection_grouping(): void
    {
        $metadata = YencBodyPreamble::fromLines([
            '=ybegin part=5 total=56 line=128 size=39854080 name=xASUfbtk4mf45SfeaPdKWQOLhW1d',
            '=ypart begin=2867201 end=3584000',
        ]);

        $this->assertSame('xASUfbtk4mf45SfeaPdKWQOLhW1d', $metadata?->name);
        $this->assertSame(0, $metadata->collectionFileNumber());
        $this->assertFalse($metadata->isUsefulForCollection());
    }

    public function test_standalone_payload_is_file_one_of_one_for_collection_grouping(): void
    {
        $metadata = YencBodyPreamble::fromLines([
            '=ybegin part=33377 total=62540 line=128 size=45362642923 name=Isle.of.Dogs.2018.Blu-ray.CEE.1080p.AVC.DTS-HD.MA.5.1-CapBd.iso',
            '=ypart begin=24681357901 end=24682097916',
        ]);

        $this->assertSame(1, $metadata?->collectionFileNumber());
        $this->assertSame(1, $metadata->collectionTotalFiles());
        $this->assertTrue($metadata->isUsefulForCollection());
    }

    public function test_rejects_text_without_required_ybegin_name_and_size(): void
    {
        $this->assertNull(YencBodyPreamble::fromLines([
            'discussion about =ybegin in a message body',
            '=ybegin name=missing-size.bin',
        ]));
    }
}
