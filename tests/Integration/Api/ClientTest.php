<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Integration\Api
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Integration\Api;

use Comfino\Api\Client;
use Comfino\Api\Dto\Payment\LoanQueryCriteria;
use Comfino\Enum\LoanType;
use Comfino\Enum\ProductListType;
use Comfino\Shop\Order\Cart;
use Comfino\Shop\Order\Cart\CartItem;
use Comfino\Shop\Order\Cart\Product;
use Comfino\Shop\Order\Customer;
use Comfino\Shop\Order\LoanParameters;
use Comfino\Shop\Order\Order;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Sunrise\Http\Client\Curl\Client as CurlClient;

final class ClientTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $apiKey = getenv('COMFINO_SANDBOX_API_KEY');

        if (empty($apiKey)) {
            $this->markTestSkipped(
                'Integration tests require a sandbox API key. '
                . 'Set COMFINO_SANDBOX_API_KEY environment variable or add it to a local phpunit.xml.'
            );
        }

        $psr17Factory = new Psr17Factory();
        $httpClient = new CurlClient($psr17Factory);

        $this->client = new Client($httpClient, $psr17Factory, $psr17Factory, $apiKey);
        $this->client->enableSandboxMode();
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testIsShopAccountActive(): void
    {
        $this->assertTrue($this->client->isShopAccountActive());
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetWidgetKey(): void
    {
        $this->assertNotEmpty($this->client->getWidgetKey());
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetWidgetTypes(): void
    {
        $response = $this->client->getWidgetTypes();

        $this->assertNotEmpty($response->widgetTypes);
        $this->assertCount(count($response->widgetTypesWithNames), $response->widgetTypes);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetProductTypesForPaywall(): void
    {
        $response = $this->client->getProductTypes(ProductListType::PAYWALL);

        $this->assertNotEmpty($response->productTypes);
        $this->assertCount(count($response->productTypesWithNames), $response->productTypes);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetProductTypesForWidget(): void
    {
        $response = $this->client->getProductTypes(ProductListType::WIDGET);

        $this->assertNotEmpty($response->productTypes);
        $this->assertNotEmpty($response->productTypesWithNames);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetFinancialProducts(): void
    {
        $response = $this->client->getFinancialProducts(new LoanQueryCriteria(loanAmount: 150000));

        $this->assertNotEmpty($response->financialProducts);

        $firstProduct = $response->financialProducts[0];

        $this->assertNotEmpty($firstProduct->name);
        $this->assertNotEmpty($firstProduct->type->getValue());
        $this->assertGreaterThan(0, $firstProduct->instalmentAmount);
        $this->assertGreaterThan(0, $firstProduct->toPay);
        $this->assertGreaterThan(0, $firstProduct->loanTerm);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetFinancialProductsWithLoanTermFilter(): void
    {
        $this->assertNotEmpty(
            $this->client->getFinancialProducts(new LoanQueryCriteria(loanAmount: 150000, loanTerm: 12))
                ->financialProducts
        );
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetFinancialProductDetails(): void
    {
        // First, get available products to pick a valid type and term.
        $productsResponse = $this->client->getFinancialProducts(new LoanQueryCriteria(loanAmount: 150000));

        if (empty($productsResponse->financialProducts)) {
            $this->markTestSkipped('No financial products available in sandbox for this API key.');
        }

        $firstProduct = $productsResponse->financialProducts[0];
        $detailCriteria = new LoanQueryCriteria(
            loanAmount: 150000,
            loanTerm: $firstProduct->loanTerm,
            loanType: $firstProduct->type
        );

        $cart = new Cart(
            items: [
                new CartItem(
                    product: new Product(name: 'Test Product', price: 150000, id: 'PROD-001', category: 'Electronics'),
                    quantity: 1
                ),
            ],
            totalAmount: 150000
        );

        $response = $this->client->getFinancialProductDetails($detailCriteria, $cart);

        $this->assertNotEmpty($response->financialProducts);
        $this->assertNotEmpty($response->financialProducts[0]->name);
        $this->assertGreaterThan(0, $response->financialProducts[0]->instalmentAmount);
        $this->assertGreaterThan(0, $response->financialProducts[0]->toPay);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testCreateAndGetOrder(): void
    {
        $orderId = 'SDK-TEST-' . time();

        $order = $this->buildTestOrder($orderId, 150000, 12, LoanType::INSTALLMENTS_ZERO_PERCENT);
        $createResponse = $this->client->createOrder($order);

        $this->assertNotEmpty($createResponse->status);
        $this->assertSame($orderId, $createResponse->externalId);
        $this->assertNotEmpty($createResponse->applicationUrl);

        // Retrieve the order.
        $response = $this->client->getOrder($orderId);

        $this->assertSame($orderId, $response->orderId);
        $this->assertNotEmpty($response->status);
        $this->assertNotEmpty($response->applicationUrl);
        $this->assertGreaterThan(0, $response->loanParameters->amount);
        $this->assertGreaterThan(0, $response->loanParameters->term);
        $this->assertNotEmpty($response->loanParameters->type->getValue());
        $this->assertNotEmpty($response->loanParameters->allowedProductTypes);
    }

    public function testValidateOrder(): void
    {
        $orderId = 'SDK-VALIDATE-' . time();
        $order = $this->buildTestOrder($orderId, 150000, 12, LoanType::INSTALLMENTS_ZERO_PERCENT);

        // validateOrder may return 200 (valid) or a 400 with validation details - both are acceptable.
        $this->assertContains($this->client->validateOrder($order)->errorCode, [200, 400]);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testCancelOrder(): void
    {
        $orderId = 'SDK-CANCEL-' . time();
        $order = $this->buildTestOrder($orderId, 150000, 12, LoanType::INSTALLMENTS_ZERO_PERCENT);

        $this->client->createOrder($order);

        // cancelOrder throws on failure; success means it returns void without exception.
        $this->client->cancelOrder($orderId);

        $this->addToAssertionCount(1);
    }

    public function testNotifyPluginRemoval(): void
    {
        $this->assertIsBool($this->client->notifyPluginRemoval()); // @phpstan-ignore method.alreadyNarrowedType
    }

    /**
     * Builds a minimal test order for use in create/validate/cancel tests.
     */
    private function buildTestOrder(string $orderId, int $amount, int $term, LoanType $loanType): Order
    {
        $product = new Product(
            name: 'Test Product',
            price: $amount,
            id: 'PROD-001',
            category: 'Electronics'
        );
        $cartItem = new CartItem(product: $product, quantity: 1);
        $cart = new Cart(items: [$cartItem], totalAmount: $amount);

        $loanParameters = new LoanParameters(amount: $amount, term: $term, type: $loanType);

        $customer = new Customer(
            firstName: 'Jan',
            lastName: 'Testowy',
            email: 'jan.testowy@example.com',
            phoneNumber: '500000000',
            ip: '127.0.0.1',
            isRegular: false,
            isLogged: false
        );

        return new Order(
            id: $orderId,
            returnUrl: 'https://example.com/return',
            loanParameters: $loanParameters,
            cart: $cart,
            customer: $customer,
            notifyUrl: 'https://example.com/notify'
        );
    }
}
