<?php

declare(strict_types=1);

namespace PdfConversionApi\Tests\Unit;

use PdfConversionApi\Client;
use PdfConversionApi\Exception\InvalidResponseException;
use PdfConversionApi\Exception\StoredFileException;
use PdfConversionApi\Internal\HttpResponse;
use PdfConversionApi\Internal\ResponseParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseParserTest extends TestCase
{
    public function testConversionResult(): void
    {
        $body = (string) json_encode([
            'ConversionCost' => 1,
            'ConversionTime' => 0.036,
            'Files' => [
                ['FileName' => 'season-guide-1.jpg', 'FileExt' => 'jpg', 'FileSize' => 5, 'FileData' => base64_encode('page1')],
                ['FileName' => 'season-guide-2.jpg', 'FileExt' => 'jpg', 'FileSize' => 6, 'FileData' => base64_encode('page-2')],
            ],
        ]);
        $result = ResponseParser::conversionResult($body, 'req-1', new Client('k'));
        self::assertSame('', $body, 'the raw body is released once decoded');
        self::assertSame(1, $result->conversionCost);
        self::assertSame(0.036, $result->conversionTime);
        self::assertSame('req-1', $result->requestId);
        self::assertCount(2, $result);
        self::assertSame($result->files[0], $result->file);
        self::assertSame('season-guide-1.jpg', $result->file->name);
        self::assertSame('jpg', $result->file->ext);
        self::assertSame(5, $result->file->size);
        self::assertFalse($result->file->stored);
        self::assertNull($result->file->url);
        self::assertSame('page1', $result->file->data());
        self::assertSame('page-2', $result->files[1]->download());
        self::assertSame(['season-guide-1.jpg', 'season-guide-2.jpg'], array_map(static fn ($f) => $f->name, iterator_to_array($result)));
    }

    public function testStoredFile(): void
    {
        $body = (string) json_encode(['ConversionCost' => 1, 'ConversionTime' => 0.04, 'Files' => [[
            'FileName' => 'a.pdf', 'FileExt' => 'pdf', 'FileSize' => 10,
            'Url' => 'https://api.example.test/v2/d/32001ff088d5c35e10e4bc6f6ddf09a9/a.pdf',
            'FileId' => '32001ff088d5c35e10e4bc6f6ddf09a9',
        ]]]);
        $file = ResponseParser::conversionResult($body, null, new Client('k'))->file;
        self::assertTrue($file->stored);
        self::assertSame('32001ff088d5c35e10e4bc6f6ddf09a9', $file->fileId);
        $this->expectException(StoredFileException::class);
        $this->expectExceptionMessage('download()');
        $file->data();
    }

    /** @return iterable<string, array{string}> */
    public static function unusableBodies(): iterable
    {
        yield 'not json' => ['{not json'];
        yield 'json string' => ['"text"'];
        yield 'no Files' => ['{"ConversionCost":1}'];
        yield 'empty Files' => ['{"Files":[]}'];
        yield 'Files not a list' => ['{"Files":{"a":1}}'];
        yield 'entry not an object' => ['{"Files":[1]}'];
        yield 'no FileName' => ['{"Files":[{"FileSize":1,"FileData":"YQ=="}]}'];
        yield 'size mismatch' => ['{"Files":[{"FileName":"a.pdf","FileExt":"pdf","FileSize":2,"FileData":"YQ=="}]}'];
        yield 'bad base64' => ['{"Files":[{"FileName":"a.pdf","FileExt":"pdf","FileSize":1,"FileData":"Y$Q=="}]}'];
        yield 'no data and no link' => ['{"Files":[{"FileName":"a.pdf","FileExt":"pdf","FileSize":1}]}'];
    }

    #[DataProvider('unusableBodies')]
    public function testUnusableConversionBody(string $body): void
    {
        $this->expectException(InvalidResponseException::class);
        ResponseParser::conversionResult($body, null, new Client('k'));
    }

    public function testUsage(): void
    {
        $usage = ResponseParser::usage(new HttpResponse(200, [], (string) json_encode([
            'Plan' => 'Free', 'Included' => 250, 'Used' => 12, 'Remaining' => 238, 'Overage' => false, 'Running' => 0,
            'ConcurrentLimit' => 1, 'MaxUploadMB' => 10,
            'PeriodStart' => '2026-11-01T00:00:00.000Z', 'PeriodEnd' => '2026-12-01T05:30:00+05:30',
        ])));
        self::assertSame('Free', $usage->plan);
        self::assertSame(238, $usage->remaining);
        self::assertSame(10, $usage->maxUploadMb);
        self::assertSame('2026-11-01T00:00:00+00:00', $usage->periodStart->format(DATE_ATOM));
        self::assertSame('2026-12-01T00:00:00+00:00', $usage->periodEnd->format(DATE_ATOM));
        self::assertSame('UTC', $usage->periodEnd->getTimezone()->getName());
    }

    public function testUsageWithNullRemainingAndBadFields(): void
    {
        $fields = [
            'Plan' => 'Scale', 'Included' => 1, 'Used' => 0, 'Remaining' => null, 'Overage' => false, 'Running' => 0,
            'ConcurrentLimit' => 1, 'MaxUploadMB' => 100, 'PeriodStart' => '2026-11-01T00:00:00Z', 'PeriodEnd' => '2026-12-01T00:00:00Z',
        ];
        self::assertNull(ResponseParser::usage(new HttpResponse(200, [], (string) json_encode($fields)))->remaining);
        foreach (['Included' => '1', 'Overage' => 'no', 'PeriodEnd' => 'next month', 'Plan' => null] as $key => $bad) {
            try {
                ResponseParser::usage(new HttpResponse(200, [], (string) json_encode([$key => $bad] + $fields)));
                self::fail("$key accepted");
            } catch (InvalidResponseException $e) {
                self::assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    public function testConversions(): void
    {
        $list = ResponseParser::conversions(new HttpResponse(200, [], '[{"From":"docx","To":"pdf","Title":"DOCX to PDF","Category":"Document","Inputs":"one"},{"From":"data","To":"qrcode"}]'));
        self::assertCount(2, $list);
        self::assertSame(['docx', 'pdf', 'DOCX to PDF', 'Document', 'one'], [$list[0]->from, $list[0]->to, $list[0]->title, $list[0]->category, $list[0]->inputs]);
        self::assertSame('', $list[1]->inputs);
        $this->expectException(InvalidResponseException::class);
        ResponseParser::conversions(new HttpResponse(200, [], '{"From":"docx"}'));
    }
}
