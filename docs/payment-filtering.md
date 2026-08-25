# Payment Product Filtering

The SDK provides two complementary filtering mechanisms to determine which Comfino financial products (loan types) should be offered to the customer for a given cart.

## Product type filter chain

`ProductTypeFilterManager` runs a chain of filters to narrow down the available product types based on cart properties:

```php
use Comfino\Backend\Payment\ProductTypeFilterManager;
use Comfino\Backend\Payment\Filter\FilterByCartValueLowerLimit;
use Comfino\Backend\Payment\Filter\FilterByCartValueUpperLimit;
use Comfino\Backend\Payment\Filter\FilterByExcludedCategory;
use Comfino\Backend\Payment\Filter\FilterByProductType;

$filterManager = ProductTypeFilterManager::getInstance();

// Exclude products where cart value is below/above configured limits.
$filterManager->addFilter(new FilterByCartValueLowerLimit($minAmountByProductType));
$filterManager->addFilter(new FilterByCartValueUpperLimit($maxAmountByProductType));

// Exclude products when cart contains items from excluded categories.
$filterManager->addFilter(new FilterByExcludedCategory($categoryFilter));

// Restrict to a specific subset of product types.
$filterManager->addFilter(new FilterByProductType($allowedTypes));

// Get the filtered list for the current cart.
$allowedTypes = $filterManager->getAllowedProductTypes($cart, $availableTypes);
```

## Category tree

The `CategoryTree` + `CategoryFilter` pair is used to determine whether a product category — and by extension, a cart — is eligible for Comfino financing, based on a shop-level exclusion list.

### Building the tree

```php
use Comfino\Shop\Product\Category;
use Comfino\Shop\Product\CategoryManager;

$categories = [
    new Category(
        id: 1, name: 'Electronics', position: 0, children: [
            new Category(id: 10, name: 'Laptops', position: 0, children: []),
            new Category(id: 11, name: 'Phones', position: 1, children: []),
        ]
    ),
    new Category(id: 2, name: 'Clothing', position: 1, children: []),
];

$descriptor = CategoryManager::buildCategoryTree($categories);
```

### Using CategoryTree with a build strategy

Wrap the descriptor in a `CategoryTree` backed by a `BuildStrategyInterface` — this enables lazy loading in production (e.g. building from a database query only when the tree is first needed):

```php
use Comfino\Shop\Product\CategoryTree;
use Comfino\Shop\Product\CategoryTree\BuildStrategyInterface;
use Comfino\Shop\Product\CategoryTree\Descriptor;

$tree = new CategoryTree(new class($descriptor) implements BuildStrategyInterface {
    public function __construct(private readonly Descriptor $descriptor) {}
    public function build(): Descriptor { return $this->descriptor; }
});
```

### Checking availability

```php
use Comfino\Shop\Product\CategoryFilter;

$filter = new CategoryFilter($tree);

// Is category 10 ("Laptops") available when category 1 ("Electronics") is excluded?
// Returns false — "Laptops" category is a descendant of the "Electronics" category.
$isAvailable = $filter->isCategoryAvailable(categoryId: 10, excludedCategoryIds: [1]);

// Are all products in the cart from available categories?
$isCartValid = $filter->isCartValid($cart, excludedCategoryIds: [2]);
```

A category is considered **unavailable** if it exactly matches or is a descendant of any excluded category.

### Direct tree traversal

```php
// Get a node by ID (cached after first lookup).
$node = $tree->getNodeById(10);

// Get all node IDs in the tree or a subtree.
$allIds = $tree->getNodeIds();
$subtreeIds = $tree->getNodeIds($tree->getNodeById(1));

// Get IDs along a path to the root.
$pathIds = $tree->getPathNodeIds($node->getPathToRoot());
```

## Implementing a custom filter

```php
use Comfino\Backend\Payment\ProductTypeFilterInterface;
use Comfino\Enum\LoanTypeInterface;
use Comfino\Shop\Cart;

class FilterByMinimumQuantity implements ProductTypeFilterInterface
{
    public function __construct(private readonly int $minimumQuantity) {}

    public function isAllowed(LoanTypeInterface $productType, Cart $cart): bool
    {
        return $cart->getTotalItemsCount() >= $this->minimumQuantity;
    }
}

$filterManager->addFilter(new FilterByMinimumQuantity(minimumQuantity: 2));
```
