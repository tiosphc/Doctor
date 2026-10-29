import { Link } from "@tanstack/react-router";
import { CalendarDays, Check, Clock3, Stethoscope } from "lucide-react";
import { Container } from "@/components/common/Container";
import { statusLabel } from "@/components/common/Status";
import { bookingResult } from "@/lib/booking-result";

export function BookingSuccessPage() {
    const appointment = bookingResult.get();

    if (!appointment) {
        return (
            <Container className="py-12 md:py-20">
                <div className="mx-auto max-w-xl rounded-md border border-border bg-ivory p-6 text-center shadow-sm sm:p-10">
                    <h1 className="text-3xl text-espresso">Không còn dữ liệu đặt lịch</h1>
                    <p className="mt-3 text-sm leading-6 text-muted-foreground">
                        Thông tin xác nhận chỉ hiển thị ngay sau khi yêu cầu đặt lịch được tiếp
                        nhận.
                    </p>
                    <Link
                        to="/booking"
                        className="mt-6 inline-flex min-h-11 items-center justify-center rounded-md bg-mocha px-6 text-sm font-medium text-ivory hover:bg-espresso"
                    >
                        Đặt lịch mới
                    </Link>
                </div>
            </Container>
        );
    }

    const details = [
        { icon: Stethoscope, label: "Dịch vụ", value: appointment.service.name },
        {
            icon: CalendarDays,
            label: "Ngày hẹn",
            value: new Intl.DateTimeFormat("vi-VN", {
                day: "2-digit",
                month: "2-digit",
                year: "numeric",
            }).format(new Date(`${appointment.appointment_date}T00:00:00`)),
        },
        {
            icon: Clock3,
            label: "Giờ hẹn",
            value: `${appointment.start_time}–${appointment.end_time}`,
        },
    ];

    return (
        <Container className="px-5 py-10 sm:px-6 md:py-16">
            <div className="mx-auto max-w-[680px] rounded-md border border-border bg-ivory p-6 text-center shadow-sm sm:p-10">
                <div
                    className="mx-auto grid size-16 place-items-center rounded-full border border-champagne bg-cream text-mocha"
                    aria-hidden="true"
                >
                    <Check size={32} strokeWidth={1.6} />
                </div>
                <p className="eyebrow mt-6 text-mocha">ĐẶT LỊCH TRỰC TUYẾN</p>
                <h1 className="mt-2 text-[clamp(2.25rem,5vw,3.25rem)] leading-tight text-espresso">
                    Đặt lịch thành công!
                </h1>
                <p className="mt-3 text-sm leading-6 text-muted-foreground">
                    Junie đã tiếp nhận yêu cầu đặt lịch của bạn.
                </p>

                {appointment.booking_code && (
                    <div className="mt-7 rounded-md border border-champagne bg-cream px-4 py-4">
                        <p className="text-xs uppercase tracking-[0.14em] text-muted-foreground">
                            Mã lịch hẹn
                        </p>
                        <strong className="mt-1 block break-all text-xl text-espresso">
                            {appointment.booking_code}
                        </strong>
                        {appointment.customer_type === "guest" && (
                            <p className="mt-2 text-xs text-muted-foreground">
                                Vui lòng lưu mã này để tra cứu lịch hẹn.
                            </p>
                        )}
                    </div>
                )}

                <dl className="mt-6 grid gap-4 border-y border-border py-5 text-left sm:grid-cols-3">
                    {details.map(({ icon: Icon, label, value }) => (
                        <div key={label} className="flex min-w-0 gap-2 sm:block">
                            <Icon
                                size={18}
                                className="mt-0.5 shrink-0 text-mocha sm:mb-2"
                                aria-hidden="true"
                            />
                            <div className="min-w-0">
                                <dt className="text-xs text-muted-foreground">{label}</dt>
                                <dd className="mt-1 break-words text-sm font-medium text-espresso">
                                    {value}
                                </dd>
                            </div>
                        </div>
                    ))}
                </dl>
                <p className="mt-5 text-sm text-espresso">
                    Trạng thái:{" "}
                    <span className="font-semibold">{statusLabel(appointment.status)}</span>
                </p>
                <p className="mt-2 text-xs leading-5 text-muted-foreground">
                    Junie sẽ liên hệ để xác nhận lịch hẹn của bạn.
                </p>

                <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                    <Link
                        to="/"
                        className="inline-flex min-h-11 items-center justify-center rounded-md bg-mocha px-6 text-sm font-medium text-ivory hover:bg-espresso focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-mocha"
                    >
                        Về trang chủ
                    </Link>
                    <Link
                        to={
                            appointment.customer_type === "guest"
                                ? "/appointment-lookup"
                                : "/account/appointments"
                        }
                        className="inline-flex min-h-11 items-center justify-center rounded-md border border-mocha px-6 text-sm font-medium text-mocha hover:bg-cream focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-mocha"
                    >
                        Xem lịch hẹn
                    </Link>
                </div>
            </div>
        </Container>
    );
}
