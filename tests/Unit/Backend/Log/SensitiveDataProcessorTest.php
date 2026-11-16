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

use Comfino\Backend\Log\SensitiveDataProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for SensitiveDataProcessor.
 *
 * Tests sensitive data redaction in log records.
 */
class SensitiveDataProcessorTest extends TestCase
{
    private SensitiveDataProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new SensitiveDataProcessor();
    }

    public function testRedactsApiKeys(): void
    {
        $record = [
            'context' => [
                'api_key' => 'sk_live_1234567890abcdefghij',
                'safe_data' => 'visible',
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        $this->assertEquals('[REDACTED]', $result['context']['api_key']);
        $this->assertEquals('visible', $result['context']['safe_data']);
    }

    public function testRedactsAuthorizationHeaders(): void
    {
        $record = [
            'context' => [
                'headers' => [
                    'Authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...',
                    'Content-Type' => 'application/json',
                ],
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        $this->assertEquals('[REDACTED]', $result['context']['headers']['Authorization']);
        $this->assertEquals('application/json', $result['context']['headers']['Content-Type']);
    }

    public function testTruncatesCrSignatureToPrefix(): void
    {
        $record = [
            'context' => [
                'cr-signature' => 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6',
                'x-cr-signature' => 'abcdef1234567890',
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        // CR-Signature values are cut to an 8-character prefix: enough to correlate, not enough to replay.
        $this->assertEquals('a1b2c3d4…', $result['context']['cr-signature']);
        $this->assertEquals('abcdef12…', $result['context']['x-cr-signature']);
    }

    public function testRedactsPasswords(): void
    {
        $record = [
            'context' => [
                'password' => 'mySecretPassword123',
                'passwd' => 'anotherPassword',
                'pwd' => 'shortPwd',
                'username' => 'john_doe', // Should not be redacted.
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        $this->assertEquals('[REDACTED]', $result['context']['password']);
        $this->assertEquals('[REDACTED]', $result['context']['passwd']);
        $this->assertEquals('[REDACTED]', $result['context']['pwd']);
        $this->assertEquals('john_doe', $result['context']['username']);
    }

    public function testRedactsTokens(): void
    {
        $record = [
            'context' => [
                'token' => 'abc123token456',
                'access_token' => 'xyz789token012',
                'secret' => 'topSecret',
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        $this->assertEquals('[REDACTED]', $result['context']['token']);
        $this->assertEquals('[REDACTED]', $result['context']['access_token']);
        $this->assertEquals('[REDACTED]', $result['context']['secret']);
    }

    public function testRedactsJwtTokens(): void
    {
        $record = [
            'context' => [
                'jwt' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9' .
                    '.eyJzdWIiOiIxMjM0NTY3ODkwIn0' .
                    '.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U',
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        // JWT should be detected and masked.
        $this->assertStringStartsWith('eyJh', $result['context']['jwt']);
        $this->assertStringEndsWith('sR8U', $result['context']['jwt']);
        $this->assertStringContainsString('...', $result['context']['jwt']);
    }

    public function testRedactsLongAlphanumericStrings(): void
    {
        $record = [
            'context' => [
                'random_field' => 'abcdefghijklmnopqrstuvwxyz123456',  // 32+ characters should be masked.
                'short_field' => 'short',  // should not be masked
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        $this->assertStringStartsWith('abcd', $result['context']['random_field']);
        $this->assertStringEndsWith('3456', $result['context']['random_field']);
        $this->assertStringContainsString('...', $result['context']['random_field']);
        $this->assertEquals('short', $result['context']['short_field']);
    }

    public function testHandlesNestedArrays(): void
    {
        $record = [
            'context' => [
                'user' => [
                    'username' => 'john',
                    'api_key' => 'secret_key_12345',
                    'profile' => [
                        'password' => 'nested_password',
                        'email' => 'john@example.com',
                    ],
                ],
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        $this->assertEquals('john', $result['context']['user']['username']);
        $this->assertEquals('[REDACTED]', $result['context']['user']['api_key']);
        $this->assertEquals('[REDACTED]', $result['context']['user']['profile']['password']);
        // E-mail is order personal data, so it is redacted too.
        $this->assertEquals('[REDACTED]', $result['context']['user']['profile']['email']);
    }

    public function testPreservesNonSensitiveData(): void
    {
        $record = [
            'context' => [
                'order_id' => 12345,
                'cart_id' => 'abc123',
                'amount' => 99.99,
                'status' => 'PAID',
                'items' => ['item1', 'item2'],
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        $this->assertEquals(12345, $result['context']['order_id']);
        $this->assertEquals('abc123', $result['context']['cart_id']);
        $this->assertEquals(99.99, $result['context']['amount']);
        $this->assertEquals('PAID', $result['context']['status']);
        $this->assertEquals(['item1', 'item2'], $result['context']['items']);
    }

    public function testHandlesEmptyContext(): void
    {
        $record = [
            'context' => [],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        $this->assertEquals([], $result['context']);
        $this->assertEquals([], $result['extra']);
    }

    public function testRedactsInExtraField(): void
    {
        $record = [
            'context' => [],
            'extra' => [
                'api_key' => 'should_be_redacted',
                'safe_info' => 'should_be_visible',
            ],
        ];

        $result = ($this->processor)($record);

        $this->assertEquals('[REDACTED]', $result['extra']['api_key']);
        $this->assertEquals('should_be_visible', $result['extra']['safe_info']);
    }

    public function testCaseInsensitiveSensitiveKeys(): void
    {
        $record = [
            'context' => [
                'API_KEY' => 'uppercase',
                'Api-Key' => 'mixedcase',
                'api_key' => 'lowercase',
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        $this->assertEquals('[REDACTED]', $result['context']['API_KEY']);
        $this->assertEquals('[REDACTED]', $result['context']['Api-Key']);
        $this->assertEquals('[REDACTED]', $result['context']['api_key']);
    }

    public function testRedactsCardNumbers(): void
    {
        $record = [
            'context' => [
                'card_number' => '1234567812345678',
                'cvv' => '123',
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        $this->assertEquals('[REDACTED]', $result['context']['card_number']);
        $this->assertEquals('[REDACTED]', $result['context']['cvv']);
    }

    public function testRedactsPersonalIdentifiers(): void
    {
        $record = [
            'context' => [
                'ssn' => '123-45-6789',
                'pesel' => '12345678901', // Polish national ID
                'nip' => '1234567890', // Polish tax ID
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        $this->assertEquals('[REDACTED]', $result['context']['ssn']);
        $this->assertEquals('[REDACTED]', $result['context']['pesel']);
        $this->assertEquals('[REDACTED]', $result['context']['nip']);
    }

    public function testMaskingShortVsLongStrings(): void
    {
        $record = [
            'context' => [
                'very_short' => 'abc',
                'short_enough' => 'abcd1234', // 8 characters will be fully redacted.
                'long_string' => 'abcdefghij1234567890', // >8 characters, will show first/last 4.
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        // Very short strings are not masked.
        $this->assertEquals('abc', $result['context']['very_short']);

        // Short string with sensitive-looking pattern.
        $longString = $result['context']['long_string'];

        if (str_contains($longString, '...')) {
            $this->assertStringStartsWith('abcd', $longString);
            $this->assertStringEndsWith('7890', $longString);
        }
    }

    public function testNestedArraysWithUnmaskedParentKey(): void
    {
        $record = [
            'context' => [
                'headers' => [
                    'CR-Signature' => 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0',
                    'Authorization' => 'Bearer token123',
                ],
                'cr-signature' => [
                    'value' => 'abcdefghijklmnopqrstuvwxyz123456',  // Nested under unmasked key.
                    'timestamp' => '2025-01-09T12:00:00Z',
                ],
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        // CR-Signature header value should not be masked (direct match).
        $this->assertEquals('a1b2c3d4…', $result['context']['headers']['CR-Signature']);

        // Authorization should still be redacted.
        $this->assertEquals('[REDACTED]', $result['context']['headers']['Authorization']);

        // Values nested under 'cr-signature' parent should not be masked (parent key match).
        $this->assertEquals('abcdefgh…', $result['context']['cr-signature']['value']);
        $this->assertEquals('2025-01-…', $result['context']['cr-signature']['timestamp']);
    }

    public function testCrSignatureCaseInsensitivity(): void
    {
        $record = [
            'context' => [
                'CR-Signature' => 'uppercase_signature_value_12345',
                'cr-signature' => 'lowercase_signature_value_67890',
                'Cr-Signature' => 'mixedcase_signature_value_abcde',
                'X-CR-SIGNATURE' => 'x_header_signature_value_fghij',
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        // All CR-Signature variations should NOT be redacted (case-insensitive pattern).
        $this->assertEquals('uppercas…', $result['context']['CR-Signature']);
        $this->assertEquals('lowercas…', $result['context']['cr-signature']);
        $this->assertEquals('mixedcas…', $result['context']['Cr-Signature']);
        $this->assertEquals('x_header…', $result['context']['X-CR-SIGNATURE']);
    }

    public function testCrSignatureAsArrayOfSignatures(): void
    {
        $record = [
            'context' => [
                'cr-signature' => [
                    'abcdefghijklmnopqrstuvwxyz123456',  // Long alphanumeric (would normally be masked)
                    'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.signature',  // JWT-like
                    'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6',  // 48 characters
                ],
                'api_key' => 'should_be_redacted_key_value_12345',
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        // CR-Signature array values should NOT be masked (parent key is unmasked pattern).
        $this->assertIsArray($result['context']['cr-signature']);
        $this->assertCount(3, $result['context']['cr-signature']);
        $this->assertEquals('abcdefgh…', $result['context']['cr-signature'][0]);
        $this->assertEquals('eyJhbGci…', $result['context']['cr-signature'][1]);
        $this->assertEquals('a1b2c3d4…', $result['context']['cr-signature'][2]);

        // API key should still be redacted.
        $this->assertEquals('[REDACTED]', $result['context']['api_key']);
    }

    public function testCrSignatureNestedArraysWithNumericKeys(): void
    {
        $record = [
            'context' => [
                'request' => [
                    'headers' => [
                        'cr-signature' => [
                            'abcdefghijklmnopqrstuvwxyz123456',
                            'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.signature',
                            'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6',
                        ],
                        'authorization' => 'Bearer should_be_redacted_token',
                    ],
                ],
                'X-CR-SIGNATURE' => [
                    'first_signature_value_123456789012',
                    'second_signature_value_987654321098',
                    'third_signature_value_abcdefghijklm',
                ],
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        // All CR-Signature array values should NOT be masked.
        $this->assertIsArray($result['context']['request']['headers']['cr-signature']);
        $this->assertCount(3, $result['context']['request']['headers']['cr-signature']);
        $this->assertEquals('abcdefgh…', $result['context']['request']['headers']['cr-signature'][0]);
        $this->assertEquals('eyJhbGci…', $result['context']['request']['headers']['cr-signature'][1]);
        $this->assertEquals('a1b2c3d4…', $result['context']['request']['headers']['cr-signature'][2]);

        // Authorization should be redacted.
        $this->assertEquals('[REDACTED]', $result['context']['request']['headers']['authorization']);

        // X-CR-SIGNATURE array should not be masked.
        $this->assertIsArray($result['context']['X-CR-SIGNATURE']);
        $this->assertCount(3, $result['context']['X-CR-SIGNATURE']);
        $this->assertEquals('first_si…', $result['context']['X-CR-SIGNATURE'][0]);
        $this->assertEquals('second_s…', $result['context']['X-CR-SIGNATURE'][1]);
        $this->assertEquals('third_si…', $result['context']['X-CR-SIGNATURE'][2]);
    }

    public function testMixedArraysWithCrSignatureAndSensitiveData(): void
    {
        $record = [
            'context' => [
                'signatures' => [
                    'cr-signature' => [
                        'abcdefghijklmnopqrstuvwxyz123456', // Would be masked if not under cr-signature.
                        'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.token',
                        'signature_value_that_is_very_long_32_plus',
                    ],
                ],
                'identifiers' => [
                    'abcdefghijklmnopqrstuvwxyz123456', // Should be masked (not under cr-signature).
                    'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6',
                ],
                'other_data' => [
                    'values' => [
                        'abcdefghijklmnopqrstuvwxyz123456',  // Should be masked.
                        'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.token',
                    ],
                ],
            ],
            'extra' => [],
        ];

        $result = ($this->processor)($record);

        // CR-Signature array values should NOT be masked.
        $this->assertIsArray($result['context']['signatures']['cr-signature']);
        $this->assertEquals('abcdefgh…', $result['context']['signatures']['cr-signature'][0]);
        $this->assertEquals('eyJhbGci…', $result['context']['signatures']['cr-signature'][1]);
        $this->assertEquals('signatur…', $result['context']['signatures']['cr-signature'][2]);

        // Identifier array values should be masked (long alphanumeric strings).
        $this->assertIsArray($result['context']['identifiers']);
        $this->assertStringStartsWith('abcd', $result['context']['identifiers'][0]);
        $this->assertStringEndsWith('3456', $result['context']['identifiers'][0]);
        $this->assertStringContainsString('...', $result['context']['identifiers'][0]);
        $this->assertStringStartsWith('a1b2', $result['context']['identifiers'][1]);
        $this->assertStringEndsWith('o5p6', $result['context']['identifiers'][1]);

        // Other values should be masked.
        $this->assertIsArray($result['context']['other_data']['values']);
        $this->assertStringStartsWith('abcd', $result['context']['other_data']['values'][0]);
        $this->assertStringEndsWith('3456', $result['context']['other_data']['values'][0]);
        $this->assertStringStartsWith('eyJh', $result['context']['other_data']['values'][1]);
        $this->assertStringEndsWith('oken', $result['context']['other_data']['values'][1]);
    }

    public function testRedactsPersonalDataNestedInRequestPayload(): void
    {
        $record = [
            'context' => [
                'request' => [
                    'customer' => [
                        'firstName' => 'Jan',
                        'PhoneNumber' => '+48500100200',
                        'address' => ['street' => 'Długa', 'city' => 'Kraków', 'countryCode' => 'PL'],
                    ],
                    'loanParameters' => ['amount' => 150000, 'term' => 12],
                ],
            ],
            'extra' => [],
        ];

        $customer = ($this->processor)($record)['context']['request']['customer'];

        $this->assertSame('[REDACTED]', $customer['firstName']);
        $this->assertSame('[REDACTED]', $customer['PhoneNumber'], 'Matched case-insensitively.');
        $this->assertSame('[REDACTED]', $customer['address']['street']);
        $this->assertSame('[REDACTED]', $customer['address']['city']);
        $this->assertSame('PL', $customer['address']['countryCode']);
        $this->assertSame(
            ['amount' => 150000, 'term' => 12],
            ($this->processor)($record)['context']['request']['loanParameters']
        );
    }

    public function testPersonalDataKeysAreMatchedExactlyNotAsSubstrings(): void
    {
        $result = ($this->processor)(['context' => ['cityCode' => 'KRK', 'emailVerified' => true], 'extra' => []]);

        $this->assertSame('KRK', $result['context']['cityCode']);
        $this->assertTrue($result['context']['emailVerified']);
    }

    public function testAdditionalSensitiveKeys(): void
    {
        $processor = new SensitiveDataProcessor(['vat_id', 'Telephone']);

        $result = $processor(['context' => ['vat_id' => 'PL1234567890', 'telephone' => '500100200', 'sku' => 'A1'], 'extra' => []]);

        $this->assertSame('[REDACTED]', $result['context']['vat_id']);
        $this->assertSame('[REDACTED]', $result['context']['telephone']);
        $this->assertSame('A1', $result['context']['sku']);
    }

    public function testSixtyFourCharacterSignatureBecomesEightCharacterPrefix(): void
    {
        $signature = hash('sha3-256', 'key' . 'body');

        $result = ($this->processor)(['context' => ['CR-Signature' => $signature], 'extra' => []]);

        $this->assertSame(substr($signature, 0, 8) . '…', $result['context']['CR-Signature']);
    }

    public function testMonolog3StyleRecordIsSanitizedIntoANewRecord(): void
    {
        $record = new FakeLogRecord(['email' => 'jan@example.com', 'orderId' => '42'], ['api_key' => 'k']);

        $result = ($this->processor)($record);

        $this->assertInstanceOf(FakeLogRecord::class, $result);
        $this->assertNotSame($record, $result);
        $this->assertSame(['email' => '[REDACTED]', 'orderId' => '42'], $result->context);
        $this->assertSame(['api_key' => '[REDACTED]'], $result->extra);
        $this->assertSame('jan@example.com', $record->context['email'], 'The original record is left untouched.');
    }

    public function testUnsupportedRecordIsReturnedUnchanged(): void
    {
        $this->assertSame('plain', ($this->processor)('plain'));
    }

    public function testSanitizeTextMasksLogLines(): void
    {
        $signature = hash('sha3-256', 'x');
        $line = '[2026-10-12] comfino.DEBUG: [API_ERROR] {"email":"jan@example.com","phoneNumber":"+48500100200",' .
            '"orderId":"42"} CR-Signature: ' . $signature . ' contact jan.kowalski@example.com api_key=abcdef123';

        $sanitized = $this->processor->sanitizeText($line);

        $this->assertStringNotContainsString('jan@example.com', $sanitized);
        $this->assertStringNotContainsString('+48500100200', $sanitized);
        $this->assertStringNotContainsString('jan.kowalski@example.com', $sanitized);
        $this->assertStringNotContainsString($signature, $sanitized);
        $this->assertStringNotContainsString('abcdef123', $sanitized);
        $this->assertStringContainsString('"orderId":"42"', $sanitized);
        $this->assertStringContainsString('CR-Signature: ' . substr($signature, 0, 8) . '…', $sanitized);
        $this->assertStringContainsString('[2026-10-12] comfino.DEBUG: [API_ERROR]', $sanitized);
    }

    public function testRedactsThrowableInContextIncludingPrevious(): void
    {
        $error = new \RuntimeException('Rejected for jan@example.com', 400, new \LogicException('phone +48 600 100 200'));

        $result = $this->processor->__invoke(['message' => 'x', 'context' => ['exception' => $error], 'extra' => []]);

        $described = $result['context']['exception'];

        $this->assertIsArray($described);
        $this->assertSame(\RuntimeException::class, $described['class']);
        $this->assertStringNotContainsString('jan@example.com', $described['message']);
        $this->assertStringNotContainsString('600 100 200', $described['previous']['message']);
        $this->assertLessThanOrEqual(4096 + 32, strlen($described['trace']));
    }

    public function testRedactsRecordMessageInArrayAndLogRecordShapes(): void
    {
        $array = $this->processor->__invoke(['message' => 'failed for jan@example.com', 'context' => [], 'extra' => []]);

        $this->assertStringNotContainsString('jan@example.com', $array['message']);

        $record = $this->processor->__invoke(new FakeLogRecord([], [], 'failed for jan@example.com'));

        $this->assertInstanceOf(FakeLogRecord::class, $record);
        $this->assertStringNotContainsString('jan@example.com', $record->message);
    }

    public function testThrowableRedactionIsIdempotent(): void
    {
        $once = $this->processor->__invoke(['message' => 'm', 'context' => ['exception' => new \RuntimeException('a@b.pl')], 'extra' => []]);
        $twice = $this->processor->__invoke($once);

        $this->assertSame($once['context']['exception']['message'], $twice['context']['exception']['message']);
    }

    public function testSanitizeTextRedactsAuthorizationHeaderValueWithBearerScheme(): void
    {
        $text = $this->processor->sanitizeText("POST /x HTTP/1.1\nAuthorization: Bearer abc.def-ghi_123\nAccept: */*");

        self::assertStringNotContainsString('abc.def-ghi_123', $text);
        self::assertStringContainsString('Authorization: [REDACTED]', $text);
        self::assertStringContainsString('Accept: */*', $text);
    }

    public function testSanitizeTextRedactsABareBearerToken(): void
    {
        self::assertStringNotContainsString('xyz789token', $this->processor->sanitizeText('request failed, sent Bearer xyz789token'));
    }

    public function testSanitizeTextRedactsAuthQueryParameterAndCookies(): void
    {
        $text = $this->processor->sanitizeText('GET /v3/paywall?auth=SECRETVALUE&loanAmount=1000');

        self::assertStringNotContainsString('SECRETVALUE', $text);
        self::assertStringContainsString('loanAmount=1000', $text);

        $cookies = $this->processor->sanitizeText('Cookie: sid=abc123; theme=dark');

        self::assertStringNotContainsString('abc123', $cookies);
        self::assertStringNotContainsString('theme=dark', $cookies);
    }

    public function testShortSensitiveWordsMatchWholeWordsOnly(): void
    {
        $record = ($this->processor)([
            'message' => 'm',
            'context' => [
                'className' => 'App\\Foo',
                'snippet' => 'a plain snippet value',
                'mnemonic' => 'a plain mnemonic value',
                'user_ssn' => '123-45-6789',
                'userSsn' => '123-45-6789',
                'company_nip' => '1234567890',
                'companyNip' => '1234567890',
                'pwd' => 'hunter2',
                'cvc' => '123',
            ],
            'extra' => [],
        ]);

        self::assertSame('App\\Foo', $record['context']['className']);
        self::assertSame('a plain snippet value', $record['context']['snippet']);
        self::assertSame('a plain mnemonic value', $record['context']['mnemonic']);

        foreach (['user_ssn', 'userSsn', 'company_nip', 'companyNip', 'pwd', 'cvc'] as $key) {
            self::assertSame('[REDACTED]', $record['context'][$key], $key);
        }
    }
}
