import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import {
    ResponsiveContainer,
    LineChart,
    Line,
    CartesianGrid,
    XAxis,
    YAxis,
    Tooltip,
    Legend,
} from "recharts";
import { AdminGuard, AdminTitle } from "@/pages/admin/AdminPages";
import { fieldClass } from "./ProductAdminShared";
import { formatProductQuantity } from "@/lib/productQuantity";
import { ErrorState } from "@/components/common/AsyncState";
import {
    reportApi,
    type ReportFilters,
    type ReportName,
    type ReportOverview,
    type ReportDetails,
} from "@/services/reportApi";

function decimal(value: string): string {
    const [integer = "0", fraction = ""] = value.split(".");
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    const significantFraction = fraction.replace(/0+$/, "");
    return significantFraction ? `${grouped},${significantFraction}` : grouped;
}
const money = (value: string) => `${decimal(value)} ₫`;
const quantity = formatProductQuantity;
const today = () =>
    new Intl.DateTimeFormat("en-CA", {
        timeZone: "Asia/Ho_Chi_Minh",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(new Date());
const firstOfMonth = () => `${today().slice(0, 7)}-01`;
const dateAgo = (days: number) =>
    new Intl.DateTimeFormat("en-CA", {
        timeZone: "Asia/Ho_Chi_Minh",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(new Date(Date.now() - days * 86_400_000));
const labels: Record<ReportName, string> = {
    sales: "Doanh thu",
    orders: "Đơn hàng",
    products: "Sản phẩm",
    inventory: "Tồn kho",
    procurement: "Mua hàng",
    dealers: "Đại lý",
    wallets: "Ví đại lý",
    promotions: "Khuyến mãi",
    "clinic-summary": "Phòng khám",
};

function Metric({ label, value, detail }: { label: string; value: string; detail?: string }) {
    return (
        <div className="card-surface min-w-0 p-5">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="mt-2 break-words text-2xl font-semibold text-primary">{value}</p>
            {detail && <p className="mt-1 text-xs text-muted-foreground">{detail}</p>}
        </div>
    );
}

function ReportTable({ columns, rows }: { columns: string[]; rows: (string | number)[][] }) {
    return (
        <div className="card-surface overflow-x-auto">
            <table className="w-full min-w-[560px] text-left text-sm">
                <thead className="border-b bg-muted/40">
                    <tr>
                        {columns.map((column) => (
                            <th key={column} className="px-4 py-3 font-semibold">
                                {column}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.length ? (
                        rows.map((row, index) => (
                            <tr key={index} className="border-b last:border-0">
                                {row.map((cell, cellIndex) => (
                                    <td key={cellIndex} className="px-4 py-3">
                                        {cell}
                                    </td>
                                ))}
                            </tr>
                        ))
                    ) : (
                        <tr>
                            <td
                                colSpan={columns.length}
                                className="px-4 py-8 text-center text-muted-foreground"
                            >
                                Không có dữ liệu trong khoảng đã chọn.
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}

function RevenueChart({ points }: { points: ReportOverview["trend"] }) {
    if (!points.length)
        return (
            <div className="card-surface flex h-72 items-center justify-center text-sm text-muted-foreground">
                Chưa có giao dịch quyết toán trong khoảng đã chọn.
            </div>
        );
    const chartData = points.map((point) => ({
        date: point.date.slice(5),
        Gộp: Number(point.gross),
        "Hoàn tiền": Number(point.refunds),
        Ròng: Number(point.net),
    }));
    return (
        <div className="card-surface h-80 min-w-0 p-4">
            <ResponsiveContainer width="100%" height="100%">
                <LineChart data={chartData} margin={{ top: 10, right: 16, left: 0, bottom: 5 }}>
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis dataKey="date" />
                    <YAxis
                        width={62}
                        tickFormatter={(value) =>
                            new Intl.NumberFormat("vi-VN", { notation: "compact" }).format(
                                Number(value),
                            )
                        }
                    />
                    <Tooltip formatter={(value) => money(String(value))} />
                    <Legend />
                    <Line type="monotone" dataKey="Gộp" stroke="#b58a57" dot={false} />
                    <Line type="monotone" dataKey="Hoàn tiền" stroke="#d46b6b" dot={false} />
                    <Line type="monotone" dataKey="Ròng" stroke="#07346a" dot={false} />
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
}

export function ErpOverviewPanel({ filters }: { filters?: ReportFilters }) {
    const overviewFilters = filters ?? {
        from: dateAgo(29),
        to: today(),
        channel: "all" as const,
    };
    const query = useQuery({
        queryKey: ["erp-overview", overviewFilters],
        queryFn: () => reportApi.overview(overviewFilters),
    });
    if (query.isPending)
        return (
            <div
                className="mt-7 grid animate-pulse gap-4 sm:grid-cols-2 xl:grid-cols-4"
                aria-label="Đang tải báo cáo ERP"
            >
                {Array.from({ length: 4 }, (_, index) => (
                    <div key={index} className="card-surface h-32 bg-muted/45" />
                ))}
            </div>
        );
    if (query.isError)
        return (
            <div className="mt-7">
                <ErrorState message="Không thể tải báo cáo ERP." retry={() => query.refetch()} />
            </div>
        );
    const report = query.data;
    return (
        <section className="mt-7 space-y-5" aria-label="Tổng quan ERP">
            <div className="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 className="text-xl text-primary">Bán hàng và vận hành</h2>
                    <p className="text-xs text-muted-foreground">
                        {filters === undefined && "30 ngày gần nhất · "}
                        {report.period.from} – {report.period.to} · Giờ Việt Nam · Doanh thu theo
                        thanh toán đã quyết toán
                    </p>
                </div>
                <Link
                    to="/admin/reports"
                    className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                >
                    Xem báo cáo chi tiết →
                </Link>
            </div>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Metric label="Doanh thu ròng" value={money(report.sales.net)} />
                <Metric label="Thanh toán gộp" value={money(report.sales.gross)} />
                <Metric label="Hoàn tiền" value={money(report.sales.refunds)} />
                <Metric
                    label="Đơn tạo mới"
                    value={String(report.orders.created_count)}
                    detail="Theo ngày tạo đơn"
                />
            </div>
            <div className="grid gap-5 xl:grid-cols-2">
                <div>
                    <h3 className="mb-2 font-medium">Xu hướng doanh thu</h3>
                    <RevenueChart points={report.trend} />
                </div>
                <div className="space-y-4">
                    <h3 className="font-medium">Kênh bán hàng</h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Metric label="Bán lẻ · ròng" value={money(report.channels.retail.net)} />
                        <Metric label="Đại lý · ròng" value={money(report.channels.dealer.net)} />
                    </div>
                    <ReportTable
                        columns={["Trạng thái đơn", "Số đơn"]}
                        rows={report.orders.by_status.map((item) => [item.status, item.count])}
                    />
                </div>
            </div>
            <div className="grid gap-5 xl:grid-cols-2">
                <div>
                    <h3 className="mb-2 font-medium">SKU xuất bán nhiều nhất</h3>
                    <ReportTable
                        columns={["SKU", "Sản phẩm", "Số lượng xuất"]}
                        rows={report.top_products.map((item) => [
                            item.sku,
                            item.product_name,
                            quantity(item.fulfilled_quantity),
                        ])}
                    />
                </div>
                <div>
                    <h3 className="mb-2 font-medium">Kho và mua hàng</h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Metric
                            label="Khả dụng hiện tại"
                            value={quantity(report.inventory.available)}
                            detail="Snapshot, không theo kỳ"
                        />
                        <Metric
                            label="SKU tồn thấp"
                            value={String(report.inventory.low_stock_rows)}
                        />
                        <Metric
                            label="Phiếu mua"
                            value={String(report.procurement.purchase_order_count)}
                        />
                        <Metric
                            label="Giá trị đã nhận"
                            value={money(report.procurement.completed_receipt_value)}
                            detail="Theo ngày nhận hàng"
                        />
                    </div>
                </div>
            </div>
            <div className="grid gap-5 xl:grid-cols-2">
                <div>
                    <h3 className="mb-2 font-medium">Đại lý theo doanh thu ròng</h3>
                    <ReportTable
                        columns={["Đại lý", "Tier hiệu lực", "Doanh thu ròng"]}
                        rows={report.top_dealers.map((dealer) => [
                            dealer.code,
                            dealer.effective_tier ?? "—",
                            money(dealer.net),
                        ])}
                    />
                </div>
                <div>
                    <h3 className="mb-2 font-medium">Khuyến mãi và ví đại lý</h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Metric
                            label="Chiết khấu còn hiệu lực"
                            value={money(report.promotions.redeemed_discount_snapshot)}
                            detail="Snapshot, không trừ lần hai khỏi doanh thu"
                        />
                        <Metric
                            label="Số dư ví hiện tại"
                            value={money(report.wallets.current_balance)}
                            detail="Không phải doanh thu"
                        />
                        <Metric
                            label="PayOS Top-Up đã trả"
                            value={money(report.wallets.paid_payos_topups.amount)}
                        />
                        <Metric
                            label="Deposit hoàn tất"
                            value={money(report.wallets.completed_deposits.amount)}
                        />
                    </div>
                </div>
            </div>
        </section>
    );
}

function Detail({ name, data }: { name: ReportName; data: ReportDetails[ReportName] }) {
    if (name === "sales" && "totals" in data && "channels" in data)
        return (
            <div className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-3">
                    <Metric label="Gộp" value={money(data.totals.gross)} />
                    <Metric label="Hoàn" value={money(data.totals.refunds)} />
                    <Metric label="Ròng" value={money(data.totals.net)} />
                </div>
                <RevenueChart points={data.trend} />
            </div>
        );
    if ("recent" in data)
        return (
            <>
                <Metric label="Đơn tạo trong kỳ" value={String(data.summary.created_count)} />
                <ReportTable
                    columns={["Mã đơn", "Kênh", "Trạng thái", "Thanh toán", "Tổng đơn", "Ngày tạo"]}
                    rows={data.recent.map((row) => [
                        row.order_code,
                        row.sales_channel,
                        row.order_status,
                        row.payment_status,
                        money(row.grand_total),
                        row.created_at.slice(0, 10),
                    ])}
                />
            </>
        );
    if ("top_skus" in data)
        return (
            <>
                <p className="text-sm text-muted-foreground">
                    Xếp hạng theo lượng đã xuất. Hàng hoàn thành:{" "}
                    {quantity(data.completed_return_quantity)}. Không phân bổ doanh thu theo SKU.
                </p>
                <ReportTable
                    columns={["SKU", "Sản phẩm", "Đã xuất"]}
                    rows={data.top_skus.map((row) => [
                        row.sku,
                        row.product_name,
                        quantity(row.fulfilled_quantity),
                    ])}
                />
            </>
        );
    if ("warehouses" in data)
        return (
            <>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <Metric label="Tồn thực" value={quantity(data.summary.on_hand)} />
                    <Metric label="Đã giữ" value={quantity(data.summary.reserved)} />
                    <Metric label="Khả dụng" value={quantity(data.summary.available)} />
                    <Metric label="SKU tồn thấp" value={String(data.summary.low_stock_rows)} />
                    <Metric label="SKU hết hàng" value={String(data.summary.out_of_stock_rows)} />
                </div>
                <p className="text-xs text-muted-foreground">
                    Snapshot lúc {data.snapshot_at}; không phải giá trị tồn kho.
                </p>
                <ReportTable
                    columns={["Kho", "Tồn thực", "Đã giữ", "Khả dụng"]}
                    rows={data.warehouses.map((row) => [
                        `${row.code} · ${row.name}`,
                        quantity(row.on_hand),
                        quantity(row.reserved),
                        quantity(row.available),
                    ])}
                />
                <ReportTable
                    columns={["Loại biến động", "Lượt", "Số lượng có dấu"]}
                    rows={data.movements.map((row) => [
                        row.type,
                        row.count,
                        quantity(row.signed_quantity),
                    ])}
                />
            </>
        );
    if ("top_suppliers" in data)
        return (
            <>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric
                        label="Giá trị PO chưa hủy"
                        value={money(data.summary.non_cancelled_ordered_value)}
                    />
                    <Metric label="Đã nhận" value={money(data.summary.completed_receipt_value)} />
                    <Metric
                        label="Trả nhà cung cấp"
                        value={money(data.summary.purchase_return_value)}
                    />
                    <Metric label="Nhận ròng" value={money(data.summary.net_received_value)} />
                </div>
                <p className="text-xs text-muted-foreground">
                    Giá trị mua hàng vận hành; không phải công nợ hoặc giá vốn.
                </p>
                <ReportTable
                    columns={["Nhà cung cấp", "Đã nhận", "Đã trả", "Nhận ròng"]}
                    rows={data.top_suppliers.map((row) => [
                        `${row.code} · ${row.name}`,
                        money(row.received_value),
                        money(row.return_value),
                        money(row.net_received_value),
                    ])}
                />
            </>
        );
    if ("top_dealers" in data)
        return (
            <>
                <Metric label="Doanh thu đại lý ròng" value={money(data.totals.net)} />
                <ReportTable
                    columns={["Đại lý", "Tier gốc", "Tier hiệu lực", "Gộp", "Hoàn", "Ròng"]}
                    rows={data.top_dealers.map((row) => [
                        `${row.code} · ${row.name}`,
                        row.base_tier ?? "—",
                        row.effective_tier ?? "—",
                        money(row.gross),
                        money(row.refunds),
                        money(row.net),
                    ])}
                />
                <ReportTable
                    columns={["Tier gốc hiện tại", "Số đại lý"]}
                    rows={data.base_tier_distribution.map((row) => [
                        row.base_tier,
                        row.dealer_count,
                    ])}
                />
            </>
        );
    if ("wallet_count" in data)
        return (
            <>
                <div className="grid gap-4 sm:grid-cols-3">
                    <Metric
                        label="Số dư ví hiện tại"
                        value={money(data.current_balance)}
                        detail="Snapshot, không phải doanh thu"
                    />
                    <Metric
                        label="Deposit hoàn tất"
                        value={money(data.completed_deposits.amount)}
                    />
                    <Metric
                        label="PayOS Top-Up đã trả"
                        value={money(data.paid_payos_topups.amount)}
                    />
                </div>
                <ReportTable
                    columns={["Loại giao dịch ví", "Lượt", "Số tiền"]}
                    rows={data.flows.map((row) => [row.type, row.count, money(row.amount)])}
                />
            </>
        );
    if ("redemptions_by_current_status" in data)
        return (
            <>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Metric
                        label="Chiết khấu redemption còn hiệu lực"
                        value={money(data.redeemed_discount_snapshot)}
                    />
                    <Metric
                        label="Đã giải phóng trong kỳ"
                        value={String(data.released_in_period.count)}
                        detail={`${money(data.released_in_period.discount_snapshot)} chiết khấu snapshot`}
                    />
                </div>
                <p className="text-xs text-muted-foreground">
                    Snapshot khuyến mãi; không trừ lần hai khỏi doanh thu đã quyết toán.
                </p>
                <ReportTable
                    columns={["Trạng thái hiện tại", "Lượt", "Chiết khấu snapshot"]}
                    rows={data.redemptions_by_current_status.map((row) => [
                        row.status,
                        row.count,
                        money(row.discount_snapshot),
                    ])}
                />
            </>
        );
    if ("appointment_count" in data)
        return (
            <>
                <Metric label="Lịch hẹn trong kỳ" value={String(data.appointment_count)} />
                <p className="text-xs text-muted-foreground">
                    Chỉ số vận hành phòng khám; không ghi nhận doanh thu.
                </p>
                <ReportTable
                    columns={["Trạng thái", "Lịch hẹn"]}
                    rows={data.by_status.map((row) => [row.status, row.count])}
                />
            </>
        );
    return null;
}

export function ErpReportsPage() {
    const [filters, setFilters] = useState<ReportFilters>({
        from: firstOfMonth(),
        to: today(),
        channel: "all",
    });
    const [tab, setTab] = useState<ReportName>("sales");
    const detail = useQuery({
        queryKey: ["erp-report", tab, filters],
        queryFn: () => reportApi.detail(tab, filters),
    });
    const setPreset = (preset: "today" | "7" | "30" | "month" | "previous") => {
        const current = today();
        if (preset === "previous") {
            const previousDay = dateAgo(Number(current.slice(8)));
            setFilters((old) => ({
                ...old,
                from: `${previousDay.slice(0, 7)}-01`,
                to: previousDay,
            }));
        } else
            setFilters((old) => ({
                ...old,
                from:
                    preset === "today"
                        ? current
                        : preset === "7"
                          ? dateAgo(6)
                          : preset === "30"
                            ? dateAgo(29)
                            : firstOfMonth(),
                to: current,
            }));
    };
    return (
        <AdminGuard>
            <AdminTitle
                title="Báo cáo ERP"
                description="Số liệu bán hàng, tồn kho và mua hàng từ chứng từ thực tế. Các khoảng ngày dùng múi giờ Việt Nam."
            />
            <div className="card-surface mt-6 space-y-4 p-4">
                <div className="flex flex-wrap gap-2">
                    {(
                        [
                            ["today", "Hôm nay"],
                            ["7", "7 ngày"],
                            ["30", "30 ngày"],
                            ["month", "Tháng này"],
                            ["previous", "Tháng trước"],
                        ] as const
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setPreset(key)}
                            className="rounded-lg border px-3 py-2 text-sm hover:bg-muted"
                        >
                            {label}
                        </button>
                    ))}
                </div>
                <div className="grid gap-3 sm:grid-cols-3">
                    <label className="admin-form-field admin-form-label">
                        Từ ngày
                        <input
                            type="date"
                            value={filters.from}
                            max={filters.to}
                            onChange={(event) =>
                                setFilters((old) => ({ ...old, from: event.target.value }))
                            }
                            className={fieldClass}
                        />
                    </label>
                    <label className="admin-form-field admin-form-label">
                        Đến ngày
                        <input
                            type="date"
                            value={filters.to}
                            min={filters.from}
                            onChange={(event) =>
                                setFilters((old) => ({ ...old, to: event.target.value }))
                            }
                            className={fieldClass}
                        />
                    </label>
                    <label className="admin-form-field admin-form-label">
                        Kênh bán
                        <select
                            value={filters.channel}
                            onChange={(event) =>
                                setFilters((old) => ({
                                    ...old,
                                    channel: event.target.value as "all" | "retail" | "dealer",
                                }))
                            }
                            className={fieldClass}
                        >
                            <option value="all">Tất cả</option>
                            <option value="retail">Bán lẻ</option>
                            <option value="dealer">Đại lý</option>
                        </select>
                    </label>
                </div>
            </div>
            <ErpOverviewPanel filters={filters} />
            <section className="mt-8 space-y-5">
                <h2 className="text-xl text-primary">Chi tiết theo nghiệp vụ</h2>
                <div className="flex flex-wrap gap-2" role="tablist" aria-label="Loại báo cáo">
                    {(Object.keys(labels) as ReportName[]).map((name) => (
                        <button
                            key={name}
                            type="button"
                            role="tab"
                            aria-selected={tab === name}
                            onClick={() => setTab(name)}
                            className={`rounded-lg border px-3 py-2 text-sm ${tab === name ? "bg-primary text-white" : "bg-background hover:bg-muted"}`}
                        >
                            {labels[name]}
                        </button>
                    ))}
                </div>
                <div className="space-y-4">
                    {detail.isPending ? (
                        <div
                            className="card-surface h-40 animate-pulse bg-muted/45"
                            aria-label="Đang tải chi tiết"
                        />
                    ) : detail.isError ? (
                        <ErrorState
                            message="Không thể tải báo cáo chi tiết."
                            retry={() => detail.refetch()}
                        />
                    ) : (
                        <Detail name={tab} data={detail.data} />
                    )}
                </div>
            </section>
        </AdminGuard>
    );
}
