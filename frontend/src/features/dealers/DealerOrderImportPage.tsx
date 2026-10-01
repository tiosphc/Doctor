import { useRef, useState } from "react";
import { formatProductQuantity } from "@/lib/productQuantity";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, Navigate } from "@tanstack/react-router";
import { Download, FileSpreadsheet, RefreshCw, UploadCloud } from "lucide-react";
import { toast } from "sonner";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { useAuth } from "@/contexts/AuthContext";
import { ApiError, errorMessage } from "@/services/api";
import { commerceCodeMessage } from "@/services/commerceErrors";
import { dealerApi, dealerKeys } from "./api";
import type { DealerImport, DealerImportError } from "./types";

const money = (value: string) =>
    new Intl.NumberFormat("vi-VN", {
        style: "currency",
        currency: "VND",
    }).format(Number(value));

const messages: Record<string, string> = {
    REQUIRED_FIELD: "Thiếu thông tin bắt buộc.",
    INVALID_PHONE: "Số điện thoại phải có chữ số hợp lệ.",
    INVALID_EMAIL: "Email không hợp lệ.",
    INVALID_QUANTITY: "Số lượng phải là số nguyên lớn hơn 0.",
    INVALID_QUANTITY_PRECISION: "Số lượng vượt độ chính xác của đơn vị.",
    SKU_NOT_FOUND: "Không tìm thấy SKU.",
    DEALER_SKU_NOT_SELLABLE: "SKU không bán cho đại lý.",
    DEALER_PRICE_NOT_FOUND: "SKU chưa có giá đại lý cho Tier hiện tại.",
    DEALER_MOQ_NOT_MET: "Số lượng dưới mức MOQ.",
    ORDER_GROUP_RECIPIENT_MISMATCH: "Thông tin người nhận trong cùng đơn không khớp.",
    ORDER_GROUP_NOTE_MISMATCH: "Ghi chú trong cùng đơn không khớp.",
    IMPORT_BATCH_INSUFFICIENT_STOCK: "Tổng nhu cầu của file vượt tồn khả dụng.",
    INSUFFICIENT_STOCK: "Không đủ tồn kho.",
    DEALER_WALLET_INSUFFICIENT_BALANCE: "Số dư ví trả trước không đủ cho file này.",
    EXTERNAL_ORDER_REF_ALREADY_USED: "Đơn này đã được tạo từ file cùng nội dung.",
    DEALER_IMPORT_CHANGED: "Dữ liệu đã thay đổi kể từ lúc xem trước. Hãy kiểm tra lại.",
    IMPORT_FILE_ALREADY_COMPLETED: "File này đã được nhập thành công trước đó.",
    FORBIDDEN_IMPORT_COLUMN:
        "File chứa cột không được phép (giá, Tier, kho hoặc thông tin thương mại khác).",
    FORMULA_NOT_ALLOWED: "File có ô công thức. Hãy thay bằng giá trị tĩnh.",
    TEXT_CELL_REQUIRED: "Số điện thoại, mã bưu chính và SKU phải ở dạng Text trong Excel.",
    INVALID_XLSX: "File XLSX không hợp lệ.",
    IMPORT_ROW_LIMIT: "File vượt giới hạn 1.000 dòng.",
    IMPORT_ORDER_LIMIT: "File vượt giới hạn 100 đơn.",
    IMPORT_LINE_LIMIT: "Một đơn vượt giới hạn 50 SKU.",
    IMPORT_EMPTY: "File chưa có dòng đơn hàng nào.",
    XLSX_TOO_LARGE: "File vượt giới hạn dung lượng cho phép.",
    UNSAFE_XLSX: "File chứa thành phần không an toàn.",
    ORDERS_SHEET_MISSING: "File thiếu sheet Orders.",
    MISSING_REQUIRED_COLUMN: "File thiếu cột bắt buộc trong mẫu.",
    DUPLICATE_COLUMN: "File có cột bị lặp.",
    UNKNOWN_COLUMN: "File có cột ngoài mẫu.",
    DEALER_ORDER_CHANGED: "Giá hoặc điều kiện đặt hàng đã thay đổi. Hãy kiểm tra lại.",
    OPERATION_KEY_CONFLICT: "Lần xác nhận này đã được dùng cho dữ liệu khác. Hãy kiểm tra lại.",
};

const importFieldNames: Record<string, string> = {
    SKU: "SKU",
    "Customer Name": "tên khách hàng",
    Phone: "số điện thoại",
    Email: "email",
    Street: "đường và số nhà",
    City: "thành phố",
    State: "tỉnh/bang",
    Country: "quốc gia",
    "Zip Code": "mã bưu chính",
    Quantity: "số lượng",
    Recipient: "người nhận",
    "Voucher Code": "mã ưu đãi",
    Note: "ghi chú",
    Order: "đơn hàng",
};

function importError(error: unknown): string {
    return error instanceof ApiError && error.code
        ? (messages[error.code] ?? errorMessage(error))
        : errorMessage(error);
}

function ErrorLine({ error, sku }: { error: DealerImportError; sku: string | undefined }) {
    const value = error.value?.trim();
    const fieldName = importFieldNames[error.field] ?? error.field;
    const detail =
        error.code === "SKU_NOT_FOUND"
            ? commerceCodeMessage(error.code, value || sku)
            : error.code === "REQUIRED_FIELD"
              ? `Thiếu ${fieldName}. Vui lòng điền ô này trong file Excel.`
              : error.code === "INVALID_EMAIL" && value
                ? `Email '${value}' không đúng định dạng. Vui lòng nhập lại.`
                : error.code === "INVALID_PHONE" && value
                  ? `Số điện thoại '${value}' không hợp lệ. Vui lòng nhập lại.`
                  : error.code === "INVALID_QUANTITY" && value
                    ? `Số lượng '${value}' phải là số nguyên lớn hơn 0.`
                    : (messages[error.code] ?? commerceCodeMessage(error.code, sku));
    return (
        <li>
            {error.row === null ? "Toàn file" : `Dòng ${error.row}`} · {fieldName}: {detail}
        </li>
    );
}

export function DealerOrderImportPage() {
    const { user, isLoading } = useAuth();
    const queryClient = useQueryClient();
    const [file, setFile] = useState<File | null>(null);
    const [current, setCurrent] = useState<DealerImport | null>(null);
    const [page, setPage] = useState(1);
    const [busy, setBusy] = useState("");
    const [notice, setNotice] = useState("");
    const [confirmOpen, setConfirmOpen] = useState(false);
    const operationKey = useRef(crypto.randomUUID());
    const accounts = useQuery({
        queryKey: dealerKeys.mine(user?.id),
        queryFn: dealerApi.mine,
        enabled: user?.role === "customer",
    });
    const selected = accounts.data?.data[0];
    const wallet = useQuery({
        queryKey: dealerKeys.wallet(user?.id, selected?.id ?? 0),
        queryFn: () => dealerApi.wallet(selected!.id),
        enabled: Boolean(selected),
    });
    const history = useQuery({
        queryKey: ["dealer-order-imports", user?.id, selected?.id, page],
        queryFn: () => dealerApi.imports(selected!.id, page),
        enabled: Boolean(selected),
        retry: false,
    });

    async function run(name: string, task: () => Promise<void>) {
        if (busy) return;
        setBusy(name);
        setNotice("");
        try {
            await task();
        } catch (error) {
            setNotice(importError(error));
        } finally {
            setBusy("");
        }
    }

    async function download() {
        if (!selected) return;
        await run("template", async () => {
            const blob = await dealerApi.importTemplate(selected.id);
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement("a");
            anchor.href = url;
            anchor.download = "dealer_order_template.xlsx";
            anchor.click();
            URL.revokeObjectURL(url);
        });
    }

    async function upload() {
        if (!selected || !file) return;
        await run("upload", async () => {
            const response = await dealerApi.importUpload(selected.id, file);
            setCurrent(response.data);
            setFile(null);
            operationKey.current = crypto.randomUUID();
            await queryClient.invalidateQueries({ queryKey: ["dealer-order-imports"] });
            toast.success("Đã đọc file. Hãy kiểm tra bản xem trước.");
        });
    }

    async function revalidate() {
        if (!selected || !current) return;
        await run("revalidate", async () => {
            const response = await dealerApi.importRevalidate(selected.id, current.id);
            setCurrent(response.data);
            operationKey.current = crypto.randomUUID();
            toast.success("Đã kiểm tra lại giá, Tier, kho và tồn kho.");
        });
    }

    async function confirm() {
        if (!selected || !current) return;
        setConfirmOpen(false);
        await run("confirm", async () => {
            const response = await dealerApi.importConfirm(
                selected.id,
                current.id,
                operationKey.current,
                current.preview_fingerprint,
            );
            setCurrent(response.data);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ["dealer-order-imports"] }),
                queryClient.invalidateQueries({ queryKey: ["dealer-orders"] }),
            ]);
            toast.success(
                response.data.status === "completed"
                    ? "Đã tạo tất cả đơn."
                    : "Đã xử lý import. Kiểm tra từng nhóm đơn.",
            );
        });
    }

    if (isLoading) return <LoadingState />;
    if (!user) return <Navigate to="/login" />;
    if (user.role !== "customer") return <Navigate to="/account" />;
    if (accounts.isPending) return <LoadingState />;
    if (accounts.isError)
        return (
            <ErrorState
                message={errorMessage(accounts.error)}
                retry={() => void accounts.refetch()}
            />
        );
    if (!selected) return <EmptyState message="Bạn chưa có tài khoản đại lý đang hoạt động." />;

    const canConfirm = Boolean(
        current &&
        current.invalid_order_count === 0 &&
        current.groups.some((group) => group.sales_order_id === null),
    );
    const walletInsufficient = Boolean(
        current &&
        wallet.data &&
        Number(wallet.data.data.available_balance) <
            Number(current.preview_summary.estimated_total),
    );

    return (
        <main className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p className="label-luxury">Junie B2B</p>
                    <h1 className="mt-2 text-3xl text-primary">Nhập đơn Excel</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Mỗi dòng là một SKU. Các dòng cùng Mã đơn được gộp thành một đơn; file cũ
                        vẫn gộp theo số điện thoại và địa chỉ. Tải file XLSX, xem trước giá và MOQ,
                        rồi xác nhận tạo đơn.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-4">
                    <button
                        type="button"
                        className="inline-flex items-center gap-2 rounded-md border px-4 py-2 text-sm"
                        disabled={Boolean(busy)}
                        onClick={() => void download()}
                    >
                        <Download size={16} /> Tải mẫu XLSX
                    </button>
                    <Link to="/dealer/orders" className="text-sm text-primary underline">
                        Đơn hàng đại lý
                    </Link>
                </div>
            </header>
            {wallet.data && (
                <p className="rounded-lg border border-[#d8e0eb] bg-white p-3 text-sm text-[#092b5c]">
                    Ví trả trước: <strong>{money(wallet.data.data.available_balance)}</strong> · Dự
                    kiến file:{" "}
                    <strong>{money(current?.preview_summary.estimated_total ?? "0.00")}</strong>
                    {walletInsufficient && (
                        <span className="ml-2 text-red-700">Số dư không đủ</span>
                    )}
                </p>
            )}
            <section className="space-y-4 rounded-xl border bg-card p-4 sm:p-6">
                <div className="flex items-center gap-2">
                    <UploadCloud size={20} />
                    <h2 className="text-xl text-primary">Tải file lên</h2>
                </div>
                <p className="text-sm text-muted-foreground">
                    Chỉ .xlsx · tối đa 5 MB · 1.000 dòng · 100 đơn · 50 SKU mỗi đơn. File được đọc
                    riêng tư và xóa sau khi xử lý.
                </p>
                <div className="flex flex-wrap items-end gap-3">
                    <label className="grid min-w-0 flex-1 gap-2 text-sm">
                        Chọn workbook
                        <input
                            type="file"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            className="min-w-0 rounded-md border bg-background p-2"
                            onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                        />
                    </label>
                    <button
                        type="button"
                        className="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50"
                        disabled={!file || Boolean(busy)}
                        onClick={() => void upload()}
                    >
                        {busy === "upload" ? "Đang đọc file..." : "Đọc và xem trước"}
                    </button>
                </div>
            </section>
            {notice && (
                <p
                    role="alert"
                    className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"
                >
                    {notice}
                    {notice.includes("thay đổi") && (
                        <button
                            type="button"
                            className="ml-2 underline"
                            onClick={() => void revalidate()}
                        >
                            Kiểm tra lại
                        </button>
                    )}
                </p>
            )}
            {current && (
                <section className="space-y-5 rounded-xl border bg-card p-4 sm:p-6">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-sm text-muted-foreground">
                                Import #{current.id} · {current.original_filename} ·{" "}
                                {Math.ceil(current.file_size / 1024)} KB
                            </p>
                            <h2 className="mt-1 text-xl text-primary">Xem trước đơn</h2>
                        </div>
                        <button
                            type="button"
                            className="inline-flex items-center gap-2 rounded-md border px-4 py-2 text-sm"
                            disabled={Boolean(busy)}
                            onClick={() => void revalidate()}
                        >
                            <RefreshCw size={15} /> Kiểm tra lại
                        </button>
                    </div>
                    <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div className="rounded-md bg-muted p-3">
                            Dòng: <strong>{current.row_count}</strong>
                        </div>
                        <div className="rounded-md bg-muted p-3">
                            Đơn hợp lệ:{" "}
                            <strong>
                                {current.valid_order_count}/{current.order_count}
                            </strong>
                        </div>
                        <div className="rounded-md bg-muted p-3">
                            Đơn lỗi: <strong>{current.invalid_order_count}</strong>
                        </div>
                        <div className="rounded-md bg-muted p-3">
                            Ước tính:{" "}
                            <strong>{money(current.preview_summary.estimated_total)}</strong>
                        </div>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Trạng thái: {current.status} · Người tải: {current.uploaded_by}. Xem trước
                        không giữ hàng.
                    </p>
                    {current.groups.map((group) => (
                        <article key={group.id} className="space-y-3 rounded-lg border p-4">
                            <div className="flex flex-wrap justify-between gap-2">
                                <div>
                                    <h3 className="font-semibold text-primary">
                                        {group.external_reference}
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        {group.preview?.recipient.recipient_name} ·{" "}
                                        {group.preview?.recipient.recipient_phone}
                                    </p>
                                    {group.preview && (
                                        <p className="text-sm text-muted-foreground">
                                            {group.preview.items.length} sản phẩm · tổng SL{" "}
                                            {new Intl.NumberFormat("vi-VN").format(
                                                group.preview.items.reduce(
                                                    (total, item) => total + BigInt(item.quantity),
                                                    0n,
                                                ),
                                            )}
                                        </p>
                                    )}
                                    {group.preview && (
                                        <p className="text-sm text-muted-foreground">
                                            {[
                                                group.preview.recipient.recipient_email,
                                                group.preview.recipient.shipping_address_line1,
                                                group.preview.recipient.shipping_address_line2,
                                                group.preview.recipient.shipping_city,
                                                group.preview.recipient.shipping_province,
                                                group.preview.recipient.shipping_country,
                                                group.preview.recipient.shipping_postal_code,
                                            ]
                                                .filter(Boolean)
                                                .join(" · ")}
                                        </p>
                                    )}
                                    {group.preview?.recipient.delivery_note && (
                                        <p className="text-sm text-muted-foreground">
                                            Ghi chú: {group.preview.recipient.delivery_note}
                                        </p>
                                    )}
                                </div>
                                <strong>{money(group.preview?.grand_total ?? "0")}</strong>
                            </div>
                            {group.preview?.discount_total &&
                                Number(group.preview.discount_total) > 0 && (
                                    <p className="text-sm text-emerald-800">
                                        Ưu đãi tự động: {money(group.preview.subtotal)} −{" "}
                                        {money(group.preview.discount_total)} ={" "}
                                        {money(group.preview.grand_total)}
                                    </p>
                                )}
                            {group.preview?.gift_item && (
                                <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                                    Quà tặng: {group.preview.gift_item.product_name} /{" "}
                                    {group.preview.gift_item.variant_name} ×{" "}
                                    {formatProductQuantity(group.preview.gift_item.quantity)} ·{" "}
                                    {money("0")}
                                </p>
                            )}
                            {group.preview?.effective_tier && (
                                <p className="text-xs text-muted-foreground">
                                    Tier: {group.preview.effective_tier.name} · Kho:{" "}
                                    {group.preview.warehouse?.name ?? "—"}
                                </p>
                            )}
                            {group.preview?.preferred_warehouse && (
                                <p className="text-xs text-muted-foreground">
                                    Kho ưu tiên: {group.preview.preferred_warehouse.name} · Kho thực
                                    tế: {group.preview.warehouse?.name ?? "—"}
                                </p>
                            )}
                            {group.preview?.fallback_used && (
                                <p role="status" className="text-sm text-amber-800">
                                    Kho ưu tiên không đủ tồn. Đơn sẽ được xử lý từ{" "}
                                    {group.preview.warehouse?.name}. Giá đã được tính lại theo kho
                                    thực tế.
                                </p>
                            )}
                            {group.preview?.items.length ? (
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[650px] text-left text-sm">
                                        <thead className="border-b text-muted-foreground">
                                            <tr>
                                                <th className="py-2">SKU / Sản phẩm</th>
                                                <th>Số lượng</th>
                                                <th>MOQ</th>
                                                <th>Đơn giá</th>
                                                <th>Thành tiền</th>
                                                <th>Khả dụng</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {group.preview.items.map((item) => (
                                                <tr
                                                    key={item.product_variant_id}
                                                    className="border-b last:border-0"
                                                >
                                                    <td className="py-2">
                                                        {item.sku} · {item.product_name} ·{" "}
                                                        {item.variant_name}
                                                    </td>
                                                    <td>
                                                        {formatProductQuantity(item.quantity)}{" "}
                                                        {item.unit_name}
                                                    </td>
                                                    <td>
                                                        {formatProductQuantity(
                                                            item.minimum_quantity,
                                                        )}
                                                    </td>
                                                    <td>{money(item.unit_price ?? "0")}</td>
                                                    <td>{money(item.line_total ?? "0")}</td>
                                                    <td>
                                                        {item.available_for_requested_quantity
                                                            ? "Có"
                                                            : "Thiếu"}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : null}
                            {group.preview?.errors.length ? (
                                <ul
                                    role="alert"
                                    className="list-disc space-y-1 pl-5 text-sm text-red-700"
                                >
                                    {group.preview.errors.map((error, index) => (
                                        <ErrorLine
                                            key={index}
                                            error={error}
                                            sku={
                                                current.rows.find((row) => row.row === error.row)
                                                    ?.sku
                                            }
                                        />
                                    ))}
                                </ul>
                            ) : null}
                            {group.error_code && (
                                <p role="alert" className="text-sm text-red-700">
                                    Không tạo được:{" "}
                                    {messages[group.error_code] ??
                                        commerceCodeMessage(group.error_code)}
                                </p>
                            )}
                            {group.sales_order_id && (
                                <Link
                                    to="/dealer/orders/$orderId"
                                    params={{ orderId: String(group.sales_order_id) }}
                                    className="inline-block text-sm font-medium text-primary underline"
                                >
                                    Đã tạo {group.order_code} · Xem đơn
                                </Link>
                            )}
                        </article>
                    ))}
                    {current.invalid_order_count > 0 && (
                        <p className="rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                            Có lỗi trong file hoặc giá/tồn kho. Sửa file và tải lại, hoặc kiểm tra
                            lại nếu dữ liệu thương mại đã đổi.
                        </p>
                    )}
                    <button
                        type="button"
                        className="rounded-md bg-primary px-5 py-2 text-sm text-primary-foreground disabled:opacity-50"
                        disabled={!canConfirm || walletInsufficient || Boolean(busy)}
                        onClick={() => setConfirmOpen(true)}
                    >
                        {busy === "confirm" ? "Đang tạo đơn..." : "Xác nhận tạo đơn"}
                    </button>
                </section>
            )}
            <section className="space-y-4 rounded-xl border bg-card p-4 sm:p-6">
                <div className="flex items-center gap-2">
                    <FileSpreadsheet size={20} />
                    <h2 className="text-xl text-primary">Lịch sử import</h2>
                </div>
                {history.isPending ? (
                    <LoadingState />
                ) : history.isError ? (
                    <ErrorState
                        message={errorMessage(history.error)}
                        retry={() => void history.refetch()}
                    />
                ) : history.data.data.length === 0 ? (
                    <EmptyState message="Chưa có file nào được nhập." />
                ) : (
                    <>
                        <div className="grid gap-2">
                            {history.data.data.map((item) => (
                                <button
                                    key={item.id}
                                    type="button"
                                    className="flex flex-wrap justify-between gap-2 rounded-md border p-3 text-left text-sm hover:bg-muted"
                                    onClick={() =>
                                        void run("detail", async () => {
                                            const result = await dealerApi.importDetail(
                                                selected.id,
                                                item.id,
                                            );
                                            setCurrent(result.data);
                                        })
                                    }
                                >
                                    <span>
                                        {item.original_filename} ·{" "}
                                        {new Date(item.created_at).toLocaleString("vi-VN")}
                                    </span>
                                    <span>
                                        {item.status} · {item.order_count} đơn
                                    </span>
                                </button>
                            ))}
                        </div>
                        <Pagination
                            current={history.data.current_page}
                            last={history.data.last_page}
                            onPage={setPage}
                        />
                    </>
                )}
            </section>
            <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Xác nhận tạo {current?.order_count} đơn?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Hệ thống sẽ kiểm tra lại giá, Tier, kho và tồn kho; mỗi nhóm đơn hợp lệ
                            được tạo và giữ hàng riêng.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Quay lại</AlertDialogCancel>
                        <AlertDialogAction disabled={Boolean(busy)} onClick={() => void confirm()}>
                            Xác nhận
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </main>
    );
}
