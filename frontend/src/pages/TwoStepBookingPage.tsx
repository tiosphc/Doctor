import { useEffect, useRef, useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate } from "@tanstack/react-router";
import {
    ArrowLeft,
    ArrowRight,
    CalendarDays,
    Check,
    Clock3,
    LoaderCircle,
    Mail,
    Stethoscope,
    Tag,
    UserRound,
} from "lucide-react";
import { Container } from "@/components/common/Container";
import { Input, Textarea } from "@/components/common/Fields";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { InputOTP, InputOTPGroup, InputOTPSlot } from "@/components/ui/input-otp";
import { useAuth } from "@/contexts/AuthContext";
import { bookingResult } from "@/lib/booking-result";
import { ApiError, errorMessage, firstFieldErrors } from "@/services/api";
import { appointmentApi, type BookingPayload } from "@/services/appointmentApi";
import { doctorApi } from "@/services/doctorApi";
import { voucherApi } from "@/services/reviewVoucherApi";
import { serviceApi } from "@/services/serviceApi";
import type { Doctor, Service, Voucher } from "@/types";

type Contact = { name: string; phone: string; email: string; note: string };
type ContactErrors = Partial<Record<keyof Contact, string | undefined>>;
const emptyContact: Contact = { name: "", phone: "", email: "", note: "" };
const slotConflictMessage = "Khung giờ này vừa được đặt. Vui lòng chọn thời gian khác.";

function localToday(): string {
    const now = new Date();
    const offset = now.getTimezoneOffset() * 60_000;
    return new Date(now.getTime() - offset).toISOString().slice(0, 10);
}

function normalizePhone(phone: string): string {
    const digits = phone.replace(/\D/g, "");
    return digits.startsWith("84") && digits.length === 11 ? `0${digits.slice(2)}` : digits;
}

function validateContact(contact: Contact): ContactErrors {
    const errors: ContactErrors = {};
    if (!contact.name.trim()) errors.name = "Vui lòng nhập họ và tên.";
    else if (contact.name.trim().length > 255) errors.name = "Họ và tên không được quá 255 ký tự.";
    if (!/^0[0-9]{9,10}$/.test(normalizePhone(contact.phone))) {
        errors.phone = "Vui lòng nhập số điện thoại Việt Nam hợp lệ.";
    }
    if (!contact.email.trim()) errors.email = "Email cần thiết để nhận mã xác minh OTP.";
    else if (
        !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contact.email.trim()) ||
        contact.email.trim().length > 255
    ) {
        errors.email = "Vui lòng nhập địa chỉ email hợp lệ.";
    }
    if (contact.note.length > 5000) errors.note = "Ghi chú không được quá 5.000 ký tự.";
    return errors;
}

function referencePrice(service: Service | undefined): string {
    const amount = Number(service?.price);
    return service?.price && Number.isFinite(amount) && amount >= 0
        ? `${new Intl.NumberFormat("vi-VN").format(amount)} ₫`
        : "Liên hệ báo giá";
}

function dateLabel(date: string): string {
    return new Intl.DateTimeFormat("vi-VN", {
        weekday: "long",
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
    }).format(new Date(`${date}T00:00:00`));
}

export function TwoStepBookingPage({ initialServiceId }: { initialServiceId?: number }) {
    const { user, isLoading: authLoading } = useAuth();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [step, setStep] = useState<1 | 2>(1);
    const [serviceId, setServiceId] = useState<number | null>(initialServiceId ?? null);
    const [date, setDate] = useState("");
    const [time, setTime] = useState("");
    const [contact, setContact] = useState<Contact>(emptyContact);
    const [errors, setErrors] = useState<ContactErrors>({});
    const [notice, setNotice] = useState("");
    const [otpOpen, setOtpOpen] = useState(false);
    const [otpSent, setOtpSent] = useState(false);
    const [otp, setOtp] = useState("");
    const [otpMessage, setOtpMessage] = useState("");
    const [verificationToken, setVerificationToken] = useState("");
    const [verifiedEmail, setVerifiedEmail] = useState("");
    const [voucherId, setVoucherId] = useState<number | null>(null);
    const [voucherCode, setVoucherCode] = useState("");
    const [voucherError, setVoucherError] = useState("");
    const [resolvedVoucher, setResolvedVoucher] = useState<Voucher | null>(null);
    const [checkingAvailability, setCheckingAvailability] = useState(false);
    const headingRef = useRef<HTMLHeadingElement>(null);
    const hasMounted = useRef(false);

    useEffect(() => {
        if (hasMounted.current) headingRef.current?.focus();
        else hasMounted.current = true;
    }, [step]);

    const servicesQuery = useQuery({
        queryKey: ["services", { booking: true }],
        queryFn: () => serviceApi.list(),
    });
    const listedService = servicesQuery.data?.data.find((item) => item.id === serviceId);
    const selectedServiceQuery = useQuery({
        queryKey: ["service", serviceId],
        queryFn: () => serviceApi.find(serviceId!),
        enabled: Boolean(serviceId && servicesQuery.isSuccess && !listedService),
    });
    const service = listedService ?? selectedServiceQuery.data?.data;
    const serviceOptions = [
        ...(service && !listedService ? [service] : []),
        ...(servicesQuery.data?.data ?? []),
    ];
    const doctorsQuery = useQuery({
        queryKey: ["doctors", { service_id: serviceId, booking: true }],
        queryFn: () => doctorApi.list({ service_id: serviceId ?? undefined, booking: true }),
        enabled: Boolean(service),
    });
    const doctor = doctorsQuery.data?.data[0];
    const slotsQuery = useQuery({
        queryKey: ["available-slots", doctor?.id, serviceId, date],
        queryFn: () => doctorApi.availableSlots(doctor!.id, serviceId!, date),
        enabled: Boolean(doctor && serviceId && date),
        retry: false,
    });
    const vouchersQuery = useQuery({
        queryKey: ["my-vouchers", { status: "active", booking: true }],
        queryFn: () => voucherApi.mine({ status: "active", per_page: 100 }),
        enabled: user?.role === "customer",
    });
    const voucherLookup = useMutation({ mutationFn: (code: string) => voucherApi.resolve(code) });
    const otpRequest = useMutation({
        mutationFn: (email: string) => appointmentApi.requestOtp(email),
    });
    const otpVerify = useMutation({
        mutationFn: (payload: { email: string; otp: string }) =>
            appointmentApi.verifyOtp(payload.email, payload.otp),
    });
    const booking = useMutation({
        mutationFn: (payload: BookingPayload) => appointmentApi.create(payload),
        retry: false,
    });
    const busy =
        checkingAvailability || otpRequest.isPending || otpVerify.isPending || booking.isPending;
    const isGuest = !user;
    const availableVouchers = [
        ...(resolvedVoucher &&
        !vouchersQuery.data?.data.some((item) => item.id === resolvedVoucher.id)
            ? [resolvedVoucher]
            : []),
        ...(vouchersQuery.data?.data ?? []),
    ];

    function updateContact(field: keyof Contact, value: string): void {
        setContact((current) => ({ ...current, [field]: value }));
        setErrors((current) => ({ ...current, [field]: undefined }));
        setNotice("");
        if (field === "email") {
            setVerificationToken("");
            setVerifiedEmail("");
            setOtpSent(false);
            setOtp("");
        }
    }

    function chooseService(nextId: number): void {
        setServiceId(nextId);
        setDate("");
        setTime("");
        setNotice("");
    }

    function continueToContact(): void {
        if (!serviceId || !service) return setNotice("Vui lòng chọn dịch vụ.");
        if (!doctor) return setNotice("Chưa có bác sĩ phù hợp để đặt dịch vụ này.");
        if (!date || date < localToday()) return setNotice("Vui lòng chọn ngày hẹn hợp lệ.");
        if (!time || !slotsQuery.data?.data.slots.includes(time))
            return setNotice("Vui lòng chọn khung giờ còn trống.");
        setNotice("");
        setStep(2);
    }

    async function applyVoucher(): Promise<void> {
        setVoucherError("");
        const code = voucherCode.trim().toUpperCase();
        if (!code) return setVoucherError("Vui lòng nhập mã voucher.");
        try {
            const response = await voucherLookup.mutateAsync(code);
            setResolvedVoucher(response.data);
            setVoucherId(response.data.id);
            setVoucherCode(response.data.code);
        } catch (reason) {
            setVoucherError(firstFieldErrors(reason)["code"] ?? errorMessage(reason));
        }
    }

    async function createAppointment(token = verificationToken): Promise<void> {
        if (!serviceId || !doctor || !date || !time) return;
        const payload: BookingPayload = {
            doctor_id: doctor.id,
            service_id: serviceId,
            appointment_date: date,
            start_time: time,
            ...(contact.note.trim() ? { note: contact.note.trim() } : {}),
            ...(!isGuest && voucherId ? { voucher_id: voucherId } : {}),
            ...(isGuest
                ? {
                      guest_name: contact.name.trim(),
                      guest_phone: normalizePhone(contact.phone),
                      guest_email: contact.email.trim().toLowerCase(),
                      verification_token: token,
                  }
                : {}),
        };
        let response: Awaited<ReturnType<typeof appointmentApi.create>>;
        try {
            response = await booking.mutateAsync(payload);
        } catch (reason) {
            setOtpOpen(false);
            if (
                reason instanceof ApiError &&
                reason.status === 409 &&
                /slot|khung|time|appointment/i.test(reason.message)
            ) {
                setTime("");
                setStep(1);
                setNotice(slotConflictMessage);
                await slotsQuery.refetch();
                return;
            }
            setNotice(errorMessage(reason));
            const fieldErrors = firstFieldErrors(reason);
            setErrors({
                name: fieldErrors["guest_name"],
                phone: fieldErrors["guest_phone"],
                email: fieldErrors["guest_email"],
                note: fieldErrors["note"],
            });
            return;
        }
        bookingResult.set(response.data);
        setOtpOpen(false);
        void queryClient.invalidateQueries({ queryKey: ["my-vouchers"] });
        void queryClient.invalidateQueries({ queryKey: ["my-appointments"] });
        await navigate({ to: "/booking/success" });
    }

    async function submit(event: FormEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();
        if (busy) return;
        const nextErrors = isGuest
            ? validateContact(contact)
            : {
                  note:
                      contact.note.length > 5000
                          ? "Ghi chú không được quá 5.000 ký tự."
                          : undefined,
              };
        setErrors(nextErrors);
        if (Object.values(nextErrors).some(Boolean)) {
            const firstInvalid = (["name", "phone", "email", "note"] as const).find(
                (field) => nextErrors[field],
            );
            if (firstInvalid) document.getElementById(`booking-${firstInvalid}`)?.focus();
            return;
        }
        if (!service || !doctor || !date || !time) {
            setStep(1);
            setNotice("Vui lòng chọn lại dịch vụ và khung giờ.");
            return;
        }
        setNotice("");
        setCheckingAvailability(true);
        try {
            const refreshed = await slotsQuery.refetch();
            if (refreshed.isError) throw refreshed.error;
            if (!refreshed.data?.data.slots.includes(time)) {
                setTime("");
                setStep(1);
                setNotice(slotConflictMessage);
                return;
            }
        } catch (reason) {
            setNotice(errorMessage(reason));
            return;
        } finally {
            setCheckingAvailability(false);
        }
        if (!isGuest) return createAppointment();
        const email = contact.email.trim().toLowerCase();
        if (verificationToken && verifiedEmail === email)
            return createAppointment(verificationToken);
        setOtpOpen(true);
        setOtpMessage("");
        setOtp("");
        try {
            await otpRequest.mutateAsync(email);
            setOtpSent(true);
            setOtpMessage("Mã OTP đã được gửi đến email của bạn.");
        } catch (reason) {
            setOtpSent(false);
            setOtpMessage(errorMessage(reason));
        }
    }

    async function resendOtp(): Promise<void> {
        setOtpMessage("");
        try {
            await otpRequest.mutateAsync(contact.email.trim().toLowerCase());
            setOtpSent(true);
            setOtp("");
            setOtpMessage("Mã OTP mới đã được gửi.");
        } catch (reason) {
            setOtpMessage(errorMessage(reason));
        }
    }

    async function verifyOtp(): Promise<void> {
        if (!/^\d{6}$/.test(otp) || busy) return;
        setOtpMessage("");
        let token: string;
        try {
            const email = contact.email.trim().toLowerCase();
            const response = await otpVerify.mutateAsync({ email, otp });
            token = response.verification_token;
            setVerificationToken(token);
            setVerifiedEmail(email);
        } catch (reason) {
            setOtpMessage(firstFieldErrors(reason)["otp"] ?? errorMessage(reason));
            return;
        }
        await createAppointment(token);
    }

    if (authLoading)
        return (
            <Container className="py-12">
                <p role="status">Đang kiểm tra phiên đăng nhập...</p>
            </Container>
        );
    if (user && user.role !== "customer") {
        return (
            <Container className="py-12">
                <p role="alert">Tài khoản này không thể tạo lịch hẹn khách hàng.</p>
            </Container>
        );
    }

    return (
        <Container className="px-5 py-8 sm:px-6 md:py-12">
            <div className="mx-auto max-w-[1080px]">
                <div className="text-center">
                    <p className="eyebrow text-mocha">ĐẶT LỊCH TRỰC TUYẾN</p>
                    <h1
                        ref={headingRef}
                        tabIndex={-1}
                        className="mt-3 text-[clamp(2.25rem,5vw,3.5rem)] leading-tight text-espresso focus:outline-none"
                    >
                        {step === 1 ? "Chọn dịch vụ và lịch hẹn" : "Hoàn tất lịch hẹn của bạn"}
                    </h1>
                    <p className="mx-auto mt-2 max-w-xl text-sm leading-6 text-muted-foreground">
                        {step === 1
                            ? "Chọn dịch vụ và thời gian phù hợp với bạn."
                            : "Vui lòng cung cấp thông tin để Junie có thể liên hệ và xác nhận lịch hẹn."}
                    </p>
                </div>
                <BookingProgress step={step} />
                {notice && (
                    <p
                        role="alert"
                        className="mt-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                    >
                        {notice}
                    </p>
                )}

                {step === 1 ? (
                    <div className="mt-6 grid gap-5">
                        <section
                            className="rounded-md border border-border bg-ivory p-5 shadow-sm sm:p-7"
                            aria-labelledby="service-heading"
                        >
                            <h2 id="service-heading" className="text-2xl text-espresso">
                                01 · Chọn dịch vụ
                            </h2>
                            {servicesQuery.isPending && (
                                <p role="status" className="mt-5 text-sm">
                                    Đang tải dịch vụ...
                                </p>
                            )}
                            {servicesQuery.isError && (
                                <p role="alert" className="mt-5 text-sm text-red-700">
                                    {errorMessage(servicesQuery.error)}
                                </p>
                            )}
                            {servicesQuery.isSuccess && servicesQuery.data.data.length === 0 && (
                                <p className="mt-5 text-sm">Hiện chưa có dịch vụ để đặt lịch.</p>
                            )}
                            {selectedServiceQuery.isError && (
                                <p role="alert" className="mt-5 text-sm text-red-700">
                                    {errorMessage(selectedServiceQuery.error)}
                                </p>
                            )}
                            <div className="mt-5 grid gap-3 sm:grid-cols-2">
                                {serviceOptions.map((item) => (
                                    <button
                                        key={item.id}
                                        type="button"
                                        onClick={() => chooseService(item.id)}
                                        aria-pressed={serviceId === item.id}
                                        className={`min-h-20 rounded-md border p-4 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${serviceId === item.id ? "border-mocha bg-cream" : "border-border bg-ivory hover:border-champagne"}`}
                                    >
                                        <span className="block font-medium text-espresso">
                                            {item.name}
                                        </span>
                                        <span className="mt-1 block text-xs text-muted-foreground">
                                            {item.duration} phút · {referencePrice(item)}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </section>

                        {service && (
                            <section
                                className="rounded-md border border-border bg-ivory px-5 py-4 shadow-sm sm:px-7"
                                aria-labelledby="booking-doctor-heading"
                            >
                                <h2 id="booking-doctor-heading" className="text-lg text-espresso">
                                    Bác sĩ trực tiếp thăm khám
                                </h2>
                                {doctorsQuery.isPending && (
                                    <p role="status" className="mt-2 text-sm text-muted-foreground">
                                        Đang tải thông tin bác sĩ...
                                    </p>
                                )}
                                {doctorsQuery.isError && (
                                    <p role="alert" className="mt-2 text-sm text-red-700">
                                        {errorMessage(doctorsQuery.error)}
                                    </p>
                                )}
                                {doctorsQuery.isSuccess && !doctor && (
                                    <p role="alert" className="mt-2 text-sm text-amber-900">
                                        Chưa có bác sĩ phù hợp để đặt dịch vụ này.
                                    </p>
                                )}
                                {doctor && (
                                    <div className="mt-3 flex min-w-0 items-center gap-3">
                                        <DoctorPortrait
                                            key={doctor.id}
                                            doctor={doctor}
                                            size="large"
                                        />
                                        <div className="min-w-0">
                                            <p className="text-xs font-medium uppercase tracking-[0.12em] text-mocha">
                                                Bác sĩ phụ trách
                                            </p>
                                            <p className="mt-0.5 break-words text-base font-medium text-espresso">
                                                {doctor.name}
                                            </p>
                                            <p className="break-words text-sm text-muted-foreground">
                                                {doctor.specialty}
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Bác sĩ phụ trách các lịch hẹn tại Junie
                                            </p>
                                        </div>
                                    </div>
                                )}
                            </section>
                        )}

                        <section
                            className="rounded-md border border-border bg-ivory p-5 shadow-sm sm:p-7"
                            aria-labelledby="time-heading"
                        >
                            <h2 id="time-heading" className="text-2xl text-espresso">
                                02 · Chọn ngày và giờ
                            </h2>
                            <label
                                htmlFor="booking-date"
                                className="mt-5 block text-sm font-medium text-espresso"
                            >
                                Ngày hẹn
                            </label>
                            <input
                                id="booking-date"
                                type="date"
                                min={localToday()}
                                value={date}
                                disabled={!doctor}
                                onChange={(event) => {
                                    setDate(event.target.value);
                                    setTime("");
                                    setNotice("");
                                }}
                                className="mt-2 min-h-12 w-full rounded-md border border-input bg-ivory px-3 text-sm text-espresso outline-none focus:border-mocha focus:ring-1 focus:ring-mocha disabled:opacity-50 sm:max-w-xs"
                            />
                            {date && slotsQuery.isPending && (
                                <p role="status" className="mt-5 text-sm">
                                    Đang tải khung giờ còn trống...
                                </p>
                            )}
                            {date && slotsQuery.isError && (
                                <p role="alert" className="mt-5 text-sm text-red-700">
                                    {errorMessage(slotsQuery.error)}
                                </p>
                            )}
                            {date &&
                                slotsQuery.isSuccess &&
                                slotsQuery.data.data.slots.length === 0 && (
                                    <p className="mt-5 text-sm text-muted-foreground">
                                        Ngày này chưa có khung giờ phù hợp. Vui lòng chọn ngày khác.
                                    </p>
                                )}
                            {slotsQuery.isSuccess && date && (
                                <div
                                    className="mt-5 grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6"
                                    aria-label="Khung giờ còn trống"
                                >
                                    {slotsQuery.data.data.slots.map((slot) => (
                                        <button
                                            key={slot}
                                            type="button"
                                            aria-pressed={time === slot}
                                            onClick={() => {
                                                setTime(slot);
                                                setNotice("");
                                            }}
                                            className={`min-h-11 rounded-md border text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${time === slot ? "border-mocha bg-mocha text-ivory" : "border-border bg-ivory text-espresso hover:border-champagne"}`}
                                        >
                                            {slot}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </section>
                        <div className="flex justify-end">
                            <button
                                type="button"
                                onClick={continueToContact}
                                className="inline-flex min-h-12 items-center justify-center gap-2 rounded-md bg-mocha px-7 text-sm font-medium text-ivory hover:bg-espresso focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-mocha"
                            >
                                Tiếp tục <ArrowRight size={17} aria-hidden="true" />
                            </button>
                        </div>
                    </div>
                ) : (
                    <form
                        noValidate
                        onSubmit={submit}
                        className="mt-6 grid gap-5 md:grid-cols-[minmax(0,3fr)_minmax(280px,2fr)] md:items-start"
                    >
                        <div className="rounded-md border border-border bg-ivory p-5 shadow-sm sm:p-7">
                            <h2 className="text-2xl text-espresso">Thông tin khách hàng</h2>
                            {isGuest ? (
                                <div className="mt-5 grid gap-4">
                                    <ContactField
                                        id="booking-name"
                                        label="Họ và tên"
                                        required
                                        error={errors.name}
                                    >
                                        <Input
                                            id="booking-name"
                                            autoComplete="name"
                                            value={contact.name}
                                            onChange={(event) =>
                                                updateContact("name", event.target.value)
                                            }
                                            maxLength={255}
                                            aria-invalid={Boolean(errors.name)}
                                            aria-describedby={
                                                errors.name ? "booking-name-error" : undefined
                                            }
                                        />
                                    </ContactField>
                                    <ContactField
                                        id="booking-phone"
                                        label="Số điện thoại"
                                        required
                                        error={errors.phone}
                                    >
                                        <Input
                                            id="booking-phone"
                                            type="tel"
                                            inputMode="tel"
                                            autoComplete="tel"
                                            value={contact.phone}
                                            onChange={(event) =>
                                                updateContact("phone", event.target.value)
                                            }
                                            maxLength={30}
                                            aria-invalid={Boolean(errors.phone)}
                                            aria-describedby={
                                                errors.phone ? "booking-phone-error" : undefined
                                            }
                                        />
                                    </ContactField>
                                    <ContactField
                                        id="booking-email"
                                        label="Email xác minh OTP"
                                        required
                                        error={errors.email}
                                    >
                                        <Input
                                            id="booking-email"
                                            type="email"
                                            autoComplete="email"
                                            value={contact.email}
                                            onChange={(event) =>
                                                updateContact("email", event.target.value)
                                            }
                                            maxLength={255}
                                            aria-invalid={Boolean(errors.email)}
                                            aria-describedby={
                                                errors.email ? "booking-email-error" : undefined
                                            }
                                        />
                                    </ContactField>
                                    <p className="-mt-2 text-xs leading-5 text-muted-foreground">
                                        Chúng tôi sẽ gửi mã OTP đến email của bạn để xác minh lịch
                                        hẹn.
                                    </p>
                                </div>
                            ) : (
                                <div className="mt-5 grid gap-3 text-sm">
                                    <p className="text-muted-foreground">
                                        Lịch hẹn sẽ dùng thông tin từ tài khoản của bạn.
                                    </p>
                                    <p>
                                        <span className="text-muted-foreground">Họ và tên:</span>{" "}
                                        {user.name}
                                    </p>
                                    <p>
                                        <span className="text-muted-foreground">
                                            Số điện thoại:
                                        </span>{" "}
                                        {user.phone || "Chưa cập nhật"}
                                    </p>
                                    <p>
                                        <span className="text-muted-foreground">Email:</span>{" "}
                                        {user.email}
                                    </p>
                                    <Link
                                        to="/account/profile"
                                        className="w-fit text-mocha underline underline-offset-4"
                                    >
                                        Cập nhật thông tin tài khoản
                                    </Link>
                                </div>
                            )}
                            <div className="mt-5">
                                <ContactField
                                    id="booking-note"
                                    label="Ghi chú cho bác sĩ"
                                    error={errors.note}
                                >
                                    <Textarea
                                        id="booking-note"
                                        rows={3}
                                        value={contact.note}
                                        onChange={(event) =>
                                            updateContact("note", event.target.value)
                                        }
                                        maxLength={5000}
                                        className="min-h-24"
                                        aria-invalid={Boolean(errors.note)}
                                        aria-describedby={
                                            errors.note ? "booking-note-error" : undefined
                                        }
                                    />
                                </ContactField>
                            </div>
                            {!isGuest && (
                                <details className="mt-6 border-t border-border pt-4 text-sm">
                                    <summary className="cursor-pointer text-mocha">
                                        Có voucher dịch vụ?
                                    </summary>
                                    <label
                                        htmlFor="booking-voucher"
                                        className="mt-4 block text-sm font-medium"
                                    >
                                        Chọn voucher của bạn
                                    </label>
                                    <select
                                        id="booking-voucher"
                                        value={voucherId ?? ""}
                                        onChange={(event) =>
                                            setVoucherId(
                                                event.target.value
                                                    ? Number(event.target.value)
                                                    : null,
                                            )
                                        }
                                        className="mt-2 min-h-11 w-full rounded-md border border-input bg-ivory px-3 text-sm focus:border-mocha focus:outline-none"
                                    >
                                        <option value="">Không dùng voucher</option>
                                        {availableVouchers.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.code} · giảm {Number(item.value)}%
                                            </option>
                                        ))}
                                    </select>
                                    <div className="mt-4 flex gap-2">
                                        <label htmlFor="booking-voucher-code" className="sr-only">
                                            Mã voucher
                                        </label>
                                        <Input
                                            id="booking-voucher-code"
                                            value={voucherCode}
                                            onChange={(event) => {
                                                setVoucherCode(event.target.value);
                                                setVoucherError("");
                                            }}
                                            className="min-w-0"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => void applyVoucher()}
                                            disabled={voucherLookup.isPending}
                                            className="min-h-12 shrink-0 rounded-md border border-mocha px-4 text-mocha"
                                        >
                                            Áp dụng
                                        </button>
                                    </div>
                                    {voucherError && (
                                        <p role="alert" className="mt-2 text-xs text-red-700">
                                            {voucherError}
                                        </p>
                                    )}
                                    {vouchersQuery.isError && (
                                        <p role="alert" className="mt-2 text-xs text-red-700">
                                            {errorMessage(vouchersQuery.error)}
                                        </p>
                                    )}
                                </details>
                            )}
                        </div>

                        <BookingSummary
                            service={service}
                            date={date}
                            time={time}
                            doctor={doctor}
                            voucher={availableVouchers.find((item) => item.id === voucherId)}
                            back={() => {
                                setStep(1);
                                setNotice("");
                            }}
                        />

                        <div className="flex flex-col-reverse gap-3 pb-2 sm:flex-row sm:justify-between md:col-span-2">
                            <button
                                type="button"
                                onClick={() => {
                                    setStep(1);
                                    setNotice("");
                                }}
                                disabled={busy}
                                className="inline-flex min-h-12 items-center justify-center gap-2 rounded-md border border-border bg-ivory px-6 text-sm text-espresso hover:border-champagne focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-mocha disabled:opacity-50"
                            >
                                <ArrowLeft size={17} aria-hidden="true" /> Quay lại
                            </button>
                            <button
                                type="submit"
                                disabled={busy}
                                className="inline-flex min-h-12 items-center justify-center gap-2 rounded-md bg-mocha px-7 text-sm font-medium text-ivory hover:bg-espresso focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-mocha disabled:opacity-50"
                            >
                                {busy ? (
                                    <>
                                        <LoaderCircle
                                            className="animate-spin"
                                            size={17}
                                            aria-hidden="true"
                                        />{" "}
                                        Đang xử lý...
                                    </>
                                ) : (
                                    "Xác nhận đặt lịch"
                                )}
                            </button>
                        </div>
                    </form>
                )}
            </div>

            <Dialog
                open={otpOpen}
                onOpenChange={(open) => {
                    if (!busy) setOtpOpen(open);
                }}
            >
                <DialogContent className="w-[calc(100%-2rem)] max-w-md rounded-md border-[#d7c9b8] bg-[#faf8f4] p-5 text-[#5e4b3e] sm:p-7">
                    <DialogHeader>
                        <DialogTitle className="font-serif text-3xl font-normal">
                            Xác minh email
                        </DialogTitle>
                        <DialogDescription>
                            Nhập mã OTP gửi tới{" "}
                            <strong className="break-all">{contact.email.trim()}</strong> để hoàn
                            tất đặt lịch.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid justify-items-center gap-4 py-4">
                        <InputOTP
                            maxLength={6}
                            value={otp}
                            onChange={setOtp}
                            disabled={!otpSent || busy}
                            autoFocus
                            inputMode="numeric"
                            aria-label="Mã OTP 6 chữ số"
                        >
                            <InputOTPGroup>
                                {Array.from({ length: 6 }, (_, index) => (
                                    <InputOTPSlot
                                        key={index}
                                        index={index}
                                        className="size-11 sm:size-12"
                                    />
                                ))}
                            </InputOTPGroup>
                        </InputOTP>
                        {otpMessage && (
                            <p role={otpSent ? "status" : "alert"} className="text-center text-sm">
                                {otpMessage}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row sm:justify-between">
                        <button
                            type="button"
                            onClick={resendOtp}
                            disabled={busy}
                            className="min-h-11 rounded-md border border-[#c9b8a5] px-4 text-sm disabled:opacity-50"
                        >
                            {otpSent ? "Gửi lại mã" : "Gửi mã OTP"}
                        </button>
                        <button
                            type="button"
                            onClick={verifyOtp}
                            disabled={!otpSent || otp.length !== 6 || busy}
                            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-[#765d4a] px-4 text-sm text-white disabled:opacity-50"
                        >
                            {otpVerify.isPending || booking.isPending ? (
                                <LoaderCircle className="animate-spin" size={16} />
                            ) : (
                                <Mail size={16} />
                            )}
                            Xác minh và đặt lịch
                        </button>
                    </div>
                </DialogContent>
            </Dialog>
        </Container>
    );
}

function BookingProgress({ step }: { step: 1 | 2 }) {
    return (
        <ol
            className="mx-auto mt-7 grid max-w-[650px] grid-cols-2 gap-2 text-center sm:gap-5"
            aria-label="Tiến trình đặt lịch"
        >
            <li
                aria-current={step === 1 ? "step" : undefined}
                className="flex min-w-0 items-center gap-2 text-left"
            >
                <span
                    className={`grid size-9 shrink-0 place-items-center rounded-full border text-sm ${step === 2 ? "border-mocha bg-mocha text-ivory" : "border-mocha bg-ivory text-mocha"}`}
                >
                    {step === 2 ? <Check size={17} aria-label="Đã hoàn thành" /> : "1"}
                </span>
                <span className="text-xs leading-4 text-espresso sm:text-sm">
                    Chọn dịch vụ & lịch hẹn
                </span>
            </li>
            <li
                aria-current={step === 2 ? "step" : undefined}
                className="flex min-w-0 items-center gap-2 text-left"
            >
                <span
                    className={`grid size-9 shrink-0 place-items-center rounded-full border text-sm ${step === 2 ? "border-mocha bg-mocha text-ivory" : "border-border bg-ivory text-mocha"}`}
                >
                    2
                </span>
                <span className="text-xs leading-4 text-espresso sm:text-sm">
                    Thông tin & xác nhận
                </span>
            </li>
        </ol>
    );
}

function ContactField({
    id,
    label,
    required,
    error,
    children,
}: {
    id: string;
    label: string;
    required?: boolean;
    error?: string | undefined;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label htmlFor={id} className="mb-2 block text-sm font-medium text-espresso">
                {label}
                {required && <span className="text-mocha"> *</span>}
            </label>
            {children}
            {error && (
                <p id={`${id}-error`} role="alert" className="mt-1.5 text-xs text-red-700">
                    {error}
                </p>
            )}
        </div>
    );
}

function DoctorPortrait({ doctor, size }: { doctor: Doctor; size: "large" | "small" }) {
    const [imageFailed, setImageFailed] = useState(false);
    const sizeClass = size === "large" ? "size-14" : "size-10";

    if (!doctor.avatar || imageFailed) {
        return (
            <span
                role="img"
                aria-label={`Chưa có ảnh đại diện của ${doctor.name}`}
                className={`grid ${sizeClass} shrink-0 place-items-center rounded-full border border-champagne bg-cream text-mocha`}
            >
                <UserRound size={size === "large" ? 24 : 18} aria-hidden="true" />
            </span>
        );
    }

    return (
        <img
            src={doctor.avatar}
            alt={`Ảnh bác sĩ ${doctor.name}`}
            onError={() => setImageFailed(true)}
            className={`${sizeClass} shrink-0 rounded-full border border-champagne object-cover`}
        />
    );
}

function BookingSummary({
    service,
    date,
    time,
    doctor,
    voucher,
    back,
}: {
    service: Service | undefined;
    date: string;
    time: string;
    doctor?: Doctor | undefined;
    voucher?: Voucher | undefined;
    back: () => void;
}) {
    const rows = [
        { icon: CalendarDays, label: "Ngày hẹn", value: date ? dateLabel(date) : "—" },
        { icon: Clock3, label: "Giờ bắt đầu", value: time || "—" },
        {
            icon: Clock3,
            label: "Thời lượng dự kiến",
            value: service ? `${service.duration} phút` : "—",
        },
        { icon: Tag, label: "Giá dịch vụ tham khảo", value: referencePrice(service) },
    ];
    return (
        <aside
            className="rounded-md border border-border bg-ivory p-5 shadow-sm sm:p-7"
            aria-labelledby="booking-summary-heading"
        >
            <h2 id="booking-summary-heading" className="text-2xl text-espresso">
                Thông tin lịch hẹn
            </h2>
            <dl className="mt-4 grid gap-3">
                <div className="flex gap-3 text-sm">
                    <Stethoscope
                        size={17}
                        className="mt-0.5 shrink-0 text-mocha"
                        aria-hidden="true"
                    />
                    <div className="min-w-0">
                        <dt className="text-xs text-muted-foreground">Dịch vụ</dt>
                        <dd className="mt-0.5 break-words font-medium text-espresso">
                            {service?.name ?? "—"}
                        </dd>
                    </div>
                </div>
                {doctor && (
                    <div className="flex items-center gap-3 text-sm">
                        <DoctorPortrait key={doctor.id} doctor={doctor} size="small" />
                        <div className="min-w-0">
                            <dt className="text-xs text-muted-foreground">
                                Bác sĩ trực tiếp thăm khám
                            </dt>
                            <dd className="mt-0.5 break-words font-medium text-espresso">
                                {doctor.name}
                            </dd>
                        </div>
                    </div>
                )}
                {rows.map(({ icon: Icon, label, value }) => (
                    <div key={label} className="flex gap-3 text-sm">
                        <Icon size={17} className="mt-0.5 shrink-0 text-mocha" aria-hidden="true" />
                        <div className="min-w-0">
                            <dt className="text-xs text-muted-foreground">{label}</dt>
                            <dd className="mt-0.5 break-words font-medium text-espresso">
                                {value}
                            </dd>
                        </div>
                    </div>
                ))}
            </dl>
            <div className="mt-5 flex items-center justify-between gap-3 border-t border-border pt-4 text-sm">
                <span className="text-muted-foreground">Tổng giá tham khảo</span>
                <strong className="text-lg text-espresso">{referencePrice(service)}</strong>
            </div>
            {voucher && (
                <p className="mt-2 text-xs text-mocha">
                    Voucher {voucher.code} sẽ được áp dụng khi đặt lịch. Giá cuối cùng được hiển thị
                    trong lịch hẹn của bạn.
                </p>
            )}
            <button
                type="button"
                onClick={back}
                className="mt-4 text-sm text-mocha underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-mocha"
            >
                Thay đổi lịch hẹn
            </button>
            <p className="mt-5 border-t border-border pt-4 text-xs leading-5 text-muted-foreground">
                Junie sẽ liên hệ xác nhận lịch hẹn. Thời gian có thể được điều chỉnh khi cần thiết.
            </p>
        </aside>
    );
}
