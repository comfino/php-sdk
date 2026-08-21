<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Order;

use Comfino\Api\Dto\Payment\AllowedProductConfig;

/**
 * Order class representing a deferred payment transaction with Comfino payment gateway (loan application).
 */
class Order implements OrderInterface
{
    /**
     * @param string $id Shop internal order ID
     * @param string $returnUrl URL to redirect the customer to after the payment is completed
     * @param LoanParametersInterface $loanParameters Loan parameters for the order
     * @param CartInterface $cart Shop cart
     * @param CustomerInterface $customer Customer associated with the order
     * @param string|null $notifyUrl URL of the Comfino API callback
     * @param SellerInterface|null $seller Seller associated with the order
     * @param string|null $accountNumber Account number associated with the order
     * @param string|null $transferTitle Transfer title associated with the order
     * @param AllowedProductConfig[]|null $allowedProductsConfig Per-product-type term constraints
     */
    public function __construct(
        private readonly string $id,
        private readonly string $returnUrl,
        private readonly LoanParametersInterface $loanParameters,
        private readonly CartInterface $cart,
        private readonly CustomerInterface $customer,
        private readonly ?string $notifyUrl = null,
        private readonly ?SellerInterface $seller = null,
        private readonly ?string $accountNumber = null,
        private readonly ?string $transferTitle = null,
        private readonly ?array $allowedProductsConfig = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @inheritDoc
     */
    public function getNotifyUrl(): ?string
    {
        return $this->notifyUrl !== null ? trim(strip_tags($this->notifyUrl)) : null;
    }

    /**
     * @inheritDoc
     */
    public function getReturnUrl(): string
    {
        return trim(strip_tags($this->returnUrl));
    }

    /**
     * @inheritDoc
     */
    public function getLoanParameters(): LoanParametersInterface
    {
        return $this->loanParameters;
    }

    /**
     * @inheritDoc
     */
    public function getCart(): CartInterface
    {
        return $this->cart;
    }

    /**
     * @inheritDoc
     */
    public function getCustomer(): CustomerInterface
    {
        return $this->customer;
    }

    /**
     * @inheritDoc
     */
    public function getSeller(): ?SellerInterface
    {
        return $this->seller;
    }

    /**
     * @inheritDoc
     */
    public function getAccountNumber(): ?string
    {
        return $this->accountNumber !== null ? trim(html_entity_decode(strip_tags($this->accountNumber))) : null;
    }

    /**
     * @inheritDoc
     */
    public function getTransferTitle(): ?string
    {
        return $this->transferTitle !== null ? trim(html_entity_decode(strip_tags($this->transferTitle))) : null;
    }

    /**
     * @inheritDoc
     */
    public function getAllowedProductsConfig(): ?array
    {
        return $this->allowedProductsConfig;
    }
}
