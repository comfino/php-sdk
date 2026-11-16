# Payment Product Filtering

The SDK provides two complementary filtering mechanisms to determine which ComfinoPay financial products (loan types) should be offered to the customer for a given cart.

## Product type filter chain

`ProductTypeFilterManager` runs a chain of filters to narrow down the available product types based on cart properties:

```php
use Comfino\Backend\Payment\ProductTypeFilterManager;
use Comfino\Backend\Payment\Filter\FilterByCartValueLowerLimit;
use Comfino\Backend\Payment\Filter\FilterByCartValueUpperLimit;
use Comfino\Backend\Payment\Filter\FilterByExcludedCategory;
use Comfino\Backend\Payment\Filter\FilterByProductType;

$filterManager = ProductTypeFilterManager::getInstance($tenantScope); // '' for single-shop plugins

// Exclude products where cart value is below/above configured limits.
$filterManager->addFilter(new FilterByCartValueLowerLimit($minAmountByProductType));
$filterManager->addFilter(new FilterByCartValueUpperLimit($maxAmountByProductType));

// Exclude products when cart contains items from excluded categories.
$filterManager->addFilter(new FilterByExcludedCategory($categoryFilter, $excludedCategoryIdsByProductType));

// Restrict to a specific subset of product types.
$filterManager->addFilter(new FilterByProductType($allowedTypes));

// Get the filtered list for the current cart.
$allowedTypes = $filterManager->getAllowedProductTypes($availableTypes, $cart);
```

## Category tree

The `CategoryTree` + `CategoryFilter` pair is used to determine whether a product category — and by extension, a cart — is eligible for ComfinoPay financing, based on a shop-level exclusion list.

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

## Opt-in lists and block lists (tags and categories)

Shop products can be assigned to financial products by **tags** or **categories**, in two modes:

| Mode | Tags | Categories | Semantics for a listed product type |
|---|---|---|---|
| **Block list** | `FilterByExcludedTag` | `FilterByExcludedCategory` | Removed when **any** cart product has a listed tag / is in a listed category (or subcategory). |
| **Opt-in list** | `FilterByRequiredTag` | `FilterByRequiredCategory` | Kept only when **every** cart product has a listed tag / is in a listed category (or subcategory). |

Product types missing from the map are never restricted, and an empty cart is never restricted.

```php
use Comfino\Backend\Payment\Filter\FilterByExcludedTag;
use Comfino\Backend\Payment\Filter\FilterByRequiredCategory;
use Comfino\Backend\Payment\Filter\FilterByRequiredTag;

// 0% instalments only when every product carries tag "raty0-12" or "raty0-24".
$filterManager->addFilter(new FilterByRequiredTag(['INSTALLMENTS_ZERO_PERCENT' => ['raty0-12', 'raty0-24']]));
// Never "pay later" for products tagged "no-paylater".
$filterManager->addFilter(new FilterByExcludedTag(['PAY_LATER' => ['no-paylater']]));
// Leasing only when every product is in the "Machinery" subtree (category 40).
$filterManager->addFilter(new FilterByRequiredCategory($categoryFilter, ['LEASING' => [40]]));
```

### Product tags

Tags are an optional product capability, `Comfino\Shop\Product\TaggedProductInterface::getTags(): ?string[]`. The
SDK `Product` implements it through the trailing `tags` constructor argument.

Tags are identified by their **names**, not by numeric IDs. Category names may contain spaces and several words, so
categories are referenced by ID. Tag names are short single tokens without spaces (e.g. `raty0-12`), so they serve
directly as identifiers, and the same values work in backend rules, in admin configuration and in the frontend
payload. `TagFilter::normalizeTags()` trims names and drops empty values and duplicates. After that, names are
compared **exactly** (case-sensitive) by default.

Every tag-matching component accepts a `caseInsensitive` flag: `TagFilter`, `FilterByRequiredTag`,
`FilterByExcludedTag` and `TagMatcher`. When the flag is set, names are compared case-insensitively with
multibyte-aware lowercasing (`mb_strtolower`, UTF-8), so `RATY-ŁÓDŹ` matches `raty-łódź`. This is why the SDK
requires `ext-mbstring`. The flag affects matching only: `getProductTags()`, `Cart::getCartTags()` and
`Cart::getAsArray()` keep the original spelling.

```php
$filterManager->addFilter(new FilterByRequiredTag(['INSTALLMENTS_ZERO_PERCENT' => ['raty0-12']], caseInsensitive: true));
$ruleSet = new TermLimitRuleSet(new TagMatcher(caseInsensitive: true), MatchMode::ALLOW_LIST, $rules);
```

```php
use Comfino\Shop\Order\Cart\Product;

$product = new Product(name: 'Laptop', price: 399900, id: '1234', categoryIds: [10, 100], tags: ['raty0-12']);
```

This interface is separate from the api-client `ProductInterface` because tags are never sent to the API. Products
that do not implement it, or that return `null`, count as untagged: they always pass a block list and never pass an
opt-in list. `Cart::getCartTags()` returns the union of the cart's tags, and `Cart::getAsArray()` emits a
product-level `tags` list (`null` for products without tag data) next to `categoryIds` for frontend filtering. `TagFilter` and `CategoryFilter` expose
the underlying checks at category, product and cart level:

| | Block list | Opt-in list |
|---|---|---|
| Category | `CategoryFilter::isCategoryAvailable()` | `CategoryFilter::isCategoryEligible()` |
| Product | `TagFilter::isProductAvailable()`, `CategoryFilter::isProductAvailable()` | `TagFilter::isProductEligible()`, `CategoryFilter::isProductEligible()` |
| Cart | `TagFilter::isCartValid()`, `CategoryFilter::isCartValid()` | `TagFilter::isCartEligible()`, `CategoryFilter::isCartEligible()` |

## Term limits per tag or category (`Comfino\Backend\Payment\TermLimit`)

The filters above decide **whether** a product type is offered. Term-limit rules also decide **which instalment
counts** are allowed, and produce the `allowedProductsConfig` sent with the order, paywall and financial-products
requests.

- `TermConstraint`: an immutable set of allowed terms with the API semantics (`minTerm`, `maxTerm` and the `terms`
  whitelist must all hold). `intersect()` is always exact. `union()` is exact as well: overlapping or adjacent ranges
  merge into a range; anything else becomes a whitelist, with open-ended ranges enumerated up to
  `DEFAULT_TERM_CEILING` (120).
- `TermLimitRule(productType, attributes, ?TermConstraint)`: binds tag names (with `TagMatcher`) or category IDs
  (with `CategoryMatcher`) to a product type.
- `TermLimitRuleSet(matcher, MatchMode, rules)`: resolves the rules against a cart. The matcher is `TagMatcher`,
  `CategoryMatcher`, or your own `ProductMatcherInterface`.
  - `MatchMode::ALLOW_LIST`: a ruled type is allowed only when every product matches a rule. Rules matched by one
    product are **united** (the most permissive wins for that product); a rule without a constraint grants any term.
  - `MatchMode::BLOCK_LIST`: a matched rule without a constraint blocks the type. A matched rule with a constraint
    restricts that product, and several matched rules are **intersected**. Unmatched products are unrestricted.
  - In both modes the cart constraint is the **intersection** over all products. An unsatisfiable result blocks the
    type.
- `TermLimitResolution`: the outcome, with blocked types plus one constraint per type. `merge()` intersects
  resolutions from different sources. `getAllowedProductsConfig()` returns exactly one entry per type, which matters
  because the API keeps only one entry per type.
- `TermLimitResolver(ruleSets, globalAllowedProductsConfig)`: merges the merchant's global term limits with every
  rule set. Results are memoized per `Cart` instance.
- `FilterByTermLimits(resolver)`: removes the types the resolution blocks, inside `ProductTypeFilterManager`.

```php
use Comfino\Backend\Payment\AllowedProductsConfigBuilder;
use Comfino\Backend\Payment\Filter\FilterByTermLimits;
use Comfino\Backend\Payment\TermLimit\CategoryMatcher;
use Comfino\Backend\Payment\TermLimit\MatchMode;
use Comfino\Backend\Payment\TermLimit\TagMatcher;
use Comfino\Backend\Payment\TermLimit\TermConstraint;
use Comfino\Backend\Payment\TermLimit\TermLimitResolver;
use Comfino\Backend\Payment\TermLimit\TermLimitRule;
use Comfino\Backend\Payment\TermLimit\TermLimitRuleSet;
use Comfino\Enum\LoanType;

$zero = LoanType::INSTALLMENTS_ZERO_PERCENT;

$resolver = new TermLimitResolver(
    [
        // Tag "raty0-12" grants 0% up to 12 terms, tag "raty0-24" up to 24 terms.
        new TermLimitRuleSet(new TagMatcher(), MatchMode::ALLOW_LIST, [
            new TermLimitRule($zero, ['raty0-12'], TermConstraint::of(maxTerm: 12)),
            new TermLimitRule($zero, ['raty0-24'], TermConstraint::of(maxTerm: 24)),
        ]),
        // Products in category 7 (and below) never get "pay later".
        new TermLimitRuleSet(new CategoryMatcher($categoryFilter), MatchMode::BLOCK_LIST, [
            new TermLimitRule(LoanType::PAY_LATER, [7]),
        ]),
    ],
    AllowedProductsConfigBuilder::fromPersistedArray($globalTermLimits) // Optional
);

$filterManager->addFilter(new FilterByTermLimits($resolver));

$allowedProductTypes = $filterManager->getAllowedProductTypes($availableTypes, $cart);
$allowedProductsConfig = $resolver->getAllowedProductsConfig($cart); // Same resolution, memoized.
```

| Cart (tags) | `INSTALLMENTS_ZERO_PERCENT` |
|---|---|
| A[raty0-24] | allowed, `maxTerm 24` |
| A[raty0-24] + B[raty0-12] | allowed, `maxTerm 12` |
| A[raty0-12, raty0-24] | allowed, `maxTerm 24` |
| A[raty0-24] + C[] | blocked |

## Implementing a custom filter

```php
use Comfino\Backend\Payment\ProductTypeFilterInterface;
use Comfino\Shop\Cart;

class FilterByMinimumQuantity implements ProductTypeFilterInterface
{
    public function __construct(private readonly int $minimumQuantity) {}

    public function getAllowedProductTypes(array $availableProductTypes, Cart $cart): array
    {
        return $cart->getTotalItemsCount() >= $this->minimumQuantity ? $availableProductTypes : [];
    }

    public function getAsArray(): array
    {
        return ['minimumQuantity' => $this->minimumQuantity];
    }
}

$filterManager->addFilter(new FilterByMinimumQuantity(minimumQuantity: 2));
```
