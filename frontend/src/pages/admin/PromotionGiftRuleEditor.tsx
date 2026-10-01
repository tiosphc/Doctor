import { Gift, Package } from "lucide-react";
import { formatProductQuantity, isPositiveProductQuantity } from "@/lib/productQuantity";
import type { Product } from "@/types/product";
import type { SalesPromotionGiftRule } from "@/services/salesPromotionApi";
import { fieldClass } from "./ProductAdminShared";
import { PromotionProductPicker } from "./PromotionProductPicker";
import type { GiftMode } from "./promotionGiftRuleState";

type Props = {
    rule: SalesPromotionGiftRule;
    mode: GiftMode;
    buyProductId: number | null;
    giftProductId: number | null;
    buyProduct: Product | undefined;
    giftProduct: Product | undefined;
    buyLoading: boolean;
    giftLoading: boolean;
    buyError: boolean;
    giftError: boolean;
    salesScope: "retail" | "dealer" | "both";
    dealerTierIds: number[];
    dealerTiers: { id: number; name: string }[];
    errors: Record<string, string>;
    onChooseBuy: (product: Product) => void;
    onChooseGift: (product: Product) => void;
    onClearBuy: () => void;
    onClearGift: () => void;
    onModeChange: (mode: GiftMode) => void;
    onRuleChange: (changes: Partial<SalesPromotionGiftRule>) => void;
    onTierToggle: (id: number) => void;
};

function ProductIdentity({ product, badge }: { product: Product | undefined; badge?: string }) {
    const image = product?.images.find((item) => item.is_primary) ?? product?.images[0];
    return (
        <div className="flex min-w-0 items-center gap-3">
            <span className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted">
                {image?.url ? (
                    <img src={image.url} alt="" className="size-full object-cover" />
                ) : (
                    <Package className="size-6 text-muted-foreground" aria-hidden="true" />
                )}
            </span>
            <span className="min-w-0">
                <strong className="block truncate text-sm text-primary">
                    {product?.name ?? "Đang tải sản phẩm..."}
                </strong>
                <span className="block truncate text-xs text-muted-foreground">
                    {product?.product_code ?? ""}
                </span>
                {badge && (
                    <span className="mt-1 inline-block rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">
                        {badge}
                    </span>
                )}
            </span>
        </div>
    );
}

function ProductChoice({
    label,
    emptyLabel,
    productId,
    product,
    loading,
    failed,
    giftFilter,
    onChoose,
    onClear,
}: {
    label: string;
    emptyLabel: string;
    productId: number | null;
    product: Product | undefined;
    loading: boolean;
    failed: boolean;
    giftFilter: "normal" | "gift_capable";
    onChoose: (product: Product) => void;
    onClear: () => void;
}) {
    return (
        <div className="space-y-2">
            {productId !== null && (
                <div className="min-w-0 rounded-lg bg-muted/40 p-3">
                    <ProductIdentity product={product} />
                    {failed && (
                        <p className="mt-2 text-xs text-red-700">
                            Không thể tải sản phẩm đã chọn. Hãy chọn lại.
                        </p>
                    )}
                    {loading && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Đang tải thông tin sản phẩm...
                        </p>
                    )}
                </div>
            )}
            <div className="flex flex-wrap items-center gap-2">
                <div className="min-w-40 flex-1">
                    <PromotionProductPicker
                        label={label}
                        triggerLabel={productId === null ? emptyLabel : "Đổi sản phẩm"}
                        selectedIds={productId === null ? [] : [productId]}
                        onChoose={onChoose}
                        activeOnly
                        giftFilter={giftFilter}
                        modalOnly
                    />
                </div>
                {productId !== null && (
                    <button
                        type="button"
                        onClick={onClear}
                        className="rounded-md px-3 py-2 text-sm text-red-700 hover:bg-red-50"
                    >
                        Xóa
                    </button>
                )}
            </div>
        </div>
    );
}

export function PromotionGiftRuleEditor({
    rule,
    mode,
    buyProductId,
    giftProductId,
    buyProduct,
    giftProduct,
    buyLoading,
    giftLoading,
    buyError,
    giftError,
    salesScope,
    dealerTierIds,
    dealerTiers,
    errors,
    onChooseBuy,
    onChooseGift,
    onClearBuy,
    onClearGift,
    onModeChange,
    onRuleChange,
    onTierToggle,
}: Props) {
    const buyVariant = buyProduct?.variants.find((variant) => variant.id === rule.buy_variant_id);
    const giftVariant = giftProduct?.variants.find(
        (variant) => variant.id === rule.gift_variant_id,
    );
    const canPreview =
        buyProduct &&
        buyProduct.status === "active" &&
        giftProduct &&
        giftProduct.status === "active" &&
        (rule.buy_variant_id === null || buyVariant?.status === "active") &&
        giftVariant &&
        giftVariant.status === "active" &&
        giftVariant.track_inventory !== false &&
        isPositiveProductQuantity(rule.minimum_buy_quantity) &&
        isPositiveProductQuantity(rule.gift_quantity);
    const eligibleGiftVariants =
        giftProduct?.variants.filter(
            (variant) => variant.status === "active" && variant.track_inventory !== false,
        ) ?? [];

    return (
        <section className="space-y-5" aria-label="Điều kiện mua và quà tặng">
            <div>
                <h3 className="text-lg font-semibold text-primary">Điều kiện mua & quà tặng</h3>
                <p className="mt-1 text-sm text-muted-foreground">
                    Thiết lập sản phẩm khách cần mua và sản phẩm khách sẽ nhận.
                </p>
                {errors["gift_rule"] && (
                    <p data-promotion-error="true" className="mt-2 text-sm text-red-700">
                        {errors["gift_rule"]}
                    </p>
                )}
            </div>
            <div className="grid items-start gap-4 xl:grid-cols-2">
                <section className="min-w-0 space-y-4 rounded-xl border bg-background p-4 sm:p-5">
                    <div>
                        <h4 className="font-semibold text-primary">1. Điều kiện mua</h4>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Khách cần mua sản phẩm nào để nhận ưu đãi?
                        </p>
                    </div>
                    <ProductChoice
                        label="Chọn sản phẩm mua"
                        emptyLabel="+ Chọn sản phẩm mua"
                        productId={buyProductId}
                        product={buyProduct}
                        loading={buyLoading}
                        failed={buyError}
                        giftFilter="normal"
                        onChoose={onChooseBuy}
                        onClear={onClearBuy}
                    />
                    {errors["gift_rule.buy_product_id"] && (
                        <p data-promotion-error="true" className="text-sm text-red-700">
                            {errors["gift_rule.buy_product_id"]}
                        </p>
                    )}
                    <label className="grid gap-1 text-sm font-medium">
                        Biến thể áp dụng
                        <select
                            className={fieldClass}
                            value={rule.buy_variant_id ?? ""}
                            disabled={!buyProduct}
                            onChange={(event) =>
                                onRuleChange({ buy_variant_id: Number(event.target.value) || null })
                            }
                        >
                            <option value="">Tất cả biến thể</option>
                            {buyProduct?.variants
                                .filter((variant) => variant.status === "active")
                                .map((variant) => (
                                    <option key={variant.id} value={variant.id}>
                                        {variant.variant_name} · {variant.sku}
                                    </option>
                                ))}
                        </select>
                    </label>
                    {errors["gift_rule.buy_variant_id"] && (
                        <p data-promotion-error="true" className="text-sm text-red-700">
                            {errors["gift_rule.buy_variant_id"]}
                        </p>
                    )}
                    <label className="grid gap-1 text-sm font-medium">
                        Số lượng tối thiểu
                        <input
                            className={`${fieldClass} max-w-36`}
                            type="number"
                            min="1"
                            step="1"
                            inputMode="numeric"
                            value={rule.minimum_buy_quantity}
                            onChange={(event) =>
                                onRuleChange({ minimum_buy_quantity: event.target.value })
                            }
                        />
                    </label>
                    {errors["gift_rule.minimum_buy_quantity"] && (
                        <p data-promotion-error="true" className="text-sm text-red-700">
                            {errors["gift_rule.minimum_buy_quantity"]}
                        </p>
                    )}
                </section>

                <section className="min-w-0 space-y-4 rounded-xl border bg-background p-4 sm:p-5">
                    <div>
                        <h4 className="font-semibold text-primary">2. Quà tặng</h4>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Chọn sản phẩm khách sẽ nhận khi đủ điều kiện.
                        </p>
                    </div>
                    <fieldset className="grid gap-2 sm:grid-cols-2">
                        <legend className="mb-2 text-sm font-medium">Loại quà tặng</legend>
                        {(
                            [
                                ["other", "Tặng sản phẩm khác"],
                                ["same", "Tặng cùng sản phẩm đang mua"],
                            ] as const
                        ).map(([value, label]) => (
                            <label
                                key={value}
                                className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-3 text-sm ${mode === value ? "border-primary bg-primary/5 font-medium text-primary" : "hover:bg-muted/40"}`}
                            >
                                <input
                                    type="radio"
                                    name="gift-product-mode"
                                    value={value}
                                    checked={mode === value}
                                    onChange={() => onModeChange(value)}
                                    className="accent-primary"
                                />
                                {label}
                            </label>
                        ))}
                    </fieldset>
                    {mode === "other" ? (
                        <ProductChoice
                            label="Chọn sản phẩm quà tặng"
                            emptyLabel="+ Chọn sản phẩm quà tặng"
                            productId={giftProductId}
                            product={giftProduct}
                            loading={giftLoading}
                            failed={giftError}
                            giftFilter="gift_capable"
                            onChoose={onChooseGift}
                            onClear={onClearGift}
                        />
                    ) : buyProductId === null ? (
                        <p className="rounded-lg bg-muted/40 p-4 text-sm text-muted-foreground">
                            Chọn sản phẩm mua trước để thiết lập quà tặng.
                        </p>
                    ) : (
                        <div className="rounded-lg bg-muted/40 p-3">
                            <ProductIdentity product={buyProduct} badge="Cùng sản phẩm mua" />
                        </div>
                    )}
                    {errors["gift_rule.gift_product_id"] && (
                        <p data-promotion-error="true" className="text-sm text-red-700">
                            {errors["gift_rule.gift_product_id"]}
                        </p>
                    )}
                    <label className="grid gap-1 text-sm font-medium">
                        Biến thể quà tặng
                        <select
                            className={fieldClass}
                            value={rule.gift_variant_id ?? ""}
                            disabled={!giftProduct}
                            onChange={(event) =>
                                onRuleChange({
                                    gift_variant_id: Number(event.target.value) || null,
                                })
                            }
                        >
                            <option value="">Chọn biến thể quà tặng</option>
                            {eligibleGiftVariants.map((variant) => (
                                <option key={variant.id} value={variant.id}>
                                    {variant.variant_name} · {variant.sku}
                                </option>
                            ))}
                        </select>
                    </label>
                    {giftProduct && eligibleGiftVariants.length === 0 && (
                        <p className="text-xs text-amber-700">
                            Sản phẩm chưa có biến thể hoạt động và theo dõi tồn kho.
                        </p>
                    )}
                    {errors["gift_rule.gift_variant_id"] && (
                        <p data-promotion-error="true" className="text-sm text-red-700">
                            {errors["gift_rule.gift_variant_id"]}
                        </p>
                    )}
                    <label className="grid gap-1 text-sm font-medium">
                        Số lượng quà
                        <input
                            className={`${fieldClass} max-w-36`}
                            type="number"
                            min="1"
                            step="1"
                            inputMode="numeric"
                            value={rule.gift_quantity}
                            onChange={(event) =>
                                onRuleChange({ gift_quantity: event.target.value })
                            }
                        />
                    </label>
                    {errors["gift_rule.gift_quantity"] && (
                        <p data-promotion-error="true" className="text-sm text-red-700">
                            {errors["gift_rule.gift_quantity"]}
                        </p>
                    )}
                </section>
            </div>

            <section className="space-y-4 rounded-xl border bg-background p-4 sm:p-5">
                <h4 className="font-semibold text-primary">3. Quy tắc áp dụng</h4>
                <div className="grid gap-4 md:grid-cols-2">
                    <label className="grid gap-1 text-sm font-medium">
                        Cách áp dụng
                        <select
                            className={fieldClass}
                            value={rule.repeat_per_multiple ? "multiples" : "once"}
                            onChange={(event) =>
                                onRuleChange({
                                    repeat_per_multiple: event.target.value === "multiples",
                                })
                            }
                        >
                            <option value="once">Chỉ áp dụng 1 lần mỗi đơn</option>
                            <option value="multiples">Lặp lại theo số lượng đủ điều kiện</option>
                        </select>
                    </label>
                    {salesScope !== "retail" && (
                        <div className="space-y-2">
                            <p className="text-sm font-medium">Hạng đại lý áp dụng</p>
                            <div className="flex flex-wrap gap-2">
                                {dealerTiers.map((tier) => {
                                    const selected = dealerTierIds.includes(tier.id);
                                    return (
                                        <button
                                            key={tier.id}
                                            type="button"
                                            aria-pressed={selected}
                                            onClick={() => onTierToggle(tier.id)}
                                            className={`rounded-full border px-3 py-1.5 text-sm ${selected ? "border-primary bg-primary text-primary-foreground" : "hover:bg-muted/40"}`}
                                        >
                                            {selected ? "✓ " : ""}
                                            {tier.name}
                                        </button>
                                    );
                                })}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Không chọn hạng nào = áp dụng cho tất cả hạng đại lý.
                            </p>
                            {errors["dealer_tier_ids"] && (
                                <p data-promotion-error="true" className="text-sm text-red-700">
                                    {errors["dealer_tier_ids"]}
                                </p>
                            )}
                        </div>
                    )}
                </div>
            </section>

            <section
                className="rounded-xl border border-primary/20 bg-primary/5 p-4 sm:p-5"
                aria-live="polite"
            >
                <h4 className="flex items-center gap-2 font-semibold text-primary">
                    <Gift className="size-4" aria-hidden="true" />
                    Xem trước ưu đãi
                </h4>
                {canPreview ? (
                    <div className="mt-3 space-y-1 text-sm">
                        <p className="font-medium text-primary">
                            Mua {formatProductQuantity(rule.minimum_buy_quantity)} {buyProduct.name}
                            {buyVariant ? ` · ${buyVariant.sku}` : ""}
                        </p>
                        <p className="font-medium text-primary">
                            → {mode === "same" ? "Tặng thêm" : "Tặng"}{" "}
                            {formatProductQuantity(rule.gift_quantity)} {giftProduct.name} ·{" "}
                            {giftVariant.sku}
                        </p>
                        <p className="pt-1 text-xs text-muted-foreground">
                            {buyVariant ? buyVariant.variant_name : "Tất cả biến thể"} ·{" "}
                            {rule.repeat_per_multiple ? "Lặp theo bội số" : "1 lần / đơn"} ·{" "}
                            {salesScope === "retail"
                                ? "Retail"
                                : dealerTierIds.length === 0
                                  ? "Tất cả hạng đại lý"
                                  : dealerTiers
                                        .filter((tier) => dealerTierIds.includes(tier.id))
                                        .map((tier) => tier.name)
                                        .join(", ")}
                        </p>
                    </div>
                ) : (
                    <p className="mt-3 text-sm text-muted-foreground">
                        Hoàn tất điều kiện mua và quà tặng để xem nội dung ưu đãi.
                    </p>
                )}
            </section>
        </section>
    );
}
