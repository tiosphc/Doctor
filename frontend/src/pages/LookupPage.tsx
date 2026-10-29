import { useState, type FormEvent } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { CalendarClock, Check, Search } from "lucide-react";
import { Container } from "@/components/common/Container";
import { Button, ButtonLink } from "@/components/common/Button";
import { ErrorState, LoadingState } from "@/components/common/AsyncState";
import { Field, Input } from "@/components/common/Fields";
import { formatDate } from "@/components/cards/Cards";
import { AppointmentProgress, StatusBadge } from "@/components/common/Status";
import { appointmentApi } from "@/services/appointmentApi";
import { ApiError, errorMessage, firstFieldErrors } from "@/services/api";
import type { PublicAppointment } from "@/types";
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

export function LookupPage() {
    const [result, setResult] = useState<PublicAppointment | null>(null);
    const [bookingCode, setBookingCode] = useState(() => {
        if (typeof window === "undefined") return "";
        return new URLSearchParams(window.location.search).get("booking_code")?.toUpperCase() ?? "";
    });
    const [phone, setPhone] = useState("");
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [rescheduleOpen, setRescheduleOpen] = useState(false);
    const lookup = useMutation({
        mutationFn: () => appointmentApi.lookup(bookingCode, phone),
        retry: false,
    });
    const cancel = useMutation({
        mutationFn: () => appointmentApi.cancelPublic(bookingCode, phone),
        retry: false,
    });

    async function submit(event: FormEvent) {
        event.preventDefault();
        setError("");
        setSuccess("");
        setErrors({});
        setResult(null);
        try {
            setResult((await lookup.mutateAsync()).data);
        } catch (reason) {
            setErrors(firstFieldErrors(reason));
            setError(
                reason instanceof ApiError && reason.status === 404
                    ? "Không tìm thấy lịch hẹn phù hợp với thông tin đã cung cấp."
                    : errorMessage(reason),
            );
        }
    }

    async function cancelAppointment() {
        setError("");
        setSuccess("");
        try {
            setResult((await cancel.mutateAsync()).data);
            setConfirmOpen(false);
            setSuccess("Lịch hẹn đã được hủy thành công.");
        } catch (reason) {
            setConfirmOpen(false);
            setError(errorMessage(reason));
        }
    }

    return (
        <Container className="section-space">
            <div className="mx-auto max-w-3xl">
                <p className="label-luxury text-center">Hỗ trợ khách hàng</p>
                <h1 className="mt-3 text-center text-4xl text-primary md:text-5xl">
                    Tra cứu lịch hẹn
                </h1>
                <p className="mx-auto mt-4 max-w-xl text-center text-sm leading-6 text-muted-foreground">
                    Nhập mã lịch hẹn và số điện thoại đã dùng khi đặt lịch. Thông tin phải khớp để
                    bảo vệ lịch hẹn của bạn.
                </p>
                <form onSubmit={submit} className="card-surface mt-8 grid gap-5 p-6 md:p-8">
                    <Field label="Mã lịch hẹn" error={errors["booking_code"]}>
                        <Input
                            value={bookingCode}
                            onChange={(event) => setBookingCode(event.target.value.toUpperCase())}
                            required
                            placeholder="JUN-YYYYMMDD-HHMMSS-MMM-XX"
                            autoComplete="off"
                        />
                    </Field>
                    <Field label="Số điện thoại" error={errors["phone"]}>
                        <Input
                            value={phone}
                            onChange={(event) => setPhone(event.target.value)}
                            type="tel"
                            inputMode="tel"
                            required
                            placeholder="0901234567"
                            autoComplete="tel"
                        />
                    </Field>
                    {error && (
                        <p role="alert" className="rounded-md bg-red-50 p-3 text-sm text-red-700">
                            {error}
                        </p>
                    )}
                    <Button disabled={lookup.isPending} type="submit">
                        <Search size={17} />
                        {lookup.isPending ? "Đang tra cứu..." : "Tra cứu lịch hẹn"}
                    </Button>
                </form>

                {success && (
                    <p
                        role="status"
                        className="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800"
                    >
                        {success}
                    </p>
                )}
                {result && (
                    <PublicAppointmentDetails
                        appointment={result}
                        onReschedule={() => {
                            setError("");
                            setSuccess("");
                            setRescheduleOpen(true);
                        }}
                        onCancel={() => {
                            setError("");
                            setSuccess("");
                            setConfirmOpen(true);
                        }}
                    />
                )}
            </div>

            <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Xác nhận hủy lịch?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Lịch hẹn sẽ chuyển sang trạng thái đã hủy và không thể khôi phục từ màn
                            hình này. Bạn chỉ có thể hủy trước giờ hẹn ít nhất{" "}
                            {result?.minimum_change_notice_hours} tiếng.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Giữ lịch</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={cancel.isPending}
                            onClick={(event) => {
                                event.preventDefault();
                                void cancelAppointment();
                            }}
                            className="bg-red-700 text-white hover:bg-red-800"
                        >
                            {cancel.isPending ? "Đang hủy..." : "Xác nhận hủy"}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {result && (
                <PublicRescheduleDialog
                    appointment={result}
                    bookingCode={bookingCode}
                    phone={phone}
                    open={rescheduleOpen}
                    setOpen={setRescheduleOpen}
                    onSuccess={(appointment) => {
                        setResult(appointment);
                        setSuccess("Đổi lịch thành công. Mã lịch hẹn của bạn được giữ nguyên.");
                    }}
                />
            )}
        </Container>
    );
}

function PublicAppointmentDetails({
    appointment,
    onReschedule,
    onCancel,
}: {
    appointment: PublicAppointment;
    onReschedule: () => void;
    onCancel: () => void;
}) {
    return (
        <section className="card-surface mt-6 overflow-hidden">
            <div className="flex flex-col gap-4 border-b bg-muted/35 p-5 sm:flex-row sm:items-start sm:justify-between sm:p-7">
                <div>
                    <p className="label-luxury">Thông tin lịch hẹn</p>
                    <p className="mt-2 break-all font-mono text-sm font-bold text-primary sm:text-base">
                        {appointment.booking_code}
                    </p>
                </div>
                <StatusBadge status={appointment.status} />
            </div>
            <div className="p-5 sm:p-7">
                <AppointmentProgress status={appointment.status} />
                <dl className="mt-6 grid gap-x-8 gap-y-5 border-t pt-6 sm:grid-cols-2">
                    <Detail label="Khách hàng" value={appointment.customer_name} />
                    <Detail label="Dịch vụ" value={appointment.service.name} />
                    <Detail label="Bác sĩ" value={appointment.doctor.name} />
                    <Detail label="Ngày" value={formatDate(appointment.appointment_date)} />
                    <Detail
                        label="Thời gian"
                        value={`${appointment.start_time}–${appointment.end_time}`}
                    />
                    <Detail label="Thời lượng" value={`${appointment.service.duration} phút`} />
                </dl>

                {(appointment.reschedule_block_reason || appointment.cancel_block_reason) && (
                    <div className="mt-6 rounded-xl border border-secondary/30 bg-secondary/10 p-4 text-sm leading-6 text-secondary-foreground">
                        {!appointment.can_reschedule && appointment.reschedule_block_reason && (
                            <p>{appointment.reschedule_block_reason}</p>
                        )}
                        {!appointment.can_cancel && appointment.cancel_block_reason && (
                            <p>{appointment.cancel_block_reason}</p>
                        )}
                        <p>Nếu cần hỗ trợ, vui lòng liên hệ phòng khám.</p>
                    </div>
                )}

                <div className="mt-6 grid gap-3 border-t pt-5 sm:grid-cols-3">
                    {appointment.can_reschedule && (
                        <Button onClick={onReschedule}>
                            <CalendarClock size={16} /> Đổi lịch
                        </Button>
                    )}
                    {appointment.can_cancel && (
                        <Button variant="outline" className="text-red-700" onClick={onCancel}>
                            Hủy lịch
                        </Button>
                    )}
                    <ButtonLink to="/booking" variant="outline">
                        Đặt lịch mới
                    </ButtonLink>
                </div>
            </div>
        </section>
    );
}

function PublicRescheduleDialog({
    appointment,
    bookingCode,
    phone,
    open,
    setOpen,
    onSuccess,
}: {
    appointment: PublicAppointment;
    bookingCode: string;
    phone: string;
    open: boolean;
    setOpen: (open: boolean) => void;
    onSuccess: (appointment: PublicAppointment) => void;
}) {
    const [date, setDate] = useState("");
    const [time, setTime] = useState("");
    const [message, setMessage] = useState("");
    const slots = useQuery({
        queryKey: ["public-reschedule-slots", bookingCode, phone, date],
        queryFn: () => appointmentApi.publicRescheduleSlots(bookingCode, phone, date),
        enabled: open && Boolean(date),
        retry: false,
    });
    const mutation = useMutation({
        mutationFn: () =>
            appointmentApi.reschedulePublic(bookingCode, phone, {
                appointment_date: date,
                start_time: time,
            }),
        onSuccess: (response) => {
            onSuccess(response.data);
            setOpen(false);
            setDate("");
            setTime("");
            setMessage("");
        },
        onError: (reason) => {
            setMessage(errorMessage(reason));
            setTime("");
            void slots.refetch();
        },
    });

    return (
        <Dialog open={open} onOpenChange={(next) => !mutation.isPending && setOpen(next)}>
            <DialogContent className="max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-2xl overflow-y-auto rounded-2xl p-0">
                <div className="border-b bg-primary px-5 py-6 text-primary-foreground sm:px-7">
                    <DialogHeader>
                        <DialogTitle className="font-serif text-2xl sm:text-3xl">
                            Đổi lịch hẹn
                        </DialogTitle>
                        <DialogDescription className="leading-6 text-primary-foreground/75">
                            Giữ nguyên dịch vụ, bác sĩ và mã lịch hẹn. Chỉ ngày và giờ được thay
                            đổi.
                        </DialogDescription>
                    </DialogHeader>
                </div>
                <div className="grid gap-5 p-5 sm:p-7">
                    <div className="rounded-xl border bg-muted/40 p-4 text-sm">
                        <p className="font-semibold text-primary">Lịch hiện tại</p>
                        <p className="mt-2 text-muted-foreground">
                            {appointment.service.name} · {appointment.doctor.name}
                        </p>
                        <p className="mt-1 font-semibold text-primary">
                            {formatDate(appointment.appointment_date)} · {appointment.start_time}–
                            {appointment.end_time}
                        </p>
                    </div>
                    <Field label="Ngày khám mới">
                        <Input
                            type="date"
                            min={localToday()}
                            value={date}
                            onChange={(event) => {
                                setDate(event.target.value);
                                setTime("");
                                setMessage("");
                            }}
                        />
                    </Field>
                    {date && slots.isPending && (
                        <LoadingState label="Đang kiểm tra lịch trống..." />
                    )}
                    {date && slots.isError && (
                        <ErrorState
                            message={errorMessage(slots.error)}
                            retry={() => slots.refetch()}
                        />
                    )}
                    {date && slots.data && slots.data.data.slots.length === 0 && (
                        <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                            Ngày này chưa có khung giờ phù hợp. Vui lòng chọn ngày khác.
                        </p>
                    )}
                    {date && slots.data && slots.data.data.slots.length > 0 && (
                        <div>
                            <p className="text-sm font-semibold text-primary">
                                Khung giờ còn trống
                            </p>
                            <div className="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5">
                                {slots.data.data.slots.map((slot) => {
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
                                            }}
                                            className={`focus-premium min-h-11 rounded-lg border px-2 text-sm font-semibold transition ${
                                                selected
                                                    ? "border-primary bg-primary text-primary-foreground"
                                                    : isCurrent
                                                      ? "cursor-not-allowed bg-muted text-muted-foreground"
                                                      : "bg-card text-primary hover:border-secondary"
                                            }`}
                                        >
                                            {slot}
                                            {selected && (
                                                <Check className="ml-1 inline" size={14} />
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                    {message && (
                        <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">
                            {message}
                        </p>
                    )}
                    <p className="text-xs leading-5 text-muted-foreground">
                        Bạn còn {appointment.reschedules_remaining} lần đổi lịch. Backend sẽ kiểm
                        tra lại điều kiện {appointment.minimum_change_notice_hours} tiếng và tình
                        trạng khung giờ khi xác nhận.
                    </p>
                </div>
                <DialogFooter className="gap-3 border-t p-5 sm:px-7">
                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                        Đóng
                    </Button>
                    <Button
                        type="button"
                        disabled={!date || !time || mutation.isPending}
                        onClick={() => mutation.mutate()}
                    >
                        {mutation.isPending ? "Đang đổi lịch..." : "Xác nhận đổi lịch"}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-1 font-semibold text-primary">{value}</dd>
        </div>
    );
}

function localToday(): string {
    const now = new Date();
    const offset = now.getTimezoneOffset() * 60_000;
    return new Date(now.getTime() - offset).toISOString().slice(0, 10);
}
