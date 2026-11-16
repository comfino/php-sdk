<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Log
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Log;

use Comfino\Backend\Log\ResponseBodySummarizer;
use PHPUnit\Framework\TestCase;

class ResponseBodySummarizerTest extends TestCase
{
    public function testHtmlDebugPageBecomesReadableText(): void
    {
        $html = '<!DOCTYPE html><html><head><style>body{color:red}</style><script>alert(1)</script></head>' .
            '<body><h1>Server &amp; error</h1><p>Unknown column \'comfino_discount\'</p></body></html>';

        $summary = ResponseBodySummarizer::summarize($html);

        $this->assertSame("[HTML response] Server & error Unknown column 'comfino_discount'", $summary);
    }

    public function testJsonAndPlainTextAreKept(): void
    {
        $this->assertSame('{"errors":{"a":"b"}}', ResponseBodySummarizer::summarize('{"errors":{"a":"b"}}'));
        $this->assertSame('Bad gateway', ResponseBodySummarizer::summarize('Bad gateway'));
    }

    public function testNullAndEmptyAreUnchanged(): void
    {
        $this->assertNull(ResponseBodySummarizer::summarize(null));
        $this->assertSame('', ResponseBodySummarizer::summarize(''));
    }

    public function testLongMultibyteBodyIsCutAtTheLimit(): void
    {
        $summary = ResponseBodySummarizer::summarize(str_repeat('ą', 3000));

        $this->assertSame(str_repeat('ą', 2000) . '... [truncated]', $summary);
    }

    public function testPlaceholderIsUntouched(): void
    {
        $this->assertSame('[REDACTED: 12 bytes, not JSON]', ResponseBodySummarizer::summarize('[REDACTED: 12 bytes, not JSON]'));
    }
}
