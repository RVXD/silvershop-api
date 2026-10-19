# silvershop/api

A headless **REST** API for [SilverShop](https://github.com/silvershop/silvershop-core) (SilverStripe CMS 6): read the
catalog, drive the cart, read orders, manage the customer's own profile/addresses, issue scoped access tokens, and emit
order/product **webhooks** — for SPAs, mobile apps, PWAs and ERP/marketplace sync.

It is a **backwards-compatible superset of core's `api/v1`**: it takes over the `api/v1` route from core's
`WebServiceController` and keeps the existing `products` and `cart` contracts (same fields, CSRF behaviour and JSON/XML
content negotiation), then layers on new resources, pagination, token auth and webhooks. Existing `api/v1/products` /
`api/v1/cart` consumers keep working — the product payload only *gains* fields.

> **GraphQL is not part of this module.** The REST base is deliberately graphql-free (no `silverstripe/graphql`
> dependency, no build step). A GraphQL schema is a separate, optional `silvershop/api-graphql` overlay.

## Requirements

- `silvershop/core` ^6, `silverstripe/framework` ^6, `silverstripe/cms` ^6
- PHP 8.3 / 8.4

## Installation

```bash
composer require silvershop/api
```

Then run `dev/build?flush=1`. The API is mounted at `/api/v1/`.

## Authentication

Two mechanisms, matching core:

1. **Bearer tokens** (server-to-server / headless). Create an **API token** in the CMS under *API* (or
   programmatically). The plaintext token is shown **once** at creation — only its SHA-256 hash is stored. Present it as:

   ```
   Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   ```

   Each token belongs to a `Member` (whose `canView()`/`canEdit()` permissions back the resources) and carries one or
   more **scopes**:

   | Scope   | Grants |
   |---------|--------|
   | `read`  | read catalog, cart, own orders and profile |
   | `write` | modify cart, profile and addresses (implies `read`) |
   | `admin` | create/update products (implies `write`) |

   Tokens may carry an optional expiry.

2. **Session + CSRF** (same-origin storefront JS). Cart writes from a logged-in/same-origin session work exactly as
   core: a valid `SecurityID` (CSRF token) is required. Bearer-token clients skip CSRF (the token *is* the auth).

**Public read:** the live catalog is readable without a token by default. Set
`SilverShop\Api\Control\ApiController.public_read: false` to require a `read`-scope token for catalog reads too.

## Endpoints

All responses negotiate **JSON** (default) or **XML** via `?format=json|xml`, a `.json`/`.xml` suffix, or the `Accept`
header. List endpoints return a bare array body plus pagination headers (`X-Total-Count`, `X-Page-Start`,
`X-Page-Limit`, `X-Total-Pages`).

### Catalog (read; `read` scope or public)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/products` | List published products |
| GET | `/api/v1/products/{id\|urlSegment}` | A single product |
| GET | `/api/v1/products/{id}/variations` | A product's variations |
| GET | `/api/v1/categories` | List categories |
| GET | `/api/v1/categories/tree` | The nested category tree |
| GET | `/api/v1/categories/{id\|urlSegment}` | A single category |
| GET | `/api/v1/variations` / `/variations/{id}` | Variations |
| GET | `/api/v1/search?q=…` | Product search (requires `silvershop/search`) |

**List params:** `start`, `limit`, `sort` (`sort=Title`, `sort=-BasePrice`, comma-separated), and
`filter[Field]=value` (products: `Title`, `InternalItemID`, `Model`, `Featured`, and `category` for recursive category
filtering).

### Cart

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/cart` | The current cart |
| POST | `/api/v1/cart/add` | Add a buyable (`ProductID`, optional `Buyable`, `quantity`) |
| POST | `/api/v1/cart/update` | Set a buyable's quantity |
| POST | `/api/v1/cart/remove` | Remove a buyable |
| POST | `/api/v1/cart/clear` | Empty the cart |
| GET | `/api/v1/cart/checkout` | **Checkout hand-off**: returns the hosted `checkoutUrl` (no API payment capture) |

Cart writes need the `write` scope (bearer) or a valid CSRF token (session). A bearer-token cart is bound to the
token's member, so it persists across stateless requests.

### Orders & customer (`read`/`write` scope; own data only)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/orders` | The member's placed orders |
| GET | `/api/v1/orders/{id}` | A single order the member owns |
| GET | `/api/v1/customer` | Profile + address book |
| PUT/PATCH | `/api/v1/customer` | Update profile (first name, surname) |
| GET | `/api/v1/customer/addresses` | List addresses |
| POST | `/api/v1/customer/addresses` | Create an address |
| GET/PUT/PATCH | `/api/v1/customer/addresses/{id}` | Read/update an address |
| GET | `/api/v1/customer/orders` | Order history |

### Admin catalog write (`admin` scope + `canEdit()`/`canCreate()`)

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/v1/products` | Create a product (published) |
| PUT/PATCH | `/api/v1/products/{id}` | Update a product (published) |

## Extending — resources & serializers

Resources are registered in a config-ordered **`ResourceRegistry`** (the suite's provider/registry pattern), and each
resource serialises its model through a dedicated **serializer** that fires an `updateSerialised<Resource>` extension
hook (generalising core's single `updateSerialisedProduct`):

```yaml
SilverShop\Api\Resource\ResourceRegistry:
  resources:
    reviews: '%$My\Module\Api\ReviewResource'

SilverShop\Api\Serializer\ProductSerializer:
  extensions:
    - My\Module\Api\MyProductEnrichment   # implements updateSerialisedProduct(&$data, $product)
```

This module ships guarded enrichers that add fields to the product payload when the sibling module is installed:
**brand** (brands), **colours** (colors), **rating** (reviews), **gallery** (product-gallery), **downloadable**
(downloads).

## Webhooks

Manage endpoints in the CMS under *API → Webhooks* (or via the `SilverShop\Api\Webhook\WebhookService.endpoints`
config map). Events:

- `order.placed` — a cart becomes an order (`onPlaceOrder`)
- `order.paid` — payment settled (`onPaid`)
- `order.statuschange` — order status transition (`onStatusChange`)
- `product.published` — a product is published

Each delivery is a JSON `POST` of `{event, timestamp, data}`. When a `Secret` is set it is signed with
`X-SilverShop-Signature: sha256=<hmac>`. Deliveries run as a background job when
`symbiote/silverstripe-queuedjobs` is installed (suggested), and inline otherwise.

## CORS

Off by default. Enable for browser-based headless clients:

```yaml
SilverShop\Api\Control\ApiController:
  cors:
    enabled: true
    allow_origin: 'https://my-spa.example.com'
```

## Configuration

```yaml
SilverShop\Api\Control\ApiController:
  public_read: true        # false = catalog reads require a read-scope token
  default_page_size: 30
  max_page_size: 100
```

## License

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
