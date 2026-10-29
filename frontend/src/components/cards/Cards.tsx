import { Clock, BadgeCheck, Star } from "lucide-react";
import type { Service, Doctor, Appointment, Blog } from "@/types";
import { ButtonLink } from "@/components/common/Button";
import { StatusBadge } from "@/components/common/Status";
import treatment from "@/assets/treatment.jpg";
import femaleDoctor from "@/assets/doctor-female.jpg";
import maleDoctor from "@/assets/doctor-male.jpg";
import { servicePath } from "@/components/services/ServiceExplorer";

export const money = (value: number | string) =>
    `${new Intl.NumberFormat("vi-VN").format(Number(value))} VNĐ`;
export const formatDate = (date: string) =>
    new Intl.DateTimeFormat("vi-VN").format(new Date(`${date}T00:00:00`));
export const serviceImage = (service: Service) => service.image || treatment;
export const doctorImage = (doctor: Doctor) =>
    doctor.avatar || (doctor.id % 2 ? femaleDoctor : maleDoctor);
export const blogImage = (blog: Blog) => blog.image || treatment;

export function BlogCard({ blog }: { blog: Blog }) {
    const publishedDate = new Intl.DateTimeFormat("vi-VN", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
    }).format(new Date(blog.published_at));

    return (
        <article className="card-surface group flex h-full flex-col overflow-hidden">
            <img
                src={blogImage(blog)}
                alt={blog.title}
                loading="lazy"
                width={1200}
                height={750}
                className="aspect-[16/10] w-full object-cover transition duration-500 group-hover:scale-[1.02]"
            />
            <div className="flex flex-1 flex-col p-5">
                <p className="text-[11px] uppercase tracking-wider text-secondary">
                    {blog.category} · {publishedDate}
                </p>
                <h3 className="mt-3 text-xl text-primary">{blog.title}</h3>
                <p className="mt-2 flex-1 text-sm leading-6 text-muted-foreground">
                    {blog.excerpt}
                </p>
                <ButtonLink
                    to={`/blogs/${blog.slug}`}
                    variant="ghost"
                    className="mt-5 min-h-0 justify-start p-0"
                >
                    Đọc thêm <span aria-hidden="true">→</span>
                </ButtonLink>
            </div>
        </article>
    );
}

export function ServiceCard({ service }: { service: Service }) {
    return (
        <article className="card-surface group flex h-full flex-col overflow-hidden">
            <img
                src={serviceImage(service)}
                alt={service.name}
                loading="lazy"
                width={1200}
                height={800}
                className="aspect-[16/10] w-full object-cover"
            />
            <div className="flex flex-1 flex-col p-5">
                <div className="flex justify-between text-[11px] uppercase tracking-wider text-muted-foreground">
                    <span>{service.category || "Liệu trình y khoa"}</span>
                    <span className="flex items-center gap-1">
                        <Clock size={13} />
                        {service.duration} phút
                    </span>
                </div>
                <h3 className="mt-3 text-xl font-semibold text-primary">{service.name}</h3>
                <p className="mt-2 flex-1 text-sm leading-6 text-muted-foreground">
                    {service.description ||
                        "Liệu trình được bác sĩ tư vấn và cá nhân hóa theo nhu cầu."}
                </p>
                <p className="mt-5 text-sm">
                    Chi phí{" "}
                    <strong className="float-right text-primary">{money(service.price)}</strong>
                </p>
                <div className="mt-4 grid grid-cols-2 gap-2">
                    <ButtonLink
                        to={servicePath(service)}
                        variant="outline"
                        className="min-h-9 px-2 text-xs"
                    >
                        Xem chi tiết
                    </ButtonLink>
                    <ButtonLink
                        to={`/booking?service_id=${service.id}`}
                        className="min-h-9 px-2 text-xs"
                    >
                        Đặt lịch
                    </ButtonLink>
                </div>
            </div>
        </article>
    );
}

export function DoctorCard({ doctor }: { doctor: Doctor }) {
    return (
        <article className="group">
            <div className="overflow-hidden rounded-t-[7rem] rounded-b-md border border-secondary/40">
                <img
                    src={doctorImage(doctor)}
                    alt={doctor.name}
                    loading="lazy"
                    width={912}
                    height={1104}
                    className="aspect-[4/5] w-full object-cover transition duration-500 group-hover:scale-[1.02]"
                />
            </div>
            <div className="pt-5 text-center">
                <p className="text-xs uppercase tracking-wider text-secondary">
                    {doctor.specialty}
                </p>
                <h3 className="mt-2 text-2xl text-primary">{doctor.name}</h3>
                <p className="mt-2 line-clamp-2 text-sm text-muted-foreground">
                    {doctor.bio || "Bác sĩ thẩm mỹ đồng hành cùng phác đồ cá nhân hóa."}
                </p>
                <div
                    className="mt-3 flex items-center justify-center gap-1.5 text-sm"
                    aria-label={`${doctor.average_rating?.toFixed(1) ?? "5.0"} trên 5 sao, ${doctor.review_count ?? 0} đánh giá`}
                >
                    <Star size={17} className="fill-secondary text-secondary" aria-hidden="true" />
                    <strong className="text-primary">
                        {doctor.average_rating?.toFixed(1) ?? "5.0"}
                    </strong>
                    <span className="text-muted-foreground">
                        ({doctor.review_count ?? 0} đánh giá)
                    </span>
                </div>
                <div className="mt-4 flex justify-center">
                    <ButtonLink to="/booking" className="min-h-9 px-4">
                        Đặt lịch
                    </ButtonLink>
                </div>
            </div>
        </article>
    );
}

export function AppointmentCard({
    item,
    onView,
    onCancel,
}: {
    item: Appointment;
    onView?: (() => void) | undefined;
    onCancel?: (() => void) | undefined;
}) {
    const canCancel = item.can_cancel;
    return (
        <article className="card-surface p-5 md:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-xs text-muted-foreground">
                        {item.booking_code || `Lịch hẹn #${item.id}`}
                    </p>
                    <h3 className="mt-1 text-xl text-primary">{item.service.name}</h3>
                </div>
                <StatusBadge status={item.status} />
            </div>
            <div className="mt-5 grid gap-2 text-sm text-muted-foreground sm:grid-cols-3">
                <span>{item.doctor.name}</span>
                <span>{formatDate(item.appointment_date)}</span>
                <span>
                    {item.start_time}–{item.end_time}
                </span>
            </div>
            {(onView || (canCancel && onCancel)) && (
                <div className="mt-5 flex flex-wrap gap-4 border-t pt-4 text-sm font-semibold text-primary">
                    {onView && <button onClick={onView}>Xem chi tiết</button>}
                    {canCancel && onCancel && (
                        <button onClick={onCancel} className="text-red-700">
                            Hủy lịch
                        </button>
                    )}
                </div>
            )}
        </article>
    );
}

export function CheckFeature({ title, children }: { title: string; children: string }) {
    return (
        <div className="card-surface p-6">
            <BadgeCheck className="text-primary" size={24} />
            <h3 className="mt-6 text-lg text-primary">{title}</h3>
            <p className="mt-2 text-sm leading-6 text-muted-foreground">{children}</p>
        </div>
    );
}
