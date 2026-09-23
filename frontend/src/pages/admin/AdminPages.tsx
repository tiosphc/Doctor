import { useState, type FormEvent, type ReactNode } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, Navigate, useNavigate, useSearch } from "@tanstack/react-router";
import { UserPlus } from "lucide-react";
import { Button } from "@/components/common/Button";
import { OperationNotice, SuccessDialog } from "@/components/common/Feedback";
import { Field, Input, Textarea } from "@/components/common/Fields";
import { AppointmentProgress, Badge, StatusBadge } from "@/components/common/Status";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { formatDate, money } from "@/components/cards/Cards";
import { LoyaltyCard } from "@/components/loyalty/LoyaltyCard";
import { useAuth } from "@/contexts/AuthContext";
import {
    adminApi,
    type AdminAppointmentParams,
    type AdminAppointmentQuickFilter,
    type AdminAppointmentSort,
    type ScheduleInput,
    type TimeOffInput,
} from "@/services/adminApi";
import { errorMessage, firstFieldErrors } from "@/services/api";
import type {
    Appointment,
    Doctor,
    DoctorSchedule,
    DoctorTimeOff,
    Service,
    ServiceCategory,
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";

export function AdminDoctorsPage() {
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const routeSearch = useSearch({ from: "/admin/doctors/" });
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState<"" | "active" | "inactive">("");
    const [page, setPage] = useState(1);
    const [notice, setNotice] = useState("");
    const [createNotice, setCreateNotice] = useState("");
    const [createOpen, setCreateOpen] = useState(false);
    const [successPopup, setSuccessPopup] = useState(routeSearch.notice ?? "");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const query = useQuery({
        queryKey: ["admin-doctors", { search, status, page }],
        queryFn: () =>
            adminApi.doctors({
                search: search || undefined,
                status: status || undefined,
                page,
            }),
    });
    const create = useMutation({
        mutationFn: adminApi.createDoctor,
        onSuccess: async (response) => {
            setCreateOpen(false);
            await queryClient.invalidateQueries({ queryKey: ["admin-doctors"] });
            await navigate({
                to: "/admin/doctors/$id",
                params: { id: String(response.data.id) },
            });
        },
    });
    const resend = useMutation({
        mutationFn: adminApi.resendDoctorInvitation,
        onSuccess: async (response) => {
            setNotice(response.message);
            await queryClient.invalidateQueries({ queryKey: ["admin-doctors"] });
        },
        onError: (reason) => setNotice(errorMessage(reason)),
    });

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setCreateNotice("");
        setErrors({});
        const formElement = event.currentTarget;
        const form = new FormData(formElement);
        try {
            await create.mutateAsync(form);
            formElement.reset();
        } catch (reason) {
            setCreateNotice(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        }
    }

    function changeCreateOpen(open: boolean) {
        if (create.isPending) return;

        setCreateOpen(open);
        if (!open) {
            setCreateNotice("");
            setErrors({});
        }
    }

    function closeSuccessPopup() {
        setSuccessPopup("");
        void navigate({ to: "/admin/doctors", search: {}, replace: true });
    }

    return (
        <AdminGuard>
            <AdminTitle
                title="Danh sách bác sĩ"
                description="Tìm kiếm và quản lý hồ sơ, trạng thái tài khoản và mức độ sẵn sàng nhận lịch của từng bác sĩ."
                action={
                    <Button
                        type="button"
                        onClick={() => {
                            setCreateNotice("");
                            setErrors({});
                            setCreateOpen(true);
                        }}
                        className="w-full shrink-0 sm:w-auto"
                    >
                        <UserPlus className="size-4" />
                        Thêm bác sĩ
                    </Button>
                }
            />
            <Dialog open={createOpen} onOpenChange={changeCreateOpen}>
                <DialogContent className="max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-3xl gap-0 overflow-y-auto rounded-2xl p-0 shadow-2xl">
                    <div className="border-b bg-muted/35 px-5 py-5 sm:px-7">
                        <DialogHeader className="pr-8">
                            <div className="mb-2 grid size-11 place-items-center rounded-full bg-primary text-primary-foreground">
                                <UserPlus className="size-5" />
                            </div>
                            <DialogTitle className="font-serif text-2xl text-primary sm:text-3xl">
                                Thêm bác sĩ mới
                            </DialogTitle>
                            <DialogDescription className="max-w-2xl leading-6">
                                Tạo hồ sơ chuyên môn và tài khoản đăng nhập cho bác sĩ.
                            </DialogDescription>
                        </DialogHeader>
                    </div>
                    <form onSubmit={submit} encType="multipart/form-data">
                        <div className="grid items-start gap-x-5 gap-y-4 px-5 py-6 sm:px-7 md:grid-cols-2">
                            <Field label="Tên bác sĩ *" error={errors["name"]}>
                                <Input name="name" autoFocus required />
                            </Field>
                            <Field label="Chuyên môn *" error={errors["specialty"]}>
                                <Input name="specialty" required />
                            </Field>
                            <Field label="Email đăng nhập *" error={errors["email"]}>
                                <Input name="email" type="email" autoComplete="email" required />
                            </Field>
                            <Field label="Số điện thoại" error={errors["phone"]}>
                                <Input name="phone" type="tel" autoComplete="tel" />
                            </Field>
                            <Field label="Ảnh bác sĩ" error={errors["avatar"]}>
                                <Input
                                    name="avatar"
                                    type="file"
                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                    aria-invalid={Boolean(errors["avatar"]) || undefined}
                                    className="cursor-pointer px-3 py-2 file:mr-3 file:rounded-full file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-primary"
                                />
                            </Field>
                            <Field label="Giới thiệu" error={errors["bio"]}>
                                <Textarea name="bio" className="min-h-28 resize-y" />
                            </Field>
                            <div className="rounded-xl border border-blue-100 bg-blue-50/70 p-4 text-sm leading-6 text-blue-950 md:col-span-2">
                                Hệ thống sẽ gửi email để bác sĩ tự thiết lập mật khẩu. Quản trị viên
                                không cần và không được nhập mật khẩu thay bác sĩ.
                            </div>
                            {createNotice && (
                                <OperationNotice
                                    message={createNotice}
                                    success={false}
                                    onClose={() => setCreateNotice("")}
                                    className="md:col-span-2"
                                />
                            )}
                        </div>
                        <DialogFooter className="gap-3 border-t bg-muted/20 px-5 py-4 sm:px-7 sm:space-x-0">
                            <Button
                                type="button"
                                variant="ghost"
                                disabled={create.isPending}
                                onClick={() => changeCreateOpen(false)}
                            >
                                Hủy
                            </Button>
                            <Button type="submit" disabled={create.isPending}>
                                <UserPlus className="size-4" />
                                {create.isPending ? "Đang tạo..." : "Thêm bác sĩ"}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <Notice value={notice} onClose={() => setNotice("")} />
            <SuccessDialog
                message={successPopup}
                onClose={closeSuccessPopup}
                title="Xóa bác sĩ thành công"
            />
            <div className="mt-7 rounded-xl border bg-card p-4 shadow-sm">
                <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_14rem]">
                    <Input
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setPage(1);
                        }}
                        placeholder="Tìm theo tên hoặc chuyên môn..."
                    />
                    <select
                        value={status}
                        onChange={(event) => {
                            setStatus(event.target.value as "" | "active" | "inactive");
                            setPage(1);
                        }}
                        className="min-h-12 rounded-md border bg-card px-4 text-sm outline-none transition focus:border-primary focus:ring-1 focus:ring-primary"
                        aria-label="Lọc trạng thái bác sĩ"
                    >
                        <option value="">Tất cả trạng thái</option>
                        <option value="active">Đang hoạt động</option>
                        <option value="inactive">Ngừng hoạt động</option>
                    </select>
                </div>
            </div>
            <div className="mt-5 hidden overflow-x-auto rounded-xl border bg-card shadow-sm md:block">
                <table className="w-full min-w-[700px] text-sm">
                    <thead className="bg-muted/45">
                        <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <th className="p-4">Bác sĩ</th>
                            <th className="p-4">Chuyên môn</th>
                            <th className="p-4">Trạng thái</th>
                            <th className="p-4">Tài khoản</th>
                            <th className="p-4">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        {query.data?.data.map((doctor) => (
                            <tr
                                key={doctor.id}
                                className="border-b transition-colors last:border-0 hover:bg-muted/25"
                            >
                                <td className="p-4 font-medium text-primary">{doctor.name}</td>
                                <td className="p-4">{doctor.specialty}</td>
                                <td className="p-4">
                                    <div className="flex flex-wrap gap-2">
                                        <Badge
                                            tone={
                                                doctor.status === "active" ? "success" : "default"
                                            }
                                        >
                                            {doctor.status === "active"
                                                ? "Đang hoạt động"
                                                : "Tạm ngưng"}
                                        </Badge>
                                        {doctorConfigurationBadge(doctor)}
                                    </div>
                                </td>
                                <td className="p-4">{doctorAccountBadge(doctor.account_status)}</td>
                                <td className="p-4">
                                    <div className="flex flex-wrap gap-3">
                                        <Link
                                            to="/admin/doctors/$id"
                                            params={{ id: String(doctor.id) }}
                                            className="font-semibold text-primary"
                                        >
                                            Chi tiết
                                        </Link>
                                        {doctor.account_status === "pending_setup" && (
                                            <button
                                                type="button"
                                                disabled={resend.isPending}
                                                onClick={() => resend.mutate(doctor.id)}
                                                className="font-semibold text-amber-700 disabled:opacity-50"
                                            >
                                                Gửi lại lời mời
                                            </button>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {query.isPending && <LoadingState />}
                {query.isError && (
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                )}
                {query.data?.data.length === 0 && <EmptyState message="Không tìm thấy bác sĩ." />}
            </div>
            <div className="mt-5 grid gap-3 md:hidden">
                {query.data?.data.map((doctor) => (
                    <article
                        key={doctor.id}
                        className="grid gap-3 rounded-xl border bg-card p-4 shadow-sm"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h2 className="font-semibold text-primary">{doctor.name}</h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {doctor.specialty}
                                </p>
                            </div>
                            <div className="flex flex-wrap justify-end gap-2">
                                {doctorConfigurationBadge(doctor)}
                                {doctorAccountBadge(doctor.account_status)}
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-3 border-t pt-3 text-sm">
                            <Link
                                to="/admin/doctors/$id"
                                params={{ id: String(doctor.id) }}
                                className="font-semibold text-primary"
                            >
                                Chi tiết
                            </Link>
                            {doctor.account_status === "pending_setup" && (
                                <button
                                    type="button"
                                    disabled={resend.isPending}
                                    onClick={() => resend.mutate(doctor.id)}
                                    className="font-semibold text-amber-700 disabled:opacity-50"
                                >
                                    Gửi lại lời mời
                                </button>
                            )}
                        </div>
                    </article>
                ))}
                {query.isPending && <LoadingState />}
                {query.isError && (
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                )}
                {query.data?.data.length === 0 && <EmptyState message="Không tìm thấy bác sĩ." />}
            </div>
            {query.data && (
                <Pagination
                    current={query.data.meta.current_page}
                    last={query.data.meta.last_page}
                    onPage={setPage}
                />
            )}
        </AdminGuard>
    );
}

export function AdminDoctorDetailPage({ id }: { id: number }) {
    const client = useQueryClient();
    const [notice, setNotice] = useState("");
    const doctor = useQuery({
        queryKey: ["admin-doctor", id],
        queryFn: () => adminApi.doctor(id),
        retry: false,
    });
    const services = useQuery({
        queryKey: ["admin-services", { assignment: true }],
        queryFn: () => adminApi.services({ per_page: 100 }),
    });
    const schedules = useQuery({
        queryKey: ["doctor-schedules", id],
        queryFn: () => adminApi.schedules(id),
    });
    const timeOffs = useQuery({
        queryKey: ["doctor-time-offs", id],
        queryFn: () => adminApi.timeOffs(id),
    });
    const sync = useMutation({
        mutationFn: (serviceIds: number[]) => adminApi.syncDoctorServices(id, serviceIds),
        onSuccess: async () => {
            setNotice("Đã cập nhật dịch vụ phụ trách.");
            await client.invalidateQueries({ queryKey: ["admin-doctor", id] });
        },
    });
    const resendInvitation = useMutation({
        mutationFn: () => adminApi.resendDoctorInvitation(id),
        onSuccess: async (response) => {
            setNotice(response.message);
            await client.invalidateQueries({ queryKey: ["admin-doctor", id] });
        },
        onError: (reason) => setNotice(errorMessage(reason)),
    });
    const createSchedule = useMutation({
        mutationFn: (body: ScheduleInput) => adminApi.createSchedule(id, body),
        onSuccess: async () => {
            setNotice("Đã thêm ca làm việc.");
            await client.invalidateQueries({ queryKey: ["doctor-schedules", id] });
        },
    });
    const createTimeOff = useMutation({
        mutationFn: (body: TimeOffInput) => adminApi.createTimeOff(id, body),
        onSuccess: async () => {
            setNotice("Đã thêm thời gian nghỉ.");
            await client.invalidateQueries({ queryKey: ["doctor-time-offs", id] });
        },
    });
    const selectedIds = doctor.data?.data.services?.map((service) => service.id) ?? [];
    const scheduleCount = schedules.data?.data.length ?? 0;
    const isReadyForBooking =
        doctor.data?.data.status === "active" && selectedIds.length > 0 && scheduleCount > 0;

    async function saveServices(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setNotice("");
        const ids = new FormData(event.currentTarget).getAll("service_ids").map(Number);
        try {
            await sync.mutateAsync(ids);
        } catch (reason) {
            setNotice(errorMessage(reason));
        }
    }
    async function addSchedule(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setNotice("");
        const form = new FormData(event.currentTarget);
        try {
            await createSchedule.mutateAsync({
                day_of_week: Number(form.get("day_of_week")),
                start_time: String(form.get("start_time")),
                end_time: String(form.get("end_time")),
            });
            event.currentTarget.reset();
        } catch (reason) {
            setNotice(errorMessage(reason));
        }
    }
    async function addTimeOff(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setNotice("");
        const form = new FormData(event.currentTarget);
        const fullDay = form.get("full_day") === "on";
        try {
            await createTimeOff.mutateAsync({
                date: String(form.get("date")),
                start_time: fullDay ? null : String(form.get("start_time")),
                end_time: fullDay ? null : String(form.get("end_time")),
                reason: optional(form.get("reason")) ?? null,
            });
            event.currentTarget.reset();
        } catch (reason) {
            setNotice(errorMessage(reason));
        }
    }

    return (
        <AdminGuard>
            {doctor.isPending ? (
                <LoadingState />
            ) : doctor.isError ? (
                <ErrorState message={errorMessage(doctor.error)} retry={() => doctor.refetch()} />
            ) : (
                <>
                    <AdminTitle
                        title={doctor.data.data.name}
                        description={doctor.data.data.specialty}
                    />
                    <Notice value={notice} onClose={() => setNotice("")} />
                    <DoctorInformationEditor
                        doctor={doctor.data.data}
                        onSaved={async () => {
                            setNotice("Đã cập nhật thông tin bác sĩ.");
                            await Promise.all([
                                doctor.refetch(),
                                client.invalidateQueries({ queryKey: ["admin-doctors"] }),
                            ]);
                        }}
                    />
                    <section className="card-surface mt-7 p-5">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 className="text-xl text-primary">Trạng thái đặt lịch</h2>
                                <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                    Bác sĩ chỉ nên xuất hiện trong đặt lịch khi đang hoạt động, đã
                                    có dịch vụ phụ trách và có ít nhất một ca làm việc.
                                </p>
                            </div>
                            <Badge tone={isReadyForBooking ? "success" : "default"}>
                                {isReadyForBooking ? "Sẵn sàng đặt lịch" : "Chưa sẵn sàng"}
                            </Badge>
                        </div>
                        {!isReadyForBooking && (
                            <ul className="mt-4 grid gap-2 text-sm text-muted-foreground sm:grid-cols-3">
                                <li>
                                    Trạng thái:{" "}
                                    <strong className="text-foreground">
                                        {doctor.data.data.status === "active"
                                            ? "Đang hoạt động"
                                            : "Tạm ngưng"}
                                    </strong>
                                </li>
                                <li>
                                    Dịch vụ phụ trách:{" "}
                                    <strong className="text-foreground">
                                        {selectedIds.length}
                                    </strong>
                                </li>
                                <li>
                                    Ca làm việc:{" "}
                                    <strong className="text-foreground">{scheduleCount}</strong>
                                </li>
                            </ul>
                        )}
                    </section>
                    <section className="card-surface mt-7 flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="text-sm text-muted-foreground">Tài khoản đăng nhập</p>
                            <div className="mt-2">
                                {doctorAccountBadge(doctor.data.data.account_status)}
                            </div>
                            <p className="mt-2 text-sm text-muted-foreground">
                                {doctor.data.data.email ?? "Chưa có email đăng nhập"}
                            </p>
                        </div>
                        {doctor.data.data.account_status === "pending_setup" && (
                            <Button
                                variant="outline"
                                disabled={resendInvitation.isPending}
                                onClick={() => resendInvitation.mutate()}
                            >
                                {resendInvitation.isPending ? "Đang gửi..." : "Gửi lại email mời"}
                            </Button>
                        )}
                    </section>
                    <section className="card-surface mt-7 p-5">
                        <h2 className="text-xl text-primary">Dịch vụ phụ trách</h2>
                        {services.isPending ? (
                            <LoadingState />
                        ) : (
                            <form onSubmit={saveServices} className="mt-4">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {services.data?.data.map((service) => (
                                        <label
                                            key={service.id}
                                            className="flex items-center gap-3 rounded-md border p-3 text-sm"
                                        >
                                            <input
                                                name="service_ids"
                                                value={service.id}
                                                type="checkbox"
                                                defaultChecked={selectedIds.includes(service.id)}
                                            />
                                            {service.name}
                                        </label>
                                    ))}
                                </div>
                                <Button className="mt-4" disabled={sync.isPending} type="submit">
                                    Lưu phân công
                                </Button>
                            </form>
                        )}
                    </section>
                    <section className="card-surface mt-6 p-5">
                        <h2 className="text-xl text-primary">Lịch làm việc</h2>
                        <form onSubmit={addSchedule} className="mt-4 grid gap-3 sm:grid-cols-4">
                            <select
                                name="day_of_week"
                                className="min-h-12 rounded-md border bg-card px-3"
                                required
                            >
                                {dayOptions()}
                            </select>
                            <Input name="start_time" type="time" required />
                            <Input name="end_time" type="time" required />
                            <Button disabled={createSchedule.isPending} type="submit">
                                Thêm ca
                            </Button>
                        </form>
                        <div className="mt-5 grid gap-3">
                            {schedules.data?.data.map((schedule) => (
                                <ScheduleRow key={schedule.id} doctorId={id} schedule={schedule} />
                            ))}
                        </div>
                    </section>
                    <section className="card-surface mt-6 p-5">
                        <h2 className="text-xl text-primary">Thời gian nghỉ</h2>
                        <form
                            onSubmit={addTimeOff}
                            className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
                        >
                            <Input name="date" type="date" required />
                            <Input name="start_time" type="time" />
                            <Input name="end_time" type="time" />
                            <Input name="reason" placeholder="Lý do" />
                            <label className="flex items-center gap-2 text-sm">
                                <input name="full_day" type="checkbox" />
                                Cả ngày
                            </label>
                            <Button
                                className="sm:col-span-2 lg:col-span-5"
                                disabled={createTimeOff.isPending}
                                type="submit"
                            >
                                Thêm thời gian nghỉ
                            </Button>
                        </form>
                        <div className="mt-5 grid gap-3">
                            {timeOffs.data?.data.map((timeOff) => (
                                <TimeOffRow key={timeOff.id} doctorId={id} timeOff={timeOff} />
                            ))}
                        </div>
                    </section>
                </>
            )}
        </AdminGuard>
    );
}

export function AdminServicesPage() {
    const client = useQueryClient();
    const { user } = useAuth();
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const [notice, setNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const query = useQuery({
        queryKey: ["admin-services", { search, page }],
        queryFn: () => adminApi.services({ search: search || undefined, page }),
    });
    const categories = useQuery({
        queryKey: ["admin-service-categories"],
        queryFn: () => adminApi.serviceCategories(),
        enabled: user?.role === "admin",
    });
    const categoryOptions = categories.data?.data ?? [];
    const hasCategories = categoryOptions.length > 0;
    const create = useMutation({
        mutationFn: adminApi.createService,
        onSuccess: async () => {
            setNotice("Đã tạo dịch vụ.");
            setErrors({});
            await client.invalidateQueries({ queryKey: ["admin-services"] });
            await client.invalidateQueries({ queryKey: ["admin-service-categories"] });
        },
    });
    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setNotice("");
        setErrors({});
        const formElement = event.currentTarget;
        try {
            await create.mutateAsync(servicePayload(new FormData(formElement)));
            formElement.reset();
        } catch (reason) {
            setNotice(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        }
    }
    return (
        <AdminGuard>
            <AdminTitle
                title="Thêm dịch vụ"
                description="Tạo dịch vụ, chọn danh mục và tải ảnh trực tiếp từ máy tính của bạn."
            />
            <form onSubmit={submit} className="card-surface mt-7 grid gap-4 p-5 md:grid-cols-2">
                <Field label="Tên dịch vụ *" error={errors["name"]}>
                    <Input
                        name="name"
                        required
                        aria-invalid={Boolean(errors["name"]) || undefined}
                    />
                </Field>
                <Field label="Thời lượng (phút) *" error={errors["duration"]}>
                    <Input
                        name="duration"
                        type="number"
                        min={1}
                        required
                        aria-invalid={Boolean(errors["duration"]) || undefined}
                    />
                </Field>
                <Field label="Giá *" error={errors["price"]}>
                    <Input
                        name="price"
                        type="number"
                        min={0}
                        step="0.01"
                        required
                        aria-invalid={Boolean(errors["price"]) || undefined}
                    />
                </Field>
                <div>
                    <Field label="Danh mục *" error={errors["category_id"]}>
                        <select
                            id="service-category"
                            name="category_id"
                            required
                            disabled={!hasCategories || create.isPending}
                            aria-invalid={Boolean(errors["category_id"]) || undefined}
                            className="min-h-12 w-full rounded-md border border-input bg-card px-4 text-sm outline-none transition focus:border-primary focus:ring-1 focus:ring-primary disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <option value="">Chọn danh mục</option>
                            {categoryOptions.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    {categories.isPending && (
                        <p role="status" className="mt-2 text-xs text-muted-foreground">
                            Đang tải danh mục...
                        </p>
                    )}
                    {categories.isError && (
                        <p
                            role="alert"
                            className="mt-2 flex flex-wrap items-center gap-2 text-xs text-red-700"
                        >
                            Không thể tải danh mục.
                            <button
                                type="button"
                                onClick={() => categories.refetch()}
                                disabled={categories.isFetching}
                                className="font-semibold underline underline-offset-2 disabled:opacity-60"
                            >
                                {categories.isFetching ? "Đang thử lại..." : "Thử lại"}
                            </button>
                        </p>
                    )}
                    {categories.data && !hasCategories && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Chưa có danh mục. Hãy{" "}
                            <Link
                                to="/admin/service-categories"
                                className="font-semibold text-primary"
                            >
                                thêm danh mục
                            </Link>{" "}
                            trước.
                        </p>
                    )}
                </div>
                <Field label="Ảnh dịch vụ" error={errors["image"]}>
                    <Input
                        name="image"
                        type="file"
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        aria-invalid={Boolean(errors["image"]) || undefined}
                    />
                </Field>
                <Field label="Mô tả" error={errors["description"]}>
                    <Textarea
                        name="description"
                        aria-invalid={Boolean(errors["description"]) || undefined}
                    />
                </Field>
                <Button
                    className="md:col-span-2"
                    disabled={create.isPending || !hasCategories}
                    type="submit"
                >
                    {create.isPending ? "Đang tạo..." : "Thêm dịch vụ"}
                </Button>
            </form>
            <Notice value={notice} onClose={() => setNotice("")} />
            <Input
                className="mt-7 max-w-md"
                value={search}
                onChange={(event) => {
                    setSearch(event.target.value);
                    setPage(1);
                }}
                placeholder="Tìm dịch vụ..."
            />
            <div className="mt-5 grid gap-4">
                {query.data?.data.map((service) => (
                    <ServiceEditor
                        key={service.id}
                        service={service}
                        categories={categoryOptions}
                        categoriesLoading={categories.isPending}
                        categoriesError={categories.isError}
                    />
                ))}
            </div>
            {query.isPending && <LoadingState />}
            {query.isError && (
                <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
            )}{" "}
            {query.data?.data.length === 0 && <EmptyState message="Không tìm thấy dịch vụ." />}
            {query.data && (
                <Pagination
                    current={query.data.meta.current_page}
                    last={query.data.meta.last_page}
                    onPage={setPage}
                />
            )}
        </AdminGuard>
    );
}

type AdminAppointmentFilters = {
    status: string;
    doctor_id: string;
    service_id: string;
    date: string;
    from: string;
    to: string;
    customer_id: string;
    quick_filter: AdminAppointmentQuickFilter;
    sort: AdminAppointmentSort | "";
};

const defaultAppointmentFilters: AdminAppointmentFilters = {
    status: "",
    doctor_id: "",
    service_id: "",
    date: "",
    from: "",
    to: "",
    customer_id: "",
    quick_filter: "all",
    sort: "",
};

const appointmentQuickFilters: Array<{
    value: AdminAppointmentQuickFilter;
    label: string;
}> = [
    { value: "all", label: "Tất cả" },
    { value: "new", label: "Mới đặt" },
    { value: "upcoming", label: "Sắp tới" },
    { value: "treatment_done", label: "Chờ hoàn tất" },
    { value: "completed", label: "Hoàn thành" },
    { value: "cancelled", label: "Đã hủy" },
];

function toAppointmentQuery(
    filters: AdminAppointmentFilters,
    page: number,
): AdminAppointmentParams {
    return {
        status: filters.status || undefined,
        doctor_id: filters.doctor_id || undefined,
        service_id: filters.service_id || undefined,
        date: filters.date || undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
        customer_id: filters.customer_id || undefined,
        quick_filter: filters.quick_filter,
        sort: filters.sort || undefined,
        page,
    };
}

function formatAppointmentCreatedAt(value: string): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "—";
    const pad = (part: number) => String(part).padStart(2, "0");
    return `${pad(date.getHours())}:${pad(date.getMinutes())} · ${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;
}

function wasAppointmentRescheduled(appointment: Appointment): boolean {
    return Boolean(
        appointment.was_rescheduled ||
        appointment.rescheduled_at ||
        (appointment.reschedule_count ?? 0) > 0 ||
        appointment.original_appointment_date ||
        appointment.original_start_time ||
        appointment.original_end_time,
    );
}

function AppointmentMeta({
    label,
    value,
    emphasize = false,
}: {
    label: string;
    value: string;
    emphasize?: boolean;
}) {
    return (
        <div className="min-w-0">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p
                className={`mt-1 truncate ${emphasize ? "font-semibold text-primary" : "text-foreground"}`}
            >
                {value}
            </p>
        </div>
    );
}

export function AdminAppointmentsPage() {
    const client = useQueryClient();
    const [filters, setFilters] = useState<AdminAppointmentFilters>(defaultAppointmentFilters);
    const [page, setPage] = useState(1);
    const [notice, setNotice] = useState("");
    const [successPopup, setSuccessPopup] = useState("");
    const [pendingCancellation, setPendingCancellation] = useState<Appointment | null>(null);
    const [pendingTransition, setPendingTransition] = useState<{
        appointment: Appointment;
        status: Appointment["status"];
    } | null>(null);
    const query = useQuery({
        queryKey: ["admin-appointments", { ...filters, page }],
        queryFn: () => adminApi.appointments(toAppointmentQuery(filters, page)),
        refetchInterval: 15_000,
    });
    const doctors = useQuery({
        queryKey: ["admin-doctors", { filter: true }],
        queryFn: () => adminApi.doctors(),
    });
    const services = useQuery({
        queryKey: ["admin-services", { filter: true }],
        queryFn: () => adminApi.services({ per_page: 100 }),
    });
    const transition = useMutation({
        mutationFn: ({ id, status }: { id: number; status: Appointment["status"] }) =>
            adminApi.updateAppointmentStatus(id, status),
        onSuccess: async () => {
            setSuccessPopup("Đã cập nhật trạng thái lịch hẹn.");
            await client.invalidateQueries({ queryKey: ["admin-appointments"] });
        },
    });
    const setFilter = <K extends keyof AdminAppointmentFilters>(
        key: K,
        value: AdminAppointmentFilters[K],
    ) => {
        setFilters((current) => ({ ...current, [key]: value }));
        setPage(1);
    };
    const resetFilters = () => {
        setFilters(defaultAppointmentFilters);
        setPage(1);
    };
    async function changeStatus(id: number, status: Appointment["status"]): Promise<boolean> {
        setNotice("");
        setSuccessPopup("");
        try {
            await transition.mutateAsync({ id, status });
            return true;
        } catch (reason) {
            setNotice(errorMessage(reason));
            return false;
        }
    }
    return (
        <AdminGuard>
            <AdminTitle
                title="Quản lý lịch hẹn"
                description="Bao gồm cả khách có tài khoản và khách đặt qua email OTP."
            />
            <div className="card-surface mt-7 grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-4">
                <select
                    className="min-h-11 rounded-md border bg-card px-3"
                    value={filters.status}
                    onChange={(event) => setFilter("status", event.target.value)}
                >
                    <option value="checked_in">Đã check-in</option>
                    <option value="in_progress">Bác sĩ đang khám</option>
                    <option value="treatment_done">Bác sĩ đã hoàn thành</option>
                    <option value="no_show">Không đến</option>
                    <option value="">Mọi trạng thái</option>
                    <option value="pending">Chờ xác nhận</option>
                    <option value="confirmed">Đã xác nhận</option>
                    <option value="completed">Hoàn thành</option>
                    <option value="cancelled">Đã hủy</option>
                </select>
                <select
                    className="min-h-11 rounded-md border bg-card px-3"
                    value={filters.doctor_id}
                    onChange={(event) => setFilter("doctor_id", event.target.value)}
                >
                    <option value="">Mọi bác sĩ</option>
                    {doctors.data?.data.map((doctor) => (
                        <option key={doctor.id} value={doctor.id}>
                            {doctor.name}
                        </option>
                    ))}
                </select>
                <select
                    className="min-h-11 rounded-md border bg-card px-3"
                    value={filters.service_id}
                    onChange={(event) => setFilter("service_id", event.target.value)}
                >
                    <option value="">Mọi dịch vụ</option>
                    {services.data?.data.map((service) => (
                        <option key={service.id} value={service.id}>
                            {service.name}
                        </option>
                    ))}
                </select>
                <Input
                    type="date"
                    value={filters.date}
                    onChange={(event) => setFilter("date", event.target.value)}
                />
                <Field label="Từ ngày">
                    <Input
                        type="date"
                        value={filters.from}
                        onChange={(event) => setFilter("from", event.target.value)}
                    />
                </Field>
                <Field label="Đến ngày">
                    <Input
                        type="date"
                        value={filters.to}
                        onChange={(event) => setFilter("to", event.target.value)}
                    />
                </Field>
                <Field label="Customer ID">
                    <Input
                        type="number"
                        min={1}
                        value={filters.customer_id}
                        onChange={(event) => setFilter("customer_id", event.target.value)}
                    />
                </Field>
                <Field label="Sắp xếp">
                    <select
                        className="min-h-11 w-full rounded-md border bg-card px-3"
                        value={filters.sort}
                        onChange={(event) =>
                            setFilter("sort", event.target.value as AdminAppointmentSort | "")
                        }
                    >
                        <option value="">Ưu tiên nghiệp vụ</option>
                        <option value="nearest">Ngày hẹn gần nhất</option>
                        <option value="newest">Mới đặt gần đây</option>
                        <option value="farthest">Ngày hẹn xa nhất</option>
                        <option value="oldest">Cũ nhất</option>
                    </select>
                </Field>
                <button
                    type="button"
                    className="self-end justify-self-start rounded-full px-4 py-2 text-sm font-semibold text-primary transition hover:bg-muted"
                    onClick={resetFilters}
                >
                    Đặt lại bộ lọc
                </button>
            </div>
            <div className="scrollbar-none mt-5 flex gap-2 overflow-x-auto pb-1" role="tablist">
                {appointmentQuickFilters.map(({ value, label }) => {
                    const count = query.data?.counts[value] ?? 0;
                    const active = filters.quick_filter === value;
                    return (
                        <button
                            key={value}
                            type="button"
                            role="tab"
                            aria-selected={active}
                            onClick={() => setFilter("quick_filter", value)}
                            className={`inline-flex min-h-10 shrink-0 items-center gap-2 rounded-full border px-4 text-sm font-semibold transition ${active ? "border-primary bg-primary text-primary-foreground" : "border-border bg-card text-primary hover:border-secondary hover:bg-muted"}`}
                        >
                            {value === "new" && count > 0 && (
                                <span
                                    aria-hidden="true"
                                    className="size-1.5 rounded-full bg-secondary"
                                />
                            )}
                            {label}
                            <span
                                className={
                                    active ? "text-primary-foreground/75" : "text-muted-foreground"
                                }
                            >
                                {count}
                            </span>
                        </button>
                    );
                })}
            </div>
            <Notice value={notice} onClose={() => setNotice("")} />
            <SuccessDialog
                message={successPopup}
                onClose={() => setSuccessPopup("")}
                title="Cập nhật thành công"
            />
            <div className="mt-5 grid gap-4">
                {query.data?.data.map((appointment) => (
                    <AdminAppointmentCard
                        key={appointment.id}
                        appointment={appointment}
                        busy={transition.isPending}
                        requestStatusChange={(appointment, status) =>
                            setPendingTransition({ appointment, status })
                        }
                        requestCancel={() => setPendingCancellation(appointment)}
                    />
                ))}
            </div>
            {query.isPending && <LoadingState />}
            {query.isError && (
                <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
            )}{" "}
            {query.data?.data.length === 0 && (
                <div className="card-surface p-8 text-center">
                    <p className="text-sm font-semibold text-primary">Không có lịch hẹn phù hợp</p>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Thử thay đổi bộ lọc hoặc khoảng thời gian.
                    </p>
                    <Button className="mt-5" variant="outline" onClick={resetFilters}>
                        Đặt lại bộ lọc
                    </Button>
                </div>
            )}
            {query.data && (
                <Pagination
                    current={query.data.meta.current_page}
                    last={query.data.meta.last_page}
                    onPage={setPage}
                />
            )}
            <AlertDialog
                open={Boolean(pendingCancellation)}
                onOpenChange={(open) => !open && setPendingCancellation(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Xác nhận hủy lịch?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Lịch hẹn của {pendingCancellation?.service.name} sẽ chuyển sang trạng
                            thái đã hủy. Thao tác này không thể hoàn tác từ màn hình quản trị.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Giữ lịch</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={transition.isPending}
                            className="bg-red-700 text-white hover:bg-red-800"
                            onClick={(event) => {
                                event.preventDefault();
                                if (!pendingCancellation) return;
                                void changeStatus(pendingCancellation.id, "cancelled").then(
                                    (success) => success && setPendingCancellation(null),
                                );
                            }}
                        >
                            {transition.isPending ? "Đang hủy..." : "Xác nhận hủy"}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <AppointmentTransitionDialog
                pending={pendingTransition}
                busy={transition.isPending}
                onClose={() => setPendingTransition(null)}
                onConfirm={async () => {
                    if (!pendingTransition) return;
                    const success = await changeStatus(
                        pendingTransition.appointment.id,
                        pendingTransition.status,
                    );
                    if (success) setPendingTransition(null);
                }}
            />
        </AdminGuard>
    );
}

export function AdminAppointmentDetailPage({ id }: { id: number }) {
    const client = useQueryClient();
    const navigate = useNavigate();
    const [notice, setNotice] = useState("");
    const [successPopup, setSuccessPopup] = useState("");
    const [pendingCancellation, setPendingCancellation] = useState<Appointment | null>(null);
    const [pendingTransition, setPendingTransition] = useState<{
        appointment: Appointment;
        status: Appointment["status"];
    } | null>(null);
    const query = useQuery({
        queryKey: ["admin-appointment", id],
        queryFn: () => adminApi.appointment(id),
        retry: false,
        refetchInterval: 15_000,
    });
    const transition = useMutation({
        mutationFn: ({ status }: { status: Appointment["status"] }) =>
            adminApi.updateAppointmentStatus(id, status),
        onSuccess: async () => {
            setSuccessPopup("Đã cập nhật trạng thái lịch hẹn.");
            await Promise.all([
                query.refetch(),
                client.invalidateQueries({ queryKey: ["admin-appointments"] }),
            ]);
        },
    });

    async function changeStatus(appointmentId: number, status: Appointment["status"]) {
        if (appointmentId !== id) return false;
        setNotice("");
        setSuccessPopup("");
        try {
            await transition.mutateAsync({ status });
            return true;
        } catch (reason) {
            setNotice(errorMessage(reason));
            return false;
        }
    }

    return (
        <AdminGuard>
            <button
                type="button"
                onClick={() => navigate({ to: "/admin/appointments" })}
                className="mb-5 text-sm font-semibold text-primary hover:text-secondary"
            >
                ← Quay lại danh sách lịch hẹn
            </button>
            <AdminTitle
                title="Chi tiết lịch hẹn"
                description="Theo dõi thông tin khách hàng, dịch vụ, bác sĩ và trạng thái lịch hẹn."
            />
            <Notice value={notice} onClose={() => setNotice("")} />
            <SuccessDialog
                message={successPopup}
                onClose={() => setSuccessPopup("")}
                title="Cập nhật thành công"
            />
            <div className="mt-7">
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                ) : (
                    <AdminAppointmentCard
                        appointment={query.data.data}
                        busy={transition.isPending}
                        requestStatusChange={(appointment, status) =>
                            setPendingTransition({ appointment, status })
                        }
                        requestCancel={() => setPendingCancellation(query.data.data)}
                    />
                )}
            </div>
            <AlertDialog
                open={Boolean(pendingCancellation)}
                onOpenChange={(open) => !open && setPendingCancellation(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Xác nhận hủy lịch?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Lịch hẹn này sẽ chuyển sang trạng thái đã hủy.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Giữ lịch</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={transition.isPending}
                            className="bg-red-700 text-white hover:bg-red-800"
                            onClick={(event) => {
                                event.preventDefault();
                                if (!pendingCancellation) return;
                                void changeStatus(pendingCancellation.id, "cancelled").then(
                                    (success) => success && setPendingCancellation(null),
                                );
                            }}
                        >
                            {transition.isPending ? "Đang hủy..." : "Xác nhận hủy"}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <AppointmentTransitionDialog
                pending={pendingTransition}
                busy={transition.isPending}
                onClose={() => setPendingTransition(null)}
                onConfirm={async () => {
                    if (!pendingTransition) return;
                    const success = await changeStatus(
                        pendingTransition.appointment.id,
                        pendingTransition.status,
                    );
                    if (success) setPendingTransition(null);
                }}
            />
        </AdminGuard>
    );
}

export function AdminCustomersPage() {
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const query = useQuery({
        queryKey: ["admin-customers", { search, page }],
        queryFn: () => adminApi.customers({ search: search || undefined, page }),
    });
    return (
        <AdminGuard>
            <AdminTitle
                title="Khách hàng"
                description="Theo dõi số lần hoàn thành và quyền lợi thành viên từ dữ liệu lịch hẹn thực tế."
            />
            <Input
                className="mt-7 max-w-md"
                value={search}
                onChange={(event) => {
                    setSearch(event.target.value);
                    setPage(1);
                }}
                placeholder="Tìm tên, email hoặc số điện thoại..."
            />
            <div className="mt-5 overflow-x-auto rounded-md border bg-card">
                <table className="w-full min-w-[780px] text-sm">
                    <thead>
                        <tr className="border-b text-left text-muted-foreground">
                            <th className="p-4">Khách hàng</th>
                            <th className="p-4">Liên hệ</th>
                            <th className="p-4">Đã hoàn thành</th>
                            <th className="p-4">Lịch gần nhất</th>
                            <th className="p-4 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        {query.data?.data.map((customer) => (
                            <tr key={customer.id} className="border-b last:border-0">
                                <td className="p-4 font-medium text-primary">{customer.name}</td>
                                <td className="p-4">
                                    <p>{customer.email}</p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {customer.phone || "Chưa có số điện thoại"}
                                    </p>
                                </td>
                                <td className="p-4 font-semibold text-primary">
                                    {customer.completed_visits} lượt
                                </td>
                                <td className="p-4">
                                    {customer.last_appointment_date
                                        ? formatDate(customer.last_appointment_date)
                                        : "—"}
                                </td>
                                <td className="p-4 text-right">
                                    <Link
                                        to="/admin/customers/$id"
                                        params={{ id: String(customer.id) }}
                                        className="inline-flex min-h-9 items-center rounded-full border px-4 text-xs font-semibold text-primary transition hover:bg-muted"
                                    >
                                        Chi tiết
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {query.isPending && <LoadingState />}
                {query.isError && (
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                )}{" "}
                {query.data?.data.length === 0 && (
                    <EmptyState message="Không tìm thấy khách hàng." />
                )}
            </div>
            {query.data && (
                <Pagination
                    current={query.data.meta.current_page}
                    last={query.data.meta.last_page}
                    onPage={setPage}
                />
            )}
        </AdminGuard>
    );
}

export function AdminCustomerDetailPage({ id }: { id: number }) {
    const navigate = useNavigate();
    const query = useQuery({
        queryKey: ["admin-customer", id],
        queryFn: () => adminApi.customer(id),
        retry: false,
    });

    return (
        <AdminGuard>
            <button
                type="button"
                onClick={() => navigate({ to: "/admin/customers" })}
                className="mb-5 text-sm font-semibold text-primary hover:text-secondary"
            >
                ← Quay lại danh sách khách hàng
            </button>
            <AdminTitle
                title="Chi tiết khách hàng"
                description="Tiến độ thành viên được tính tự động từ các lịch hẹn đã hoàn thành."
            />
            {query.isPending ? (
                <LoadingState />
            ) : query.isError ? (
                <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
            ) : (
                <div className="mt-7 grid gap-6">
                    <section className="card-surface grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-3">
                        <AppointmentMeta
                            label="Khách hàng"
                            value={query.data.data.name}
                            emphasize
                        />
                        <AppointmentMeta label="Email" value={query.data.data.email || "—"} />
                        <AppointmentMeta
                            label="Số điện thoại"
                            value={query.data.data.phone || "—"}
                        />
                    </section>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                        {[
                            ["Tổng lịch hẹn", query.data.data.statistics.total_appointments],
                            ["Đã hoàn thành", query.data.data.statistics.completed_appointments],
                            ["Sắp tới", query.data.data.statistics.upcoming_appointments],
                            ["Đã hủy", query.data.data.statistics.cancelled_appointments],
                            ["Đánh giá", query.data.data.statistics.reviews],
                            ["Voucher khả dụng", query.data.data.statistics.available_vouchers],
                        ].map(([label, value]) => (
                            <div key={label} className="card-surface p-4">
                                <p className="text-xs text-muted-foreground">{label}</p>
                                <p className="mt-2 text-2xl font-semibold text-primary">{value}</p>
                            </div>
                        ))}
                    </section>

                    <LoyaltyCard summary={query.data.data.loyalty} />

                    <section className="card-surface p-5 sm:p-6">
                        <h2 className="text-2xl text-primary">Voucher thành viên đã cấp</h2>
                        {query.data.data.loyalty_vouchers.length === 0 ? (
                            <p className="mt-4 text-sm text-muted-foreground">
                                Khách hàng chưa nhận Voucher từ mốc thành viên.
                            </p>
                        ) : (
                            <div className="mt-5 grid gap-4 md:grid-cols-2">
                                {query.data.data.loyalty_vouchers.map((voucher) => (
                                    <article key={voucher.id} className="rounded-xl border p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="text-xs font-semibold uppercase tracking-[.12em] text-secondary-foreground">
                                                    Mốc {voucher.milestone} lần
                                                </p>
                                                <p className="mt-2 text-2xl text-primary">
                                                    Voucher {Number(voucher.value)}%
                                                </p>
                                            </div>
                                            <Badge
                                                tone={
                                                    voucher.status === "active"
                                                        ? "success"
                                                        : "default"
                                                }
                                            >
                                                {voucher.status === "active"
                                                    ? "Còn hiệu lực"
                                                    : voucher.status === "used"
                                                      ? "Đã sử dụng"
                                                      : voucher.status === "expired"
                                                        ? "Đã hết hạn"
                                                        : "Đã thu hồi"}
                                            </Badge>
                                        </div>
                                        <p className="mt-4 text-sm text-muted-foreground">
                                            Hết hạn: {formatDate(voucher.expires_at)}
                                        </p>
                                    </article>
                                ))}
                            </div>
                        )}
                    </section>
                </div>
            )}
        </AdminGuard>
    );
}

function DoctorInformationEditor({
    doctor,
    onSaved,
}: {
    doctor: Doctor;
    onSaved: () => Promise<void>;
}) {
    const [notice, setNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const update = useMutation({
        mutationFn: (body: FormData) => adminApi.updateDoctorProfile(doctor.id, body),
        onSuccess: async () => {
            setErrors({});
            await onSaved();
        },
    });

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setNotice("");
        setErrors({});
        const form = new FormData(event.currentTarget);
        const avatar = form.get("avatar");

        if (!(avatar instanceof File) || avatar.size === 0) {
            form.delete("avatar");
        }

        try {
            await update.mutateAsync(form);
        } catch (reason) {
            setNotice(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        }
    }

    return (
        <section className="card-surface mt-7 p-5">
            <div>
                <h2 className="text-xl text-primary">Thông tin bác sĩ</h2>
                <p className="mt-2 text-sm leading-6 text-muted-foreground">
                    Cập nhật hồ sơ cơ bản. Dịch vụ, lịch làm việc và thời gian nghỉ được quản lý ở
                    các phần bên dưới.
                </p>
            </div>
            <form
                onSubmit={submit}
                encType="multipart/form-data"
                className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3"
            >
                {doctor.avatar && (
                    <div className="flex items-center gap-3 md:col-span-2 xl:col-span-3">
                        <img
                            src={doctor.avatar}
                            alt={`Ảnh hiện tại của ${doctor.name}`}
                            className="size-16 rounded-full border object-cover"
                        />
                        <p className="text-sm text-muted-foreground">
                            Chọn ảnh mới nếu bạn muốn thay thế ảnh hiện tại.
                        </p>
                    </div>
                )}
                <Field label="Họ tên bác sĩ *" error={errors["name"]}>
                    <Input name="name" defaultValue={doctor.name} required />
                </Field>
                <Field label="Chuyên môn *" error={errors["specialty"]}>
                    <Input name="specialty" defaultValue={doctor.specialty} required />
                </Field>
                <Field label="Email" error={errors["email"]}>
                    <Input name="email" type="email" defaultValue={doctor.email ?? ""} required />
                </Field>
                <Field label="Số điện thoại" error={errors["phone"]}>
                    <Input name="phone" defaultValue={doctor.phone ?? ""} />
                </Field>
                <Field label="Ảnh đại diện" error={errors["avatar"]}>
                    <Input
                        name="avatar"
                        type="file"
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    />
                </Field>
                <Field label="Giới thiệu" error={errors["bio"]}>
                    <Textarea name="bio" defaultValue={doctor.bio ?? ""} className="min-h-12" />
                </Field>
                <Button
                    className="md:col-span-2 xl:col-span-3"
                    disabled={update.isPending}
                    type="submit"
                >
                    {update.isPending ? "Đang lưu..." : "Lưu thông tin bác sĩ"}
                </Button>
                {notice && (
                    <p role="alert" className="text-sm text-red-700 md:col-span-2 xl:col-span-3">
                        {notice}
                    </p>
                )}
            </form>
        </section>
    );
}

function ScheduleRow({ doctorId, schedule }: { doctorId: number; schedule: DoctorSchedule }) {
    const client = useQueryClient();
    const [notice, setNotice] = useState("");
    const update = useMutation({
        mutationFn: (body: ScheduleInput) => adminApi.updateSchedule(doctorId, schedule.id, body),
        onSuccess: () => client.invalidateQueries({ queryKey: ["doctor-schedules", doctorId] }),
    });
    const remove = useMutation({
        mutationFn: () => adminApi.deleteSchedule(doctorId, schedule.id),
        onSuccess: () => client.invalidateQueries({ queryKey: ["doctor-schedules", doctorId] }),
    });
    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        setNotice("");
        try {
            await update.mutateAsync({
                day_of_week: Number(form.get("day_of_week")),
                start_time: String(form.get("start_time")),
                end_time: String(form.get("end_time")),
            });
        } catch (reason) {
            setNotice(errorMessage(reason));
        }
    }
    return (
        <form
            onSubmit={submit}
            className="grid gap-2 rounded-md border p-3 sm:grid-cols-[1fr_1fr_1fr_auto_auto]"
        >
            <select
                name="day_of_week"
                defaultValue={schedule.day_of_week}
                className="min-h-10 rounded-md border bg-card px-2"
            >
                {dayOptions()}
            </select>
            <Input name="start_time" type="time" defaultValue={schedule.start_time} />
            <Input name="end_time" type="time" defaultValue={schedule.end_time} />
            <Button className="min-h-10" disabled={update.isPending} type="submit">
                Lưu
            </Button>
            <Button
                className="min-h-10 text-red-700"
                variant="outline"
                disabled={remove.isPending}
                type="button"
                onClick={async () => {
                    try {
                        await remove.mutateAsync();
                    } catch (reason) {
                        setNotice(errorMessage(reason));
                    }
                }}
            >
                Xóa
            </Button>
            {notice && <p className="text-sm text-red-700 sm:col-span-5">{notice}</p>}
        </form>
    );
}

function TimeOffRow({ doctorId, timeOff }: { doctorId: number; timeOff: DoctorTimeOff }) {
    const client = useQueryClient();
    const [notice, setNotice] = useState("");
    const update = useMutation({
        mutationFn: (body: TimeOffInput) => adminApi.updateTimeOff(doctorId, timeOff.id, body),
        onSuccess: () => client.invalidateQueries({ queryKey: ["doctor-time-offs", doctorId] }),
    });
    const remove = useMutation({
        mutationFn: () => adminApi.deleteTimeOff(doctorId, timeOff.id),
        onSuccess: () => client.invalidateQueries({ queryKey: ["doctor-time-offs", doctorId] }),
    });
    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const fullDay = form.get("full_day") === "on";
        setNotice("");
        try {
            await update.mutateAsync({
                date: String(form.get("date")),
                start_time: fullDay ? null : String(form.get("start_time")),
                end_time: fullDay ? null : String(form.get("end_time")),
                reason: optional(form.get("reason")) ?? null,
            });
        } catch (reason) {
            setNotice(errorMessage(reason));
        }
    }
    return (
        <form
            onSubmit={submit}
            className="grid gap-2 rounded-md border p-3 md:grid-cols-[1fr_1fr_1fr_1.5fr_auto_auto]"
        >
            <Input name="date" type="date" defaultValue={timeOff.date} />
            <Input name="start_time" type="time" defaultValue={timeOff.start_time ?? ""} />
            <Input name="end_time" type="time" defaultValue={timeOff.end_time ?? ""} />
            <Input name="reason" defaultValue={timeOff.reason ?? ""} />
            <label className="flex items-center gap-2 text-xs">
                <input name="full_day" type="checkbox" defaultChecked={timeOff.full_day} />
                Cả ngày
            </label>
            <div className="flex gap-2">
                <Button className="min-h-10" disabled={update.isPending} type="submit">
                    Lưu
                </Button>
                <Button
                    className="min-h-10 text-red-700"
                    variant="outline"
                    disabled={remove.isPending}
                    type="button"
                    onClick={async () => {
                        try {
                            await remove.mutateAsync();
                        } catch (reason) {
                            setNotice(errorMessage(reason));
                        }
                    }}
                >
                    Xóa
                </Button>
            </div>
            {notice && <p className="text-sm text-red-700 md:col-span-6">{notice}</p>}
        </form>
    );
}

function ServiceEditor({
    service,
    categories,
    categoriesLoading,
    categoriesError,
}: {
    service: Service;
    categories: ServiceCategory[];
    categoriesLoading: boolean;
    categoriesError: boolean;
}) {
    const client = useQueryClient();
    const [notice, setNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const update = useMutation({
        mutationFn: (body: FormData) => adminApi.updateService(service.id, body),
        onSuccess: async () => {
            setNotice("Đã lưu.");
            setErrors({});
            await client.invalidateQueries({ queryKey: ["admin-services"] });
            await client.invalidateQueries({ queryKey: ["admin-service-categories"] });
        },
    });
    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setNotice("");
        setErrors({});
        try {
            await update.mutateAsync(servicePayload(new FormData(event.currentTarget), true));
        } catch (reason) {
            setNotice(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        }
    }
    return (
        <form
            onSubmit={submit}
            className="card-surface grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-6"
        >
            {service.image && (
                <div className="flex items-center gap-4 md:col-span-2 xl:col-span-6">
                    <img
                        src={service.image}
                        alt={`Ảnh hiện tại của ${service.name}`}
                        className="size-20 rounded-md border object-cover"
                    />
                    <p className="text-xs text-muted-foreground">
                        Ảnh hiện tại. Chọn ảnh mới nếu bạn muốn thay thế.
                    </p>
                </div>
            )}
            <Field label="Tên dịch vụ *" error={errors["name"]}>
                <Input
                    name="name"
                    defaultValue={service.name}
                    required
                    aria-invalid={Boolean(errors["name"]) || undefined}
                />
            </Field>
            <Field label="Thời lượng (phút) *" error={errors["duration"]}>
                <Input
                    name="duration"
                    type="number"
                    min={1}
                    defaultValue={service.duration}
                    required
                    aria-invalid={Boolean(errors["duration"]) || undefined}
                />
            </Field>
            <Field label="Giá *" error={errors["price"]}>
                <Input
                    name="price"
                    type="number"
                    min={0}
                    step="0.01"
                    defaultValue={service.price}
                    required
                    aria-invalid={Boolean(errors["price"]) || undefined}
                />
            </Field>
            <Field label="Danh mục *" error={errors["category_id"]}>
                <select
                    name="category_id"
                    required
                    defaultValue={service.category_id ?? ""}
                    disabled={categoriesLoading || categoriesError || categories.length === 0}
                    aria-invalid={Boolean(errors["category_id"]) || undefined}
                    className="min-h-12 w-full rounded-md border border-input bg-card px-4 text-sm outline-none transition focus:border-primary focus:ring-1 focus:ring-primary disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <option value="">
                        {categoriesLoading ? "Đang tải danh mục..." : "Chọn danh mục"}
                    </option>
                    {categories.map((category) => (
                        <option key={category.id} value={category.id}>
                            {category.name}
                        </option>
                    ))}
                </select>
                {categoriesError && (
                    <span role="alert" className="text-xs text-red-700">
                        Không thể tải danh mục.
                    </span>
                )}
                {!categoriesLoading && !categoriesError && categories.length === 0 && (
                    <span className="text-xs text-muted-foreground">Chưa có danh mục.</span>
                )}
            </Field>
            <Field label="Ảnh mới" error={errors["image"]}>
                <Input
                    name="image"
                    type="file"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    aria-invalid={Boolean(errors["image"]) || undefined}
                />
            </Field>
            <Field label="Trạng thái" error={errors["status"]}>
                <select
                    name="status"
                    defaultValue={service.status}
                    aria-invalid={Boolean(errors["status"]) || undefined}
                    className="min-h-12 w-full rounded-md border border-input bg-card px-4 text-sm outline-none transition focus:border-primary focus:ring-1 focus:ring-primary"
                >
                    <option value="active">Hoạt động</option>
                    <option value="inactive">Tạm ngưng</option>
                </select>
            </Field>
            <div className="md:col-span-2 xl:col-span-6">
                <Field label="Mô tả" error={errors["description"]}>
                    <Textarea
                        name="description"
                        defaultValue={service.description ?? ""}
                        aria-invalid={Boolean(errors["description"]) || undefined}
                    />
                </Field>
            </div>
            <Button
                className="md:col-span-2 xl:col-span-6 sm:justify-self-start"
                disabled={update.isPending}
                type="submit"
            >
                {update.isPending ? "Đang lưu..." : "Lưu"}
            </Button>
            <div className="md:col-span-2 xl:col-span-6">
                <OperationNotice
                    message={notice}
                    success={notice === "Đã lưu."}
                    onClose={() => setNotice("")}
                />
            </div>
        </form>
    );
}

function AdminAppointmentCard({
    appointment,
    busy,
    requestStatusChange,
    requestCancel,
}: {
    appointment: Appointment;
    busy: boolean;
    requestStatusChange: (appointment: Appointment, status: Appointment["status"]) => void;
    requestCancel: () => void;
}) {
    const customer =
        appointment.customer_type === "guest" ? appointment.guest : appointment.customer;
    const rescheduled = wasAppointmentRescheduled(appointment);
    return (
        <article
            className={`card-surface p-5 ${appointment.status === "pending" ? "border-secondary/70 bg-secondary/5" : ""}`}
        >
            <div className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-4">
                <div className="min-w-0">
                    <p className="text-xs text-muted-foreground">
                        #{appointment.id}{" "}
                        {appointment.booking_code && `· ${appointment.booking_code}`}
                    </p>
                    <h2 className="mt-1 break-words text-xl font-semibold text-primary">
                        {appointment.service.name}
                    </h2>
                </div>
                <div className="flex flex-wrap justify-end gap-2 self-start">
                    {rescheduled && <Badge tone="info">Đã đổi lịch</Badge>}
                    <StatusBadge status={appointment.status} />
                </div>
            </div>
            <AppointmentProgress status={appointment.status} />
            <div className="mt-5 grid gap-4 text-sm md:grid-cols-2 xl:grid-cols-4">
                <AppointmentMeta label="Khách hàng" value={customer?.name || "—"} emphasize />
                <AppointmentMeta label="Email" value={customer?.email || "—"} />
                <AppointmentMeta label="Điện thoại" value={customer?.phone || "—"} />
                <AppointmentMeta
                    label="Loại khách"
                    value={appointment.customer_type === "guest" ? "Khách vãng lai" : "Khách hàng"}
                />
                <AppointmentMeta label="Bác sĩ" value={appointment.doctor.name} />
                <AppointmentMeta
                    label="Ngày hẹn"
                    value={formatDate(appointment.appointment_date)}
                />
                <AppointmentMeta
                    label="Giờ hẹn"
                    value={`${appointment.start_time}–${appointment.end_time}`}
                />
                {rescheduled &&
                    appointment.original_appointment_date &&
                    appointment.original_start_time && (
                        <AppointmentMeta
                            label="Lịch cũ"
                            value={`${formatDate(appointment.original_appointment_date)} · ${appointment.original_start_time}${appointment.original_end_time ? `–${appointment.original_end_time}` : ""}`}
                        />
                    )}
                <AppointmentMeta
                    label="Đặt lúc"
                    value={formatAppointmentCreatedAt(appointment.created_at)}
                />
            </div>
            {appointment.pricing.original_price !== null && (
                <div className="mt-4 grid gap-3 rounded-lg bg-muted/60 p-4 text-sm sm:grid-cols-3">
                    <AppointmentMeta
                        label="Giá gốc"
                        value={money(appointment.pricing.original_price)}
                    />
                    <AppointmentMeta
                        label="Ưu đãi"
                        value={`- ${money(appointment.pricing.discount_amount)}`}
                    />
                    <AppointmentMeta
                        label="Giá sau ưu đãi"
                        value={money(
                            appointment.pricing.final_price ?? appointment.pricing.original_price,
                        )}
                        emphasize
                    />
                    {appointment.voucher && (
                        <p className="sm:col-span-3 text-xs text-muted-foreground">
                            Voucher:{" "}
                            <strong className="font-mono text-primary">
                                {appointment.voucher.code}
                            </strong>
                        </p>
                    )}
                </div>
            )}
            {appointment.note && (
                <p className="mt-3 text-sm text-muted-foreground">Ghi chú: {appointment.note}</p>
            )}
            <div className="mt-4 flex flex-wrap justify-end gap-2 border-t pt-4">
                {appointment.status === "pending" && (
                    <Button
                        disabled={busy}
                        onClick={() => requestStatusChange(appointment, "confirmed")}
                    >
                        Xác nhận
                    </Button>
                )}
                {appointment.status === "confirmed" && (
                    <Button
                        disabled={busy}
                        onClick={() => requestStatusChange(appointment, "checked_in")}
                    >
                        Check-in
                    </Button>
                )}
                {appointment.status === "checked_in" && (
                    <p className="mr-auto text-sm text-muted-foreground">
                        Đã check-in / Chờ bác sĩ.
                    </p>
                )}
                {appointment.status === "in_progress" && (
                    <p className="mr-auto text-sm text-muted-foreground">Bác sĩ đang khám.</p>
                )}
                {appointment.status === "treatment_done" && (
                    <Button
                        disabled={busy}
                        onClick={() => requestStatusChange(appointment, "completed")}
                    >
                        Hoàn tất lịch hẹn
                    </Button>
                )}
                {["pending", "confirmed"].includes(appointment.status) && (
                    <Button
                        variant="outline"
                        className="text-red-700"
                        disabled={busy}
                        onClick={requestCancel}
                    >
                        Hủy
                    </Button>
                )}
            </div>
        </article>
    );
}

function AppointmentTransitionDialog({
    pending,
    busy,
    onClose,
    onConfirm,
}: {
    pending: { appointment: Appointment; status: Appointment["status"] } | null;
    busy: boolean;
    onClose: () => void;
    onConfirm: () => Promise<void>;
}) {
    const copy = pending ? appointmentTransitionCopy(pending.status) : null;
    const appointment = pending?.appointment;

    return (
        <AlertDialog open={Boolean(pending)} onOpenChange={(open) => !open && !busy && onClose()}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{copy?.title}</AlertDialogTitle>
                    <AlertDialogDescription>{copy?.description}</AlertDialogDescription>
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
                            <span className="text-muted-foreground">Bác sĩ:</span>{" "}
                            <strong>{appointment.doctor.name}</strong>
                        </p>
                        <p>
                            <span className="text-muted-foreground">Lịch:</span>{" "}
                            <strong>
                                {formatDate(appointment.appointment_date)} ·{" "}
                                {appointment.start_time}–{appointment.end_time}
                            </strong>
                        </p>
                    </div>
                )}
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={busy}>Quay lại</AlertDialogCancel>
                    <AlertDialogAction
                        disabled={busy}
                        onClick={(event) => {
                            event.preventDefault();
                            void onConfirm();
                        }}
                    >
                        {busy ? "Đang cập nhật..." : copy?.confirmLabel}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function appointmentTransitionCopy(status: Appointment["status"]): {
    title: string;
    description: string;
    confirmLabel: string;
} {
    const copy: Partial<
        Record<Appointment["status"], { title: string; description: string; confirmLabel: string }>
    > = {
        confirmed: {
            title: "Xác nhận lịch hẹn?",
            description: "Bạn có chắc chắn muốn xác nhận lịch hẹn này không?",
            confirmLabel: "Xác nhận lịch",
        },
        checked_in: {
            title: "Xác nhận khách đã đến?",
            description:
                "Sau khi check-in, lịch hẹn sẽ được chuyển sang bác sĩ phụ trách để tiếp nhận.",
            confirmLabel: "Xác nhận check-in",
        },
        completed: {
            title: "Hoàn tất lịch hẹn?",
            description:
                "Bác sĩ đã hoàn tất điều trị. Bạn có chắc chắn muốn đóng lịch hẹn này không?",
            confirmLabel: "Hoàn tất lịch hẹn",
        },
    };

    return (
        copy[status] ?? {
            title: "Cập nhật lịch hẹn?",
            description: "Bạn có chắc chắn muốn cập nhật trạng thái lịch hẹn này không?",
            confirmLabel: "Xác nhận",
        }
    );
}

export function AdminGuard({ children }: { children: ReactNode }) {
    const { user, isLoading } = useAuth();
    if (isLoading) return <LoadingState label="Đang kiểm tra quyền quản trị..." />;
    if (!user) return <Navigate to="/login" />;
    if (user.role !== "admin") {
        return (
            <Navigate
                to={
                    user.role === "receptionist"
                        ? "/receptionist"
                        : user.role === "doctor"
                          ? "/doctor"
                          : "/account"
                }
            />
        );
    }
    return children;
}
export function AdminTitle({
    title,
    description,
    action,
}: {
    title: string;
    description: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div className="min-w-0">
                <p className="label-luxury">Quản trị</p>
                <h1 className="mt-2 text-3xl text-primary md:text-4xl">{title}</h1>
                <p className="mt-3 max-w-3xl text-sm leading-6 text-muted-foreground">
                    {description}
                </p>
            </div>
            {action && <div className="shrink-0">{action}</div>}
        </div>
    );
}
function Notice({ value, onClose }: { value: string; onClose: () => void }) {
    return (
        <OperationNotice
            message={value}
            success={value.startsWith("Đã")}
            onClose={onClose}
            className="mt-4"
        />
    );
}
function doctorAccountBadge(status: Doctor["account_status"]) {
    if (status === "pending_setup") return <Badge tone="warning">Chờ thiết lập mật khẩu</Badge>;
    if (status === "active") return <Badge tone="success">Đã kích hoạt</Badge>;
    if (status === "suspended") return <Badge tone="danger">Đã tạm ngưng</Badge>;
    return <Badge tone="default">Hồ sơ cũ chưa liên kết</Badge>;
}
function doctorConfigurationBadge(doctor: Doctor) {
    if (doctor.configuration_status === "inactive") {
        return <Badge tone="danger">Ngừng hoạt động</Badge>;
    }
    if (doctor.configuration_status === "missing_services") {
        return <Badge tone="warning">Chưa thiết lập dịch vụ</Badge>;
    }
    if (doctor.configuration_status === "missing_schedule") {
        return <Badge tone="warning">Chưa thiết lập lịch</Badge>;
    }
    if (doctor.configuration_status === "ready") {
        return <Badge tone="success">Sẵn sàng nhận lịch</Badge>;
    }
    return doctor.schedules_count === 0 ? <Badge tone="warning">Chưa thiết lập lịch</Badge> : null;
}
function optional(value: FormDataEntryValue | null): string | undefined {
    const text = String(value ?? "").trim();
    return text || undefined;
}
function servicePayload(form: FormData, includeMethod = false): FormData {
    const payload = new FormData();
    payload.append("name", String(form.get("name") ?? "").trim());
    payload.append("duration", String(form.get("duration") ?? ""));
    payload.append("price", String(form.get("price") ?? ""));

    const categoryId = optional(form.get("category_id"));
    if (categoryId) payload.append("category_id", categoryId);

    const description = optional(form.get("description"));
    if (description) payload.append("description", description);

    payload.append("status", optional(form.get("status")) ?? "active");

    const image = form.get("image");
    if (image instanceof File && image.size > 0) payload.append("image", image);

    if (includeMethod) payload.append("_method", "PATCH");
    return payload;
}
function dayOptions() {
    return [
        [1, "Thứ hai"],
        [2, "Thứ ba"],
        [3, "Thứ tư"],
        [4, "Thứ năm"],
        [5, "Thứ sáu"],
        [6, "Thứ bảy"],
        [7, "Chủ nhật"],
    ].map(([value, label]) => (
        <option key={value} value={value}>
            {label}
        </option>
    ));
}
