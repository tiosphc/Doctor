# ERP Phase 2 — Product Master, SKU and Retail Pricing Foundation

## Scope and audit

Implemented on 2026-09-23 as the first production package after the Retail + Dealer Unified Commerce design. Before this task, no Product, SKU, Product Image or Price List models, tables, APIs or pages existed. Clinic Service and Booking prices remain separate.

Initial Git status: main with only docs/ERP_CORE_DESIGN.md modified by the preceding architecture task (433 insertions, 363 deletions). That work was preserved. No commit was created.

The implementation reuses Laravel Form Requests, Eloquent relationships, Sanctum admin middleware, public disk uploads, the existing frontend API client, TanStack Query, file routes and responsive Tailwind UI shell.

## Database and models

Two additive migrations create eight tables and add item status:

| Table | Identity and relationship | Lifecycle / constraints |
| --- | --- | --- |
| product_categories | unique normalized code, optional self parent_id | active/inactive, restricted FK, cycle check and 20 ancestor limit |
| brands | unique normalized code | active/inactive |
| units | unique normalized code, symbol, decimal_precision 0–3 | active/inactive; one base unit per SKU |
| products | PK-derived unique immutable product_code, unique slug, category, optional brand | draft/active/inactive; track flags and decimal low-stock threshold |
| product_variants | globally unique normalized sku, product, unit, optional unique barcode | active/inactive; Retail/Dealer/Clinic capabilities and track flags |
| product_images | product, optional same-product SKU, public-disk path | ordered, one preferred primary per product |
| price_lists | unique normalized code, Retail context, all-warehouse scope, VND | active/inactive, effective range and priority |
| price_list_items | price list and SKU | decimal price/quantity, effective range, active/inactive |

All master references use restricted delete. Normal APIs activate or inactivate rather than delete Products, SKUs, masters or prices. Image removal is the sole ordinary delete and removes its public-disk file. Product creation uses a database transaction: insert with a temporary unique code, derive PRD plus a zero-padded immutable primary key, and create a default SKU with the selected active unit. It never uses MAX(id)+1.

Sku::normalize() trims surrounding whitespace and uppercases a SKU. Validation permits uppercase letters, digits, dot, underscore and hyphen; the database unique index is the final concurrency guard. Category, Brand, Unit and Price List codes are likewise trimmed and uppercased. Product/SKU capability flags contain no stock balance or Dealer Tier.

Images reuse the Laravel public disk with 5 MB JPEG/PNG/WebP validation. The first image becomes primary; setting another primary clears the previous flag under a Product row lock. Removing the primary promotes the first image by sort order then ID. The public API emits a generated `/storage/products/...` URL and omits the stored `path`; the Admin API's `path` is relative to the public disk, not an absolute filesystem path. Public storage must be persisted and served through `public/storage -> storage/app/public`. The local `backend/public/storage` junction was present at final review.

## Retail price resolution

RetailPricingService is the backend authority for Retail prices. It accepts a SKU, currency and time; V1 accepts only VND, global warehouse scope and minimum quantity 1.000. It considers an active Product, active Retail-sellable SKU, active Retail list, active item, matching currency and inclusive list/item effective ranges. Highest numeric list priority wins. If two eligible items share the winning priority, the service returns HTTP 409 PRICE_AMBIGUOUS. If none match, it returns HTTP 409 PRICE_NOT_FOUND.

Item create/update and list update run in transactions with price-list row locks. Same-priority overlapping effective windows for one SKU are rejected. Activating an inactive list or item is rechecked. An item can be deactivated and replaced without hard deletion. List and item date ranges are validated. Public catalog and Product detail include only active, Retail-sellable SKUs with a currently resolvable Retail price. An unpriced SKU remains in Admin Product Master but cannot be selected publicly. A Product with no currently priced Retail SKU is absent from the catalog and its public detail returns 404. Direct price resolution for a missing SKU price still returns PRICE_NOT_FOUND and cannot be used for a purchase.

**Retail Price != Silver Dealer Price.** A normal User has no Dealer Tier and resolves only Retail context. sellable_dealer is master-data readiness; this phase has no Dealer price record, Dealer resolver, Dealer catalog or Dealer ordering workflow.

## API and authorization

Public GET APIs:

- /api/products: active Product and active Retail-sellable, currently priced SKUs; search by name, code or visible SKU; category/brand filters; newest or name sort; pagination.
- /api/products/{product}: detail by slug or numeric ID, category, brand, ordered images, active Retail-sellable SKUs with current Retail prices, unit and display-safe specifications. An unavailable Product returns 404.
- /api/product-filters: active category and brand options.

Public resources expose Retail amount/currency/context but no Dealer pricing, inventory balance, internal cost or Price List administration fields. Price sorting was omitted because it requires a correct aggregate of effective SKU prices.

Admin routes under /api/admin:

- product-master/{categories|brands|units}: list, create, show, update/status.
- products: list, create, show, update/status.
- products/{product}/variants: create; .../{variant}: update/status.
- products/{product}/images: upload; .../{image}: update metadata/primary or remove.
- retail-price-lists: list, create, show, update/status.
- retail-price-lists/{priceList}/items: create; .../{item}: update/status.

All writes use existing auth:sanctum plus admin middleware. Public and customer users share catalog visibility. Receptionist, doctor and customer roles cannot manage Product or Retail Pricing. This is the minimal Phase 1 authorization compatibility bridge; no role-system rewrite or Dealer permission model was added.

## Frontend

- /products and /products/:slug: responsive public catalog and detail with images, search/filter/sort/pagination, variant selector and effective Retail price. Informational copy does not simulate cart or checkout.
- /admin/products and /admin/products/:id: product search, category/brand filters, pagination, create and four sections for general data, SKUs, images and Retail pricing.
- /admin/product-master: create/edit/deactivate categories, brands and units.
- /admin/retail-pricing: create/manage Retail lists, activate/inactivate lists and items, and set SKU prices.

Product types, API calls and query keys are in frontend/src/types/product.ts and frontend/src/services/productApi.ts; forms use server validation errors and existing loading/error/empty components. Public and admin navigation have Product links.

## Verification

Development migrations applied additively as batches 22 and 23; no seed/demo products or prices inserted. At the final acceptance gate, focused PHPUnit passed 12 tests/132 assertions; full PHPUnit passed 372 tests/2,067 assertions, including Clinic/Booking/Customer regression. Frontend TypeScript, ESLint and production build passed; ESLint retains the same 18 pre-existing Fast Refresh warnings with zero errors. Pint and Git diff whitespace check passed. Earlier local HTTP smoke returned 200 for public catalog/filter APIs and public/admin Product and Retail Pricing page routes; the temporary servers were stopped afterward. The working tree still includes the pre-existing ERP architecture document edit and this uncommitted Phase 2 package.

## Final acceptance gate — PASS

The original detail query included active Retail SKUs without a current price. Its resource then resolved every variant and returned `PRICE_NOT_FOUND` for the whole Product. Detail now uses the same priced-SKU visibility query as the catalog. A three-variant fixture (SKU-A and SKU-B priced, SKU-C unpriced) verifies HTTP 200 with only A/B in the public detail and list, while Admin Product Master retains all three. A Product with zero priced Retail SKUs is absent from the public list and returns 404 on detail. Direct resolution of an unpriced SKU still returns `PRICE_NOT_FOUND`.

The Retail-only resolver remains backend authoritative, rejects missing prices and same-priority ambiguity with HTTP 409, and has no Dealer fallback. The public image response contains a generated URL and no storage `path`; tests also verify the uploaded file exists on the public disk. Git status, diff/stat and untracked-file audit found only the pre-existing architecture edit and Phase 2 Product/Pricing files. No Warehouse, Inventory, Cart, Checkout, Sales Order, Dealer Account/Tier pricing, Excel Import, Payment, Return/Refund or Promotion implementation was added.

## V1 limits and future dependencies

There is no Warehouse table or warehouse-specific price in Phase 2; scope_type=all means an all-warehouse Retail price. Warehouse-first precedence begins only after the Warehouse/Inventory phase adds stable warehouse identity. Currency is VND, Retail quantity breakpoint is fixed at one base unit, and there is no unit conversion. No production catalog content is seeded. The frontend has no dedicated browser test framework, so acceptance relies on backend contract tests plus typecheck/lint/build.

Dealer Application/Account/Tier/price resolution, cart, checkout, Sales Order, warehouse/stock, Excel import, payment, returns, promotions, Clinic Material Usage and guest Retail checkout remain outside this package. The next recommended phase is Warehouse + Inventory Core; it has not been started.
