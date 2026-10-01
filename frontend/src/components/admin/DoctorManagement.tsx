import { useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient, type QueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { useNavigate } from "@tanstack/react-router";
import { Check, ChevronsUpDown, CircleAlert, Copy, Plus, Trash2, X } from "lucide-react";
import { Button } from "@/components/common/Button";
import { EmptyState, ErrorState, LoadingState } from "@/components/common/AsyncState";
import { OperationNotice } from "@/components/common/Feedback";
import { Field, Input, Textarea } from "@/components/common/Fields";
import { Badge } from "@/components/common/Status";
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
import { Checkbox } from "@/components/ui/checkbox";
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from "@/components/ui/command";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
    adminApi,
    type ScheduleInput,
    type ScheduleReplaceInput,
    type TimeOffInput,
} from "@/services/adminApi";
import { errorMessage, firstFieldErrors } from "@/services/api";
import type { Doctor, DoctorSchedule, DoctorTimeOff, Service } from "@/types";

const weekdays = [
    { value: 1, label: "Thứ hai" },
    { value: 2, label: "Thứ ba" },
    { value: 3, label: "Thứ tư" },
    { value: 4, label: "Thứ năm" },
    { value: 5, label: "Thứ sáu" },
    { value: 6, label: "Thứ bảy" },
    { value: 7, label: "Chủ nhật" },
] as const;

type DoctorScheduleDraft = ScheduleReplaceInput & { clientId: string };
type ShiftTemplate = Pick<ScheduleInput, "start_time" | "end_time"> & { clientId: string };
type ApplyMode = "empty-only" | "overwrite";

let draftSequence = 0;

function draftId(): string {
    draftSequence += 1;
    return `doctor-schedule-draft-${draftSequence}`;
}

function createDraftShift(
    dayOfWeek: number,
    startTime = "08:00",
    endTime = "12:00",
    id?: number,
): DoctorScheduleDraft {
    return {
        clientId: draftId(),
        ...(id === undefined ? {} : { id }),
        day_of_week: dayOfWeek,
        start_time: startTime.slice(0, 5),
        end_time: endTime.slice(0, 5),
    };
}

function scheduleDraftFromResponse(schedule: DoctorSchedule): DoctorScheduleDraft {
    return createDraftShift(
        schedule.day_of_week,
        schedule.start_time,
        schedule.end_time,
        schedule.id,
    );
}

function validateScheduleDraft(
    shifts: Array<Pick<DoctorScheduleDraft, "day_of_week" | "start_time" | "end_time">>,
): string {
    for (const shift of shifts) {
        if (!shift.start_time || !shift.end_time) {
            return "Vui lòng nhập đầy đủ giờ bắt đầu và giờ kết thúc.";
        }
        if (shift.start_time >= shift.end_time) {
            return "Giờ kết thúc phải sau giờ bắt đầu.";
        }
    }

    for (const day of weekdays) {
        const dayShifts = shifts
            .filter((shift) => shift.day_of_week === day.value)
            .sort((left, right) => left.start_time.localeCompare(right.start_time));
        const seen = new Set<string>();

        for (let index = 0; index < dayShifts.length; index += 1) {
            const shift = dayShifts[index]!;
            const key = `${shift.start_time}-${shift.end_time}`;
            if (seen.has(key)) return `${day.label} có ca làm việc bị trùng lặp.`;
            seen.add(key);

            if (index > 0 && shift.start_time < dayShifts[index - 1]!.end_time) {
                return `${day.label} có các ca làm việc chồng lấn.`;
            }
        }
    }

    return "";
}

async function invalidateDoctorQueries(client: QueryClient, doctorId: number): Promise<void> {
    await Promise.all([
        client.invalidateQueries({ queryKey: ["admin-doctor", doctorId] }),
        client.invalidateQueries({ queryKey: ["admin-doctors"] }),
        client.invalidateQueries({ queryKey: ["doctors"] }),
        client.invalidateQueries({ queryKey: ["available-slots"] }),
        client.invalidateQueries({ queryKey: ["admin-appointments"] }),
    ]);
}

export function DoctorManagement({ id }: { id: number }) {
    const client = useQueryClient();
    const navigate = useNavigate();
    const [notice, setNotice] = useState("");
    const [deleteError, setDeleteError] = useState("");
    const [confirmAction, setConfirmAction] = useState<"deactivate" | "delete" | null>(null);
    const doctorQuery = useQuery({
        queryKey: ["admin-doctor", id],
        queryFn: () => adminApi.doctor(id),
        retry: false,
    });
    const servicesQuery = useQuery({
        queryKey: ["admin-services", { assignment: true }],
        queryFn: () => adminApi.services({ per_page: 100 }),
    });
    const schedulesQuery = useQuery({
        queryKey: ["doctor-schedules", id],
        queryFn: () => adminApi.schedules(id),
    });
    const timeOffsQuery = useQuery({
        queryKey: ["doctor-time-offs", id],
        queryFn: () => adminApi.timeOffs(id),
    });
    const statusMutation = useMutation({
        mutationFn: (status: "active" | "inactive") => adminApi.updateDoctor(id, { status }),
        onSuccess: async (_, status) => {
            setConfirmAction(null);
            setNotice(
                status === "inactive" ? "Đã ngừng hoạt động bác sĩ." : "Đã kích hoạt lại bác sĩ.",
            );
            await invalidateDoctorQueries(client, id);
        },
        onError: (reason) => setNotice(errorMessage(reason)),
    });
    const resendInvitation = useMutation({
        mutationFn: () => adminApi.resendDoctorInvitation(id),
        onSuccess: async (response) => {
            setNotice(response.message);
            await invalidateDoctorQueries(client, id);
        },
        onError: (reason) => setNotice(errorMessage(reason)),
    });
    const deleteMutation = useMutation({
        mutationFn: () => adminApi.deleteDoctor(id),
        onSuccess: async () => {
            await invalidateDoctorQueries(client, id);
            await navigate({
                to: "/admin/doctors",
                search: { notice: "Đã xóa bác sĩ thành công." },
            });
        },
        onError: (reason) => {
            setDeleteError(errorMessage(reason));
            toast.error(errorMessage(reason));
        },
    });

    if (doctorQuery.isPending) return <LoadingState />;
    if (doctorQuery.isError) {
        return (
            <ErrorState
                message={errorMessage(doctorQuery.error)}
                retry={() => doctorQuery.refetch()}
            />
        );
    }

    const doctor = doctorQuery.data.data;

    return (
        <div className="pb-8">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p className="label-luxury">Quản lý bác sĩ</p>
                    <h1 className="mt-2 text-3xl text-primary md:text-4xl">{doctor.name}</h1>
                    <p className="mt-2 text-muted-foreground">{doctor.specialty}</p>
                </div>
                <div className="flex flex-col items-start gap-3 sm:items-end">
                    <ConfigurationBadge doctor={doctor} />
                    {doctor.account_status === "pending_setup" && (
                        <Button
                            variant="outline"
                            disabled={resendInvitation.isPending}
                            onClick={() => resendInvitation.mutate()}
                        >
                            {resendInvitation.isPending ? "Đang gửi..." : "Gửi lại email mời"}
                        </Button>
                    )}
                </div>
            </div>

            <Notice value={notice} onClose={() => setNotice("")} />

            <DoctorInformationSection
                doctor={doctor}
                onNotice={setNotice}
                onSaved={() => invalidateDoctorQueries(client, id)}
            />

            <ServiceAssignmentSection
                doctor={doctor}
                services={servicesQuery.data?.data ?? []}
                loading={servicesQuery.isPending}
                loadError={servicesQuery.isError ? errorMessage(servicesQuery.error) : ""}
                onNotice={setNotice}
                onSaved={() => invalidateDoctorQueries(client, id)}
            />

            {schedulesQuery.isPending ? (
                <section className="card-surface mt-7 p-5">
                    <LoadingState />
                </section>
            ) : schedulesQuery.isError ? (
                <section className="card-surface mt-7 p-5">
                    <ErrorState
                        message={errorMessage(schedulesQuery.error)}
                        retry={() => schedulesQuery.refetch()}
                    />
                </section>
            ) : (
                <WeeklyScheduleSection
                    doctorId={id}
                    initialSchedules={schedulesQuery.data.data}
                    onNotice={setNotice}
                    onSaved={() => invalidateDoctorQueries(client, id)}
                />
            )}

            <TimeOffSection
                doctorId={id}
                timeOffs={timeOffsQuery.data?.data ?? []}
                loading={timeOffsQuery.isPending}
                loadError={timeOffsQuery.isError ? errorMessage(timeOffsQuery.error) : ""}
                onNotice={setNotice}
            />

            <section className="card-surface mt-7 p-5">
                <h2 className="text-xl text-primary">Trạng thái bác sĩ</h2>
                <div className="mt-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <Badge tone={doctor.status === "active" ? "success" : "danger"}>
                            {doctor.status === "active" ? "Đang hoạt động" : "Ngừng hoạt động"}
                        </Badge>
                        <p className="mt-3 max-w-2xl text-sm leading-6 text-muted-foreground">
                            Bác sĩ ngừng hoạt động không xuất hiện trong quy trình đặt lịch và không
                            thể sử dụng Doctor Portal. Dữ liệu lịch sử vẫn được giữ nguyên.
                        </p>
                    </div>
                    {doctor.status === "active" ? (
                        <Button
                            variant="outline"
                            disabled={statusMutation.isPending}
                            onClick={() => setConfirmAction("deactivate")}
                        >
                            Ngừng hoạt động
                        </Button>
                    ) : (
                        <Button
                            disabled={statusMutation.isPending}
                            onClick={() => statusMutation.mutate("active")}
                        >
                            {statusMutation.isPending ? "Đang kích hoạt..." : "Kích hoạt lại"}
                        </Button>
                    )}
                </div>
            </section>

            <section className="mt-7 rounded-xl border border-red-200 bg-red-50/50 p-5">
                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-red-700">
                    Vùng nguy hiểm
                </p>
                <h2 className="mt-2 text-xl text-red-900">Xóa vĩnh viễn</h2>
                <p className="mt-2 max-w-2xl text-sm leading-6 text-red-800/80">
                    Chỉ sử dụng khi hồ sơ được tạo nhầm và chưa phát sinh bất kỳ dữ liệu lịch sử
                    nào. Cấu hình dịch vụ, lịch làm việc và thời gian nghỉ sẽ bị xóa cùng hồ sơ.
                </p>
                <Button
                    variant="outline"
                    className="mt-4 border-red-300 text-red-700 hover:bg-red-100"
                    disabled={deleteMutation.isPending}
                    onClick={() => setConfirmAction("delete")}
                >
                    Xóa vĩnh viễn
                </Button>
            </section>

            <DoctorActionDialog
                action={confirmAction}
                doctor={doctor}
                busy={statusMutation.isPending || deleteMutation.isPending}
                close={() => setConfirmAction(null)}
                confirm={() => {
                    if (confirmAction === "deactivate") statusMutation.mutate("inactive");
                    if (confirmAction === "delete") deleteMutation.mutate();
                }}
            />
            <Dialog open={deleteError !== ""} onOpenChange={(open) => !open && setDeleteError("")}>
                <DialogContent className="w-[calc(100%-2rem)] max-w-md rounded-2xl border-red-200 p-0 shadow-2xl">
                    <div className="flex gap-4 p-6 pr-12">
                        <span className="grid size-11 shrink-0 place-items-center rounded-full bg-red-100 text-red-700">
                            <CircleAlert size={22} aria-hidden="true" />
                        </span>
                        <DialogHeader className="gap-2 text-left">
                            <DialogTitle className="text-red-800">Không thể xóa bác sĩ</DialogTitle>
                            <DialogDescription className="leading-6 text-red-700">
                                {deleteError}
                            </DialogDescription>
                        </DialogHeader>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function ConfigurationBadge({ doctor }: { doctor: Doctor }) {
    if (doctor.configuration_status === "inactive")
        return <Badge tone="danger">Ngừng hoạt động</Badge>;
    if (doctor.configuration_status === "missing_services")
        return <Badge tone="warning">Chưa thiết lập dịch vụ</Badge>;
    if (doctor.configuration_status === "missing_schedule")
        return <Badge tone="warning">Chưa thiết lập lịch</Badge>;
    return <Badge tone="success">Sẵn sàng nhận lịch</Badge>;
}

function DoctorInformationSection({
    doctor,
    onNotice,
    onSaved,
}: {
    doctor: Doctor;
    onNotice: (message: string) => void;
    onSaved: () => Promise<void>;
}) {
    const [errors, setErrors] = useState<Record<string, string>>({});
    const update = useMutation({
        mutationFn: (body: FormData) => adminApi.updateDoctorProfile(doctor.id, body),
        onSuccess: async () => {
            setErrors({});
            onNotice("Đã cập nhật thông tin bác sĩ.");
            await onSaved();
        },
        onError: (reason) => {
            onNotice(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        },
    });

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        onNotice("");
        setErrors({});
        const form = new FormData(event.currentTarget);
        const avatar = form.get("avatar");
        if (!(avatar instanceof File) || avatar.size === 0) form.delete("avatar");
        await update.mutateAsync(form).catch(() => undefined);
    }

    return (
        <section className="card-surface mt-7 p-5">
            <h2 className="text-xl text-primary">Thông tin bác sĩ</h2>
            <form
                onSubmit={submit}
                encType="multipart/form-data"
                className="mt-5 grid items-start gap-5 md:grid-cols-2 xl:grid-cols-12"
            >
                <div className="md:col-span-2 xl:col-span-4">
                    <Field label="Email *" error={errors["email"]}>
                        <Input
                            name="email"
                            type="email"
                            defaultValue={doctor.email ?? ""}
                            required
                        />
                    </Field>
                </div>
                <div className="xl:col-span-4">
                    <Field label="Họ tên bác sĩ *" error={errors["name"]}>
                        <Input name="name" defaultValue={doctor.name} required />
                    </Field>
                </div>
                <div className="xl:col-span-4">
                    <Field label="Chuyên môn *" error={errors["specialty"]}>
                        <Input name="specialty" defaultValue={doctor.specialty} required />
                    </Field>
                </div>
                <div className="max-w-xs xl:col-span-3">
                    <Field label="Số điện thoại" error={errors["phone"]}>
                        <Input name="phone" defaultValue={doctor.phone ?? ""} />
                    </Field>
                </div>
                <div className="max-w-sm xl:col-span-4">
                    <Field label="Ảnh đại diện" error={errors["avatar"]}>
                        <Input
                            name="avatar"
                            type="file"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        />
                    </Field>
                    {doctor.avatar && (
                        <div className="mt-3 flex items-center gap-3">
                            <img
                                src={doctor.avatar}
                                alt={`Ảnh hiện tại của ${doctor.name}`}
                                className="size-12 rounded-full border object-cover"
                            />
                            <p className="text-xs leading-5 text-muted-foreground">
                                Chọn ảnh mới để thay ảnh hiện tại.
                            </p>
                        </div>
                    )}
                </div>
                <div className="md:col-span-2 xl:col-span-5">
                    <Field label="Giới thiệu" error={errors["bio"]}>
                        <Textarea name="bio" defaultValue={doctor.bio ?? ""} className="min-h-24" />
                    </Field>
                </div>
                <div className="md:col-span-2 xl:col-span-12">
                    <Button disabled={update.isPending} type="submit">
                        {update.isPending ? "Đang lưu..." : "Lưu thông tin"}
                    </Button>
                </div>
            </form>
            <div className="mt-5 flex flex-wrap items-center gap-3 border-t pt-5 text-sm">
                <span className="text-muted-foreground">Tài khoản:</span>
                <AccountBadge status={doctor.account_status} />
                <span className="text-muted-foreground">{doctor.email}</span>
            </div>
        </section>
    );
}

function AccountBadge({ status }: { status: Doctor["account_status"] }) {
    if (status === "pending_setup") return <Badge tone="warning">Chờ thiết lập mật khẩu</Badge>;
    if (status === "active") return <Badge tone="success">Đã kích hoạt</Badge>;
    if (status === "suspended") return <Badge tone="danger">Đã tạm ngưng</Badge>;
    return <Badge tone="default">Hồ sơ cũ chưa liên kết</Badge>;
}

function ServiceAssignmentSection({
    doctor,
    services,
    loading,
    loadError,
    onNotice,
    onSaved,
}: {
    doctor: Doctor;
    services: Service[];
    loading: boolean;
    loadError: string;
    onNotice: (message: string) => void;
    onSaved: () => Promise<void>;
}) {
    const [selectedIds, setSelectedIds] = useState<number[]>(
        doctor.services?.map((service) => service.id) ?? [],
    );
    const [open, setOpen] = useState(false);
    const sync = useMutation({
        mutationFn: () => adminApi.syncDoctorServices(doctor.id, selectedIds),
        onSuccess: async () => {
            onNotice("Đã cập nhật dịch vụ phụ trách.");
            await onSaved();
        },
        onError: (reason) => onNotice(errorMessage(reason)),
    });
    const selectedServices = services.filter((service) => selectedIds.includes(service.id));

    function toggle(serviceId: number) {
        setSelectedIds((current) =>
            current.includes(serviceId)
                ? current.filter((id) => id !== serviceId)
                : [...current, serviceId],
        );
    }

    return (
        <section className="card-surface mt-7 p-5">
            <h2 className="text-xl text-primary">Dịch vụ phụ trách</h2>
            <p className="mt-2 text-sm text-muted-foreground">
                Chỉ chọn các dịch vụ đã có trong hệ thống. Thay đổi chỉ được lưu khi bạn nhấn nút
                bên dưới.
            </p>
            {loading ? (
                <LoadingState />
            ) : loadError ? (
                <ErrorState message={loadError} />
            ) : (
                <div className="mt-5 grid gap-4">
                    <Popover open={open} onOpenChange={setOpen}>
                        <PopoverTrigger asChild>
                            <button
                                type="button"
                                role="combobox"
                                aria-expanded={open}
                                className="flex min-h-12 w-full max-w-xl items-center justify-between rounded-md border bg-card px-4 text-left text-sm"
                            >
                                <span>
                                    {selectedIds.length > 0
                                        ? `Đã chọn ${selectedIds.length} dịch vụ`
                                        : "Chọn dịch vụ..."}
                                </span>
                                <ChevronsUpDown className="size-4 text-muted-foreground" />
                            </button>
                        </PopoverTrigger>
                        <PopoverContent
                            align="start"
                            className="isolate w-[min(34rem,calc(100vw-2rem))] overflow-hidden border bg-popover p-0 shadow-xl"
                        >
                            <Command>
                                <CommandInput placeholder="Tìm dịch vụ..." />
                                <CommandList>
                                    <CommandEmpty>Không tìm thấy dịch vụ.</CommandEmpty>
                                    <CommandGroup>
                                        {services.map((service) => {
                                            const selected = selectedIds.includes(service.id);
                                            return (
                                                <CommandItem
                                                    key={service.id}
                                                    value={service.name}
                                                    onSelect={() => toggle(service.id)}
                                                >
                                                    <span
                                                        className={`grid size-4 place-items-center rounded-sm border ${selected ? "border-primary bg-primary text-primary-foreground" : "border-input"}`}
                                                    >
                                                        {selected && <Check className="size-3" />}
                                                    </span>
                                                    {service.name}
                                                </CommandItem>
                                            );
                                        })}
                                    </CommandGroup>
                                </CommandList>
                            </Command>
                        </PopoverContent>
                    </Popover>
                    <div className="flex flex-wrap gap-2">
                        {selectedServices.length === 0 ? (
                            <span className="text-sm text-muted-foreground">
                                Chưa chọn dịch vụ.
                            </span>
                        ) : (
                            selectedServices.map((service) => (
                                <span
                                    key={service.id}
                                    className="inline-flex items-center gap-1 rounded-full border border-secondary/40 bg-secondary/10 px-3 py-1 text-sm text-primary"
                                >
                                    {service.name}
                                    <button
                                        type="button"
                                        onClick={() => toggle(service.id)}
                                        aria-label={`Bỏ ${service.name}`}
                                        className="rounded-full p-0.5 hover:bg-secondary/20"
                                    >
                                        <X className="size-3.5" />
                                    </button>
                                </span>
                            ))
                        )}
                    </div>
                    <div>
                        <Button disabled={sync.isPending} onClick={() => sync.mutate()}>
                            {sync.isPending ? "Đang lưu..." : "Lưu thay đổi"}
                        </Button>
                    </div>
                </div>
            )}
        </section>
    );
}

function WeeklyScheduleSection({
    doctorId,
    initialSchedules,
    onNotice,
    onSaved,
}: {
    doctorId: number;
    initialSchedules: DoctorSchedule[];
    onNotice: (message: string) => void;
    onSaved: () => Promise<void>;
}) {
    const [draft, setDraft] = useState<DoctorScheduleDraft[]>(
        initialSchedules.map(scheduleDraftFromResponse),
    );
    const [validationError, setValidationError] = useState("");
    const [quickOpen, setQuickOpen] = useState(false);
    const [quickDays, setQuickDays] = useState<number[]>([1, 2, 3, 4, 5]);
    const [quickShifts, setQuickShifts] = useState<ShiftTemplate[]>([
        { clientId: draftId(), start_time: "08:00", end_time: "12:00" },
        { clientId: draftId(), start_time: "13:30", end_time: "17:00" },
    ]);
    const [quickConflict, setQuickConflict] = useState(false);
    const [copySourceDay, setCopySourceDay] = useState<number | null>(null);
    const [copyDays, setCopyDays] = useState<number[]>([]);
    const [copyConflict, setCopyConflict] = useState(false);
    const replace = useMutation({
        mutationFn: () =>
            adminApi.replaceSchedules(
                doctorId,
                draft.map(({ clientId: _, ...schedule }) => schedule),
            ),
        onSuccess: async (response) => {
            setDraft(response.data.map(scheduleDraftFromResponse));
            setValidationError("");
            onNotice("Đã cập nhật lịch làm việc.");
            await onSaved();
        },
        onError: (reason) => onNotice(errorMessage(reason)),
    });

    function dayShifts(day: number): DoctorScheduleDraft[] {
        return draft
            .filter((shift) => shift.day_of_week === day)
            .sort((left, right) => left.start_time.localeCompare(right.start_time));
    }

    function toggleDay(day: number, enabled: boolean) {
        setDraft((current) =>
            enabled
                ? current.some((shift) => shift.day_of_week === day)
                    ? current
                    : [...current, createDraftShift(day)]
                : current.filter((shift) => shift.day_of_week !== day),
        );
    }

    function updateShift(clientId: string, field: "start_time" | "end_time", value: string) {
        setDraft((current) =>
            current.map((shift) =>
                shift.clientId === clientId ? { ...shift, [field]: value } : shift,
            ),
        );
    }

    function validateTemplates(templates: ShiftTemplate[]): string {
        return validateScheduleDraft(
            templates.map((shift) => ({
                day_of_week: 1,
                start_time: shift.start_time,
                end_time: shift.end_time,
            })),
        );
    }

    function applyQuick(mode?: ApplyMode) {
        const error = validateTemplates(quickShifts);
        if (error) return setValidationError(error);
        if (quickDays.length === 0)
            return setValidationError("Vui lòng chọn ít nhất một ngày áp dụng.");
        const conflictingDays = quickDays.filter((day) => dayShifts(day).length > 0);
        if (conflictingDays.length > 0 && mode === undefined) return setQuickConflict(true);
        const targetDays =
            mode === "empty-only"
                ? quickDays.filter((day) => dayShifts(day).length === 0)
                : quickDays;
        setDraft((current) => [
            ...current.filter(
                (shift) => mode !== "overwrite" || !targetDays.includes(shift.day_of_week),
            ),
            ...targetDays.flatMap((day) =>
                quickShifts.map((shift) => createDraftShift(day, shift.start_time, shift.end_time)),
            ),
        ]);
        setQuickConflict(false);
        setQuickOpen(false);
        setValidationError("");
    }

    function applyCopy(mode?: ApplyMode) {
        if (copySourceDay === null || copyDays.length === 0)
            return setValidationError("Vui lòng chọn ít nhất một ngày để sao chép.");
        const sourceShifts = dayShifts(copySourceDay);
        const conflictingDays = copyDays.filter((day) => dayShifts(day).length > 0);
        if (conflictingDays.length > 0 && mode === undefined) return setCopyConflict(true);
        const targetDays =
            mode === "empty-only"
                ? copyDays.filter((day) => dayShifts(day).length === 0)
                : copyDays;
        setDraft((current) => [
            ...current.filter(
                (shift) => mode !== "overwrite" || !targetDays.includes(shift.day_of_week),
            ),
            ...targetDays.flatMap((day) =>
                sourceShifts.map((shift) =>
                    createDraftShift(day, shift.start_time, shift.end_time),
                ),
            ),
        ]);
        setCopyConflict(false);
        setCopySourceDay(null);
        setCopyDays([]);
        setValidationError("");
    }

    function saveSchedule() {
        const error = validateScheduleDraft(draft);
        setValidationError(error);
        if (!error) replace.mutate();
    }

    return (
        <section className="card-surface mt-7 p-5">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="text-xl text-primary">Lịch làm việc</h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Mọi thay đổi, kể cả tạo nhanh và sao chép, chỉ là bản nháp cho đến khi lưu
                        lịch.
                    </p>
                </div>
                <Button variant="outline" onClick={() => setQuickOpen(true)}>
                    Tạo lịch nhanh
                </Button>
            </div>

            <div className="mt-5 grid gap-4">
                {weekdays.map((day) => {
                    const shifts = dayShifts(day.value);
                    return (
                        <article key={day.value} className="rounded-lg border bg-card p-4">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <label className="flex items-center gap-3 font-semibold text-primary">
                                    <Checkbox
                                        checked={shifts.length > 0}
                                        onCheckedChange={(checked) =>
                                            toggleDay(day.value, checked === true)
                                        }
                                    />
                                    {day.label} · Làm việc
                                </label>
                                {shifts.length > 0 && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setCopySourceDay(day.value);
                                            setCopyDays([]);
                                            setCopyConflict(false);
                                        }}
                                        className="inline-flex items-center gap-2 text-sm font-semibold text-primary hover:underline"
                                    >
                                        <Copy className="size-4" /> Sao chép lịch này
                                    </button>
                                )}
                            </div>
                            {shifts.length === 0 ? (
                                <p className="mt-3 text-sm text-muted-foreground">Nghỉ</p>
                            ) : (
                                <div className="mt-4 grid gap-3">
                                    {shifts.map((shift) => (
                                        <div
                                            key={shift.clientId}
                                            className="grid gap-2 sm:grid-cols-[1fr_auto_1fr_auto] sm:items-center"
                                        >
                                            <Input
                                                aria-label={`Giờ bắt đầu ${day.label}`}
                                                type="time"
                                                value={shift.start_time}
                                                onChange={(event) =>
                                                    updateShift(
                                                        shift.clientId,
                                                        "start_time",
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <span className="hidden text-muted-foreground sm:block">
                                                –
                                            </span>
                                            <Input
                                                aria-label={`Giờ kết thúc ${day.label}`}
                                                type="time"
                                                value={shift.end_time}
                                                onChange={(event) =>
                                                    updateShift(
                                                        shift.clientId,
                                                        "end_time",
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <Button
                                                variant="ghost"
                                                className="min-h-10 text-red-700"
                                                onClick={() =>
                                                    setDraft((current) =>
                                                        current.filter(
                                                            (item) =>
                                                                item.clientId !== shift.clientId,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4" /> Xóa
                                            </Button>
                                        </div>
                                    ))}
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setDraft((current) => [
                                                ...current,
                                                createDraftShift(day.value),
                                            ])
                                        }
                                        className="inline-flex w-fit items-center gap-2 text-sm font-semibold text-primary hover:underline"
                                    >
                                        <Plus className="size-4" /> Thêm ca
                                    </button>
                                </div>
                            )}
                        </article>
                    );
                })}
            </div>
            {validationError && (
                <p role="alert" className="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                    {validationError}
                </p>
            )}
            <Button className="mt-5" disabled={replace.isPending} onClick={saveSchedule}>
                {replace.isPending ? "Đang lưu..." : "Lưu lịch làm việc"}
            </Button>

            <ScheduleTemplateDialog
                open={quickOpen}
                title="Tạo lịch nhanh"
                description="Chọn ngày và các ca muốn đưa vào bản nháp."
                selectedDays={quickDays}
                excludedDay={null}
                shifts={quickShifts}
                conflict={quickConflict}
                setOpen={(open) => {
                    setQuickOpen(open);
                    if (!open) setQuickConflict(false);
                }}
                toggleDay={(day) =>
                    setQuickDays((current) =>
                        current.includes(day)
                            ? current.filter((value) => value !== day)
                            : [...current, day],
                    )
                }
                updateShift={(clientId, field, value) =>
                    setQuickShifts((current) =>
                        current.map((shift) =>
                            shift.clientId === clientId ? { ...shift, [field]: value } : shift,
                        ),
                    )
                }
                addShift={() =>
                    setQuickShifts((current) => [
                        ...current,
                        { clientId: draftId(), start_time: "13:30", end_time: "17:00" },
                    ])
                }
                removeShift={(clientId) =>
                    setQuickShifts((current) =>
                        current.filter((shift) => shift.clientId !== clientId),
                    )
                }
                apply={applyQuick}
            />

            <ScheduleTemplateDialog
                open={copySourceDay !== null}
                title="Sao chép lịch"
                description={
                    copySourceDay === null
                        ? ""
                        : `Sao chép các ca của ${weekdays.find((day) => day.value === copySourceDay)?.label} sang ngày khác.`
                }
                selectedDays={copyDays}
                excludedDay={copySourceDay}
                shifts={[]}
                conflict={copyConflict}
                setOpen={(open) => {
                    if (!open) {
                        setCopySourceDay(null);
                        setCopyConflict(false);
                    }
                }}
                toggleDay={(day) =>
                    setCopyDays((current) =>
                        current.includes(day)
                            ? current.filter((value) => value !== day)
                            : [...current, day],
                    )
                }
                apply={applyCopy}
            />
        </section>
    );
}

function ScheduleTemplateDialog({
    open,
    title,
    description,
    selectedDays,
    excludedDay,
    shifts,
    conflict,
    setOpen,
    toggleDay,
    updateShift,
    addShift,
    removeShift,
    apply,
}: {
    open: boolean;
    title: string;
    description: string;
    selectedDays: number[];
    excludedDay: number | null;
    shifts: ShiftTemplate[];
    conflict: boolean;
    setOpen: (open: boolean) => void;
    toggleDay: (day: number) => void;
    updateShift?: (clientId: string, field: "start_time" | "end_time", value: string) => void;
    addShift?: () => void;
    removeShift?: (clientId: string) => void;
    apply: (mode?: ApplyMode) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent className="max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-xl">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {weekdays
                        .filter((day) => day.value !== excludedDay)
                        .map((day) => (
                            <label
                                key={day.value}
                                className="flex items-center gap-2 rounded-md border p-3 text-sm"
                            >
                                <Checkbox
                                    checked={selectedDays.includes(day.value)}
                                    onCheckedChange={() => toggleDay(day.value)}
                                />
                                {day.label}
                            </label>
                        ))}
                </div>
                {shifts.length > 0 && (
                    <div className="grid gap-3 border-t pt-4">
                        {shifts.map((shift, index) => (
                            <div
                                key={shift.clientId}
                                className="grid gap-2 sm:grid-cols-[auto_1fr_auto_1fr_auto] sm:items-center"
                            >
                                <span className="text-sm font-semibold">Ca {index + 1}</span>
                                <Input
                                    type="time"
                                    value={shift.start_time}
                                    onChange={(event) =>
                                        updateShift?.(
                                            shift.clientId,
                                            "start_time",
                                            event.target.value,
                                        )
                                    }
                                />
                                <span className="hidden sm:block">–</span>
                                <Input
                                    type="time"
                                    value={shift.end_time}
                                    onChange={(event) =>
                                        updateShift?.(
                                            shift.clientId,
                                            "end_time",
                                            event.target.value,
                                        )
                                    }
                                />
                                <button
                                    type="button"
                                    aria-label={`Xóa ca ${index + 1}`}
                                    onClick={() => removeShift?.(shift.clientId)}
                                    className="p-2 text-red-700"
                                >
                                    <Trash2 className="size-4" />
                                </button>
                            </div>
                        ))}
                        <button
                            type="button"
                            onClick={addShift}
                            className="inline-flex w-fit items-center gap-2 text-sm font-semibold text-primary"
                        >
                            <Plus className="size-4" /> Thêm ca
                        </button>
                    </div>
                )}
                {conflict && (
                    <p className="rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                        Một hoặc nhiều ngày đã có lịch làm việc trong bản nháp. Chọn cách áp dụng để
                        tránh mất dữ liệu ngoài ý muốn.
                    </p>
                )}
                <DialogFooter className="gap-2 sm:space-x-0">
                    <Button variant="ghost" onClick={() => setOpen(false)}>
                        Hủy
                    </Button>
                    {conflict ? (
                        <>
                            <Button variant="outline" onClick={() => apply("empty-only")}>
                                Chỉ áp dụng ngày chưa có lịch
                            </Button>
                            <Button onClick={() => apply("overwrite")}>Ghi đè</Button>
                        </>
                    ) : (
                        <Button onClick={() => apply()}>Áp dụng</Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function TimeOffSection({
    doctorId,
    timeOffs,
    loading,
    loadError,
    onNotice,
}: {
    doctorId: number;
    timeOffs: DoctorTimeOff[];
    loading: boolean;
    loadError: string;
    onNotice: (message: string) => void;
}) {
    const client = useQueryClient();
    const [fullDay, setFullDay] = useState(true);
    const create = useMutation({
        mutationFn: (body: TimeOffInput) => adminApi.createTimeOff(doctorId, body),
        onSuccess: async () => {
            onNotice("Đã thêm thời gian nghỉ.");
            await client.invalidateQueries({ queryKey: ["doctor-time-offs", doctorId] });
            await client.invalidateQueries({ queryKey: ["available-slots"] });
        },
        onError: (reason) => onNotice(errorMessage(reason)),
    });

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const formElement = event.currentTarget;
        const form = new FormData(formElement);
        await create
            .mutateAsync({
                date: String(form.get("date")),
                start_time: fullDay ? null : String(form.get("start_time")),
                end_time: fullDay ? null : String(form.get("end_time")),
                reason: String(form.get("reason") ?? "").trim() || null,
            })
            .then(() => formElement.reset())
            .catch(() => undefined);
    }

    return (
        <section className="card-surface mt-7 p-5">
            <h2 className="text-xl text-primary">Ngày nghỉ / thời gian nghỉ</h2>
            <form onSubmit={submit} className="mt-5 grid gap-3 md:grid-cols-2 lg:grid-cols-5">
                <Input name="date" type="date" required />
                <Input name="start_time" type="time" disabled={fullDay} required={!fullDay} />
                <Input name="end_time" type="time" disabled={fullDay} required={!fullDay} />
                <Input name="reason" placeholder="Lý do (không bắt buộc)" />
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={fullDay}
                        onCheckedChange={(checked) => setFullDay(checked === true)}
                    />{" "}
                    Cả ngày
                </label>
                <div className="md:col-span-2 lg:col-span-5">
                    <Button disabled={create.isPending} type="submit">
                        {create.isPending ? "Đang thêm..." : "Thêm ngày nghỉ"}
                    </Button>
                </div>
            </form>
            <div className="mt-5 grid gap-3">
                {loading ? (
                    <LoadingState />
                ) : loadError ? (
                    <ErrorState message={loadError} />
                ) : timeOffs.length === 0 ? (
                    <EmptyState message="Chưa có ngày nghỉ hoặc thời gian nghỉ." />
                ) : (
                    timeOffs.map((timeOff) => (
                        <TimeOffEditor
                            key={timeOff.id}
                            doctorId={doctorId}
                            timeOff={timeOff}
                            onNotice={onNotice}
                        />
                    ))
                )}
            </div>
        </section>
    );
}

function TimeOffEditor({
    doctorId,
    timeOff,
    onNotice,
}: {
    doctorId: number;
    timeOff: DoctorTimeOff;
    onNotice: (message: string) => void;
}) {
    const client = useQueryClient();
    const [editing, setEditing] = useState(false);
    const [fullDay, setFullDay] = useState(timeOff.full_day);
    const refresh = async () => {
        await client.invalidateQueries({ queryKey: ["doctor-time-offs", doctorId] });
        await client.invalidateQueries({ queryKey: ["available-slots"] });
    };
    const update = useMutation({
        mutationFn: (body: TimeOffInput) => adminApi.updateTimeOff(doctorId, timeOff.id, body),
        onSuccess: async () => {
            setEditing(false);
            onNotice("Đã cập nhật thời gian nghỉ.");
            await refresh();
        },
        onError: (reason) => onNotice(errorMessage(reason)),
    });
    const remove = useMutation({
        mutationFn: () => adminApi.deleteTimeOff(doctorId, timeOff.id),
        onSuccess: async () => {
            onNotice("Đã xóa thời gian nghỉ.");
            await refresh();
        },
        onError: (reason) => onNotice(errorMessage(reason)),
    });

    if (!editing) {
        return (
            <article className="flex flex-col gap-3 rounded-lg border p-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <strong>{timeOff.date}</strong>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {timeOff.full_day
                            ? "Cả ngày"
                            : `${timeOff.start_time} – ${timeOff.end_time}`}
                        {timeOff.reason ? ` · ${timeOff.reason}` : ""}
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button variant="outline" onClick={() => setEditing(true)}>
                        Sửa
                    </Button>
                    <Button
                        variant="ghost"
                        className="text-red-700"
                        disabled={remove.isPending}
                        onClick={() => remove.mutate()}
                    >
                        {remove.isPending ? "Đang xóa..." : "Xóa"}
                    </Button>
                </div>
            </article>
        );
    }

    return (
        <form
            className="grid gap-3 rounded-lg border p-4 md:grid-cols-2 lg:grid-cols-5"
            onSubmit={(event) => {
                event.preventDefault();
                const form = new FormData(event.currentTarget);
                update.mutate({
                    date: String(form.get("date")),
                    start_time: fullDay ? null : String(form.get("start_time")),
                    end_time: fullDay ? null : String(form.get("end_time")),
                    reason: String(form.get("reason") ?? "").trim() || null,
                });
            }}
        >
            <Input name="date" type="date" defaultValue={timeOff.date} required />
            <Input
                name="start_time"
                type="time"
                defaultValue={timeOff.start_time ?? ""}
                disabled={fullDay}
                required={!fullDay}
            />
            <Input
                name="end_time"
                type="time"
                defaultValue={timeOff.end_time ?? ""}
                disabled={fullDay}
                required={!fullDay}
            />
            <Input name="reason" defaultValue={timeOff.reason ?? ""} />
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={fullDay}
                    onCheckedChange={(checked) => setFullDay(checked === true)}
                />{" "}
                Cả ngày
            </label>
            <div className="flex gap-2 md:col-span-2 lg:col-span-5">
                <Button type="submit" disabled={update.isPending}>
                    {update.isPending ? "Đang lưu..." : "Lưu"}
                </Button>
                <Button type="button" variant="ghost" onClick={() => setEditing(false)}>
                    Hủy
                </Button>
            </div>
        </form>
    );
}

function DoctorActionDialog({
    action,
    doctor,
    busy,
    close,
    confirm,
}: {
    action: "deactivate" | "delete" | null;
    doctor: Doctor;
    busy: boolean;
    close: () => void;
    confirm: () => void;
}) {
    const deleting = action === "delete";
    return (
        <AlertDialog open={action !== null} onOpenChange={(open) => !open && !busy && close()}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {deleting ? "Xóa vĩnh viễn bác sĩ?" : "Ngừng hoạt động bác sĩ?"}
                    </AlertDialogTitle>
                    <AlertDialogDescription className="leading-6">
                        Bạn có chắc chắn muốn {deleting ? "xóa vĩnh viễn" : "ngừng hoạt động"} “
                        {doctor.name}”?
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {deleting ? (
                    <p className="text-sm leading-6 text-muted-foreground">
                        Chỉ có thể xóa khi bác sĩ chưa phát sinh dữ liệu lịch sử. Hành động này
                        không thể hoàn tác.
                    </p>
                ) : (
                    <ul className="grid gap-2 text-sm leading-6 text-muted-foreground">
                        <li>• Bác sĩ sẽ không còn xuất hiện trong booking hoặc nhận lịch mới.</li>
                        <li>• Doctor Portal sẽ bị vô hiệu hóa.</li>
                        <li>• Lịch hẹn, đánh giá và dữ liệu lịch sử vẫn được giữ nguyên.</li>
                    </ul>
                )}
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={busy}>Hủy</AlertDialogCancel>
                    <AlertDialogAction
                        disabled={busy}
                        onClick={(event) => {
                            event.preventDefault();
                            confirm();
                        }}
                        className={deleting ? "bg-red-700 text-white hover:bg-red-800" : ""}
                    >
                        {busy ? "Đang xử lý..." : deleting ? "Xóa vĩnh viễn" : "Ngừng hoạt động"}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function Notice({ value, onClose }: { value: string; onClose: () => void }) {
    return (
        <OperationNotice
            message={value}
            success={value.startsWith("Đã")}
            onClose={onClose}
            className="mt-5"
        />
    );
}
