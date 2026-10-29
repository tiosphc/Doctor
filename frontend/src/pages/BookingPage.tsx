import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "@tanstack/react-router";
import {
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Gift,
    LoaderCircle,
    Mail,
    Sparkles,
} from "lucide-react";
import { Container } from "@/components/common/Container";
import { Button, ButtonLink } from "@/components/common/Button";
import { Field, Input, Textarea } from "@/components/common/Fields";
import { ErrorState, LoadingState } from "@/components/common/AsyncState";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { InputOTP, InputOTPGroup, InputOTPSlot } from "@/components/ui/input-otp";
import { doctorImage, formatDate, money } from "@/components/cards/Cards";
import { StatusBadge } from "@/components/common/Status";
import { useAuth } from "@/contexts/AuthContext";
import { serviceApi } from "@/services/serviceApi";
import { doctorApi } from "@/services/doctorApi";
import { appointmentApi, type BookingPayload } from "@/services/appointmentApi";
import { voucherApi } from "@/services/reviewVoucherApi";
import { ApiError, errorMessage, firstFieldErrors } from "@/services/api";
import { bookingResult } from "@/lib/booking-result";
import type { Appointment, BookingDraft, User, Voucher } from "@/types";

const steps = ["Dịch vụ", "Bác sĩ", "Ngày giờ & thông tin", "Xác nhận"];
const notePlaceholder =
    "Ví dụ: Tôi muốn tư vấn thêm về..., đây là lần đầu tôi..., hoặc một điều bạn muốn bác sĩ lưu ý.";
const emptyDraft: BookingDraft = {
    serviceId: null,
    doctorId: null,
    date: "",
    time: "",
    customer: { name: "", phone: "", email: "", note: "" },
};

export function BookingPage({ initialServiceId }: { initialServiceId?: number | undefined }) {
    const { user, isLoading: authLoading } = useAuth();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const [step, setStep] = useState(1);
    const [draft, setDraft] = useState<BookingDraft>({
        ...emptyDraft,
        serviceId: initialServiceId ?? null,
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState("");
    const [otpSent, setOtpSent] = useState(false);
    const [otp, setOtp] = useState("");
    const [verificationToken, setVerificationToken] = useState("");
    const [otpDialogOpen, setOtpDialogOpen] = useState(false);
    const [otpMessage, setOtpMessage] = useState("");
    const [otpErrors, setOtpErrors] = useState<Record<string, string>>({});
    const [completedAppointment, setCompletedAppointment] = useState<Appointment | null>(null);
    const [voucherId, setVoucherId] = useState<number | null>(null);
    const [resolvedVoucher, setResolvedVoucher] = useState<Voucher | null>(null);

    const servicesQuery = useQuery({
        queryKey: ["services", { booking: true }],
        queryFn: () => serviceApi.list(),
    });
    const doctorsQuery = useQuery({
        queryKey: ["doctors", { service_id: draft.serviceId, booking: true }],
        queryFn: () => doctorApi.list({ service_id: draft.serviceId ?? undefined, booking: true }),
        enabled: draft.serviceId !== null,
    });
    const slotsQuery = useQuery({
        queryKey: ["available-slots", draft.doctorId, draft.serviceId, draft.date],
        queryFn: () => doctorApi.availableSlots(draft.doctorId!, draft.serviceId!, draft.date),
        enabled: Boolean(draft.doctorId && draft.serviceId && draft.date),
        retry: false,
    });
    const vouchersQuery = useQuery({
        queryKey: ["my-vouchers", { status: "active", booking: true }],
        queryFn: () => voucherApi.mine({ status: "active", per_page: 100 }),
        enabled: user?.role === "customer",
    });

    useEffect(() => {
        if (!initialServiceId || !servicesQuery.data) return;
        if (!servicesQuery.data.data.some((service) => service.id === initialServiceId)) {
            setDraft((current) => ({ ...current, serviceId: null }));
            setMessage("Dịch vụ được chọn không tồn tại hoặc không còn hoạt động.");
        }
    }, [initialServiceId, servicesQuery.data]);

    const selectedService = servicesQuery.data?.data.find(
        (service) => service.id === draft.serviceId,
    );
    const selectedDoctor = doctorsQuery.data?.data.find((doctor) => doctor.id === draft.doctorId);
    const isGuest = !user;

    useEffect(() => {
        if (!draft.doctorId || !doctorsQuery.data) return;
        if (!doctorsQuery.data.data.some((doctor) => doctor.id === draft.doctorId)) {
            setDraft((current) => ({ ...current, doctorId: null, date: "", time: "" }));
        }
    }, [doctorsQuery.data, draft.doctorId]);

    const otpRequest = useMutation({
        mutationFn: () => appointmentApi.requestOtp(draft.customer.email),
    });
    const otpVerify = useMutation({
        mutationFn: () => appointmentApi.verifyOtp(draft.customer.email, otp),
    });
    const booking = useMutation({
        mutationFn: (payload: BookingPayload) => appointmentApi.create(payload),
        retry: false,
    });
    const voucherLookup = useMutation({
        mutationFn: (code: string) => voucherApi.resolve(code),
        retry: false,
    });

    async function applyVoucherCode(code: string): Promise<Voucher> {
        const response = await voucherLookup.mutateAsync(code);

        setResolvedVoucher(response.data);
        setVoucherId(response.data.id);

        return response.data;
    }

    function updateDraft(next: Partial<BookingDraft>) {
        setDraft((current) => ({ ...current, ...next }));
        setMessage("");
    }

    function validateStep(): boolean {
        const next: Record<string, string> = {};
        if (step === 1 && !draft.serviceId) next["service"] = "Vui lòng chọn dịch vụ.";
        if (step === 2 && !draft.doctorId) next["doctor"] = "Vui lòng chọn bác sĩ cụ thể.";
        if (step === 3) {
            if (!draft.date) next["date"] = "Vui lòng chọn ngày khám.";
            if (!draft.time) next["time"] = "Vui lòng chọn một khung giờ còn trống.";
        }
        if (step === 3 && isGuest) {
            if (!draft.customer.name.trim()) next["guest_name"] = "Vui lòng nhập họ và tên.";
            if (!draft.customer.email.trim()) next["guest_email"] = "Vui lòng nhập email.";
            if (!draft.customer.phone.trim()) next["guest_phone"] = "Vui lòng nhập số điện thoại.";
        }
        setErrors(next);
        return Object.keys(next).length === 0;
    }

    function nextStep() {
        if (validateStep()) setStep((current) => Math.min(4, current + 1));
    }

    async function requestOtp() {
        setOtpMessage("");
        setOtpErrors({});
        try {
            await otpRequest.mutateAsync();
            setOtpSent(true);
            setVerificationToken("");
            setOtp("");
            setOtpMessage(
                "Mã OTP đã được gửi. Khi chạy local với MAIL_MAILER=log, hãy xem storage/logs/laravel.log.",
            );
        } catch (reason) {
            setOtpMessage(errorMessage(reason));
            setOtpErrors(firstFieldErrors(reason));
        }
    }

    async function verifyOtpAndBook() {
        setOtpMessage("");
        setOtpErrors({});
        try {
            const result = await otpVerify.mutateAsync();
            setVerificationToken(result.verification_token);
            setOtpMessage("Email đã được xác minh. Đang tạo lịch hẹn...");
            await createAppointment(result.verification_token);
        } catch (reason) {
            setOtpMessage(errorMessage(reason));
            setOtpErrors(firstFieldErrors(reason));
        }
    }

    async function beginBooking() {
        if (!isGuest) {
            await createAppointment();
            return;
        }
        if (verificationToken) {
            await createAppointment(verificationToken);
            return;
        }
        setOtpDialogOpen(true);
        if (!otpSent) await requestOtp();
    }

    async function createAppointment(guestVerificationToken = verificationToken) {
        if (!draft.doctorId || !draft.serviceId || !draft.date || !draft.time) return;
        setMessage("");
        setErrors({});
        const payload: BookingPayload = {
            doctor_id: draft.doctorId,
            service_id: draft.serviceId,
            appointment_date: draft.date,
            start_time: draft.time,
            ...(draft.customer.note.trim() ? { note: draft.customer.note.trim() } : {}),
            ...(!isGuest && voucherId ? { voucher_id: voucherId } : {}),
            ...(isGuest
                ? {
                      guest_name: draft.customer.name.trim(),
                      guest_email: draft.customer.email.trim(),
                      guest_phone: draft.customer.phone.trim(),
                      verification_token: guestVerificationToken,
                  }
                : {}),
        };
        try {
            const response = await booking.mutateAsync(payload);
            bookingResult.set(response.data);
            setOtpDialogOpen(false);
            setCompletedAppointment(response.data);
            await queryClient.invalidateQueries({ queryKey: ["my-vouchers"] });
        } catch (reason) {
            if (
                reason instanceof ApiError &&
                reason.status === 409 &&
                reason.message.toLowerCase().includes("slot")
            ) {
                setDraft((current) => ({ ...current, time: "" }));
                setStep(3);
                setOtpDialogOpen(false);
                setMessage("Khung giờ này vừa được người khác đặt. Vui lòng chọn giờ khác.");
                await slotsQuery.refetch();
                return;
            }
            const nextMessage = errorMessage(reason);
            setMessage(nextMessage);
            setErrors(firstFieldErrors(reason));
            if (otpDialogOpen) setOtpMessage(nextMessage);
        }
    }

    if (authLoading)
        return (
            <Container className="section-space">
                <LoadingState label="Đang kiểm tra phiên đăng nhập..." />
            </Container>
        );
    if (user?.role === "admin")
        return (
            <Container className="section-space">
                <ErrorState message="Tài khoản quản trị không thể tạo lịch hẹn khách hàng." />
                <ButtonLink to="/admin" className="mt-5">
                    Về trang quản trị
                </ButtonLink>
            </Container>
        );

    return (
        <Container className="py-10 md:py-14">
            <div className="mx-auto max-w-5xl">
                <p className="label-luxury text-center">Đặt lịch trực tuyến</p>
                <h1 className="mt-3 text-center text-4xl text-primary md:text-5xl">
                    Chọn lịch hẹn phù hợp
                </h1>
                <Stepper step={step} />
                <div className="card-surface mt-8 p-5 md:p-8">
                    {message && (
                        <p
                            role="status"
                            className={`mb-5 rounded-md p-4 text-sm ${message.includes("đã được") || message.includes("đã xác minh") ? "bg-emerald-50 text-emerald-800" : "bg-amber-50 text-amber-900"}`}
                        >
                            {message}
                        </p>
                    )}
                    {step === 1 && (
                        <ServiceStep
                            services={servicesQuery.data?.data ?? []}
                            loading={servicesQuery.isPending}
                            error={servicesQuery.isError ? errorMessage(servicesQuery.error) : ""}
                            selected={draft.serviceId}
                            choose={(serviceId) =>
                                updateDraft({ serviceId, doctorId: null, date: "", time: "" })
                            }
                        />
                    )}{" "}
                    {step === 2 && (
                        <DoctorStep
                            doctors={doctorsQuery.data?.data ?? []}
                            loading={doctorsQuery.isPending}
                            error={doctorsQuery.isError ? errorMessage(doctorsQuery.error) : ""}
                            selected={draft.doctorId}
                            choose={(doctorId) => updateDraft({ doctorId, date: "", time: "" })}
                        />
                    )}{" "}
                    {step === 3 && (
                        <BookingDetailsStep
                            draft={draft}
                            setDraft={setDraft}
                            slotsQuery={slotsQuery}
                            updateDraft={updateDraft}
                            userName={user?.name}
                            isGuest={isGuest}
                            errors={errors}
                            resetVerification={() => {
                                setVerificationToken("");
                                setOtpSent(false);
                                setOtp("");
                                setOtpDialogOpen(false);
                            }}
                        />
                    )}{" "}
                    {step === 4 && (
                        <Confirmation
                            draft={draft}
                            serviceName={selectedService?.name}
                            doctorName={selectedDoctor?.name}
                            duration={selectedService?.duration}
                            price={selectedService?.price}
                            user={user}
                            vouchers={vouchersQuery.data?.data ?? []}
                            vouchersLoading={vouchersQuery.isPending}
                            voucherId={voucherId}
                            setVoucherId={setVoucherId}
                            resolvedVoucher={resolvedVoucher}
                            applyVoucherCode={applyVoucherCode}
                            voucherLookupPending={voucherLookup.isPending}
                        />
                    )}
                    <div className="mt-8 flex justify-between border-t pt-6">
                        <Button
                            variant="outline"
                            disabled={step === 1 || booking.isPending}
                            onClick={() => setStep((current) => current - 1)}
                        >
                            <ChevronLeft size={17} />
                            Quay lại
                        </Button>
                        {step < 4 ? (
                            <Button onClick={nextStep}>
                                Tiếp tục
                                <ChevronRight size={17} />
                            </Button>
                        ) : (
                            <Button
                                disabled={booking.isPending || otpRequest.isPending}
                                onClick={beginBooking}
                            >
                                {booking.isPending || otpRequest.isPending ? (
                                    <>
                                        <LoaderCircle className="animate-spin" />
                                        {otpRequest.isPending
                                            ? "Đang gửi OTP..."
                                            : "Đang đặt lịch..."}
                                    </>
                                ) : (
                                    "Đặt lịch"
                                )}
                            </Button>
                        )}
                    </div>
                </div>
            </div>
            <OtpDialog
                open={otpDialogOpen}
                setOpen={setOtpDialogOpen}
                email={draft.customer.email}
                otp={otp}
                setOtp={setOtp}
                sent={otpSent}
                message={otpMessage}
                errors={otpErrors}
                requesting={otpRequest.isPending}
                verifying={otpVerify.isPending}
                booking={booking.isPending}
                resend={requestOtp}
                verify={verifyOtpAndBook}
            />
            <BookingSuccessDialog
                appointment={completedAppointment}
                registered={!isGuest}
                finish={(destination) => {
                    bookingResult.clear();
                    setCompletedAppointment(null);
                    void navigate({ to: destination, replace: true });
                }}
            />
        </Container>
    );
}

function Stepper({ step }: { step: number }) {
    return (
        <div className="scrollbar-none mt-8 flex overflow-x-auto pb-2">
            <div className="mx-auto flex min-w-max items-center">
                {steps.map((label, index) => (
                    <div className="flex items-center" key={label}>
                        <div className="grid justify-items-center gap-2">
                            <span
                                className={`grid size-9 place-items-center rounded-full border text-sm font-semibold ${index + 1 <= step ? "border-primary bg-primary text-primary-foreground" : "bg-card text-muted-foreground"}`}
                            >
                                {index + 1 < step ? <Check size={16} /> : index + 1}
                            </span>
                            <span className="text-[11px] text-muted-foreground">{label}</span>
                        </div>
                        {index < steps.length - 1 && (
                            <span
                                className={`mb-5 h-px w-8 md:w-16 ${index + 1 < step ? "bg-primary" : "bg-border"}`}
                            />
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}

function ServiceStep({
    services,
    loading,
    error,
    selected,
    choose,
}: {
    services: Awaited<ReturnType<typeof serviceApi.list>>["data"];
    loading: boolean;
    error: string;
    selected: number | null;
    choose: (id: number) => void;
}) {
    if (loading) return <LoadingState />;
    if (error) return <ErrorState message={error} />;
    return (
        <div className="grid gap-3 md:grid-cols-2">
            {services.map((service) => (
                <button
                    key={service.id}
                    onClick={() => choose(service.id)}
                    className={`rounded-md border p-5 text-left transition ${selected === service.id ? "border-primary bg-muted ring-1 ring-primary" : "bg-card hover:border-secondary"}`}
                >
                    <strong className="text-primary">{service.name}</strong>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {service.duration} phút · {money(service.price)}
                    </p>
                </button>
            ))}
        </div>
    );
}

function DoctorStep({
    doctors,
    loading,
    error,
    selected,
    choose,
}: {
    doctors: Awaited<ReturnType<typeof doctorApi.list>>["data"];
    loading: boolean;
    error: string;
    selected: number | null;
    choose: (id: number) => void;
}) {
    if (loading) return <LoadingState />;
    if (error) return <ErrorState message={error} />;
    if (doctors.length === 0)
        return <ErrorState message="Chưa có bác sĩ đang hoạt động cho dịch vụ này." />;
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {doctors.map((doctor) => (
                <button
                    key={doctor.id}
                    onClick={() => choose(doctor.id)}
                    className={`flex items-center gap-4 rounded-md border p-4 text-left ${selected === doctor.id ? "border-primary bg-muted ring-1 ring-primary" : "bg-card"}`}
                >
                    <img
                        src={doctorImage(doctor)}
                        alt=""
                        className="size-16 rounded-full object-cover"
                    />
                    <span>
                        <strong className="block text-primary">{doctor.name}</strong>
                        <small className="text-muted-foreground">{doctor.specialty}</small>
                    </span>
                </button>
            ))}
        </div>
    );
}

function BookingDetailsStep({
    draft,
    setDraft,
    slotsQuery,
    updateDraft,
    userName,
    isGuest,
    errors,
    resetVerification,
}: {
    draft: BookingDraft;
    setDraft: React.Dispatch<React.SetStateAction<BookingDraft>>;
    slotsQuery: ReturnType<typeof useQuery<Awaited<ReturnType<typeof doctorApi.availableSlots>>>>;
    updateDraft: (next: Partial<BookingDraft>) => void;
    userName?: string | undefined;
    isGuest: boolean;
    errors: Record<string, string>;
    resetVerification: () => void;
}) {
    return (
        <div className="grid gap-8">
            <section aria-labelledby="booking-time-heading" className="grid gap-5">
                <div>
                    <h2 id="booking-time-heading" className="text-xl text-primary">
                        Ngày và giờ khám
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Chọn ngày trước để xem các khung giờ còn trống của bác sĩ.
                    </p>
                </div>
                <Field label="Bạn muốn đến vào ngày nào?" error={errors["date"]}>
                    <span className="relative flex min-h-12 items-center rounded-md border border-input bg-card px-4 text-sm transition focus-within:border-primary focus-within:ring-1 focus-within:ring-primary">
                        <span className={draft.date ? "text-foreground" : "text-muted-foreground"}>
                            {draft.date ? formatDate(draft.date) : "Chọn ngày phù hợp"}
                        </span>
                        <CalendarDays
                            className="ml-auto text-muted-foreground"
                            size={18}
                            aria-hidden="true"
                        />
                        <input
                            type="date"
                            min={localToday()}
                            value={draft.date}
                            aria-label="Bạn muốn đến vào ngày nào?"
                            className="absolute inset-0 size-full cursor-pointer opacity-0"
                            onClick={(event) => event.currentTarget.showPicker()}
                            onChange={(event) =>
                                updateDraft({ date: event.target.value, time: "" })
                            }
                        />
                    </span>
                </Field>
                {draft.date ? (
                    <SlotStep
                        query={slotsQuery}
                        selected={draft.time}
                        choose={(time) => updateDraft({ time })}
                        error={errors["time"]}
                    />
                ) : (
                    <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                        Vui lòng chọn ngày khám để hiển thị khung giờ.
                    </p>
                )}
            </section>

            <section aria-labelledby="booking-contact-heading" className="grid gap-5 border-t pt-8">
                <div>
                    <h2 id="booking-contact-heading" className="text-xl text-primary">
                        Thông tin liên hệ
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Điền thông tin để chúng tôi xác nhận lịch hẹn với bạn.
                    </p>
                </div>
                <ContactStep
                    draft={draft}
                    setDraft={setDraft}
                    userName={userName}
                    isGuest={isGuest}
                    errors={errors}
                    resetVerification={resetVerification}
                />
            </section>
        </div>
    );
}

function SlotStep({
    query,
    selected,
    choose,
    error,
}: {
    query: ReturnType<typeof useQuery<Awaited<ReturnType<typeof doctorApi.availableSlots>>>>;
    selected: string;
    choose: (time: string) => void;
    error?: string | undefined;
}) {
    if (query.isPending) return <LoadingState label="Đang kiểm tra lịch trống từ hệ thống..." />;
    if (query.isError)
        return <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />;
    const slots = query.data.data.slots;
    if (slots.length === 0)
        return (
            <ErrorState message="Ngày này chưa có khung giờ phù hợp. Vui lòng chọn ngày khác." />
        );
    return (
        <div>
            <p className="mb-4 text-sm text-muted-foreground">Các khung giờ còn trống.</p>
            <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
                {slots.map((time) => (
                    <button
                        key={time}
                        onClick={() => choose(time)}
                        className={`rounded-md border p-3 text-sm ${selected === time ? "bg-primary text-primary-foreground" : "bg-card hover:border-secondary"}`}
                    >
                        {time}
                        {selected === time && <Check size={14} className="ml-1 inline" />}
                    </button>
                ))}
            </div>
            {error && (
                <p role="alert" className="mt-3 text-sm text-red-700">
                    {error}
                </p>
            )}
        </div>
    );
}

function ContactStep({
    draft,
    setDraft,
    userName,
    isGuest,
    errors,
    resetVerification,
}: {
    draft: BookingDraft;
    setDraft: React.Dispatch<React.SetStateAction<BookingDraft>>;
    userName?: string | undefined;
    isGuest: boolean;
    errors: Record<string, string>;
    resetVerification: () => void;
}) {
    const update = (key: keyof BookingDraft["customer"], value: string) => {
        setDraft((current) => ({ ...current, customer: { ...current.customer, [key]: value } }));
        if (key === "email") resetVerification();
    };
    if (!isGuest)
        return (
            <div className="grid gap-5">
                <Field label="Bạn có muốn nhắn gì thêm? (không bắt buộc)">
                    <Textarea
                        value={draft.customer.note}
                        onChange={(event) => update("note", event.target.value)}
                        placeholder={notePlaceholder}
                        maxLength={5000}
                    />
                </Field>
            </div>
        );
    return (
        <div className="grid gap-5">
            <Field label="Họ và tên *" error={errors["guest_name"]}>
                <Input
                    value={draft.customer.name}
                    onChange={(event) => update("name", event.target.value)}
                    maxLength={255}
                />
            </Field>
            <Field label="Email *" error={errors["guest_email"]}>
                <Input
                    value={draft.customer.email}
                    onChange={(event) => update("email", event.target.value)}
                    type="email"
                    maxLength={255}
                />
            </Field>
            <Field label="Số điện thoại *" error={errors["guest_phone"]}>
                <Input
                    value={draft.customer.phone}
                    onChange={(event) => update("phone", event.target.value)}
                    inputMode="tel"
                    maxLength={30}
                />
            </Field>
            <Field label="Bạn có muốn nhắn gì thêm? (không bắt buộc)">
                <Textarea
                    value={draft.customer.note}
                    onChange={(event) => update("note", event.target.value)}
                    placeholder={notePlaceholder}
                    maxLength={5000}
                />
            </Field>
        </div>
    );
}

function OtpDialog({
    open,
    setOpen,
    email,
    otp,
    setOtp,
    sent,
    message,
    errors,
    requesting,
    verifying,
    booking,
    resend,
    verify,
}: {
    open: boolean;
    setOpen: (open: boolean) => void;
    email: string;
    otp: string;
    setOtp: (value: string) => void;
    sent: boolean;
    message: string;
    errors: Record<string, string>;
    requesting: boolean;
    verifying: boolean;
    booking: boolean;
    resend: () => void;
    verify: () => void;
}) {
    const busy = requesting || verifying || booking;

    return (
        <Dialog open={open} onOpenChange={(nextOpen) => !busy && setOpen(nextOpen)}>
            <DialogContent className="w-[calc(100%-2rem)] max-w-md rounded-xl p-5 sm:p-7">
                <DialogHeader className="items-center text-center">
                    <span className="grid size-12 place-items-center rounded-full bg-muted text-primary">
                        <Mail size={22} />
                    </span>
                    <DialogTitle className="pt-2 text-xl text-primary">Xác minh email</DialogTitle>
                    <DialogDescription className="leading-6">
                        Nhập mã OTP 6 chữ số đã gửi đến{" "}
                        <strong className="break-all text-foreground">{email}</strong>.
                    </DialogDescription>
                </DialogHeader>

                {requesting ? (
                    <div className="flex items-center justify-center gap-2 py-8 text-sm text-muted-foreground">
                        <LoaderCircle className="animate-spin" size={20} />
                        Đang gửi mã OTP...
                    </div>
                ) : (
                    <div className="grid justify-items-center gap-4 py-3">
                        <InputOTP
                            maxLength={6}
                            value={otp}
                            onChange={setOtp}
                            disabled={!sent || busy}
                            autoFocus
                            inputMode="numeric"
                            aria-label="Mã OTP 6 chữ số"
                        >
                            <InputOTPGroup>
                                {Array.from({ length: 6 }, (_, index) => (
                                    <InputOTPSlot
                                        key={index}
                                        index={index}
                                        className="size-11 text-base sm:size-12"
                                    />
                                ))}
                            </InputOTPGroup>
                        </InputOTP>
                        {message && (
                            <p role="status" className="text-center text-sm text-muted-foreground">
                                {message}
                            </p>
                        )}
                        {(errors["otp"] || errors["email"]) && (
                            <p role="alert" className="text-center text-sm text-red-700">
                                {errors["otp"] || errors["email"]}
                            </p>
                        )}
                    </div>
                )}

                <DialogFooter className="gap-3 sm:justify-between sm:space-x-0">
                    <Button variant="outline" disabled={busy} onClick={resend}>
                        {sent ? "Gửi lại OTP" : "Gửi OTP"}
                    </Button>
                    <Button disabled={!sent || otp.length !== 6 || busy} onClick={verify}>
                        {verifying || booking ? (
                            <>
                                <LoaderCircle className="animate-spin" size={17} />
                                {booking ? "Đang đặt lịch..." : "Đang xác minh..."}
                            </>
                        ) : (
                            "Xác minh & đặt lịch"
                        )}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function BookingSuccessDialog({
    appointment,
    registered,
    finish,
}: {
    appointment: Appointment | null;
    registered: boolean;
    finish: (destination: "/" | "/account" | "/appointment-lookup" | "/booking") => void;
}) {
    if (!appointment) return null;

    const defaultDestination = registered ? "/account" : "/";

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) finish(defaultDestination);
            }}
        >
            <DialogContent className="max-h-[calc(100vh-2rem)] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-2xl p-0">
                <div className="relative overflow-hidden bg-primary px-6 py-8 text-center text-primary-foreground sm:px-9">
                    <div className="absolute -right-8 -top-10 size-28 rounded-full bg-white/10" />
                    <div className="absolute -bottom-12 -left-8 size-32 rounded-full bg-white/10" />
                    <span className="relative mx-auto grid size-16 place-items-center rounded-full bg-white text-primary shadow-lg">
                        {registered ? <CheckCircle2 size={34} /> : <Sparkles size={32} />}
                    </span>
                    <DialogHeader className="relative mt-4 items-center text-center">
                        <DialogTitle className="text-2xl text-white sm:text-3xl">
                            {registered ? "Đặt lịch thành công!" : "Một cuộc hẹn đẹp đã bắt đầu!"}
                        </DialogTitle>
                        <DialogDescription className="max-w-md text-sm leading-6 text-white/80">
                            {registered
                                ? "Lịch hẹn đã được lưu. Bạn có thể theo dõi và quản lý lịch trong trang tài khoản."
                                : "Cảm ơn bạn đã tin chọn Junie. Chúng tôi rất mong được đón tiếp và đồng hành cùng bạn trong buổi hẹn sắp tới."}
                        </DialogDescription>
                    </DialogHeader>
                </div>

                <div className="grid gap-5 p-6 sm:p-8">
                    {!registered && appointment.booking_code && (
                        <div className="rounded-xl border border-secondary/40 bg-secondary/10 p-4 text-center">
                            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                                Mã lịch hẹn của bạn
                            </p>
                            <strong className="mt-2 block break-all text-2xl tracking-wider text-primary">
                                {appointment.booking_code}
                            </strong>
                            <p className="mt-2 text-xs text-muted-foreground">
                                Hãy lưu lại mã này để tra cứu hoặc quản lý lịch hẹn sau này.
                            </p>
                        </div>
                    )}

                    <div className="rounded-xl border bg-muted/40 p-5">
                        <div className="mb-4 flex items-center gap-2 text-primary">
                            <CalendarDays size={19} />
                            <h3 className="font-semibold">Thông tin lịch hẹn</h3>
                        </div>
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            <SuccessInfo label="Dịch vụ" value={appointment.service.name} />
                            <SuccessInfo label="Bác sĩ" value={appointment.doctor.name} />
                            <SuccessInfo
                                label="Ngày khám"
                                value={formatDate(appointment.appointment_date)}
                            />
                            <SuccessInfo
                                label="Khung giờ"
                                value={`${appointment.start_time}–${appointment.end_time}`}
                            />
                            <div className="grid gap-1 sm:col-span-2">
                                <dt className="text-xs text-muted-foreground">Trạng thái</dt>
                                <dd>
                                    <StatusBadge status={appointment.status} />
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <DialogFooter className="gap-3 sm:justify-center sm:space-x-0">
                        {registered ? (
                            <Button className="w-full sm:w-auto" onClick={() => finish("/account")}>
                                Về trang tài khoản
                                <ChevronRight size={17} />
                            </Button>
                        ) : (
                            <>
                                <Button
                                    variant="outline"
                                    className="w-full sm:w-auto"
                                    onClick={() => finish("/")}
                                >
                                    Về trang chủ
                                </Button>
                                <Button
                                    className="w-full sm:w-auto"
                                    onClick={() => finish("/appointment-lookup")}
                                >
                                    Tra cứu lịch hẹn
                                    <ChevronRight size={17} />
                                </Button>
                                <Button
                                    variant="outline"
                                    className="w-full sm:w-auto"
                                    onClick={() => finish("/booking")}
                                >
                                    Đặt lịch mới
                                </Button>
                            </>
                        )}
                    </DialogFooter>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function SuccessInfo({ label, value }: { label: string; value: string }) {
    return (
        <div className="grid gap-1">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-semibold text-primary">{value}</dd>
        </div>
    );
}

function Confirmation({
    draft,
    serviceName,
    doctorName,
    duration,
    price,
    user,
    vouchers,
    vouchersLoading,
    voucherId,
    setVoucherId,
    resolvedVoucher,
    applyVoucherCode,
    voucherLookupPending,
}: {
    draft: BookingDraft;
    serviceName?: string | undefined;
    doctorName?: string | undefined;
    duration?: number | undefined;
    price?: string | undefined;
    user: User | null;
    vouchers: Voucher[];
    vouchersLoading: boolean;
    voucherId: number | null;
    setVoucherId: (id: number | null) => void;
    resolvedVoucher: Voucher | null;
    applyVoucherCode: (code: string) => Promise<Voucher>;
    voucherLookupPending: boolean;
}) {
    const [voucherCode, setVoucherCode] = useState("");
    const [voucherCodeError, setVoucherCodeError] = useState("");
    const [voucherCodeMessage, setVoucherCodeMessage] = useState("");
    const availableVouchers =
        resolvedVoucher && !vouchers.some((voucher) => voucher.id === resolvedVoucher.id)
            ? [resolvedVoucher, ...vouchers]
            : vouchers;
    const selectedVoucher = availableVouchers.find((voucher) => voucher.id === voucherId);
    const originalPrice = Number(price ?? 0);
    const discount = selectedVoucher
        ? Math.round((originalPrice * Number(selectedVoucher.value)) / 100)
        : 0;

    async function submitVoucherCode(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const normalizedCode = voucherCode.trim();

        setVoucherCodeError("");
        setVoucherCodeMessage("");

        if (!normalizedCode) {
            setVoucherCodeError("Vui lòng nhập mã voucher.");
            return;
        }

        try {
            const voucher = await applyVoucherCode(normalizedCode);
            setVoucherCode(voucher.code);
            setVoucherCodeMessage(`Đã áp dụng mã ${voucher.code}.`);
        } catch (reason) {
            const fieldErrors = firstFieldErrors(reason);
            setVoucherCodeError(fieldErrors["code"] ?? errorMessage(reason));
        }
    }

    return (
        <div>
            <div className="rounded-md bg-muted p-5">
                <SummaryRow label="Dịch vụ" value={serviceName} />
                <SummaryRow label="Bác sĩ" value={doctorName} />
                <SummaryRow label="Ngày" value={draft.date} />
                <SummaryRow label="Giờ" value={draft.time} />
                <SummaryRow label="Thời lượng" value={duration ? `${duration} phút` : undefined} />
                <SummaryRow label="Giá dự kiến" value={price ? money(price) : undefined} />
                {selectedVoucher && (
                    <>
                        <SummaryRow
                            label={`Voucher ${Number(selectedVoucher.value)}%`}
                            value={`- ${money(String(discount))}`}
                        />
                        <SummaryRow
                            label="Tạm tính sau ưu đãi"
                            value={money(String(Math.max(0, originalPrice - discount)))}
                        />
                    </>
                )}
            </div>
            {user?.role === "customer" && (
                <section className="relative mt-5 overflow-hidden rounded-2xl border border-blue-200/80 bg-[radial-gradient(circle_at_top_right,_rgba(96,165,250,0.22),_transparent_40%),linear-gradient(135deg,_#f8fbff,_#edf6ff)] p-4 shadow-[0_12px_32px_rgba(37,99,235,0.08)] sm:p-5">
                    <div className="pointer-events-none absolute -right-8 -top-10 size-36 rounded-full bg-blue-200/30 blur-2xl" />
                    <Gift className="pointer-events-none absolute right-5 top-5 hidden size-16 rotate-6 text-blue-400/25 sm:block" />

                    <div className="relative flex items-start gap-3 pr-0 sm:pr-24">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-600 shadow-sm">
                            <Gift className="size-5" aria-hidden="true" />
                        </span>
                        <div>
                            <h3 className="font-semibold text-primary">Ưu đãi</h3>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                Nhập mã hoặc chọn một voucher còn hiệu lực cho lịch hẹn này.
                            </p>
                        </div>
                    </div>

                    <form
                        className="relative mt-4 rounded-xl border border-blue-100 bg-white/85 p-4"
                        onSubmit={submitVoucherCode}
                    >
                        <label
                            className="text-sm font-semibold text-primary"
                            htmlFor="booking-voucher-code"
                        >
                            Nhập mã voucher
                        </label>
                        <div className="mt-2 flex flex-col gap-2 sm:flex-row">
                            <Input
                                id="booking-voucher-code"
                                value={voucherCode}
                                onChange={(event) => {
                                    setVoucherCode(event.target.value.toUpperCase());
                                    setVoucherCodeError("");
                                    setVoucherCodeMessage("");
                                }}
                                placeholder="Ví dụ: RVW-ABCD-EFGH"
                                autoComplete="off"
                                aria-invalid={Boolean(voucherCodeError)}
                                aria-describedby="booking-voucher-code-feedback"
                                className="font-medium uppercase tracking-wide sm:flex-1"
                            />
                            <Button
                                type="submit"
                                disabled={voucherLookupPending}
                                className="w-full sm:w-auto"
                            >
                                {voucherLookupPending && (
                                    <LoaderCircle
                                        className="size-4 animate-spin"
                                        aria-hidden="true"
                                    />
                                )}
                                Áp dụng
                            </Button>
                        </div>
                        <p
                            id="booking-voucher-code-feedback"
                            role={voucherCodeError ? "alert" : "status"}
                            className={`mt-2 min-h-5 text-xs ${voucherCodeError ? "text-red-700" : voucherCodeMessage ? "text-emerald-700" : "text-muted-foreground"}`}
                        >
                            {voucherCodeError ||
                                voucherCodeMessage ||
                                "Mã chỉ áp dụng được khi thuộc tài khoản của bạn và còn hiệu lực."}
                        </p>
                    </form>

                    <div className="relative my-4 flex items-center gap-3" aria-hidden="true">
                        <span className="h-px flex-1 bg-blue-200/80" />
                        <span className="text-xs font-medium text-slate-500">
                            Hoặc chọn voucher của bạn
                        </span>
                        <span className="h-px flex-1 bg-blue-200/80" />
                    </div>

                    {vouchersLoading ? (
                        <div className="relative flex min-h-24 items-center justify-center rounded-xl border border-blue-100 bg-white/75 text-sm text-muted-foreground">
                            <LoaderCircle className="mr-2 size-4 animate-spin" aria-hidden="true" />
                            Đang tải ưu đãi...
                        </div>
                    ) : availableVouchers.length === 0 ? (
                        <div className="relative flex items-start gap-3 rounded-xl border border-dashed border-blue-200 bg-white/75 p-4">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500">
                                <Gift className="size-4" aria-hidden="true" />
                            </span>
                            <div className="min-w-0">
                                <p className="text-sm font-medium text-primary">
                                    Bạn chưa có voucher khả dụng
                                </p>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    Nếu bạn có mã voucher, hãy nhập vào ô phía trên để kiểm tra và
                                    áp dụng.
                                </p>
                            </div>
                        </div>
                    ) : (
                        <div className="relative grid max-h-80 gap-3 overflow-y-auto pr-1 sm:grid-cols-2">
                            <button
                                type="button"
                                aria-pressed={voucherId === null}
                                onClick={() => setVoucherId(null)}
                                className={`focus-premium flex min-h-24 items-center gap-3 rounded-xl border p-4 text-left transition ${voucherId === null ? "border-blue-500 bg-blue-50/90 ring-1 ring-blue-400 shadow-sm" : "border-slate-200 bg-white/90 hover:border-blue-300 hover:bg-white"}`}
                            >
                                <VoucherSelector selected={voucherId === null} />
                                <span>
                                    <strong className="block text-sm text-primary">
                                        Không dùng voucher
                                    </strong>
                                    <span className="mt-1 block text-xs text-muted-foreground">
                                        Không áp dụng ưu đãi cho lịch hẹn này.
                                    </span>
                                </span>
                            </button>

                            {availableVouchers.map((voucher) => {
                                const selected = voucherId === voucher.id;

                                return (
                                    <button
                                        key={voucher.id}
                                        type="button"
                                        aria-pressed={selected}
                                        onClick={() => setVoucherId(voucher.id)}
                                        className={`focus-premium flex min-h-24 items-center gap-3 rounded-xl border p-4 text-left transition ${selected ? "border-blue-500 bg-blue-50/90 ring-1 ring-blue-400 shadow-sm" : "border-slate-200 bg-white/90 hover:border-blue-300 hover:bg-white"}`}
                                    >
                                        <VoucherSelector selected={selected} />
                                        <span className="min-w-0">
                                            <strong className="block text-sm text-primary">
                                                Giảm {Number(voucher.value)}%
                                            </strong>
                                            <span className="mt-1 block truncate text-xs text-muted-foreground">
                                                {voucher.source === "loyalty_milestone"
                                                    ? `Thưởng thành viên · Mốc ${voucher.milestone ?? voucher.source_id} lần`
                                                    : "Thưởng đánh giá"}
                                            </span>
                                            <span className="mt-1 flex items-center gap-1 text-xs text-slate-500">
                                                <Clock3 className="size-3.5" aria-hidden="true" />
                                                Hạn{" "}
                                                {new Date(voucher.expires_at).toLocaleDateString(
                                                    "vi-VN",
                                                )}
                                            </span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </section>
            )}
            <div className="mt-5 rounded-md border p-5">
                <h3 className="text-lg text-primary">Thông tin khách hàng</h3>
                <dl className="mt-4 grid gap-3 text-sm">
                    <CustomerInfoRow label="Họ và tên" value={user?.name || draft.customer.name} />
                    <CustomerInfoRow
                        label="Số điện thoại"
                        value={user?.phone || draft.customer.phone || "Chưa cập nhật"}
                    />
                    <CustomerInfoRow label="Email" value={user?.email || draft.customer.email} />
                    {draft.customer.note && (
                        <CustomerInfoRow label="Ghi chú" value={draft.customer.note} />
                    )}
                </dl>
            </div>
        </div>
    );
}

function VoucherSelector({ selected }: { selected: boolean }) {
    return (
        <span
            className={`flex size-7 shrink-0 items-center justify-center rounded-full border-2 transition ${selected ? "border-blue-600 bg-blue-600 text-white shadow-sm" : "border-slate-300 bg-white"}`}
            aria-hidden="true"
        >
            {selected && <Check className="size-4" strokeWidth={3} />}
        </span>
    );
}

function CustomerInfoRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="grid gap-1 sm:grid-cols-[9rem_1fr] sm:gap-4">
            <dt className="font-medium text-muted-foreground">{label}:</dt>
            <dd className="break-words text-foreground sm:text-right">{value}</dd>
        </div>
    );
}

function SummaryRow({ label, value }: { label: string; value?: string | undefined }) {
    return (
        <div className="flex justify-between gap-5 border-b py-4 text-sm last:border-0">
            <span className="text-muted-foreground">{label}</span>
            <strong className="text-right text-primary">{value || "Chưa chọn"}</strong>
        </div>
    );
}

function localToday(): string {
    const now = new Date();
    const offset = now.getTimezoneOffset() * 60_000;
    return new Date(now.getTime() - offset).toISOString().slice(0, 10);
}
