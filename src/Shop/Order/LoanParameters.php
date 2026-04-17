<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Order
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Order;

use Comfino\Enum\LoanTypeInterface;

/**
 * Represents loan parameters for the Comfino payment gateway API.
 */
class LoanParameters implements LoanParametersInterface
{
    /**
     * @param int $amount Loan amount
     * @param int|null $term Loan term in months
     * @param LoanTypeInterface|null $type Loan type
     * @param LoanTypeInterface[]|null $allowedProductTypes Allowed product types
     */
    public function __construct(
        private readonly int $amount,
        private readonly ?int $term = null,
        private readonly ?LoanTypeInterface $type = null,
        private readonly ?array $allowedProductTypes = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getAmount(): int
    {
        return $this->amount;
    }

    /**
     * @inheritDoc
     */
    public function getTerm(): ?int
    {
        return $this->term;
    }

    /**
     * @inheritDoc
     */
    public function getType(): ?LoanTypeInterface
    {
        return $this->type;
    }

    /**
     * @inheritDoc
     */
    public function getAllowedProductTypes(): ?array
    {
        return $this->allowedProductTypes;
    }
}
