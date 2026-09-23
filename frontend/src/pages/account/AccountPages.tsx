import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Navigate, useNavigate } from "@tanstack/react-router";
import {
    ArrowDown,
    ArrowRight,
    CalendarDays,
    CalendarClock,
    Check,
    CheckCheck,
    Clock3,
    LoaderCircle,
    UserRound,
} from "lucide-react";
import { Button, ButtonLink } from "@/components/common/Button";
import { AppointmentCard, formatDate, money } from "@/components/cards/Cards";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { Field, Input } from "@/components/common/Fields";
import { SuccessDialog } from "@/components/common/Feedback";
import { AppointmentProgress, StatusBadge } from "@/components/common/Status";
import { useAuth } from "@/contexts/AuthContext";
import { appointmentApi } from "@/services/appointmentApi";
import { ApiError, errorMessage, firstFieldErrors } from "@/services/api";
import { notificationActionErrorMessage } from "@/services/notificationApi";
import type { Appointment, AppointmentStatus } from "@/types";
import {
    useMarkAllNotificationsRead,
    useMarkNotificationRead,
    useNotificationUnreadCount,
    useNotifications,
    notificationQueryKeys,
    invalidateNotificationAppointment,
} from "@/hooks/useNotifications";
import { NotificationItem } from "@/components/notifications/NotificationItem";
import { CustomerReviewPanel } from "@/components/reviews/ReviewExperience";
import { LoyaltyCard } from "@/components/loyalty/LoyaltyCard";
import { loyaltyApi } from "@/services/loyaltyApi";
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";

export function DashboardPage() {
    const { user } = useAuth();
    const query = useQuery({
        queryKey: ["my-appointments", { upcoming: true, dashboard: true }],
        queryFn: () => appointmentApi.mine({ upcoming: true }),
    });
    const loyalty = useQuery({
        queryKey: ["my-loyalty"],
        queryFn: loyaltyApi.mine,
        refetchInterval: 15_000,
    });
    return (
        <CustomerGuard>
            <div className="grid gap-9">
                <section className="border-b border-border pb-6">
                    <div className="flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
                        <div>
                            <p className="label-luxury">Tổng quan</p>
                            <h1 className="mt-2 text-3xl font-semibold text-primary md:text-[2.4rem]">
                                Xin chào, {user?.name}
                            </h1>
                            <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
                                Theo dõi lịch hẹn và thông tin chăm sóc của bạn tại Junie.
                            </p>
                        </div>
                        <ButtonLink to="/booking" className="shrink-0 self-start sm:self-end">
                            Đặt lịch mới
                        </ButtonLink>
                    </div>
                </section>
                {loyalty.isPending ? (
                    <LoadingState />
                ) : loyalty.isError ? (
                    <ErrorState
                        message={errorMessage(loyalty.error)}
                        retry={() => loyalty.refetch()}
                    />
                ) : (
                    <LoyaltyCard summary={loyalty.data.data} showWalletLink />
                )}
                <section className="card-surface rounded-xl p-5 sm:p-6 md:p-7">
                    <div className="flex items-center justify-between gap-4 border-b pb-5">
                        <div className="flex items-center gap-3 text-primary">
                            <span className="grid size-9 place-items-center rounded-lg bg-muted">
                                <CalendarDays size={18} />
                            </span>
                            <h2 className="text-2xl">Lịch hẹn sắp tới</h2>
                        </div>
                        {!query.isPending && !query.isError && query.data?.data[0] && (
                            <span className="hidden text-xs text-muted-foreground sm:block">
                                Lịch hẹn gần nhất
                            </span>
                        )}
                    </div>
                    <div className="pt-6">
                        {query.isPending ? (
                            <LoadingState />
                        ) : query.isError ? (
                            <ErrorState
                                message={errorMessage(query.error)}
                                retry={() => query.refetch()}
                            />
                        ) : query.data.data[0] ? (
                            <UpcomingAppointment appointment={query.data.data[0]} />
                        ) : (
                            <div className="rounded-lg border border-dashed p-8 text-center">
                                <CalendarClock className="mx-auto text-secondary" size={28} />
                                <p className="mt-3 text-sm text-muted-foreground">
                                    Bạn chưa có lịch hẹn sắp tới.
                                </p>
                                <ButtonLink to="/booking" variant="outline" className="mt-5">
                                    Đặt lịch đầu tiên
                                </ButtonLink>
                            </div>
                        )}
                    </div>
                </section>
                <section className="overflow-hidden rounded-xl bg-navy-deep px-6 py-7 text-primary-foreground shadow-card sm:px-8 md:flex md:items-center md:justify-between md:gap-10 md:px-10 md:py-9">
                    <div className="max-w-2xl">
                        <p className="label-luxury text-secondary">Chăm sóc da chuẩn khoa học</p>
                        <h2 className="mt-3 text-3xl leading-tight md:text-4xl">
                            Bắt đầu hành trình chăm sóc làn da của bạn
                        </h2>
                        <p className="mt-3 max-w-xl text-sm leading-6 text-primary-foreground/75">
                            Khám phá dịch vụ phù hợp hoặc đặt lịch để được đội ngũ Junie tư vấn.
                        </p>
                    </div>
                    <div className="mt-6 flex shrink-0 flex-wrap gap-3 md:mt-0">
                        <ButtonLink
                            to="/booking"
                            className="border-secondary bg-secondary text-secondary-foreground hover:bg-secondary/90"
                        >
                            Đặt lịch ngay
                        </ButtonLink>
                        <ButtonLink
                            to="/services"
                            variant="outline"
                            className="border-primary-foreground/40 text-primary-foreground hover:bg-primary-foreground/10"
                        >
                            Xem dịch vụ
                        </ButtonLink>
                    </div>
                </section>
            </div>
        </CustomerGuard>
    );
}

function UpcomingAppointment({ appointment }: { appointment: Appointment }) {
    return (
        <article className="rounded-lg border border-border bg-background p-5 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[.08em] text-muted-foreground">
                        <span>{appointment.booking_code || `Lịch hẹn #${appointment.id}`}</span>
                        <StatusBadge status={appointment.status} />
                    </div>
                    <h3 className="mt-4 text-2xl leading-tight text-primary md:text-[1.65rem]">
                        {appointment.service.name}
                    </h3>
                    {appointment.service.description && (
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            {appointment.service.description}
                        </p>
                    )}
                </div>
            </div>
            <div className="mt-6 grid gap-5 border-t border-dashed pt-5 sm:grid-cols-3">
                <AppointmentMeta
                    icon={<UserRound size={17} />}
                    label="Bác sĩ phụ trách"
                    value={appointment.doctor.name}
                />
                <AppointmentMeta
                    icon={<CalendarDays size={17} />}
                    label="Ngày khám"
                    value={formatDate(appointment.appointment_date)}
                />
                <AppointmentMeta
                    icon={<Clock3 size={17} />}
                    label="Khung giờ"
                    value={`${appointment.start_time}–${appointment.end_time}`}
                />
            </div>
            <div className="mt-6 flex flex-wrap justify-end gap-2 border-t pt-5">
                <div className="flex flex-wrap gap-2">
                    <ButtonLink
                        to={`/account/appointments/${appointment.id}`}
                        variant="outline"
                        className="min-h-10 px-4 text-xs"
                    >
                        Xem chi tiết <ArrowRight size={14} />
                    </ButtonLink>
                    <ButtonLink to="/booking" className="min-h-10 px-4 text-xs">
                        Đặt lịch tiếp theo
                    </ButtonLink>
                </div>
            </div>
        </article>
    );
}

function AppointmentMeta({
    icon,
    label,
    value,
}: {
    icon: ReactNode;
    label: string;
    value: string;
}) {
    return (
        <div className="flex items-start gap-3">
            <span className="grid size-8 shrink-0 place-items-center rounded-full bg-muted text-primary">
                {icon}
            </span>
            <div className="min-w-0">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="mt-1 truncate text-sm font-semibold text-primary">{value}</p>
            </div>
        </div>
    );
}

export function AppointmentsPage() {
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const [filter, setFilter] = useState<"upcoming" | AppointmentStatus | "all">("upcoming");
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState<Appointment | null>(null);
    const params =
        filter === "upcoming"
            ? { upcoming: true, page }
            : filter === "all"
              ? { page }
              : { status: filter, page };
    const query = useQuery({
        queryKey: ["my-appointments", params],
        queryFn: () => appointmentApi.mine(params),
    });
    const cancel = useMutation({
        mutationFn: (id: number) => appointmentApi.cancelMine(id),
        onSuccess: async () => {
            setSelected(null);
            await queryClient.invalidateQueries({ queryKey: ["my-appointments"] });
        },
    });
    const tabs: Array<[typeof filter, string]> = [
        ["no_show", "Không đến"],
        ["upcoming", "Sắp tới"],
        ["all", "Tất cả"],
        ["pending", "Chờ xác nhận"],
        ["confirmed", "Đã xác nhận"],
        ["completed", "Hoàn thành"],
        ["cancelled", "Đã hủy"],
    ];
    return (
        <CustomerGuard>
            <div>
                <PageTitle title="Lịch hẹn của tôi" />
                <div className="scrollbar-none mt-6 flex gap-2 overflow-x-auto">
                    {tabs.map(([value, label]) => (
                        <button
                            key={value}
                            onClick={() => {
                                setFilter(value);
                                setPage(1);
                            }}
                            className={`whitespace-nowrap rounded-full px-4 py-2 text-sm ${filter === value ? "bg-primary text-primary-foreground" : "border bg-card"}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
                {cancel.isError && (
                    <p className="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                        {errorMessage(cancel.error)}
                    </p>
                )}
                <div className="mt-6 grid gap-4">
                    {query.isPending ? (
                        <LoadingState />
                    ) : query.isError ? (
                        <ErrorState
                            message={errorMessage(query.error)}
                            retry={() => query.refetch()}
                        />
                    ) : query.data.data.length === 0 ? (
                        <EmptyState message="Không có lịch hẹn phù hợp bộ lọc." />
                    ) : (
                        query.data.data.map((appointment) => (
                            <AppointmentCard
                                key={appointment.id}
                                item={appointment}
                                onView={() =>
                                    navigate({
                                        to: "/account/appointments/$id",
                                        params: { id: String(appointment.id) },
                                    })
                                }
                                onCancel={() => setSelected(appointment)}
                            />
                        ))
                    )}
                </div>
                {query.data && (
                    <Pagination
                        current={query.data.meta.current_page}
                        last={query.data.meta.last_page}
                        onPage={setPage}
                    />
                )}
                <CancelDialog
                    appointment={selected}
                    busy={cancel.isPending}
                    close={() => setSelected(null)}
                    confirm={() => selected && cancel.mutate(selected.id)}
                />
            </div>
        </CustomerGuard>
    );
}

export function AppointmentDetailPage({ id }: { id: string }) {
    const queryClient = useQueryClient();
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [rescheduleOpen, setRescheduleOpen] = useState(false);
    const [rescheduleMessage, setRescheduleMessage] = useState("");
    const query = useQuery({
        queryKey: ["my-appointment", id],
        queryFn: () => appointmentApi.mineDetail(id),
        retry: false,
    });
    const cancel = useMutation({
        mutationFn: () => appointmentApi.cancelMine(Number(id)),
        onSuccess: async () => {
            setConfirmOpen(false);
            await query.refetch();
            await queryClient.invalidateQueries({ queryKey: ["my-appointments"] });
        },
    });
    return (
        <CustomerGuard>
            <div>
                <PageTitle title="Chi tiết lịch hẹn" />
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <div className="mt-6">
                        <ErrorState
                            message={errorMessage(query.error)}
                            retry={() => query.refetch()}
                        />
                    </div>
                ) : (
                    <>
                        <AppointmentDetail
                            appointment={query.data.data}
                            onReschedule={
                                query.data.data.can_reschedule
                                    ? () => {
                                          setRescheduleMessage("");
                                          setRescheduleOpen(true);
                                      }
                                    : undefined
                            }
                            onCancel={
                                query.data.data.can_cancel ? () => setConfirmOpen(true) : undefined
                            }
                        />
                        <CustomerReviewPanel
                            appointment={query.data.data}
                            onChanged={async () => {
                                await query.refetch();
                                await queryClient.invalidateQueries({
                                    queryKey: ["my-appointments"],
                                });
                            }}
                        />
                    </>
                )}{" "}
                <SuccessDialog
                    message={rescheduleMessage}
                    onClose={() => setRescheduleMessage("")}
                    title="Đổi lịch thành công"
                />
                {cancel.isError && (
                    <p className="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                        {errorMessage(cancel.error)}
                    </p>
                )}
                <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Xác nhận hủy lịch?</AlertDialogTitle>
                            <AlertDialogDescription>
                                Backend sẽ kiểm tra trạng thái và thời gian lịch trước khi chấp
                                nhận.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Giữ lịch</AlertDialogCancel>
                            <AlertDialogAction
                                disabled={cancel.isPending}
                                onClick={(event) => {
                                    event.preventDefault();
                                    cancel.mutate();
                                }}
                                className="bg-red-700 text-white"
                            >
                                Hủy lịch
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
                {query.data && (
                    <RescheduleDialog
                        appointment={query.data.data}
                        open={rescheduleOpen}
                        setOpen={setRescheduleOpen}
                        onSuccess={async () => {
                            setRescheduleMessage("Đổi lịch thành công");
                            await Promise.all([
                                query.refetch(),
                                queryClient.invalidateQueries({ queryKey: ["my-appointments"] }),
                                queryClient.invalidateQueries({
                                    queryKey: notificationQueryKeys.all,
                                }),
                            ]);
                        }}
                    />
                )}
            </div>
        </CustomerGuard>
    );
}

export function NotificationsPage() {
    const { user } = useAuth();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [page, setPage] = useState(1);
    const [actionError, setActionError] = useState<string | null>(null);
    const enabled = user !== null;
    const userId = user?.id ?? null;
    const query = useNotifications(userId, { page, per_page: 10 }, enabled);
    const unreadCount = useNotificationUnreadCount(userId);
    const markRead = useMarkNotificationRead(userId);
    const markAllRead = useMarkAllNotificationsRead(userId);

    async function openNotification(
        id: string,
        appointmentId: number | null,
        actionUrl: string | null,
    ) {
        setActionError(null);
        try {
            await markRead.mutateAsync(id);
        } catch (error) {
            setActionError(notificationActionErrorMessage(error));
            return;
        }

        if (user && appointmentId !== null) {
            void invalidateNotificationAppointment(queryClient, user.role, appointmentId).catch(
                () => undefined,
            );
        }
        if (actionUrl?.includes("/account/vouchers")) {
            void navigate({ to: "/account/vouchers" });
        } else if (user?.role === "admin" && appointmentId !== null) {
            void navigate({
                to: "/admin/appointments/$id",
                params: { id: String(appointmentId) },
            });
        } else if (user?.role === "doctor" && appointmentId !== null) {
            void navigate({
                to: "/doctor/appointments/$id",
                params: { id: String(appointmentId) },
            });
        } else if (user?.role === "receptionist" && appointmentId !== null) {
            void navigate({
                to: "/receptionist/appointments/$id",
                params: { id: String(appointmentId) },
            });
        } else if (appointmentId !== null) {
            void navigate({
                to: "/account/appointments/$id",
                params: { id: String(appointmentId) },
            });
        } else if (actionUrl) {
            window.location.assign(actionUrl);
        }
    }

    async function markAll() {
        setActionError(null);
        try {
            await markAllRead.mutateAsync();
        } catch (error) {
            setActionError(notificationActionErrorMessage(error));
        }
    }

    return (
        <div>
            <div className="flex flex-col justify-between gap-4 border-b border-border pb-6 sm:flex-row sm:items-end">
                <div>
                    <PageTitle title="Thông báo" />
                    <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
                        {user?.role === "customer"
                            ? "Theo dõi các cập nhật mới nhất về lịch hẹn và hành trình chăm sóc của bạn."
                            : "Theo dõi các cập nhật vận hành và mở nhanh lịch hẹn cần xử lý."}
                    </p>
                </div>
                <button
                    type="button"
                    onClick={markAll}
                    disabled={markAllRead.isPending || (unreadCount.data ?? 0) === 0}
                    className="focus-premium inline-flex min-h-10 items-center justify-center gap-2 self-start rounded-full border border-secondary px-4 text-xs font-semibold text-primary transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50 sm:self-end"
                >
                    <CheckCheck size={15} />
                    {markAllRead.isPending ? "Đang cập nhật..." : "Đánh dấu tất cả đã đọc"}
                </button>
            </div>
            {actionError && (
                <p role="alert" className="mt-5 rounded-md bg-red-50 p-3 text-sm text-red-700">
                    {actionError}
                </p>
            )}
            <div className="mt-7 grid gap-3">
                {query.isPending ? (
                    <LoadingState label="Đang tải thông báo..." />
                ) : query.isError ? (
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                ) : query.data.data.length === 0 ? (
                    <EmptyState message="Bạn chưa có thông báo nào." />
                ) : (
                    query.data.data.map((notification) => (
                        <NotificationItem
                            key={notification.id}
                            notification={notification}
                            onSelect={() =>
                                void openNotification(
                                    notification.id,
                                    notification.appointment_id,
                                    notification.action_url,
                                )
                            }
                        />
                    ))
                )}
            </div>
            {query.data && (
                <Pagination
                    current={query.data.meta.current_page}
                    last={query.data.meta.last_page}
                    onPage={setPage}
                />
            )}
        </div>
    );
}

export function HistoryPage() {
    return (
        <CustomerGuard>
            <ComingSoon
                title="Lịch sử dịch vụ"
                message="Backend chưa có API lịch sử dịch vụ riêng. Các lịch đã hoàn thành vẫn xem được trong Lịch hẹn của tôi."
            />
        </CustomerGuard>
    );
}
export function LoyaltyPage() {
    const loyalty = useQuery({
        queryKey: ["my-loyalty"],
        queryFn: loyaltyApi.mine,
        refetchInterval: 15_000,
    });

    return (
        <CustomerGuard>
            <div className="grid gap-7">
                <section className="border-b border-border pb-6">
                    <p className="label-luxury">Khách hàng thân thiết</p>
                    <h1 className="mt-2 text-3xl font-semibold text-primary md:text-[2.4rem]">
                        Quyền lợi thành viên
                    </h1>
                    <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
                        Theo dõi số lần điều trị đã hoàn tất và các mốc quà tặng của bạn tại Junie.
                    </p>
                </section>
                {loyalty.isPending ? (
                    <LoadingState label="Đang tải tiến trình thành viên..." />
                ) : loyalty.isError ? (
                    <ErrorState
                        message={errorMessage(loyalty.error)}
                        retry={() => loyalty.refetch()}
                    />
                ) : (
                    <LoyaltyCard summary={loyalty.data.data} showWalletLink />
                )}
            </div>
        </CustomerGuard>
    );
}
export function ProfilePage() {
    const { user, updateProfile } = useAuth();
    const [name, setName] = useState("");
    const [phone, setPhone] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!user) return;
        setName(user.name);
        setPhone(user.phone ?? "");
    }, [user]);

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError("");
        setErrors({});
        setSuccess("");

        const normalizedName = name.trim();
        const normalizedPhone = phone.trim();
        if (!normalizedName) {
            setErrors({ name: "Vui lòng nhập họ và tên." });
            return;
        }

        setSaving(true);
        try {
            await updateProfile({ name: normalizedName, phone: normalizedPhone });
            setSuccess("Thông tin cá nhân đã được cập nhật.");
        } catch (reason) {
            setError(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        } finally {
            setSaving(false);
        }
    }

    return (
        <CustomerGuard>
            <div>
                <PageTitle title="Thông tin cá nhân" />
                <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
                    Cập nhật thông tin liên hệ để Junie hỗ trợ bạn tốt hơn.
                </p>
                <section className="card-surface mt-7 p-5 sm:p-7">
                    <form onSubmit={submit} className="grid gap-5" noValidate>
                        <div className="grid gap-5 md:grid-cols-2">
                            <Field label="Họ và tên" error={errors["name"]}>
                                <Input
                                    name="name"
                                    value={name}
                                    onChange={(event) => setName(event.target.value)}
                                    autoComplete="name"
                                    maxLength={255}
                                    aria-invalid={Boolean(errors["name"])}
                                    required
                                />
                            </Field>
                            <Field label="Số điện thoại" error={errors["phone"]}>
                                <Input
                                    name="phone"
                                    value={phone}
                                    onChange={(event) => setPhone(event.target.value)}
                                    inputMode="tel"
                                    autoComplete="tel"
                                    maxLength={30}
                                    aria-invalid={Boolean(errors["phone"])}
                                />
                            </Field>
                        </div>
                        <Field label="Email">
                            <Input
                                name="email"
                                type="email"
                                value={user?.email ?? ""}
                                disabled
                                readOnly
                                aria-describedby="profile-email-note"
                                className="cursor-not-allowed bg-muted text-muted-foreground opacity-80"
                            />
                            <span
                                id="profile-email-note"
                                className="text-xs font-normal text-muted-foreground"
                            >
                                Email được dùng để đăng nhập và không thể thay đổi.
                            </span>
                        </Field>
                        {error && (
                            <p
                                role="alert"
                                className="rounded-md bg-red-50 p-3 text-sm text-red-700"
                            >
                                {error}
                            </p>
                        )}
                        <SuccessDialog
                            message={success}
                            onClose={() => setSuccess("")}
                            title="Cập nhật thành công"
                        />
                        <div className="flex justify-end border-t pt-5">
                            <Button type="submit" disabled={saving}>
                                {saving ? "Đang lưu..." : "Lưu thay đổi"}
                            </Button>
                        </div>
                    </form>
                </section>
            </div>
        </CustomerGuard>
    );
}

export function CustomerGuard({ children }: { children: ReactNode }) {
    const { user, isLoading } = useAuth();
    if (isLoading) return <LoadingState label="Đang kiểm tra tài khoản..." />;
    if (!user) return <Navigate to="/login" />;
    if (user.role !== "customer") {
        return (
            <Navigate
                to={
                    user.role === "admin"
                        ? "/admin"
                        : user.role === "receptionist"
                          ? "/receptionist"
                          : "/doctor"
                }
            />
        );
    }
    return children;
}

function AppointmentDetail({
    appointment,
    onReschedule,
    onCancel,
}: {
    appointment: Appointment;
    onReschedule?: (() => void) | undefined;
    onCancel?: (() => void) | undefined;
}) {
    return (
        <section className="card-surface mt-7 p-6">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p className="font-mono text-sm text-muted-foreground">
                        {appointment.booking_code || `Lịch hẹn #${appointment.id}`}
                    </p>
                    <h2 className="mt-2 text-2xl text-primary">{appointment.service.name}</h2>
                </div>
                <StatusBadge status={appointment.status} />
            </div>
            <AppointmentProgress status={appointment.status} />
            <dl className="mt-6 grid gap-4 border-t pt-6 sm:grid-cols-2">
                <Detail label="Bác sĩ" value={appointment.doctor.name} />
                <Detail label="Ngày" value={formatDate(appointment.appointment_date)} />
                <Detail
                    label="Thời gian"
                    value={`${appointment.start_time}–${appointment.end_time}`}
                />
                <Detail label="Ghi chú" value={appointment.note || "Không có"} />
            </dl>
            {appointment.has_exhausted_reschedules && (
                <p className="mt-5 rounded-xl border border-secondary/30 bg-secondary/10 px-4 py-3 text-sm leading-6 text-secondary-foreground">
                    Bạn đã sử dụng hết 2 lần đổi lịch cho lịch hẹn này. Nếu cần hỗ trợ thêm, vui
                    lòng liên hệ phòng khám.
                </p>
            )}
            {appointment.pricing.original_price !== null && (
                <div className="mt-5 grid gap-3 rounded-lg bg-muted/60 p-4 text-sm sm:grid-cols-3">
                    <Detail label="Giá gốc" value={money(appointment.pricing.original_price)} />
                    <Detail
                        label="Ưu đãi"
                        value={`- ${money(appointment.pricing.discount_amount)}`}
                    />
                    <Detail
                        label="Giá sau ưu đãi"
                        value={money(
                            appointment.pricing.final_price ?? appointment.pricing.original_price,
                        )}
                    />
                    {appointment.voucher && (
                        <p className="sm:col-span-3 text-xs text-muted-foreground">
                            Voucher đã áp dụng:{" "}
                            <strong className="font-mono text-primary">
                                {appointment.voucher.code}
                            </strong>
                        </p>
                    )}
                </div>
            )}
            {(onReschedule || onCancel) && (
                <div className="mt-6 flex flex-col gap-3 border-t pt-5 sm:flex-row">
                    {onReschedule && (
                        <Button onClick={onReschedule}>
                            <CalendarClock size={16} />
                            Đổi lịch
                        </Button>
                    )}
                    {onCancel && (
                        <Button variant="outline" className="text-red-700" onClick={onCancel}>
                            Hủy lịch hẹn
                        </Button>
                    )}
                </div>
            )}
        </section>
    );
}

function RescheduleDialog({
    appointment,
    open,
    setOpen,
    onSuccess,
}: {
    appointment: Appointment;
    open: boolean;
    setOpen: (open: boolean) => void;
    onSuccess: () => Promise<void>;
}) {
    const [date, setDate] = useState("");
    const [time, setTime] = useState("");
    const [step, setStep] = useState<"select" | "confirm">("select");
    const [message, setMessage] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const slotsQuery = useQuery({
        queryKey: ["appointment-reschedule-slots", appointment.id, date],
        queryFn: () => appointmentApi.rescheduleSlots(appointment.id, date),
        enabled: open && Boolean(date),
        retry: false,
    });
    const mutation = useMutation({
        mutationFn: () =>
            appointmentApi.rescheduleMine(appointment.id, {
                appointment_date: date,
                start_time: time,
            }),
        onSuccess: async () => {
            await onSuccess();
            setOpen(false);
        },
        onError: (reason) => {
            setErrors(firstFieldErrors(reason));
            if (reason instanceof ApiError && reason.status === 409) {
                setStep("select");
                setTime("");
                setMessage(
                    "Khung giờ này vừa được người khác đặt. Vui lòng chọn một khung giờ khác.",
                );
                void slotsQuery.refetch();
                return;
            }

            setMessage(errorMessage(reason));
        },
    });

    useEffect(() => {
        if (!open) return;
        setDate("");
        setTime("");
        setStep("select");
        setMessage("");
        setErrors({});
        mutation.reset();
        // Reset only when a fresh dialog session starts.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, appointment.id]);

    const duration = slotsQuery.data?.data.duration ?? appointment.service.duration;
    const newEndTime = time ? addMinutesToTime(time, duration) : "";
    const reschedulesRemaining = appointment.reschedules_remaining;

    function reviewChange() {
        const nextErrors: Record<string, string> = {};
        if (!date) nextErrors["appointment_date"] = "Vui lòng chọn ngày khám mới.";
        if (!time) nextErrors["start_time"] = "Vui lòng chọn khung giờ mới.";
        setErrors(nextErrors);
        setMessage("");
        if (Object.keys(nextErrors).length === 0) setStep("confirm");
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(nextOpen) => {
                if (!mutation.isPending) setOpen(nextOpen);
            }}
        >
            <DialogContent className="max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-2xl overflow-y-auto rounded-2xl border-secondary/30 p-0 shadow-2xl">
                <div className="border-b bg-primary px-5 py-6 text-primary-foreground sm:px-7">
                    <DialogHeader>
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-secondary">
                            Điều chỉnh lịch hẹn
                        </p>
                        <DialogTitle className="pt-2 font-serif text-2xl sm:text-3xl">
                            {step === "select" ? "Chọn lịch mới" : "Xác nhận đổi lịch hẹn?"}
                        </DialogTitle>
                        <DialogDescription className="pt-1 leading-6 text-primary-foreground/75">
                            {step === "select"
                                ? "Bác sĩ và dịch vụ được giữ nguyên. Hệ thống sẽ kiểm tra lại lịch trống khi bạn xác nhận."
                                : "Mỗi lịch hẹn chỉ có thể đổi tối đa 2 lần. Hãy kiểm tra lại thời gian trước khi xác nhận nhé."}
                        </DialogDescription>
                    </DialogHeader>
                </div>

                <div className="grid gap-6 p-5 sm:p-7">
                    <section className="rounded-xl border border-secondary/30 bg-muted/40 p-4 sm:p-5">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-secondary-foreground">
                            Lịch hiện tại
                        </p>
                        <div className="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                            <div>
                                <p className="font-serif text-xl text-primary">
                                    {appointment.service.name}
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {appointment.doctor.name}
                                </p>
                            </div>
                            <div className="text-sm font-semibold text-primary sm:text-right">
                                <p>{formatDate(appointment.appointment_date)}</p>
                                <p className="mt-1">
                                    {appointment.start_time}–{appointment.end_time}
                                </p>
                            </div>
                        </div>
                    </section>

                    {step === "select" ? (
                        <section className="grid gap-5" aria-labelledby="reschedule-new-time">
                            <div>
                                <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-secondary-foreground">
                                    Chọn lịch mới
                                </p>
                                <h3
                                    id="reschedule-new-time"
                                    className="mt-2 font-serif text-2xl text-primary"
                                >
                                    Ngày và giờ khám phù hợp
                                </h3>
                            </div>
                            <Field label="Ngày khám mới" error={errors["appointment_date"]}>
                                <span className="relative flex min-h-12 items-center rounded-md border border-input bg-card px-4 text-sm transition focus-within:border-primary focus-within:ring-1 focus-within:ring-primary">
                                    <span
                                        className={
                                            date ? "text-foreground" : "text-muted-foreground"
                                        }
                                    >
                                        {date ? formatDate(date) : "Chọn ngày phù hợp"}
                                    </span>
                                    <CalendarDays
                                        className="ml-auto text-muted-foreground"
                                        size={18}
                                        aria-hidden="true"
                                    />
                                    <input
                                        type="date"
                                        min={localToday()}
                                        value={date}
                                        aria-label="Ngày khám mới"
                                        className="absolute inset-0 size-full cursor-pointer opacity-0"
                                        onClick={(event) => event.currentTarget.showPicker()}
                                        onChange={(event) => {
                                            setDate(event.target.value);
                                            setTime("");
                                            setMessage("");
                                            setErrors({});
                                        }}
                                    />
                                </span>
                            </Field>

                            {date && slotsQuery.isPending && (
                                <LoadingState label="Đang kiểm tra lịch trống..." />
                            )}
                            {date && slotsQuery.isError && (
                                <ErrorState
                                    message={errorMessage(slotsQuery.error)}
                                    retry={() => slotsQuery.refetch()}
                                />
                            )}
                            {date && slotsQuery.data && slotsQuery.data.data.slots.length === 0 && (
                                <p className="rounded-lg border border-dashed p-4 text-sm leading-6 text-muted-foreground">
                                    Ngày này chưa có khung giờ phù hợp. Vui lòng chọn ngày khác.
                                </p>
                            )}
                            {date && slotsQuery.data && slotsQuery.data.data.slots.length > 0 && (
                                <div>
                                    <p className="text-sm font-medium text-primary">
                                        Khung giờ còn trống
                                    </p>
                                    <div className="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5">
                                        {slotsQuery.data.data.slots.map((slot) => {
                                            const isCurrent =
                                                date === appointment.appointment_date &&
                                                slot === appointment.start_time;
                                            const selected = time === slot;

                                            return (
                                                <button
                                                    key={slot}
                                                    type="button"
                                                    disabled={isCurrent}
                                                    onClick={() => {
                                                        setTime(slot);
                                                        setMessage("");
                                                        setErrors((current) => ({
                                                            ...current,
                                                            start_time: "",
                                                        }));
                                                    }}
                                                    className={`focus-premium min-h-11 rounded-lg border px-2 text-sm font-semibold transition ${
                                                        selected
                                                            ? "border-primary bg-primary text-primary-foreground"
                                                            : isCurrent
                                                              ? "cursor-not-allowed border-secondary/30 bg-muted text-muted-foreground"
                                                              : "border-input bg-card text-primary hover:border-secondary hover:bg-muted"
                                                    }`}
                                                >
                                                    {slot}
                                                    {selected && (
                                                        <Check className="ml-1 inline" size={14} />
                                                    )}
                                                    {isCurrent && (
                                                        <span className="block text-[9px] font-normal uppercase">
                                                            Hiện tại
                                                        </span>
                                                    )}
                                                </button>
                                            );
                                        })}
                                    </div>
                                    {errors["start_time"] && (
                                        <p role="alert" className="mt-3 text-sm text-red-700">
                                            {errors["start_time"]}
                                        </p>
                                    )}
                                </div>
                            )}
                        </section>
                    ) : (
                        <section className="grid gap-4" aria-labelledby="reschedule-confirmation">
                            <h3 id="reschedule-confirmation" className="sr-only">
                                So sánh lịch hiện tại và lịch mới
                            </h3>
                            <ScheduleComparison
                                label="Lịch hiện tại"
                                date={appointment.appointment_date}
                                startTime={appointment.start_time}
                                endTime={appointment.end_time}
                            />
                            <ArrowDown className="mx-auto text-secondary" aria-hidden="true" />
                            <ScheduleComparison
                                label="Lịch mới"
                                date={date}
                                startTime={time}
                                endTime={newEndTime}
                                highlighted
                            />
                            <p className="rounded-xl border border-secondary/30 bg-secondary/10 px-4 py-3 text-sm font-medium text-secondary-foreground">
                                Bạn còn {reschedulesRemaining} lần đổi lịch
                            </p>
                        </section>
                    )}

                    {message && (
                        <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">
                            {message}
                        </p>
                    )}
                </div>

                <DialogFooter className="sticky bottom-0 gap-3 border-t bg-background p-5 sm:justify-between sm:space-x-0 sm:px-7">
                    {step === "confirm" ? (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={mutation.isPending}
                            onClick={() => {
                                setStep("select");
                                setMessage("");
                            }}
                        >
                            Quay lại
                        </Button>
                    ) : (
                        <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                            Đóng
                        </Button>
                    )}
                    {step === "select" ? (
                        <Button type="button" onClick={reviewChange} disabled={!date || !time}>
                            Tiếp tục
                            <ArrowRight size={16} />
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            onClick={() => mutation.mutate()}
                            disabled={mutation.isPending}
                        >
                            {mutation.isPending && (
                                <LoaderCircle className="animate-spin" size={16} />
                            )}
                            {mutation.isPending ? "Đang đổi lịch..." : "Xác nhận đổi lịch"}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ScheduleComparison({
    label,
    date,
    startTime,
    endTime,
    highlighted = false,
}: {
    label: string;
    date: string;
    startTime: string;
    endTime: string;
    highlighted?: boolean;
}) {
    return (
        <div
            className={`rounded-xl border p-4 sm:p-5 ${
                highlighted ? "border-secondary bg-secondary/10" : "border-border bg-card"
            }`}
        >
            <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">
                {label}
            </p>
            <div className="mt-3 flex flex-col gap-1 text-primary sm:flex-row sm:items-center sm:justify-between">
                <p className="font-serif text-xl">{formatDate(date)}</p>
                <p className="text-sm font-semibold">
                    {startTime}–{endTime}
                </p>
            </div>
        </div>
    );
}

function addMinutesToTime(time: string, minutesToAdd: number): string {
    const [hours = 0, minutes = 0] = time.split(":").map(Number);
    const totalMinutes = hours * 60 + minutes + minutesToAdd;

    return `${String(Math.floor(totalMinutes / 60)).padStart(2, "0")}:${String(totalMinutes % 60).padStart(2, "0")}`;
}

function localToday(): string {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, "0");
    const day = String(today.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs uppercase tracking-wider text-muted-foreground">{label}</dt>
            <dd className="mt-1 font-medium text-primary">{value}</dd>
        </div>
    );
}
function ComingSoon({ title, message }: { title: string; message: string }) {
    return (
        <div>
            <PageTitle title={title} />
            <div className="card-surface mt-7 p-8 text-center">
                <h2 className="text-2xl text-primary">Sắp ra mắt</h2>
                <p className="mx-auto mt-3 max-w-xl text-sm leading-6 text-muted-foreground">
                    {message}
                </p>
                <ButtonLink to="/account/appointments" className="mt-6">
                    Xem lịch hẹn
                </ButtonLink>
            </div>
        </div>
    );
}
function PageTitle({ title }: { title: string }) {
    return (
        <>
            <p className="label-luxury">Tài khoản</p>
            <h1 className="mt-2 text-3xl text-primary md:text-4xl">{title}</h1>
        </>
    );
}
function CancelDialog({
    appointment,
    busy,
    close,
    confirm,
}: {
    appointment: Appointment | null;
    busy: boolean;
    close: () => void;
    confirm: () => void;
}) {
    return (
        <AlertDialog open={Boolean(appointment)} onOpenChange={(open) => !open && close()}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Xác nhận hủy lịch?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Lịch {appointment?.service.name} sẽ được chuyển sang trạng thái đã hủy.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Giữ lịch</AlertDialogCancel>
                    <AlertDialogAction
                        disabled={busy}
                        onClick={(event) => {
                            event.preventDefault();
                            confirm();
                        }}
                        className="bg-red-700 text-white"
                    >
                        {busy ? "Đang hủy..." : "Xác nhận hủy"}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
