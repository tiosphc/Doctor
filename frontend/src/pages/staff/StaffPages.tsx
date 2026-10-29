import { useState, type ReactNode } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, Navigate, useNavigate, useParams } from "@tanstack/react-router";
import {
    ArrowRight,
    CalendarDays,
    Check,
    CheckCircle2,
    Clock3,
    LogIn,
    Search,
    UserRound,
    Users,
} from "lucide-react";
import { Button, ButtonLink } from "@/components/common/Button";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { Field, Input } from "@/components/common/Fields";
import { AppointmentProgress, StatusBadge } from "@/components/common/Status";
import { formatDate, money } from "@/components/cards/Cards";
import { useAuth } from "@/contexts/AuthContext";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { doctorPortalApi, receptionistApi, type StaffAppointmentParams } from "@/services/staffApi";
import type {
    Appointment,
    CanonicalCustomer,
    DoctorSchedule,
    PaginatedResponse,
    StaffDashboard,
    User,
} from "@/types";
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

type StaffRole = "receptionist" | "doctor";

export function StaffGuard({ role, children }: { role: StaffRole; children: ReactNode }) {
    const { user, isLoading } = useAuth();
    if (isLoading) return <LoadingState label="Đang kiểm tra quyền truy cập..." />;
    if (!user) return <Navigate to="/login" />;
    if (user.role === role) return children;
    return (
        <Navigate
            to={
                user.role === "admin"
                    ? "/admin"
                    : user.role === "customer"
                      ? "/account"
                      : user.role === "doctor"
                        ? "/doctor"
                        : "/receptionist"
            }
        />
    );
}

export function ReceptionistDashboardPage() {
    return (
        <StaffGuard role="receptionist">
            <StaffDashboardView role="receptionist" />
        </StaffGuard>
    );
}

export function DoctorDashboardPage() {
    return (
        <StaffGuard role="doctor">
            <StaffDashboardView role="doctor" />
        </StaffGuard>
    );
}

function StaffDashboardView({ role }: { role: StaffRole }) {
    const { user } = useAuth();
    const query = useQuery({
        queryKey: [role, "dashboard"],
        queryFn: () =>
            role === "doctor" ? doctorPortalApi.dashboard() : receptionistApi.dashboard(),
        refetchInterval: 15_000,
    });
    const appointments = useQuery({
        queryKey: [role, "appointments", { date: "today" }],
        queryFn: () =>
            role === "doctor"
                ? doctorPortalApi.appointments({ date: today() })
                : receptionistApi.appointments({ date: today() }),
        refetchInterval: 15_000,
    });
    const dashboard = query.data;
    return (
        <>
            <section className="border-b border-border pb-6">
                <p className="label-luxury">{role === "doctor" ? "Bác sĩ" : "Lễ tân"}</p>
                <h1 className="mt-2 text-3xl font-semibold text-primary md:text-4xl">
                    Xin chào, {user?.name}
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Theo dõi lịch khám và các hoạt động trong ngày tại Junie.
                </p>
            </section>
            {query.isPending ? (
                <LoadingState />
            ) : query.isError ? (
                <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
            ) : (
                <DashboardCards dashboard={dashboard} role={role} />
            )}
            <section className="mt-8 card-surface p-5 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b pb-5">
                    <div>
                        <p className="label-luxury">Hôm nay</p>
                        <h2 className="mt-1 text-2xl text-primary">Hàng đợi lịch khám</h2>
                    </div>
                    <ButtonLink
                        to={role === "doctor" ? "/doctor/today" : "/receptionist/today"}
                        variant="outline"
                    >
                        Xem toàn bộ <ArrowRight size={15} />
                    </ButtonLink>
                </div>
                <div className="pt-5">
                    {appointments.isPending ? (
                        <LoadingState />
                    ) : appointments.isError ? (
                        <ErrorState
                            message={errorMessage(appointments.error)}
                            retry={() => appointments.refetch()}
                        />
                    ) : appointments.data.data.length === 0 ? (
                        <EmptyState message="Hôm nay chưa có lịch hẹn." />
                    ) : (
                        <div className="grid gap-3">
                            {appointments.data.data.slice(0, 5).map((appointment) => (
                                <StaffAppointmentRow
                                    key={appointment.id}
                                    appointment={appointment}
                                    role={role}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </section>
        </>
    );
}

function DashboardCards({
    dashboard,
    role,
}: {
    dashboard: StaffDashboard | undefined;
    role: StaffRole;
}) {
    const cards =
        role === "doctor"
            ? ([
                  ["Lịch hôm nay", dashboard?.today ?? 0, CalendarDays],
                  ["Đã check-in", dashboard?.checked_in ?? 0, LogIn],
                  ["Đang thực hiện", dashboard?.in_progress ?? 0, CheckCircle2],
                  ["Hoàn thành", dashboard?.completed ?? 0, CheckCircle2],
              ] as const)
            : ([
                  ["Lịch hôm nay", dashboard?.today ?? 0, CalendarDays],
                  ["Chờ xác nhận", dashboard?.pending ?? 0, Clock3],
                  ["Đã check-in", dashboard?.checked_in ?? 0, LogIn],
                  ["Đang thực hiện", dashboard?.in_progress ?? 0, CheckCircle2],
                  ["Chờ hoàn tất", dashboard?.treatment_done ?? 0, CheckCircle2],
              ] as const);

    return (
        <div
            className={`mt-7 grid gap-4 sm:grid-cols-2 ${role === "doctor" ? "xl:grid-cols-4" : "xl:grid-cols-5"}`}
        >
            {cards.map(([label, value, Icon]) => (
                <div key={label} className="card-surface p-5">
                    <Icon size={20} className="text-secondary" />
                    <strong className="mt-5 block text-3xl text-primary">{value}</strong>
                    <span className="text-sm text-muted-foreground">{label}</span>
                </div>
            ))}
        </div>
    );
}

export function ReceptionistTodayPage() {
    return <StaffAppointmentsPage role="receptionist" todayOnly />;
}

export function ReceptionistAppointmentsPage() {
    return <StaffAppointmentsPage role="receptionist" />;
}

export function DoctorTodayPage() {
    return <StaffAppointmentsPage role="doctor" todayOnly />;
}

export function DoctorAppointmentsPage() {
    return <StaffAppointmentsPage role="doctor" />;
}

function StaffAppointmentsPage({
    role,
    todayOnly = false,
}: {
    role: StaffRole;
    todayOnly?: boolean;
}) {
    const [page, setPage] = useState(1);
    const [status, setStatus] = useState("");
    const [search, setSearch] = useState("");
    const params: StaffAppointmentParams = {
        page,
        status: status || undefined,
        search: search || undefined,
        date: todayOnly ? today() : undefined,
    };
    const query = useQuery({
        queryKey: [role, "appointments", params],
        queryFn: () =>
            role === "doctor"
                ? doctorPortalApi.appointments(params)
                : receptionistApi.appointments(params),
        refetchInterval: 15_000,
    });
    return (
        <>
            <section>
                <p className="label-luxury">{todayOnly ? "Lịch hôm nay" : "Lịch hẹn"}</p>
                <h1 className="mt-2 text-3xl text-primary md:text-4xl">
                    {todayOnly
                        ? "Hàng đợi hôm nay"
                        : role === "doctor"
                          ? "Lịch hẹn của tôi"
                          : "Quản lý lịch hẹn"}
                </h1>
                <p className="mt-3 text-sm text-muted-foreground">
                    Cập nhật tự động mỗi 15 giây để đội ngũ luôn nắm được trạng thái mới nhất.
                </p>
            </section>
            <div className="card-surface mt-7 grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_200px]">
                <div className="relative">
                    <Search
                        className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"
                        size={16}
                    />
                    <Input
                        className="pl-9"
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setPage(1);
                        }}
                        placeholder="Tìm theo mã hoặc tên khách..."
                    />
                </div>
                <select
                    className="min-h-11 rounded-md border bg-card px-3 text-sm"
                    value={status}
                    onChange={(event) => {
                        setStatus(event.target.value);
                        setPage(1);
                    }}
                >
                    <option value="">Mọi trạng thái</option>
                    <option value="pending">Chờ xác nhận</option>
                    <option value="confirmed">Đã xác nhận</option>
                    <option value="checked_in">Đã check-in</option>
                    <option value="in_progress">Đang thực hiện</option>
                    <option value="treatment_done">Chờ hoàn tất</option>
                    <option value="completed">Hoàn thành</option>
                    <option value="cancelled">Đã hủy</option>
                    <option value="no_show">Không đến</option>
                </select>
            </div>
            <div className="mt-5">
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                ) : query.data.data.length === 0 ? (
                    <EmptyState message="Không tìm thấy lịch hẹn phù hợp." />
                ) : (
                    <div className="grid gap-3">
                        {query.data.data.map((appointment) => (
                            <StaffAppointmentRow
                                key={appointment.id}
                                appointment={appointment}
                                role={role}
                            />
                        ))}
                    </div>
                )}
            </div>
            {query.data && (
                <Pagination
                    current={query.data.meta.current_page}
                    last={query.data.meta.last_page}
                    onPage={setPage}
                />
            )}
        </>
    );
}

function StaffAppointmentRow({ appointment, role }: { appointment: Appointment; role: StaffRole }) {
    const customer =
        appointment.customer_type === "guest" ? appointment.guest : appointment.customer;
    return (
        <Link
            to={role === "doctor" ? "/doctor/appointments/$id" : "/receptionist/appointments/$id"}
            params={{ id: String(appointment.id) }}
            className="focus-premium grid gap-4 rounded-lg border bg-card p-4 transition-shadow hover:shadow-card sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
        >
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <span>#{appointment.id}</span>
                    <StatusBadge status={appointment.status} />
                </div>
                <h2 className="mt-2 truncate text-lg font-semibold text-primary">
                    {appointment.service.name}
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    {customer?.name || "Khách vãng lai"}
                </p>
            </div>
            <div className="grid gap-1 text-left text-sm text-muted-foreground sm:text-right">
                <span>{formatDate(appointment.appointment_date)}</span>
                <span className="font-semibold text-primary">
                    {appointment.start_time}–{appointment.end_time}
                </span>
            </div>
        </Link>
    );
}

export function StaffAppointmentDetailPage({ role }: { role: StaffRole }) {
    const params = useParams({ strict: false }) as { id?: string };
    const id = Number(params.id);
    const queryClient = useQueryClient();
    const query = useQuery({
        queryKey: [role, "appointment", id],
        queryFn: () =>
            role === "doctor" ? doctorPortalApi.appointment(id) : receptionistApi.appointment(id),
        enabled: Number.isFinite(id),
        refetchInterval: 15_000,
    });
    const mutation = useMutation({
        mutationFn: ({ action, appointmentId }: { action: string; appointmentId: number }) => {
            if (role === "doctor") {
                return action === "start"
                    ? doctorPortalApi.start(appointmentId)
                    : doctorPortalApi.completeTreatment(appointmentId);
            }
            if (action === "confirm") return receptionistApi.confirm(appointmentId);
            if (action === "check-in") return receptionistApi.checkIn(appointmentId);
            if (action === "complete") return receptionistApi.complete(appointmentId);
            if (action === "no-show") return receptionistApi.noShow(appointmentId);
            return receptionistApi.cancel(appointmentId);
        },
        onSuccess: async (updatedAppointment) => {
            queryClient.setQueryData([role, "appointment", id], updatedAppointment);
            await queryClient.invalidateQueries({ queryKey: [role] });
        },
    });
    const [error, setError] = useState("");
    const [pendingAction, setPendingAction] = useState<string | null>(null);
    const appointment = query.data?.data;
    async function perform(action: string): Promise<boolean> {
        if (!appointment) return false;
        setError("");
        try {
            await mutation.mutateAsync({ action, appointmentId: appointment.id });
            return true;
        } catch (reason) {
            setError(errorMessage(reason));
            return false;
        }
    }
    return (
        <StaffGuard role={role}>
            {query.isPending ? (
                <LoadingState />
            ) : query.isError ? (
                <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
            ) : !appointment ? (
                <EmptyState message="Không tìm thấy lịch hẹn." />
            ) : (
                <>
                    <Link
                        to={
                            role === "doctor"
                                ? "/doctor/appointments"
                                : "/receptionist/appointments"
                        }
                        className="text-sm font-semibold text-primary"
                    >
                        ← Quay lại danh sách
                    </Link>
                    <section className="card-surface mt-5 p-5 sm:p-7">
                        <div className="flex flex-wrap items-start justify-between gap-4 border-b pb-5">
                            <div>
                                <p className="text-xs text-muted-foreground">
                                    Lịch hẹn #{appointment.id}
                                </p>
                                <h1 className="mt-2 text-3xl text-primary">
                                    {appointment.service.name}
                                </h1>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {appointment.booking_code || ""}
                                </p>
                            </div>
                            <StatusBadge status={appointment.status} />
                        </div>
                        <AppointmentProgress status={appointment.status} />
                        <div className="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            <DetailItem
                                icon={<UserRound size={16} />}
                                label="Khách hàng"
                                value={
                                    appointment.customer_type === "guest"
                                        ? appointment.guest?.name || "Khách vãng lai"
                                        : appointment.customer?.name || "—"
                                }
                            />
                            <DetailItem
                                icon={<CalendarDays size={16} />}
                                label="Ngày khám"
                                value={formatDate(appointment.appointment_date)}
                            />
                            <DetailItem
                                icon={<Clock3 size={16} />}
                                label="Khung giờ"
                                value={`${appointment.start_time}–${appointment.end_time}`}
                            />
                            <DetailItem
                                icon={<Check size={16} />}
                                label="Bác sĩ"
                                value={appointment.doctor.name}
                            />
                        </div>
                        {appointment.pricing.original_price !== null && (
                            <div className="mt-5 grid gap-4 rounded-lg bg-muted/60 p-4 sm:grid-cols-3">
                                <DetailItem
                                    icon={<Check size={16} />}
                                    label="Giá gốc"
                                    value={money(appointment.pricing.original_price)}
                                />
                                <DetailItem
                                    icon={<Check size={16} />}
                                    label="Ưu đãi"
                                    value={`- ${money(appointment.pricing.discount_amount)}`}
                                />
                                <DetailItem
                                    icon={<Check size={16} />}
                                    label="Giá sau ưu đãi"
                                    value={money(
                                        appointment.pricing.final_price ??
                                            appointment.pricing.original_price,
                                    )}
                                />
                            </div>
                        )}
                        {appointment.note && (
                            <p className="mt-5 border-t pt-5 text-sm text-muted-foreground">
                                Ghi chú: {appointment.note}
                            </p>
                        )}
                        {error && (
                            <p className="mt-5 rounded-md bg-red-50 p-3 text-sm text-red-700">
                                {error}
                            </p>
                        )}
                        <StaffActions
                            appointment={appointment}
                            role={role}
                            busy={mutation.isPending}
                            onAction={setPendingAction}
                        />
                    </section>
                    <AlertDialog
                        open={pendingAction !== null}
                        onOpenChange={(open) =>
                            !open && !mutation.isPending && setPendingAction(null)
                        }
                    >
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    {staffActionCopy(pendingAction, role).title}
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    {staffActionCopy(pendingAction, role).description}
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            {appointment && (
                                <div className="grid gap-2 rounded-lg border bg-muted/40 p-4 text-sm">
                                    <p>
                                        <span className="text-muted-foreground">Khách hàng:</span>{" "}
                                        <strong>
                                            {appointment.customer?.name ??
                                                appointment.guest?.name ??
                                                "Khách vãng lai"}
                                        </strong>
                                    </p>
                                    <p>
                                        <span className="text-muted-foreground">Dịch vụ:</span>{" "}
                                        <strong>{appointment.service.name}</strong>
                                    </p>
                                    <p>
                                        <span className="text-muted-foreground">Thời gian:</span>{" "}
                                        <strong>
                                            {formatDate(appointment.appointment_date)} ·{" "}
                                            {appointment.start_time}–{appointment.end_time}
                                        </strong>
                                    </p>
                                </div>
                            )}
                            <AlertDialogFooter>
                                <AlertDialogCancel disabled={mutation.isPending}>
                                    Quay lại
                                </AlertDialogCancel>
                                <AlertDialogAction
                                    disabled={mutation.isPending}
                                    onClick={(event) => {
                                        event.preventDefault();
                                        if (!pendingAction) return;
                                        void perform(pendingAction).then((success) => {
                                            if (success) setPendingAction(null);
                                        });
                                    }}
                                >
                                    {mutation.isPending
                                        ? "Đang cập nhật..."
                                        : staffActionCopy(pendingAction, role).confirmLabel}
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                </>
            )}
        </StaffGuard>
    );
}

function StaffActions({
    appointment,
    role,
    busy,
    onAction,
}: {
    appointment: Appointment;
    role: StaffRole;
    busy: boolean;
    onAction: (action: string) => void;
}) {
    const actions: Array<[string, string]> =
        role === "doctor"
            ? appointment.status === "checked_in"
                ? [["start", "Bắt đầu khám"]]
                : appointment.status === "in_progress"
                  ? [["complete", "Hoàn thành khám"]]
                  : []
            : appointment.status === "pending"
              ? [
                    ["confirm", "Xác nhận"],
                    ["cancel", "Hủy"],
                ]
              : appointment.status === "confirmed"
                ? [
                      ["check-in", "Check-in"],
                      ["no-show", "Không đến"],
                      ["cancel", "Hủy"],
                  ]
                : appointment.status === "treatment_done"
                  ? [["complete", "Hoàn tất lịch hẹn"]]
                  : [];
    if (actions.length === 0) {
        if (appointment.status === "checked_in") {
            return (
                <p className="mt-7 border-t pt-5 text-sm text-muted-foreground">
                    Đã check-in / Chờ bác sĩ.
                </p>
            );
        }
        if (appointment.status === "in_progress") {
            return (
                <p className="mt-7 border-t pt-5 text-sm text-muted-foreground">
                    Bác sĩ đang khám.
                </p>
            );
        }
        if (appointment.status === "treatment_done" && role === "doctor") {
            return (
                <p className="mt-7 border-t pt-5 text-sm text-muted-foreground">
                    Đã hoàn thành khám. Đang chờ nhân viên hoàn tất lịch hẹn.
                </p>
            );
        }
        return null;
    }
    return (
        <div className="mt-7 flex flex-wrap justify-end gap-3 border-t pt-5">
            {actions.map(([action, label]) => (
                <Button
                    key={action}
                    variant={action === "cancel" ? "outline" : "primary"}
                    className={action === "cancel" ? "text-red-700" : ""}
                    disabled={busy}
                    onClick={() => onAction(action)}
                >
                    {label}
                </Button>
            ))}
        </div>
    );
}

function staffActionCopy(
    action: string | null,
    role: StaffRole,
): {
    title: string;
    description: string;
    confirmLabel: string;
} {
    return (
        {
            confirm: {
                title: "Xác nhận lịch hẹn?",
                description: "Bạn có chắc chắn muốn xác nhận lịch hẹn này không?",
                confirmLabel: "Xác nhận lịch",
            },
            "check-in": {
                title: "Xác nhận khách đã đến?",
                description:
                    "Sau khi check-in, lịch hẹn sẽ được chuyển sang bác sĩ phụ trách để tiếp nhận.",
                confirmLabel: "Xác nhận check-in",
            },
            start: {
                title: "Bắt đầu khám?",
                description: "Bạn có chắc muốn bắt đầu khám cho lịch hẹn này?",
                confirmLabel: "Bắt đầu khám",
            },
            complete:
                role === "doctor"
                    ? {
                          title: "Hoàn thành khám?",
                          description:
                              "Xác nhận đã hoàn thành phần khám của lịch hẹn này? Lịch hẹn vẫn cần nhân viên hoàn tất.",
                          confirmLabel: "Hoàn thành khám",
                      }
                    : {
                          title: "Hoàn tất lịch hẹn?",
                          description: "Bạn có chắc muốn hoàn tất lịch hẹn này?",
                          confirmLabel: "Hoàn tất lịch hẹn",
                      },
            cancel: {
                title: "Xác nhận hủy lịch hẹn?",
                description:
                    "Lịch hẹn sẽ chuyển sang trạng thái đã hủy và không thể tiếp tục check-in.",
                confirmLabel: "Xác nhận hủy",
            },
            "no-show": {
                title: "Xác nhận khách không đến?",
                description:
                    "Chỉ xác nhận khi khách đã quá thời gian chờ theo quy định của phòng khám.",
                confirmLabel: "Xác nhận không đến",
            },
        }[action ?? ""] ?? {
            title: "Cập nhật lịch hẹn?",
            description: "Bạn có chắc chắn muốn cập nhật trạng thái lịch hẹn này không?",
            confirmLabel: "Xác nhận",
        }
    );
}

function DetailItem({ icon, label, value }: { icon: ReactNode; label: string; value: string }) {
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

export function ReceptionistCustomersPage() {
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const query = useQuery({
        queryKey: ["receptionist", "customers", page, search],
        queryFn: () => receptionistApi.customers({ page, search: search || undefined }),
    });
    return (
        <CustomerTable
            query={query}
            search={search}
            setSearch={(value) => {
                setSearch(value);
                setPage(1);
            }}
            onPage={setPage}
        />
    );
}

function CustomerTable({
    query,
    search,
    setSearch,
    onPage,
}: {
    query: {
        data?: PaginatedResponse<CanonicalCustomer> | undefined;
        isPending: boolean;
        isError: boolean;
        error: unknown;
        refetch: () => unknown;
    };
    search: string;
    setSearch: (value: string) => void;
    onPage: (page: number) => void;
}) {
    const data = query.data;
    return (
        <>
            <section>
                <p className="label-luxury">Lễ tân</p>
                <h1 className="mt-2 text-3xl text-primary md:text-4xl">Khách hàng</h1>
                <p className="mt-3 text-sm text-muted-foreground">
                    Tra cứu thông tin liên hệ phục vụ lịch hẹn.
                </p>
            </section>
            <div className="card-surface mt-7 p-4">
                <Input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Tìm theo tên, email hoặc số điện thoại..."
                />
            </div>
            {query.isPending ? (
                <LoadingState />
            ) : query.isError ? (
                <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
            ) : !data?.data.length ? (
                <EmptyState message="Không tìm thấy khách hàng." />
            ) : (
                <div className="mt-5 overflow-x-auto rounded-lg border bg-card">
                    <table className="w-full min-w-[620px] text-sm">
                        <thead>
                            <tr className="border-b text-left text-muted-foreground">
                                <th className="p-4">Họ tên</th>
                                <th className="p-4">Email</th>
                                <th className="p-4">Điện thoại</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.data.map((customer) => (
                                <tr key={customer.id} className="border-b last:border-0">
                                    <td className="p-4 font-semibold text-primary">
                                        {customer.name}
                                    </td>
                                    <td className="p-4">{customer.email}</td>
                                    <td className="p-4">{customer.phone || "—"}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {data && (
                <Pagination
                    current={data.meta.current_page}
                    last={data.meta.last_page}
                    onPage={onPage}
                />
            )}
        </>
    );
}

export function DoctorSchedulePage() {
    const query = useQuery({
        queryKey: ["doctor", "schedule"],
        queryFn: () => doctorPortalApi.schedule(),
        refetchInterval: 15_000,
    });
    return (
        <>
            <section>
                <p className="label-luxury">Bác sĩ</p>
                <h1 className="mt-2 text-3xl text-primary md:text-4xl">Lịch làm việc</h1>
                <p className="mt-3 text-sm text-muted-foreground">
                    Lịch làm việc được phân công trong hệ thống.
                </p>
            </section>
            {query.isPending ? (
                <LoadingState />
            ) : query.isError ? (
                <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
            ) : !query.data?.schedules.length && !query.data?.time_offs.length ? (
                <EmptyState message="Chưa có lịch làm việc." />
            ) : (
                <div className="mt-7 grid gap-7">
                    <div className="grid gap-3 sm:grid-cols-2">
                        {query.data.schedules.map((item: DoctorSchedule) => (
                            <div key={item.id} className="card-surface p-5">
                                <p className="label-luxury">{item.day_name}</p>
                                <p className="mt-3 text-xl font-semibold text-primary">
                                    {item.start_time}–{item.end_time}
                                </p>
                            </div>
                        ))}
                    </div>
                    {query.data.time_offs.length > 0 && (
                        <section>
                            <h2 className="text-2xl text-primary">Ngày nghỉ đã đăng ký</h2>
                            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                {query.data.time_offs.map((item) => (
                                    <div
                                        key={item.id}
                                        className="rounded-lg border bg-card p-4 text-sm"
                                    >
                                        <p className="font-semibold text-primary">
                                            {formatDate(item.date)}
                                        </p>
                                        <p className="mt-1 text-muted-foreground">
                                            {item.full_day
                                                ? "Cả ngày"
                                                : `${item.start_time ?? "—"}–${item.end_time ?? "—"}`}
                                        </p>
                                        {item.reason && (
                                            <p className="mt-2 text-muted-foreground">
                                                {item.reason}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </section>
                    )}
                    {query.data.appointments.length > 0 && (
                        <section>
                            <h2 className="text-2xl text-primary">Lịch hẹn trong khoảng xem</h2>
                            <div className="mt-3 grid gap-3">
                                {query.data.appointments.map((appointment) => (
                                    <StaffAppointmentRow
                                        key={appointment.id}
                                        appointment={appointment}
                                        role="doctor"
                                    />
                                ))}
                            </div>
                        </section>
                    )}
                </div>
            )}
        </>
    );
}

function today(): string {
    return new Date().toISOString().slice(0, 10);
}
