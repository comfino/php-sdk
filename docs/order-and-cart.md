# Building Order, Cart and Customer objects

## Using OrderFactory (recommended)

`OrderFactory` builds a complete `Order` object from flat parameters:

```php
use Comfino\Backend\Factory\OrderFactory;
use Comfino\Enum\LoanType;

$order = (new OrderFactory())->createOrder(
    orderId: 'ORDER-123',
    orderTotal: 150000,        // in cents, including delivery
    deliveryCost: 1500,        // in cents
    loanTerm: 12,              // months
    loanType: LoanType::INSTALLMENTS_ZERO_PERCENT,
    cartItems: $cartItems,     // CartItemInterface[]
    customer: $customer,       // CustomerInterface
    returnUrl: 'https://my-shop.com/order/confirm',
    notificationUrl: 'https://my-shop.com/comfino/webhook/status',
    // Optional:
    allowedProductTypes: null,
    deliveryNetCost: null,
    deliveryCostTaxRate: null,
    deliveryCostTaxValue: null,
    category: null
);
```

## Building objects manually

### Cart items and products

```php
use Comfino\Shop\Order\Cart\Product;
use Comfino\Shop\Order\Cart\CartItem;

$product = new Product(
    name: 'Laptop XYZ',
    price: 149999,           // gross price in cents
    id: 'SKU-001',           // shop product ID
    category: 'Electronics',
    ean: '1234567890123',
    photoUrl: 'https://my-shop.com/img/laptop.jpg',
    categoryIds: [10, 42],   // platform category IDs
    netPrice: 121950,
    taxRate: 23,             // percent
    taxValue: 28049
);

$cartItems = [new CartItem($product, quantity: 2)];
```

### Cart

```php
use Comfino\Shop\Order\Cart;

$cart = new Cart(
    items: $cartItems,
    totalAmount: 301498, // in cents, sum of items + delivery
    deliveryCost: 1500,
    deliveryNetCost: 1220,
    deliveryCostTaxRate: 23,
    deliveryCostTaxValue: 280,
    category: 'Electronics'
);
```

### Customer and address

```php
use Comfino\Shop\Order\Customer;
use Comfino\Shop\Order\Customer\Address;

$address = new Address(
    street: 'Testowa',
    buildingNumber: '1',
    apartmentNumber: '2A',
    postalCode: '00-001',
    city: 'Warszawa',
    countryCode: 'PL'
);

$customer = new Customer(
    firstName: 'Jan',
    lastName: 'Kowalski',
    email: 'jan.kowalski@example.com',
    phoneNumber: '+48123456789',
    ip: '127.0.0.1',
    taxId: '1234567890', // NIP / VAT ID (B2B)
    isRegular: true,
    isLogged: true,
    address: $address
);
```

### Assembling the order

```php
use Comfino\Shop\Order\Order;
use Comfino\Shop\Order\LoanParameters;
use Comfino\Enum\LoanType;

$order = new Order(
    id: 'ORDER-123',
    returnUrl: 'https://my-shop.com/order/confirm',
    loanParameters: new LoanParameters(
        amount: 150000,
        term: 12,
        type: LoanType::INSTALLMENTS_ZERO_PERCENT
    ),
    cart: $cart,
    customer: $customer,
    notifyUrl: 'https://my-shop.com/comfino/webhook/status'
);
```

## Notes

- All monetary values are **integers in cents** (e.g. 1 PLN = 100).
- String fields (name, category, EAN, etc.) are automatically stripped of HTML tags on getter calls.
- `OrderFactory` is the recommended path for most integrations — it reduces boilerplate and keeps the constructor signature stable across SDK versions.
