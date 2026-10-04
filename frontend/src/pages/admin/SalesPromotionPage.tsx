import { useEffect, useRef, useState } from "react";
import { LoaderCircle } from "lucide-react";
import { useNavigate } from "@tanstack/react-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { adminFormLayout } from "@/components/admin/AdminFormLayout";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { formatProductQuantity, isPositiveProductQuantity } from "@/lib/productQuantity";
import { formatPercentage } from "@/lib/formatPercentage";
import { productApi } from "@/services/productApi";
import type { Product } from "@/types/product";
import {
    salesPromotionApi,
    type SalesPromotion,
    type SalesPromotionInput,
} from "@/services/salesPromotionApi";
import {
    ProductAdminGuard,
    buttonClass,
    fieldClass,
    secondaryButtonClass,
} from "./ProductAdminShared";
import { PromotionProductPicker } from "./PromotionProductPicker";
import { PromotionGiftRuleEditor } from "./PromotionGiftRuleEditor";
import { PromotionDateRangePicker } from "./PromotionDateRangePicker";
import { giftModeForRule, giftRuleForMode, giftRuleWithBuyProduct } from "./promotionGiftRuleState";
import type { GiftMode } from "./promotionGiftRuleState";

const emptyForm: SalesPromotionInput = {
    code: "",
    name: "",
    description: null,
    discount_type: "percentage",
    discount_value: "10",
    sales_scope: "both",
    starts_at: null,
    ends_at: null,
    total_usage_limit: null,
    per_buyer_usage_limit: null,
    status: "active",
    product_ids: [],
    category_ids: [],
    dealer_tier_ids: [],
    gift_rule: {
        buy_product_id: null,
        buy_variant_id: null,
        minimum_buy_quantity: "1",
        gift_product_id: null,
        gift_variant_id: null,
        gift_quantity: "1",
        repeat_per_multiple: false,
    },
};

const serverDateInput = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat("sv-SE", {
              timeZone: "Asia/Ho_Chi_Minh",
              year: "numeric",
              month: "2-digit",
              day: "2-digit",
              hour: "2-digit",
              minute: "2-digit",
              hour12: false,
          })
              .format(new Date(value))
              .replace(" ", "T")
        : "";
const money = (value: string | null) =>
    value === null
        ? "—"
        : new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND" }).format(
              Number(value),
          );

export function SalesPromotionPage({
    mode = "list",
    promotionId,
}: {
    mode?: "list" | "create" | "edit";
    promotionId?: number;
}) {
    const navigate = useNavigate();
    const client = useQueryClient();
    const [search, setSearch] = useState("");
    const [statusFilter, setStatusFilter] = useState("");
    const [scopeFilter, setScopeFilter] = useState("");
    const [typeFilter, setTypeFilter] = useState("");
    const [page, setPage] = useState(1);
    const [selectedProductNames, setSelectedProductNames] = useState<Record<number, string>>({});
    const [categoryPage, setCategoryPage] = useState(1);
    const editingId = mode === "edit" ? (promotionId ?? null) : null;
    const loadedEditId = useRef<number | null>(null);
    const [detailId, setDetailId] = useState<number | null>(null);
    const [pendingStatusChange, setPendingStatusChange] = useState<{
        id: number;
        code: string;
        name: string;
        active: boolean;
    } | null>(null);
    const [form, setForm] = useState<SalesPromotionInput>(emptyForm);
    const [giftMode, setGiftMode] = useState<GiftMode>("other");
    const [notice, setNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const list = useQuery({
        queryKey: ["sales-promotions", search, statusFilter, scopeFilter, typeFilter, page],
        queryFn: () =>
            salesPromotionApi.list({
                search,
                status: statusFilter,
                sales_scope: scopeFilter,
                discount_type: typeFilter,
                page,
            }),
        enabled: mode === "list",
    });
    const editDetail = useQuery({
        queryKey: ["sales-promotion-edit", editingId],
        queryFn: () => salesPromotionApi.detail(editingId!),
        enabled: editingId !== null,
    });
    const detail = useQuery({
        queryKey: ["sales-promotion-detail", detailId],
        queryFn: () => salesPromotionApi.detail(detailId!),
        enabled: detailId !== null,
    });
    const statusChange = useMutation({
        mutationFn: ({ id, active }: { id: number; active: boolean }) =>
            salesPromotionApi.setActive(id, active),
        onSuccess: async (_result, variables) => {
            setPendingStatusChange(null);
            toast.success(
                variables.active ? "Đã kích hoạt ưu đãi bán hàng." : "Đã ngừng ưu đãi bán hàng.",
            );
            await client.invalidateQueries({ queryKey: ["sales-promotions"] });
            await client.invalidateQueries({ queryKey: ["sales-promotion-detail"] });
        },
        onError: (error) => {
            setNotice(errorMessage(error));
            toast.error(errorMessage(error));
        },
    });
    const categories = useQuery({
        queryKey: ["promotion-categories", categoryPage],
        queryFn: () => productApi.masters("categories", categoryPage),
        enabled: mode !== "list",
    });
    const buyProduct = useQuery({
        queryKey: ["promotion-buy-product", form.gift_rule.buy_product_id],
        queryFn: () => productApi.adminProduct(form.gift_rule.buy_product_id!),
        enabled:
            mode !== "list" &&
            form.discount_type === "buy_a_get_b" &&
            form.gift_rule.buy_product_id !== null,
    });
    const giftProduct = useQuery({
        queryKey: ["promotion-gift-product", form.gift_rule.gift_product_id],
        queryFn: () => productApi.adminProduct(form.gift_rule.gift_product_id!),
        enabled:
            mode !== "list" &&
            form.discount_type === "buy_a_get_b" &&
            form.gift_rule.gift_product_id !== null &&
            giftMode === "other",
    });
    const dealerTiers = useQuery({
        queryKey: ["promotion-dealer-tiers"],
        queryFn: productApi.dealerTiers,
        enabled:
            mode !== "list" &&
            form.discount_type === "buy_a_get_b" &&
            form.sales_scope !== "retail",
    });
    const save = useMutation({
        mutationFn: () => {
            const base = {
                ...form,
                code: form.code.trim(),
                name: form.name.trim(),
                description: form.description?.trim() || null,
            };
            const body =
                form.discount_type === "buy_a_get_b"
                    ? {
                          ...base,
                          discount_value: "0",
                          dealer_tier_ids:
                              form.sales_scope === "retail" ? [] : form.dealer_tier_ids,
                          product_ids: undefined,
                          category_ids: undefined,
                      }
                    : {
                          ...base,
                          category_ids: form.sales_scope === "dealer" ? form.category_ids : [],
                          dealer_tier_ids:
                              form.sales_scope === "retail" ? [] : form.dealer_tier_ids,
                          gift_rule: undefined,
                      };
            return editingId === null
                ? salesPromotionApi.create(body)
                : salesPromotionApi.update(editingId, body);
        },
        onSuccess: async () => {
            toast.success(
                editingId === null ? "Tạo ưu đãi thành công" : "Cập nhật ưu đãi thành công",
            );
            setErrors({});
            await client.invalidateQueries({ queryKey: ["sales-promotions"] });
            void navigate({ to: "/admin/sales-promotions" });
        },
        onError: (error) => {
            setNotice(errorMessage(error));
            setErrors(firstFieldErrors(error));
            toast.error(errorMessage(error));
            window.setTimeout(
                () =>
                    document
                        .querySelector('[data-promotion-error="true"]')
                        ?.scrollIntoView({ behavior: "smooth", block: "center" }),
                0,
            );
        },
    });
    const generate = useMutation({
        mutationFn: salesPromotionApi.generateCode,
        onSuccess: (response) => {
            setForm((current) => ({ ...current, code: response.code }));
            toast.success("Đã tạo mã ưu đãi.");
        },
        onError: (error) => {
            setNotice(errorMessage(error));
            toast.error(errorMessage(error));
        },
    });
    const populateForm = (promotion: SalesPromotion) => {
        setErrors({});
        setNotice("");
        setGiftMode(promotion.gift_rule ? giftModeForRule(promotion.gift_rule) : "other");
        setForm({
            code: promotion.code,
            name: promotion.name,
            description: promotion.description,
            discount_type: promotion.discount_type,
            discount_value:
                promotion.discount_type === "fixed_amount"
                    ? String(Number(promotion.discount_value))
                    : promotion.discount_value,
            sales_scope: promotion.sales_scope,
            starts_at: serverDateInput(promotion.starts_at) || null,
            ends_at: serverDateInput(promotion.ends_at) || null,
            total_usage_limit: promotion.total_usage_limit,
            per_buyer_usage_limit: promotion.per_buyer_usage_limit,
            status: promotion.status,
            product_ids: promotion.targets
                .filter((target) => target.product_id !== null)
                .map((target) => target.product_id!),
            category_ids: promotion.targets
                .filter((target) => target.product_category_id !== null)
                .map((target) => target.product_category_id!),
            dealer_tier_ids: promotion.dealer_tiers?.map((tier) => tier.id) ?? [],
            gift_rule: promotion.gift_rule
                ? {
                      buy_product_id: promotion.gift_rule.buy_product_id,
                      buy_variant_id: promotion.gift_rule.buy_variant_id,
                      minimum_buy_quantity: formatProductQuantity(
                          promotion.gift_rule.minimum_buy_quantity,
                      ),
                      gift_product_id: promotion.gift_rule.gift_product_id,
                      gift_variant_id: promotion.gift_rule.gift_variant_id,
                      gift_quantity: formatProductQuantity(promotion.gift_rule.gift_quantity),
                      repeat_per_multiple: promotion.gift_rule.repeat_per_multiple,
                  }
                : emptyForm.gift_rule,
        });
    };
    useEffect(() => {
        if (editingId !== null && editDetail.data?.data && loadedEditId.current !== editingId) {
            loadedEditId.current = editingId;
            populateForm(editDetail.data.data);
        }
    }, [editingId, editDetail.data]);
    const set = <K extends keyof SalesPromotionInput>(key: K, value: SalesPromotionInput[K]) =>
        setForm((current) => ({ ...current, [key]: value }));
    const toggle = (key: "product_ids" | "category_ids", id: number) =>
        setForm((current) => ({
            ...current,
            [key]: current[key].includes(id)
                ? current[key].filter((item) => item !== id)
                : [...current[key], id],
        }));
    const selectBuyProduct = (product: Product) => {
        setForm((current) => ({
            ...current,
            gift_rule: giftRuleWithBuyProduct(current.gift_rule, product, giftMode),
        }));
        setErrors((current) => ({
            ...current,
            "gift_rule.buy_product_id": "",
            "gift_rule.buy_variant_id": "",
        }));
    };
    const selectGiftProduct = (product: Product) => {
        if (product.id === form.gift_rule.buy_product_id) {
            setErrors((current) => ({
                ...current,
                "gift_rule.gift_product_id":
                    "Chọn chế độ tặng cùng sản phẩm đang mua để dùng sản phẩm A làm quà.",
            }));
            return;
        }
        const variants = product.variants.filter(
            (variant) => variant.status === "active" && variant.track_inventory !== false,
        );
        setForm((current) => ({
            ...current,
            gift_rule: {
                ...current.gift_rule,
                gift_product_id: product.id,
                gift_variant_id: variants.length === 1 ? (variants[0]?.id ?? null) : null,
            },
        }));
        setErrors((current) => ({
            ...current,
            "gift_rule.gift_product_id": "",
            "gift_rule.gift_variant_id": "",
        }));
    };
    const changeGiftMode = (nextMode: GiftMode) => {
        setGiftMode(nextMode);
        setForm((current) => ({
            ...current,
            gift_rule: giftRuleForMode(current.gift_rule, nextMode, buyProduct.data?.data),
        }));
        setErrors((current) => ({
            ...current,
            "gift_rule.gift_product_id": "",
            "gift_rule.gift_variant_id": "",
        }));
    };
    const clearBuyProduct = () =>
        setForm((current) => ({
            ...current,
            gift_rule: {
                ...current.gift_rule,
                buy_product_id: null,
                buy_variant_id: null,
                ...(giftMode === "same" ? { gift_product_id: null, gift_variant_id: null } : {}),
            },
        }));
    const clearGiftProduct = () =>
        setForm((current) => ({
            ...current,
            gift_rule: { ...current.gift_rule, gift_product_id: null, gift_variant_id: null },
        }));
    const changeGiftRule = (changes: Partial<SalesPromotionInput["gift_rule"]>) => {
        setForm((current) => ({
            ...current,
            gift_rule: { ...current.gift_rule, ...changes },
        }));
        setErrors((current) => {
            const next = { ...current };
            for (const key of Object.keys(changes)) delete next[`gift_rule.${key}`];
            return next;
        });
    };
    const toggleDealerTier = (id: number) =>
        setForm((current) => ({
            ...current,
            dealer_tier_ids: current.dealer_tier_ids.includes(id)
                ? current.dealer_tier_ids.filter((selected) => selected !== id)
                : [...current.dealer_tier_ids, id],
        }));
    const addApplicableProducts = (chosen: Product[]) => {
        setForm((current) => ({
            ...current,
            product_ids: [...new Set([...current.product_ids, ...chosen.map((item) => item.id)])],
        }));
        setSelectedProductNames((current) => ({
            ...current,
            ...Object.fromEntries(chosen.map((item) => [item.id, item.name])),
        }));
    };

    return (
        <ProductAdminGuard>
            <div className={`${mode === "list" ? "" : adminFormLayout.standard} space-y-6`}>
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="label-luxury">Bán hàng</p>
                        <h1 className="admin-page-title mt-2 text-primary">
                            {mode === "list"
                                ? "Ưu đãi bán hàng"
                                : mode === "create"
                                  ? "Thêm ưu đãi"
                                  : "Sửa ưu đãi"}
                        </h1>
                        <p className="admin-helper-text mt-2">
                            Quản lý các chương trình giảm giá và quà tặng.
                        </p>
                    </div>
                    {mode === "list" ? (
                        <button
                            type="button"
                            className={buttonClass}
                            onClick={() => void navigate({ to: "/admin/sales-promotions/create" })}
                        >
                            + Thêm ưu đãi
                        </button>
                    ) : (
                        <button
                            type="button"
                            className={secondaryButtonClass}
                            onClick={() => void navigate({ to: "/admin/sales-promotions" })}
                        >
                            ← Quay lại danh sách
                        </button>
                    )}
                </header>
                {notice && (
                    <p role="status" className="rounded-lg border bg-card p-3 text-sm">
                        {notice}
                    </p>
                )}
                {mode !== "list" &&
                    (editDetail.isPending && mode === "edit" ? (
                        <LoadingState />
                    ) : editDetail.isError && mode === "edit" ? (
                        <ErrorState
                            message={errorMessage(editDetail.error)}
                            retry={() => void editDetail.refetch()}
                        />
                    ) : (
                        <form
                            className="space-y-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                if (save.isPending) return;
                                if (form.discount_type === "buy_a_get_b") {
                                    const fieldErrors: Record<string, string> = {};
                                    const rule = form.gift_rule;
                                    const gift =
                                        giftMode === "same"
                                            ? buyProduct.data?.data
                                            : giftProduct.data?.data;
                                    if (
                                        rule.buy_product_id === null ||
                                        buyProduct.isError ||
                                        buyProduct.data?.data.status !== "active"
                                    )
                                        fieldErrors["gift_rule.buy_product_id"] =
                                            "Vui lòng chọn sản phẩm mua đang hoạt động.";
                                    if (
                                        rule.buy_variant_id !== null &&
                                        buyProduct.data &&
                                        !buyProduct.data.data.variants.some(
                                            (variant) =>
                                                variant.id === rule.buy_variant_id &&
                                                variant.status === "active" &&
                                                variant.track_inventory !== false,
                                        )
                                    )
                                        fieldErrors["gift_rule.buy_variant_id"] =
                                            "Biến thể mua đã ngừng hoạt động. Vui lòng chọn lại.";
                                    if (
                                        rule.gift_product_id === null ||
                                        (giftMode === "other" && giftProduct.isError) ||
                                        gift?.status !== "active" ||
                                        (giftMode === "other" && gift?.can_be_gift === false)
                                    )
                                        fieldErrors["gift_rule.gift_product_id"] =
                                            "Vui lòng chọn sản phẩm quà tặng đang hoạt động.";
                                    if (
                                        giftMode === "other" &&
                                        rule.gift_product_id === rule.buy_product_id &&
                                        rule.buy_product_id !== null
                                    )
                                        fieldErrors["gift_rule.gift_product_id"] =
                                            "Chọn chế độ tặng cùng sản phẩm đang mua.";
                                    if (
                                        rule.gift_variant_id === null ||
                                        (gift &&
                                            !gift.variants.some(
                                                (variant) =>
                                                    variant.id === rule.gift_variant_id &&
                                                    variant.status === "active" &&
                                                    variant.track_inventory !== false,
                                            ))
                                    )
                                        fieldErrors["gift_rule.gift_variant_id"] =
                                            "Vui lòng chọn biến thể quà tặng còn hoạt động.";
                                    if (!isPositiveProductQuantity(rule.minimum_buy_quantity))
                                        fieldErrors["gift_rule.minimum_buy_quantity"] =
                                            "Số lượng mua phải là số nguyên dương.";
                                    if (!isPositiveProductQuantity(rule.gift_quantity))
                                        fieldErrors["gift_rule.gift_quantity"] =
                                            "Số lượng quà phải là số nguyên dương.";
                                    if (Object.keys(fieldErrors).length) {
                                        setErrors(fieldErrors);
                                        setNotice("Vui lòng kiểm tra các trường được đánh dấu.");
                                        window.setTimeout(
                                            () =>
                                                document
                                                    .querySelector('[data-promotion-error="true"]')
                                                    ?.scrollIntoView({
                                                        behavior: "smooth",
                                                        block: "center",
                                                    }),
                                            0,
                                        );
                                        return;
                                    }
                                }
                                setErrors({});
                                setNotice("");
                                save.mutate();
                            }}
                        >
                            <section className="space-y-4 rounded-xl border bg-card p-4 sm:p-6">
                                <h2 className="admin-section-title text-primary">
                                    Thông tin cơ bản
                                </h2>
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-12">
                                    <label className="admin-form-label grid min-w-0 gap-1.5 lg:col-span-4">
                                        Mã chương trình
                                        <div className="flex gap-2">
                                            <input
                                                className={`${fieldClass} min-w-0 flex-1`}
                                                required
                                                maxLength={80}
                                                value={form.code}
                                                onChange={(event) =>
                                                    set("code", event.target.value)
                                                }
                                            />
                                            <button
                                                type="button"
                                                className={secondaryButtonClass}
                                                disabled={generate.isPending}
                                                onClick={() => generate.mutate()}
                                            >
                                                Tạo mã
                                            </button>
                                        </div>
                                        {errors["code"] && (
                                            <span className="text-red-700">{errors["code"]}</span>
                                        )}
                                    </label>
                                    <label className="admin-form-label grid min-w-0 gap-1.5 lg:col-span-5">
                                        Tên ưu đãi
                                        <input
                                            className={fieldClass}
                                            required
                                            value={form.name}
                                            onChange={(event) => set("name", event.target.value)}
                                        />
                                        {errors["name"] && (
                                            <span className="text-red-700">{errors["name"]}</span>
                                        )}
                                    </label>
                                    <label className="admin-form-label grid min-w-0 gap-1.5 sm:col-span-2 lg:col-span-3">
                                        Phạm vi
                                        <select
                                            className={fieldClass}
                                            value={form.sales_scope}
                                            onChange={(event) =>
                                                setForm((current) => ({
                                                    ...current,
                                                    sales_scope: event.target
                                                        .value as SalesPromotionInput["sales_scope"],
                                                    dealer_tier_ids:
                                                        event.target.value === "retail"
                                                            ? []
                                                            : current.dealer_tier_ids,
                                                }))
                                            }
                                        >
                                            <option value="both">Retail và Dealer</option>
                                            <option value="retail">Retail</option>
                                            <option value="dealer">Dealer</option>
                                        </select>
                                        {errors["sales_scope"] && (
                                            <span className="text-red-700">
                                                {errors["sales_scope"]}
                                            </span>
                                        )}
                                    </label>
                                </div>
                            </section>
                            <section className="space-y-4 rounded-xl border bg-card p-4 sm:p-6">
                                <h2 className="admin-section-title text-primary">
                                    Điều kiện & giới hạn áp dụng
                                </h2>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <label className="admin-form-label grid min-w-0 gap-1.5">
                                        Kiểu giảm
                                        <select
                                            className={`${fieldClass} sm:max-w-64`}
                                            value={form.discount_type}
                                            onChange={(event) =>
                                                setForm((current) => {
                                                    const discountType = event.target
                                                        .value as SalesPromotionInput["discount_type"];
                                                    const value = Number(current.discount_value);
                                                    return {
                                                        ...current,
                                                        discount_type: discountType,
                                                        discount_value:
                                                            discountType === "buy_a_get_b"
                                                                ? "0"
                                                                : discountType === "fixed_amount" &&
                                                                    current.discount_type !==
                                                                        "fixed_amount"
                                                                  ? "50000"
                                                                  : value <= 0 ||
                                                                      (discountType ===
                                                                          "percentage" &&
                                                                          value > 100)
                                                                    ? "10"
                                                                    : current.discount_value,
                                                    };
                                                })
                                            }
                                        >
                                            <option value="percentage">Phần trăm</option>
                                            <option value="fixed_amount">
                                                Số tiền cố định (VND)
                                            </option>
                                            <option value="buy_a_get_b">Mua A tặng A hoặc B</option>
                                        </select>
                                        {errors["discount_type"] && (
                                            <span className="text-red-700">
                                                {errors["discount_type"]}
                                            </span>
                                        )}
                                    </label>
                                    {form.discount_type !== "buy_a_get_b" && (
                                        <label className="admin-form-label grid min-w-0 gap-1.5">
                                            {form.discount_type === "fixed_amount"
                                                ? "Giá trị giảm (VND)"
                                                : "Giá trị (%)"}
                                            <input
                                                className={`${fieldClass} sm:max-w-64`}
                                                type="number"
                                                min={
                                                    form.discount_type === "fixed_amount"
                                                        ? "1"
                                                        : "0.01"
                                                }
                                                max={
                                                    form.discount_type === "percentage"
                                                        ? 100
                                                        : undefined
                                                }
                                                step={
                                                    form.discount_type === "fixed_amount"
                                                        ? "1"
                                                        : "0.01"
                                                }
                                                required
                                                value={form.discount_value}
                                                onChange={(event) =>
                                                    set("discount_value", event.target.value)
                                                }
                                            />
                                            {form.discount_type === "fixed_amount" &&
                                                Number(form.discount_value) > 0 && (
                                                    <span className="admin-helper-text">
                                                        Giảm {money(form.discount_value)} cho mỗi
                                                        đơn vị sản phẩm đủ điều kiện.
                                                    </span>
                                                )}
                                            {errors["discount_value"] && (
                                                <span className="text-red-700">
                                                    {errors["discount_value"]}
                                                </span>
                                            )}
                                        </label>
                                    )}
                                    <label className="admin-form-label grid min-w-0 gap-1.5">
                                        Lượt dùng tối đa
                                        <input
                                            className={`${fieldClass} sm:max-w-64`}
                                            type="number"
                                            min="1"
                                            step="1"
                                            placeholder="Để trống nếu không giới hạn"
                                            value={form.total_usage_limit ?? ""}
                                            onChange={(event) =>
                                                set(
                                                    "total_usage_limit",
                                                    event.target.value
                                                        ? Number(event.target.value)
                                                        : null,
                                                )
                                            }
                                        />
                                        {errors["total_usage_limit"] && (
                                            <span className="text-red-700">
                                                {errors["total_usage_limit"]}
                                            </span>
                                        )}
                                    </label>
                                    <label className="admin-form-label grid min-w-0 gap-1.5">
                                        Lượt dùng mỗi người mua / đại lý
                                        <input
                                            className={`${fieldClass} sm:max-w-64`}
                                            type="number"
                                            min="1"
                                            step="1"
                                            placeholder="Để trống nếu không giới hạn"
                                            value={form.per_buyer_usage_limit ?? ""}
                                            onChange={(event) =>
                                                set(
                                                    "per_buyer_usage_limit",
                                                    event.target.value
                                                        ? Number(event.target.value)
                                                        : null,
                                                )
                                            }
                                        />
                                        {errors["per_buyer_usage_limit"] && (
                                            <span className="text-red-700">
                                                {errors["per_buyer_usage_limit"]}
                                            </span>
                                        )}
                                    </label>
                                    <label className="admin-form-label grid min-w-0 gap-1.5">
                                        Trạng thái
                                        <select
                                            className={`${fieldClass} sm:max-w-64`}
                                            value={form.status}
                                            onChange={(event) =>
                                                set(
                                                    "status",
                                                    event.target
                                                        .value as SalesPromotionInput["status"],
                                                )
                                            }
                                        >
                                            <option value="active">Đang hoạt động</option>
                                            <option value="inactive">Tạm dừng</option>
                                        </select>
                                    </label>
                                </div>
                            </section>
                            <section className="space-y-3 rounded-xl border bg-card p-4 sm:p-6">
                                <h2 className="admin-section-title text-primary">
                                    Thời gian áp dụng
                                </h2>
                                <PromotionDateRangePicker
                                    start={form.starts_at}
                                    end={form.ends_at}
                                    onChange={(startsAt, endsAt) => {
                                        setForm((current) => ({
                                            ...current,
                                            starts_at: startsAt,
                                            ends_at: endsAt,
                                        }));
                                        setErrors((current) => {
                                            const next = { ...current };
                                            delete next["starts_at"];
                                            delete next["ends_at"];
                                            return next;
                                        });
                                    }}
                                    errors={[errors["starts_at"], errors["ends_at"]].filter(
                                        (error): error is string => Boolean(error),
                                    )}
                                />
                            </section>
                            <section className="space-y-3 rounded-xl border bg-card p-4 sm:p-6">
                                <h2 className="admin-section-title text-primary">Mô tả</h2>
                                <label className="admin-form-label grid gap-1.5">
                                    Nội dung
                                    <textarea
                                        className={fieldClass}
                                        rows={2}
                                        placeholder="Nhập mô tả ngắn về chương trình ưu đãi..."
                                        value={form.description ?? ""}
                                        onChange={(event) =>
                                            set("description", event.target.value || null)
                                        }
                                    />
                                </label>
                            </section>
                            {form.discount_type === "buy_a_get_b" && (
                                <section className="rounded-xl border bg-card p-4 sm:p-6">
                                    <PromotionGiftRuleEditor
                                        rule={form.gift_rule}
                                        mode={giftMode}
                                        buyProductId={form.gift_rule.buy_product_id}
                                        giftProductId={form.gift_rule.gift_product_id}
                                        buyProduct={buyProduct.data?.data}
                                        giftProduct={
                                            giftMode === "same"
                                                ? buyProduct.data?.data
                                                : giftProduct.data?.data
                                        }
                                        buyLoading={
                                            buyProduct.isPending &&
                                            form.gift_rule.buy_product_id !== null
                                        }
                                        giftLoading={
                                            giftProduct.isPending &&
                                            giftMode === "other" &&
                                            form.gift_rule.gift_product_id !== null
                                        }
                                        buyError={buyProduct.isError}
                                        giftError={giftProduct.isError}
                                        salesScope={form.sales_scope}
                                        dealerTierIds={form.dealer_tier_ids}
                                        dealerTiers={dealerTiers.data?.data ?? []}
                                        errors={errors}
                                        onChooseBuy={selectBuyProduct}
                                        onChooseGift={selectGiftProduct}
                                        onClearBuy={clearBuyProduct}
                                        onClearGift={clearGiftProduct}
                                        onModeChange={changeGiftMode}
                                        onRuleChange={changeGiftRule}
                                        onTierToggle={toggleDealerTier}
                                    />
                                </section>
                            )}
                            {form.discount_type !== "buy_a_get_b" &&
                                form.sales_scope !== "retail" && (
                                    <section className="grid gap-2 rounded-xl border bg-card p-4 text-sm sm:p-6">
                                        <h2 className="admin-section-title text-primary">
                                            Hạng đại lý được áp dụng
                                        </h2>
                                        <p className="admin-helper-text">
                                            Để trống để áp dụng cho tất cả hạng đại lý.
                                        </p>
                                        {dealerTiers.data?.data.map((tier) => (
                                            <label
                                                key={tier.id}
                                                className="flex items-center gap-2"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={form.dealer_tier_ids.includes(tier.id)}
                                                    onChange={() =>
                                                        setForm((current) => ({
                                                            ...current,
                                                            dealer_tier_ids:
                                                                current.dealer_tier_ids.includes(
                                                                    tier.id,
                                                                )
                                                                    ? current.dealer_tier_ids.filter(
                                                                          (id) => id !== tier.id,
                                                                      )
                                                                    : [
                                                                          ...current.dealer_tier_ids,
                                                                          tier.id,
                                                                      ],
                                                        }))
                                                    }
                                                />
                                                {tier.name}
                                            </label>
                                        ))}
                                        {errors["dealer_tier_ids"] && (
                                            <span className="text-red-700">
                                                {errors["dealer_tier_ids"]}
                                            </span>
                                        )}
                                    </section>
                                )}
                            {form.discount_type !== "buy_a_get_b" && (
                                <section className="space-y-4 rounded-xl border bg-card p-4 sm:p-6">
                                    <h2 className="admin-section-title text-primary">
                                        {form.sales_scope === "dealer"
                                            ? "Sản phẩm & danh mục áp dụng"
                                            : "Sản phẩm áp dụng"}
                                    </h2>
                                    <div
                                        className={`grid gap-4 ${form.sales_scope === "dealer" ? "lg:grid-cols-2" : ""}`}
                                    >
                                        {form.sales_scope === "dealer" && (
                                            <div className="rounded-lg border p-3">
                                                <h3 className="admin-subsection-title">
                                                    Danh mục áp dụng
                                                </h3>
                                                <p className="mb-2 text-xs text-muted-foreground">
                                                    Để trống cả sản phẩm và danh mục để áp dụng toàn
                                                    đơn.
                                                </p>
                                                <div className="max-h-44 space-y-1 overflow-auto">
                                                    {categories.data?.data.map((category) => (
                                                        <label
                                                            key={category.id}
                                                            className="flex gap-2 text-sm"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                checked={form.category_ids.includes(
                                                                    category.id,
                                                                )}
                                                                onChange={() =>
                                                                    toggle(
                                                                        "category_ids",
                                                                        category.id,
                                                                    )
                                                                }
                                                            />
                                                            {category.name}
                                                        </label>
                                                    ))}
                                                </div>
                                                {categories.data && (
                                                    <Pagination
                                                        current={categories.data.current_page}
                                                        last={categories.data.last_page}
                                                        onPage={setCategoryPage}
                                                    />
                                                )}
                                                <p className="mt-2 text-xs text-muted-foreground">
                                                    Đã chọn ID:{" "}
                                                    {form.category_ids.join(", ") || "—"}
                                                </p>
                                                <div className="mt-2 flex flex-wrap gap-1">
                                                    {form.category_ids.map((id) => (
                                                        <button
                                                            key={id}
                                                            type="button"
                                                            className="rounded border px-2 py-1 text-xs"
                                                            onClick={() =>
                                                                toggle("category_ids", id)
                                                            }
                                                            aria-label={`Bỏ danh mục ${id}`}
                                                        >
                                                            Danh mục #{id} ×
                                                        </button>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                        <div className="min-w-0 rounded-lg border p-3">
                                            <h3 className="admin-subsection-title">
                                                Sản phẩm áp dụng
                                            </h3>
                                            <p className="mb-3 text-xs text-muted-foreground">
                                                {form.sales_scope === "dealer"
                                                    ? "Để trống cả sản phẩm và danh mục để áp dụng toàn đơn."
                                                    : "Chọn ít nhất một sản phẩm. Mỗi sản phẩm chỉ có một ưu đãi giảm giá đang hoạt động."}
                                            </p>
                                            <PromotionProductPicker
                                                label="Tìm sản phẩm áp dụng"
                                                selectedIds={form.product_ids}
                                                onChoose={(product) =>
                                                    addApplicableProducts([product])
                                                }
                                                onChooseMany={addApplicableProducts}
                                                multiple
                                                checkDiscountAvailability
                                                promotionScope={form.sales_scope}
                                                promotionTierIds={form.dealer_tier_ids}
                                                promotionStartsAt={form.starts_at}
                                                promotionEndsAt={form.ends_at}
                                                {...(editingId !== null
                                                    ? { excludePromotionId: editingId }
                                                    : {})}
                                            />
                                            {errors["product_ids"] && (
                                                <p className="mt-2 text-sm text-red-700">
                                                    {errors["product_ids"]}
                                                </p>
                                            )}
                                            {form.product_ids.length > 0 && (
                                                <div className="mt-3 flex flex-wrap gap-2">
                                                    {form.product_ids.map((id) => (
                                                        <button
                                                            key={id}
                                                            type="button"
                                                            className="rounded-md border px-2 py-1 text-xs hover:bg-accent"
                                                            onClick={() =>
                                                                toggle("product_ids", id)
                                                            }
                                                            aria-label={"Bỏ sản phẩm " + id}
                                                        >
                                                            {selectedProductNames[id] ??
                                                                "Sản phẩm #" + id}{" "}
                                                            ×
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </section>
                            )}
                            <div className="sticky bottom-0 z-10 flex flex-col gap-2 rounded-xl border bg-card/95 p-4 shadow-[0_-4px_16px_rgba(0,0,0,0.05)] backdrop-blur sm:flex-row sm:justify-end sm:p-5">
                                <button
                                    type="button"
                                    className={secondaryButtonClass}
                                    disabled={save.isPending}
                                    onClick={() => void navigate({ to: "/admin/sales-promotions" })}
                                >
                                    Hủy
                                </button>
                                <button
                                    type="submit"
                                    className={`${buttonClass} inline-flex items-center justify-center gap-2`}
                                    disabled={save.isPending}
                                >
                                    {save.isPending && (
                                        <LoaderCircle
                                            className="size-4 animate-spin"
                                            aria-hidden="true"
                                        />
                                    )}
                                    {save.isPending ? "Đang lưu..." : "Lưu ưu đãi"}
                                </button>
                            </div>
                        </form>
                    ))}
                {mode === "list" && (
                    <>
                        <section className="rounded-xl border bg-card p-4 sm:p-6">
                            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                                <h2 className="text-xl text-primary">Danh sách ưu đãi</h2>
                                <div className="grid w-full gap-3 md:grid-cols-[minmax(0,1fr)_160px_160px_170px]">
                                    <input
                                        className={fieldClass}
                                        placeholder="Tìm mã hoặc tên ưu đãi"
                                        value={search}
                                        onChange={(event) => {
                                            setSearch(event.target.value);
                                            setPage(1);
                                        }}
                                    />
                                    <select
                                        className={fieldClass}
                                        value={statusFilter}
                                        onChange={(event) => {
                                            setStatusFilter(event.target.value);
                                            setPage(1);
                                        }}
                                        aria-label="Lọc trạng thái"
                                    >
                                        <option value="">Mọi trạng thái</option>
                                        <option value="active">Hoạt động</option>
                                        <option value="inactive">Tạm dừng</option>
                                        <option value="upcoming">Chưa bắt đầu</option>
                                        <option value="expired">Hết hạn</option>
                                    </select>
                                    <select
                                        className={fieldClass}
                                        value={scopeFilter}
                                        onChange={(event) => {
                                            setScopeFilter(event.target.value);
                                            setPage(1);
                                        }}
                                        aria-label="Lọc phạm vi"
                                    >
                                        <option value="">Mọi phạm vi</option>
                                        <option value="retail">Retail</option>
                                        <option value="dealer">Dealer</option>
                                        <option value="both">Cả hai</option>
                                    </select>
                                    <select
                                        className={fieldClass}
                                        value={typeFilter}
                                        onChange={(event) => {
                                            setTypeFilter(event.target.value);
                                            setPage(1);
                                        }}
                                        aria-label="Lọc loại ưu đãi"
                                    >
                                        <option value="">Mọi loại ưu đãi</option>
                                        <option value="percentage">Giảm %</option>
                                        <option value="fixed_amount">Giảm tiền</option>
                                        <option value="buy_a_get_b">Mua X tặng Y</option>
                                    </select>
                                </div>
                            </div>
                            {list.isPending ? (
                                <LoadingState />
                            ) : list.isError ? (
                                <ErrorState
                                    message={errorMessage(list.error)}
                                    retry={() => void list.refetch()}
                                />
                            ) : list.data.data.length === 0 ? (
                                search || statusFilter || scopeFilter || typeFilter ? (
                                    <EmptyState message="Không tìm thấy ưu đãi phù hợp." />
                                ) : (
                                    <div className="rounded-xl border bg-card px-5 py-10 text-center sm:px-8">
                                        <h3 className="text-lg font-semibold text-primary">
                                            Chưa có ưu đãi nào
                                        </h3>
                                        <p className="mx-auto mt-2 max-w-md text-sm text-muted-foreground">
                                            Tạo chương trình ưu đãi đầu tiên để áp dụng giảm giá
                                            hoặc quà tặng cho sản phẩm.
                                        </p>
                                        <button
                                            type="button"
                                            className={`${buttonClass} mt-5`}
                                            onClick={() =>
                                                void navigate({
                                                    to: "/admin/sales-promotions/create",
                                                })
                                            }
                                        >
                                            + Thêm ưu đãi
                                        </button>
                                    </div>
                                )
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[800px] text-left text-sm">
                                        <thead className="border-b text-muted-foreground">
                                            <tr>
                                                <th className="py-2">Mã / tên</th>
                                                <th>Phạm vi</th>
                                                <th>Loại ưu đãi</th>
                                                <th>Giảm</th>
                                                <th>Sản phẩm</th>
                                                <th>Hiệu lực</th>
                                                <th>Sử dụng</th>
                                                <th>Trạng thái</th>
                                                <th />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {list.data.data.map((promotion) => (
                                                <tr
                                                    key={promotion.id}
                                                    className="border-b last:border-0"
                                                >
                                                    <td className="py-3">
                                                        <strong>{promotion.code}</strong>
                                                        <span className="block text-muted-foreground">
                                                            {promotion.name}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        {promotion.sales_scope === "both"
                                                            ? "Retail & Đại lý"
                                                            : promotion.sales_scope === "dealer"
                                                              ? "Đại lý"
                                                              : "Retail"}
                                                    </td>
                                                    <td>
                                                        {promotion.discount_type === "percentage"
                                                            ? "Giảm %"
                                                            : promotion.discount_type ===
                                                                "fixed_amount"
                                                              ? "Giảm tiền"
                                                              : promotion.gift_rule
                                                                      ?.buy_product_id ===
                                                                  promotion.gift_rule
                                                                      ?.gift_product_id
                                                                ? "Mua A tặng A"
                                                                : "Mua A tặng B"}
                                                    </td>
                                                    <td>
                                                        {promotion.discount_type === "buy_a_get_b"
                                                            ? `Mua A tặng ${promotion.gift_rule?.buy_product_id === promotion.gift_rule?.gift_product_id ? "A" : "B"} · ${formatProductQuantity(promotion.gift_units_granted ?? "0")} quà`
                                                            : promotion.discount_type ===
                                                                "percentage"
                                                              ? formatPercentage(
                                                                    promotion.discount_value,
                                                                )
                                                              : money(promotion.discount_value)}
                                                    </td>
                                                    <td>
                                                        {promotion.discount_type === "buy_a_get_b"
                                                            ? "1 sản phẩm mua"
                                                            : promotion.targets.some(
                                                                    (target) =>
                                                                        target.product_category_id !==
                                                                        null,
                                                                )
                                                              ? "Theo danh mục"
                                                              : promotion.targets.length === 0
                                                                ? "Toàn bộ"
                                                                : `${promotion.targets.length} sản phẩm`}
                                                    </td>
                                                    <td>
                                                        {promotion.starts_at?.slice(0, 10) ?? "—"} →{" "}
                                                        {promotion.ends_at?.slice(0, 10) ?? "—"}
                                                    </td>
                                                    <td>
                                                        {promotion.redeemed_count}
                                                        {promotion.total_usage_limit !== null
                                                            ? ` / ${promotion.total_usage_limit}`
                                                            : ""}
                                                    </td>
                                                    <td>
                                                        {promotion.status === "inactive"
                                                            ? "Tạm dừng"
                                                            : promotion.starts_at &&
                                                                new Date(promotion.starts_at) >
                                                                    new Date()
                                                              ? "Chưa bắt đầu"
                                                              : promotion.ends_at &&
                                                                  new Date(promotion.ends_at) <
                                                                      new Date()
                                                                ? "Hết hạn"
                                                                : "Hoạt động"}
                                                    </td>
                                                    <td>
                                                        <button
                                                            type="button"
                                                            className={secondaryButtonClass}
                                                            onClick={() =>
                                                                setDetailId(promotion.id)
                                                            }
                                                        >
                                                            Chi tiết
                                                        </button>{" "}
                                                        <button
                                                            type="button"
                                                            className={secondaryButtonClass}
                                                            onClick={() =>
                                                                void navigate({
                                                                    to: "/admin/sales-promotions/$id/edit",
                                                                    params: {
                                                                        id: String(promotion.id),
                                                                    },
                                                                })
                                                            }
                                                        >
                                                            Sửa
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className={secondaryButtonClass}
                                                            disabled={statusChange.isPending}
                                                            onClick={() =>
                                                                setPendingStatusChange({
                                                                    id: promotion.id,
                                                                    code: promotion.code,
                                                                    name: promotion.name,
                                                                    active:
                                                                        promotion.status !==
                                                                        "active",
                                                                })
                                                            }
                                                        >
                                                            {promotion.status === "active"
                                                                ? "Tạm dừng"
                                                                : "Kích hoạt"}
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                            {list.data && (
                                <Pagination
                                    current={list.data.current_page}
                                    last={list.data.last_page}
                                    onPage={setPage}
                                />
                            )}
                        </section>
                        <AlertDialog
                            open={pendingStatusChange !== null}
                            onOpenChange={(open) => {
                                if (!open && !statusChange.isPending) setPendingStatusChange(null);
                            }}
                        >
                            <AlertDialogContent className="w-[calc(100%-2rem)] max-w-md rounded-xl">
                                <AlertDialogHeader>
                                    <AlertDialogTitle className="text-primary">
                                        {pendingStatusChange?.active
                                            ? "Kích hoạt ưu đãi?"
                                            : "Tạm dừng ưu đãi?"}
                                    </AlertDialogTitle>
                                    <AlertDialogDescription className="leading-6">
                                        Bạn có chắc muốn{" "}
                                        {pendingStatusChange?.active ? "kích hoạt" : "tạm dừng"} ưu
                                        đãi{" "}
                                        <strong className="font-semibold text-foreground">
                                            {pendingStatusChange?.name} ({pendingStatusChange?.code}
                                            )
                                        </strong>
                                        ?{" "}
                                        {pendingStatusChange?.active
                                            ? "Ưu đãi sẽ áp dụng theo điều kiện và thời gian đã cấu hình."
                                            : "Ưu đãi sẽ ngừng áp dụng cho các đơn hàng mới."}
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter className="gap-2">
                                    <AlertDialogCancel disabled={statusChange.isPending}>
                                        Hủy
                                    </AlertDialogCancel>
                                    <button
                                        type="button"
                                        className={buttonClass}
                                        disabled={
                                            statusChange.isPending || pendingStatusChange === null
                                        }
                                        onClick={() => {
                                            if (pendingStatusChange) {
                                                statusChange.mutate({
                                                    id: pendingStatusChange.id,
                                                    active: pendingStatusChange.active,
                                                });
                                            }
                                        }}
                                    >
                                        {statusChange.isPending
                                            ? "Đang xử lý..."
                                            : pendingStatusChange?.active
                                              ? "Xác nhận kích hoạt"
                                              : "Xác nhận tạm dừng"}
                                    </button>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                        <Dialog
                            open={detailId !== null}
                            onOpenChange={(open) => {
                                if (!open) setDetailId(null);
                            }}
                        >
                            <DialogContent className="max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl overflow-y-auto rounded-xl">
                                <DialogHeader>
                                    <DialogTitle className="text-xl text-primary">
                                        Chi tiết ưu đãi
                                    </DialogTitle>
                                    <DialogDescription>
                                        Thông tin áp dụng và các đơn hàng đã sử dụng ưu đãi.
                                    </DialogDescription>
                                </DialogHeader>
                                {detailId !== null && (
                                    <>
                                        {detail.isPending ? (
                                            <LoadingState />
                                        ) : detail.isError ? (
                                            <ErrorState
                                                message={errorMessage(detail.error)}
                                                retry={() => void detail.refetch()}
                                            />
                                        ) : (
                                            <div className="space-y-4 text-sm">
                                                <p>
                                                    <strong>{detail.data.data.name}</strong> ·{" "}
                                                    {detail.data.data.code} ·{" "}
                                                    {detail.data.data.sales_scope}
                                                </p>
                                                {detail.data.data.gift_rule && (
                                                    <p>
                                                        Mua Product #
                                                        {detail.data.data.gift_rule.buy_product_id}
                                                        {detail.data.data.gift_rule.buy_variant_id
                                                            ? ` / SKU #${detail.data.data.gift_rule.buy_variant_id}`
                                                            : ""}
                                                        {` × ${formatProductQuantity(detail.data.data.gift_rule.minimum_buy_quantity)} → Tặng SKU #${detail.data.data.gift_rule.gift_variant_id} × ${formatProductQuantity(detail.data.data.gift_rule.gift_quantity)}`}
                                                        {detail.data.data.gift_rule
                                                            .repeat_per_multiple &&
                                                            " theo mỗi bội số"}
                                                        .
                                                    </p>
                                                )}
                                                <p>
                                                    Đã dùng: {detail.data.data.redeemed_count} · Quà
                                                    đã cấp:{" "}
                                                    {formatProductQuantity(
                                                        detail.data.data.gift_units_granted ?? "0",
                                                    )}{" "}
                                                    · Quà đã trả:{" "}
                                                    {formatProductQuantity(
                                                        detail.data.data.gift_units_returned ?? "0",
                                                    )}
                                                </p>
                                                <p>
                                                    Hạng đại lý:{" "}
                                                    {detail.data.data.dealer_tiers
                                                        ?.map((tier) => tier.name)
                                                        .join(", ") || "Tất cả hạng phù hợp"}
                                                </p>
                                                <div className="overflow-x-auto">
                                                    <table className="w-full min-w-[600px] text-left">
                                                        <thead>
                                                            <tr>
                                                                <th>Đơn hàng</th>
                                                                <th>Kênh</th>
                                                                <th>Quà</th>
                                                                <th>Ngày áp dụng</th>
                                                                <th>Trạng thái</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {detail.data.data.related_orders?.map(
                                                                (order) => (
                                                                    <tr
                                                                        key={order.order_code}
                                                                        className="border-t"
                                                                    >
                                                                        <td>{order.order_code}</td>
                                                                        <td>
                                                                            {order.sales_channel}
                                                                        </td>
                                                                        <td>
                                                                            {order.gift_sku
                                                                                ? `${order.gift_sku} × ${formatProductQuantity(order.gift_quantity)}`
                                                                                : "—"}
                                                                        </td>
                                                                        <td>
                                                                            {order.redeemed_at?.slice(
                                                                                0,
                                                                                16,
                                                                            )}
                                                                        </td>
                                                                        <td>
                                                                            {order.order_status} ·{" "}
                                                                            {
                                                                                order.redemption_status
                                                                            }
                                                                        </td>
                                                                    </tr>
                                                                ),
                                                            )}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        )}
                                    </>
                                )}
                                <DialogFooter>
                                    <button
                                        type="button"
                                        className={secondaryButtonClass}
                                        onClick={() => setDetailId(null)}
                                    >
                                        Đóng
                                    </button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </>
                )}
            </div>
        </ProductAdminGuard>
    );
}
