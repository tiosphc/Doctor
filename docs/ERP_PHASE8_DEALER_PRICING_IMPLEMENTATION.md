# ERP Phase 8 — Dealer pricing integration

## Scope and existing pricing audit

Phase 8 connects active Dealer Account membership and the effective Dealer Tier to the existing Tier/SKU price rows. The existing `dealer_tiers`, `price_lists` and `price_list_items` tables, Product/SKU master, `DealerContextService`, `DealerTierService` and `DealerPricingService` are reused. No pricing table or migration was added. The Retail resolver and public Retail catalog remain independent.

Before this phase, `DealerPricingService::resolve(ProductVariant, DealerTier, quantity)` required an explicitly trusted Tier, selected active VND Tier price rows for an active Dealer-sellable SKU, rejected missing/ambiguous rows, and enforced MOQ. It was not connected to a Dealer Account. Phase 8 keeps the strict MOQ behavior as the default for existing callers, adds a read-only mode for quoting below MOQ, and shares the same row selection with a batch catalog read. Dealer price-list priority selects the highest-priority valid item; a tie at that priority is `DEALER_PRICE_AMBIGUOUS`. No Retail fallback exists. A missing or wrong-currency Dealer price is `DEALER_PRICE_NOT_FOUND`.

## Authoritative path

`DealerEffectivePricingService` requires a Customer-role User, resolves active membership and Account through `DealerContextService`, then calls `DealerTierService::resolve()` for the effective Tier. Active manual overrides immediately affect pricing; expired/cancelled overrides revert to the current Tier, and an Admin Tier change is reflected on the next read. An unassigned Tier fails with `DEALER_TIER_NOT_ASSIGNED`; an inactive Tier fails with `DEALER_TIER_INACTIVE`. The client cannot choose a Tier, price, currency, or price list. Quote input accepts only `product_variant_id` and positive `quantity`; spoofed commercial fields receive validation errors. Quantity is checked against the SKU Unit precision (maximum three decimal places) without floating-point calculations.

The Dealer quote returns Account, base/effective Tier, pricing source, Product/SKU, quantity, VND unit price, line total, `minimum_quantity`, `meets_moq`, and a stable SHA-256 fingerprint. MOQ means minimum Dealer order quantity, not a volume discount: quoting a positive quantity below MOQ returns the valid price with `meets_moq=false`. The existing strict resolver still raises `DEALER_MOQ_NOT_MET` when used for a command requiring MOQ. The fingerprint includes Account/effective Tier/override, SKU, selected list/item identity, price, MOQ, currency, row update times and effective dates. It is a future stale-price signal, **not** authorization or an Order snapshot. No reservation or write occurs on quote/catalog reads.

## API and catalog

All routes are under `auth:sanctum` and require active membership in the explicit active Dealer Account:

| Method | Route | Result |
| --- | --- | --- |
| GET | `/api/dealer/accounts/{dealer}/products` | Paginated products with at least one currently priced Dealer-sellable SKU; optional `search`, `page`, `per_page` |
| GET | `/api/dealer/accounts/{dealer}/products/{product}` | Product by slug or ID with only currently priced Dealer variants |
| POST | `/api/dealer/accounts/{dealer}/pricing/quote` | Authoritative read-only quote from `product_variant_id` and `quantity` |

The catalog uses shared Product, Variant, Unit and public-disk Product images. It resolves context/Tier once per request, eager loads Product images/variants, and batch resolves price items. Unpriced variants are excluded from list/detail; a Product with no current Dealer price is absent and detail returns 404. Direct quote on an unpriced SKU returns `DEALER_PRICE_NOT_FOUND`. Inactive or Retail-only SKUs return `DEALER_SKU_NOT_SELLABLE`. Dealer responses omit internal price-list IDs, Admin audit reasons/actors, cost, exact stock and Retail price. Image URLs use the public disk; deployment still requires the public storage link and persistent storage.

## Frontend and authorization

Approved active members see “Sản phẩm đại lý” beside the Dealer profile navigation. `/dealer/products` supports an explicit Account selector for multiple memberships, search, pagination, Tier summary and responsive Product cards with price/MOQ. `/dealer/products/:slug` shows images, description, priced variant selection, Unit, current Dealer price and a read-only quantity quote. There is no order or cart CTA. A suspended Account or removed membership loses commercial API access; the UI displays the API error. Public, unrelated Customer, Doctor and Receptionist calls cannot read another Account's Dealer pricing. Normal `/products`, Retail Cart and Retail Checkout remain Retail-priced.

Admin Tier/SKU pricing stays in the existing Product wizard/editor. Phase 8 tightens Dealer price validation to require a value greater than zero; active Tier, SKU membership, positive integer MOQ and duplicate Tier/SKU validation were already present. Price-list/item effective dates and status are honored by the resolver. Direct legacy rows with an incompatible currency are not quoted.

## Verification and limits

Focused Phase 8 feature tests cover trusted context, explicit Account isolation, Tier override/change, Admin price edit freshness, missing/ambiguous/inactive/future/wrong-currency rows, Product/SKU eligibility, Retail Catalog/Cart/Checkout isolation, MOQ and Unit precision, spoofed input and unassigned Tier. They also deny a Doctor even if an invalid membership row is attached. The existing Product pricing tests preserve strict MOQ and Retail separation. Focused Dealer/Product/Retail tests passed 24/265; the final full backend suite passed 459 tests/2,995 assertions. Frontend TypeScript, lint (0 errors, 18 pre-existing Fast Refresh warnings), production build and Bun tests 8/30 passed. Pint and Git whitespace audit passed. No authenticated visual browser session was run.

**DEALER ORDERING IS NOT IMPLEMENTED. DEALER CART IS NOT IMPLEMENTED. QUICK ORDER IS NOT IMPLEMENTED. EXCEL IMPORT IS NOT IMPLEMENTED. AUTOMATIC REVENUE TIER IS NOT IMPLEMENTED.** Dealer Checkout, Payment, credit/debt, Promotion and Return/Refund are also outside Phase 8. A quote is informational current pricing; a later order command must revalidate context, price/fingerprint, MOQ and inventory.

The development database had no Dealer Accounts or configured initial Tier at the Phase 7 handoff, so authenticated visual Dealer Catalog checks require Admin to configure an initial Tier and approve a real application first. API feature tests use isolated factories; they do not seed business data into development.
