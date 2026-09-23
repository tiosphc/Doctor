import { CheckCircle2 } from "lucide-react";
import { Container } from "@/components/common/Container";
import { ButtonLink } from "@/components/common/Button";
import { formatDate } from "@/components/cards/Cards";
import { StatusBadge } from "@/components/common/Status";
import { bookingResult } from "@/lib/booking-result";

export function BookingSuccessPage() {
    const appointment = bookingResult.get();
    if (!appointment)
        return (
            <Container className="py-14 md:py-20">
                <div className="card-surface mx-auto max-w-2xl p-8 text-center">
                    <h1 className="text-3xl text-primary">Không còn dữ liệu xác nhận</h1>
                    <p className="mt-3 text-sm text-muted-foreground">
                        Trang này chỉ hiển thị ngay sau khi backend tạo lịch thành công.
                    </p>
                    <ButtonLink to="/booking" className="mt-6">
                        Đặt lịch mới
                    </ButtonLink>
                </div>
            </Container>
        );
    return (
        <Container className="py-14 md:py-20">
            <div className="card-surface mx-auto max-w-2xl p-6 text-center md:p-10">
                <CheckCircle2 className="mx-auto text-success" size={60} />
                <h1 className="mt-5 text-4xl text-primary">Đặt lịch thành công</h1>
                {appointment.booking_code ? (
                    <>
                        <p className="mt-3 text-sm text-muted-foreground">
                            Mã lịch hẹn do hệ thống cấp
                        </p>
                        <strong className="mt-2 block break-all text-2xl text-primary">
                            {appointment.booking_code}
                        </strong>
                        <p className="mt-3 rounded-md bg-amber-50 p-3 text-sm font-semibold text-amber-900">
                            Vui lòng lưu mã lịch hẹn để tra cứu hoặc thay đổi lịch sau này.
                        </p>
                    </>
                ) : (
                    <p className="mt-3 text-sm text-muted-foreground">
                        Lịch hẹn đã được lưu vào tài khoản của bạn.
                    </p>
                )}
                <div className="mt-7 grid grid-cols-2 gap-4 rounded-md bg-muted p-5 text-left text-sm">
                    <span>
                        Dịch vụ
                        <br />
                        <b>{appointment.service.name}</b>
                    </span>
                    <span>
                        Bác sĩ
                        <br />
                        <b>{appointment.doctor.name}</b>
                    </span>
                    <span>
                        Ngày
                        <br />
                        <b>{formatDate(appointment.appointment_date)}</b>
                    </span>
                    <span>
                        Giờ
                        <br />
                        <b>
                            {appointment.start_time}–{appointment.end_time}
                        </b>
                    </span>
                    <span>
                        Trạng thái
                        <br />
                        <StatusBadge status={appointment.status} />
                    </span>
                </div>
                <div className="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
                    <ButtonLink to="/">Về trang chủ</ButtonLink>
                    {appointment.booking_code ? (
                        <ButtonLink to="/appointment-lookup" variant="outline">
                            Tra cứu lịch hẹn
                        </ButtonLink>
                    ) : (
                        <ButtonLink to="/account/appointments" variant="outline">
                            Lịch hẹn của tôi
                        </ButtonLink>
                    )}
                    <ButtonLink to="/booking" variant="outline">
                        Đặt lịch mới
                    </ButtonLink>
                </div>
            </div>
        </Container>
    );
}
