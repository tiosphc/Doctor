import { useEffect, useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Eye, RotateCcw, Search } from "lucide-react";
import { AdminGuard, AdminTitle } from "@/pages/admin/AdminPages";
import { EmptyState, ErrorState, Pagination } from "@/components/common/AsyncState";
import { Button } from "@/components/common/Button";
import { Badge } from "@/components/ui/badge";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { adminApi, type AuditLog, type AuditLogParams } from "@/services/adminApi";

const roleLabels: Record<string, string> = {
    admin: "Admin",
    receptionist: "Lễ tân",
    doctor: "Bác sĩ",
    customer: "Khách hàng",
    guest: "Khách",
};

const moduleLabels: Record<string, string> = {
    STAFF: "Nhân viên",
    DOCTOR: "Bác sĩ",
    APPOINTMENT: "Lịch hẹn",
    SERVICE: "Dịch vụ",
    VOUCHER: "Voucher",
    REVIEW: "Đánh giá",
    BLOG: "Blog",
};

const actionLabels: Record<string, string> = {
    CREATE: "Tạo mới",
    UPDATE: "Cập nhật",
    DELETE: "Xóa",
    ACTIVATE: "Kích hoạt",
    DEACTIVATE: "Vô hiệu hóa",
    CONFIRM: "Xác nhận",
    CHECK_IN: "Check-in",
    START_EXAMINATION: "Bắt đầu khám",
    FINISH_EXAMINATION: "Kết thúc khám",
    COMPLETE: "Hoàn thành",
    CANCEL: "Hủy",
    NO_SHOW: "Không đến",
    RESCHEDULE: "Chuyển lịch",
};

const fieldLabels: Record<string, string> = {
    name: "Họ và tên",
    email: "Email",
    phone: "Số điện thoại",
    role: "Vai trò",
    status: "Trạng thái",
    price: "Giá",
    duration: "Thời lượng",
    appointment_date: "Ngày hẹn",
    start_time: "Giờ bắt đầu",
    end_time: "Giờ kết thúc",
    doctor_id: "Bác sĩ",
    service_id: "Dịch vụ",
    booking_code: "Mã lịch hẹn",
    category_id: "Danh mục",
    published_at: "Thời gian xuất bản",
};

const statusLabels: Record<string, string> = {
    pending: "Chờ xác nhận",
    confirmed: "Đã xác nhận",
    checked_in: "Đã check-in",
    in_progress: "Đang khám",
    treatment_done: "Đã khám xong",
    completed: "Hoàn thành",
    cancelled: "Đã hủy",
    no_show: "Không đến",
    active: "Đang hoạt động",
    inactive: "Ngừng hoạt động",
    published: "Đang hiển thị",
    hidden: "Đã ẩn",
    revoked: "Đã thu hồi",
};

const modules = Object.keys(moduleLabels);
const actions = Object.keys(actionLabels);

export function AdminAuditLogPage() {
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [debouncedSearch, setDebouncedSearch] = useState("");
    const [role, setRole] = useState("");
    const [module, setModule] = useState("");
    const [action, setAction] = useState("");
    const [from, setFrom] = useState("");
    const [to, setTo] = useState("");
    const [selected, setSelected] = useState<AuditLog | null>(null);

    useEffect(() => {
        const timeout = window.setTimeout(() => setDebouncedSearch(search.trim()), 350);
        return () => window.clearTimeout(timeout);
    }, [search]);

    useEffect(() => setPage(1), [debouncedSearch, role, module, action, from, to]);

    const params = useMemo<AuditLogParams>(
        () => ({
            page,
            per_page: 25,
            search: debouncedSearch || undefined,
            role: role || undefined,
            module: module || undefined,
            action: action || undefined,
            from: from || undefined,
            to: to || undefined,
        }),
        [page, debouncedSearch, role, module, action, from, to],
    );
    const query = useQuery({
        queryKey: ["admin-audit-logs", params],
        queryFn: () => adminApi.auditLogs(params),
    });
    const hasFilters = Boolean(search || role || module || action || from || to);

    const resetFilters = () => {
        setSearch("");
        setDebouncedSearch("");
        setRole("");
        setModule("");
        setAction("");
        setFrom("");
        setTo("");
        setPage(1);
    };

    return (
        <AdminGuard>
            <AdminTitle
                title="Nhật ký hoạt động"
                description="Theo dõi các thao tác quan trọng được thực hiện trong hệ thống."
            />

            <section className="card-surface mt-7 p-4 sm:p-5">
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(240px,1.5fr)_repeat(3,minmax(145px,1fr))]">
                    <label className="relative sm:col-span-2 xl:col-span-1">
                        <span className="sr-only">Tìm kiếm nhật ký</span>
                        <Search
                            size={17}
                            className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"
                        />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Tìm người thao tác, đối tượng hoặc mã lịch..."
                            className="h-11 w-full rounded-md border bg-background pl-10 pr-3 text-sm outline-none focus:ring-2 focus:ring-secondary/40"
                        />
                    </label>
                    <FilterSelect
                        value={role}
                        onChange={setRole}
                        label="Tất cả vai trò"
                        options={roleLabels}
                    />
                    <FilterSelect
                        value={module}
                        onChange={setModule}
                        label="Tất cả module"
                        options={moduleLabels}
                    />
                    <FilterSelect
                        value={action}
                        onChange={setAction}
                        label="Tất cả hành động"
                        options={actionLabels}
                    />
                </div>
                <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_auto]">
                    <DateFilter label="Từ ngày" value={from} onChange={setFrom} />
                    <DateFilter
                        label="Đến ngày"
                        value={to}
                        onChange={setTo}
                        min={from || undefined}
                    />
                    <Button variant="outline" onClick={resetFilters} disabled={!hasFilters}>
                        <RotateCcw size={16} /> Đặt lại bộ lọc
                    </Button>
                </div>
            </section>

            <div className="mt-6">
                {query.isPending ? (
                    <AuditLogSkeleton />
                ) : query.isError ? (
                    <ErrorState
                        message="Không thể tải nhật ký hoạt động. Vui lòng thử lại."
                        retry={() => query.refetch()}
                    />
                ) : query.data.data.length === 0 ? (
                    <EmptyState
                        message={
                            hasFilters
                                ? "Không tìm thấy hoạt động phù hợp với bộ lọc."
                                : "Chưa có hoạt động nào phù hợp."
                        }
                    />
                ) : (
                    <>
                        <AuditLogTable logs={query.data.data} onView={setSelected} />
                        <AuditLogCards logs={query.data.data} onView={setSelected} />
                        <Pagination
                            current={query.data.meta.current_page}
                            last={query.data.meta.last_page}
                            onPage={setPage}
                        />
                    </>
                )}
            </div>

            <AuditLogDetail log={selected} onOpenChange={(open) => !open && setSelected(null)} />
        </AdminGuard>
    );
}

function FilterSelect({
    value,
    onChange,
    label,
    options,
}: {
    value: string;
    onChange: (value: string) => void;
    label: string;
    options: Record<string, string>;
}) {
    return (
        <select
            value={value}
            onChange={(event) => onChange(event.target.value)}
            className="h-11 rounded-md border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-secondary/40"
        >
            <option value="">{label}</option>
            {Object.entries(options).map(([optionValue, optionLabel]) => (
                <option key={optionValue} value={optionValue}>
                    {optionLabel}
                </option>
            ))}
        </select>
    );
}

function DateFilter({
    label,
    value,
    onChange,
    min,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    min?: string | undefined;
}) {
    return (
        <label className="grid gap-1 text-xs font-semibold text-muted-foreground">
            {label}
            <input
                type="date"
                value={value}
                min={min}
                onChange={(event) => onChange(event.target.value)}
                className="h-11 rounded-md border bg-background px-3 text-sm font-normal text-foreground outline-none focus:ring-2 focus:ring-secondary/40"
            />
        </label>
    );
}

function AuditLogTable({ logs, onView }: { logs: AuditLog[]; onView: (log: AuditLog) => void }) {
    return (
        <div className="card-surface hidden overflow-hidden md:block">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[900px] text-left text-sm">
                    <thead className="bg-muted/50 text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            {[
                                "Thời gian",
                                "Người thao tác",
                                "Vai trò",
                                "Hành động",
                                "Module",
                                "Đối tượng",
                                "Chi tiết",
                            ].map((title) => (
                                <th key={title} className="px-4 py-3">
                                    {title}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {logs.map((log) => (
                            <tr key={log.id} className="hover:bg-muted/20">
                                <td className="whitespace-nowrap px-4 py-4 text-muted-foreground">
                                    {formatDateTime(log.created_at)}
                                </td>
                                <td className="px-4 py-4 font-semibold text-primary">
                                    {log.actor_name}
                                </td>
                                <td className="px-4 py-4">
                                    <RoleBadge role={log.actor_role} />
                                </td>
                                <td className="px-4 py-4">
                                    <ActionBadge action={log.action} />
                                </td>
                                <td className="px-4 py-4">
                                    {moduleLabels[log.module] ?? log.module}
                                </td>
                                <td className="max-w-44 truncate px-4 py-4 font-medium">
                                    {log.target_name ?? `${log.target_type} #${log.target_id}`}
                                </td>
                                <td className="px-4 py-4">
                                    <ViewButton onClick={() => onView(log)} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function AuditLogCards({ logs, onView }: { logs: AuditLog[]; onView: (log: AuditLog) => void }) {
    return (
        <div className="grid gap-3 md:hidden">
            {logs.map((log) => (
                <article key={log.id} className="card-surface p-4">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="font-semibold text-primary">{log.actor_name}</p>
                            <div className="mt-2 flex flex-wrap gap-2">
                                <RoleBadge role={log.actor_role} />
                                <ActionBadge action={log.action} />
                            </div>
                        </div>
                        <span className="text-xs text-muted-foreground">
                            {formatDateTime(log.created_at)}
                        </span>
                    </div>
                    <p className="mt-4 text-sm">
                        <span className="text-muted-foreground">
                            {moduleLabels[log.module] ?? log.module}:
                        </span>{" "}
                        <strong>{log.target_name ?? `${log.target_type} #${log.target_id}`}</strong>
                    </p>
                    <ViewButton onClick={() => onView(log)} className="mt-4 w-full" />
                </article>
            ))}
        </div>
    );
}

function ViewButton({ onClick, className = "" }: { onClick: () => void; className?: string }) {
    return (
        <Button variant="outline" className={className} onClick={onClick}>
            <Eye size={15} /> Xem
        </Button>
    );
}

function RoleBadge({ role }: { role: string }) {
    return (
        <Badge variant="outline" className="border-secondary/35 bg-secondary/10 text-primary">
            {roleLabels[role] ?? role}
        </Badge>
    );
}

function ActionBadge({ action }: { action: string }) {
    const destructive = action === "DELETE" || action === "CANCEL" || action === "DEACTIVATE";
    return (
        <Badge variant={destructive ? "destructive" : "secondary"}>
            {actionLabels[action] ?? action}
        </Badge>
    );
}

function AuditLogDetail({
    log,
    onOpenChange,
}: {
    log: AuditLog | null;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={log !== null} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                {log && (
                    <>
                        <DialogHeader>
                            <p className="label-luxury">Nhật ký hoạt động</p>
                            <DialogTitle>
                                {log.target_name ?? `${log.target_type} #${log.target_id}`}
                            </DialogTitle>
                            <DialogDescription>{log.description}</DialogDescription>
                        </DialogHeader>
                        <dl className="grid gap-4 rounded-lg bg-muted/30 p-4 text-sm sm:grid-cols-2">
                            <Detail label="Người thao tác" value={log.actor_name} />
                            <Detail
                                label="Vai trò"
                                value={roleLabels[log.actor_role] ?? log.actor_role}
                            />
                            <Detail
                                label="Hành động"
                                value={actionLabels[log.action] ?? log.action}
                            />
                            <Detail label="Module" value={moduleLabels[log.module] ?? log.module} />
                            <Detail
                                label="Thời gian"
                                value={formatDateTime(log.created_at, true)}
                            />
                            <Detail label="IP" value={log.ip_address ?? "Không có"} />
                            <div className="sm:col-span-2">
                                <Detail
                                    label="Thiết bị / User Agent"
                                    value={log.user_agent ?? "Không có"}
                                />
                            </div>
                        </dl>
                        <Changes oldValues={log.old_values} newValues={log.new_values} />
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {label}
            </dt>
            <dd className="mt-1 break-words font-medium text-foreground">{value}</dd>
        </div>
    );
}

function Changes({
    oldValues,
    newValues,
}: {
    oldValues: Record<string, unknown> | null;
    newValues: Record<string, unknown> | null;
}) {
    const keys = Array.from(
        new Set([...Object.keys(oldValues ?? {}), ...Object.keys(newValues ?? {})]),
    );
    if (keys.length === 0) return null;
    return (
        <section>
            <h3 className="text-sm font-bold uppercase tracking-wide text-primary">Thay đổi</h3>
            <div className="mt-3 grid gap-3">
                {keys.map((key) => (
                    <div key={key} className="rounded-lg border p-3">
                        <p className="text-sm font-semibold text-primary">
                            {fieldLabels[key] ?? cleanKey(key)}
                        </p>
                        <div className="mt-2 grid gap-2 text-sm sm:grid-cols-[1fr_auto_1fr]">
                            <ValueBox label="Trước" value={formatValue(key, oldValues?.[key])} />
                            <span className="hidden self-end pb-2 text-secondary sm:block">→</span>
                            <ValueBox label="Sau" value={formatValue(key, newValues?.[key])} />
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

function ValueBox({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-md bg-muted/40 p-3">
            <span className="text-xs text-muted-foreground">{label}</span>
            <p className="mt-1 break-words font-medium">{value}</p>
        </div>
    );
}

function formatValue(key: string, value: unknown): string {
    if (value === null || value === undefined || value === "") return "—";
    if (key === "price" && !Number.isNaN(Number(value)))
        return `${new Intl.NumberFormat("vi-VN").format(Number(value))}đ`;
    if (key === "status") return statusLabels[String(value)] ?? String(value);
    if (key === "role") return roleLabels[String(value)] ?? String(value);
    if (typeof value === "boolean") return value ? "Có" : "Không";
    if (typeof value === "object")
        return Object.values(value as Record<string, unknown>)
            .map(String)
            .join(", ");
    return String(value);
}

function cleanKey(key: string): string {
    return key.replaceAll("_", " ").replace(/^./, (letter) => letter.toUpperCase());
}

function formatDateTime(value: string, seconds = false): string {
    return new Intl.DateTimeFormat("vi-VN", {
        dateStyle: "short",
        timeStyle: seconds ? "medium" : "short",
    }).format(new Date(value));
}

function AuditLogSkeleton() {
    return (
        <div className="card-surface animate-pulse space-y-3 p-4">
            {Array.from({ length: 8 }, (_, index) => (
                <div key={index} className="h-14 rounded-md bg-muted/60" />
            ))}
        </div>
    );
}
