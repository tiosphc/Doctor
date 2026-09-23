import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Gift, ShieldCheck, Star } from "lucide-react";
import { Button, ButtonLink } from "@/components/common/Button";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { reviewApi } from "@/services/reviewVoucherApi";
import type { Appointment, Doctor, Review, Voucher } from "@/types";

export function CustomerReviewPanel({
    appointment,
    onChanged,
}: {
    appointment: Appointment;
    onChanged: () => Promise<unknown>;
}) {
    const queryClient = useQueryClient();
    const [open, setOpen] = useState(false);
    const [rating, setRating] = useState(appointment.review?.rating ?? 0);
    const [comment, setComment] = useState(appointment.review?.comment ?? "");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [reward, setReward] = useState<Voucher | null>(null);
    const mutation = useMutation({
        mutationFn: async (): Promise<{ data: Review; voucher?: Voucher | null }> => {
            if (appointment.review) {
                return reviewApi.update(appointment.review.id, {
                    rating,
                    comment: comment || null,
                });
            }

            return reviewApi.create(appointment.id, { rating, comment: comment || null });
        },
        onSuccess: async (response) => {
            if ("voucher" in response && response.voucher) setReward(response.voucher);
            await Promise.all([
                onChanged(),
                queryClient.invalidateQueries({ queryKey: ["doctor"] }),
                queryClient.invalidateQueries({ queryKey: ["doctor-reviews"] }),
                queryClient.invalidateQueries({ queryKey: ["my-vouchers"] }),
                queryClient.invalidateQueries({ queryKey: ["notifications"] }),
            ]);
        },
        onError: (reason) => setErrors(firstFieldErrors(reason)),
    });

    useEffect(() => {
        setRating(appointment.review?.rating ?? 0);
        setComment(appointment.review?.comment ?? "");
    }, [appointment.review]);

    if (appointment.status !== "completed") return null;

    return (
        <section className="mt-6 rounded-2xl border border-secondary/35 bg-[linear-gradient(135deg,hsl(var(--card)),hsl(var(--muted)))] p-5 sm:p-6">
            <div className="flex flex-col justify-between gap-5 sm:flex-row sm:items-center">
                <div>
                    <p className="label-luxury">Đánh giá đã xác thực</p>
                    <h3 className="mt-2 text-xl text-primary">
                        {appointment.review ? "Đánh giá của bạn" : "Chia sẻ trải nghiệm của bạn"}
                    </h3>
                    {appointment.review ? (
                        <div className="mt-3 grid gap-2">
                            <Stars value={appointment.review.rating} />
                            <p className="text-sm text-muted-foreground">
                                {appointment.review.comment || "Bạn chưa thêm nhận xét."}
                            </p>
                        </div>
                    ) : (
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
                            Chia sẻ trải nghiệm và nhận ưu đãi 5% cho lần đặt lịch tiếp theo. Ưu đãi
                            áp dụng như nhau cho mọi đánh giá hợp lệ, không phụ thuộc vào số sao.
                        </p>
                    )}
                </div>
                <Button onClick={() => setOpen(true)}>
                    <Star size={16} />
                    {appointment.review ? "Chỉnh sửa đánh giá" : "Đánh giá buổi hẹn"}
                </Button>
            </div>
            {appointment.review && (
                <ButtonLink to="/account/vouchers" variant="outline" className="mt-4">
                    <Gift size={16} /> Xem voucher đánh giá
                </ButtonLink>
            )}
            <Dialog open={open} onOpenChange={(next) => !mutation.isPending && setOpen(next)}>
                <DialogContent className="max-w-xl rounded-2xl p-0">
                    {reward ? (
                        <div className="p-6 sm:p-8">
                            <div className="mx-auto grid size-14 place-items-center rounded-full bg-secondary/20 text-secondary-foreground">
                                <Gift size={26} />
                            </div>
                            <DialogHeader className="mt-5 items-center text-center sm:text-center">
                                <DialogTitle className="text-center text-3xl text-primary">
                                    Cảm ơn bạn đã chia sẻ!
                                </DialogTitle>
                                <DialogDescription className="max-w-md text-center leading-6">
                                    Đánh giá đã được ghi nhận. Bạn nhận voucher giảm{" "}
                                    {Number(reward.value)}% cho lần đặt lịch tiếp theo.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="mt-6 rounded-xl border border-dashed border-secondary bg-muted/50 p-5 text-center">
                                <p className="text-xs uppercase tracking-[0.18em] text-muted-foreground">
                                    Mã voucher
                                </p>
                                <p className="mt-2 font-mono text-xl font-bold text-primary">
                                    {reward.code}
                                </p>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    Hạn dùng{" "}
                                    {new Date(reward.expires_at).toLocaleDateString("vi-VN")}
                                </p>
                            </div>
                            <p className="mt-5 text-center text-xs leading-5 text-muted-foreground">
                                Ưu đãi được trao cho mọi đánh giá hợp lệ, không phụ thuộc vào số
                                sao.
                            </p>
                            <DialogFooter className="mt-6 sm:justify-center">
                                <ButtonLink to="/account/vouchers" variant="outline">
                                    Xem ưu đãi
                                </ButtonLink>
                                <ButtonLink to="/booking">Đặt lịch mới</ButtonLink>
                            </DialogFooter>
                        </div>
                    ) : (
                        <form
                            className="grid gap-5 p-6 sm:p-8"
                            onSubmit={(event) => {
                                event.preventDefault();
                                setErrors({});
                                if (rating === 0) {
                                    setErrors({ rating: "Vui lòng chọn từ 1 đến 5 sao." });
                                    return;
                                }
                                mutation.mutate();
                            }}
                        >
                            <DialogHeader>
                                <DialogTitle className="text-2xl text-primary">
                                    Đánh giá trải nghiệm
                                </DialogTitle>
                                <DialogDescription>
                                    {appointment.service.name} · {appointment.doctor.name}
                                </DialogDescription>
                            </DialogHeader>
                            <fieldset>
                                <legend className="text-sm font-semibold text-primary">
                                    Bạn đánh giá trải nghiệm thế nào?
                                </legend>
                                <div
                                    className="mt-3 flex gap-2"
                                    role="radiogroup"
                                    aria-label="Số sao đánh giá"
                                >
                                    {[1, 2, 3, 4, 5].map((value) => (
                                        <button
                                            key={value}
                                            type="button"
                                            role="radio"
                                            aria-checked={rating === value}
                                            aria-label={`${value} sao`}
                                            onClick={() => setRating(value)}
                                            className="focus-premium rounded-md p-1 text-secondary transition-transform hover:scale-110"
                                        >
                                            <Star
                                                size={30}
                                                fill={value <= rating ? "currentColor" : "none"}
                                            />
                                        </button>
                                    ))}
                                </div>
                                {errors["rating"] && (
                                    <p className="mt-2 text-sm text-red-700">{errors["rating"]}</p>
                                )}
                            </fieldset>
                            <label className="grid gap-2 text-sm font-semibold text-primary">
                                Nhận xét của bạn
                                <textarea
                                    value={comment}
                                    maxLength={1000}
                                    rows={5}
                                    onChange={(event) => setComment(event.target.value)}
                                    className="focus-premium resize-none rounded-lg border bg-background p-3 font-normal text-foreground"
                                />
                                <span className="text-right text-xs font-normal text-muted-foreground">
                                    {comment.length} / 1000
                                </span>
                            </label>
                            <p className="flex gap-2 rounded-lg bg-muted p-3 text-xs leading-5 text-muted-foreground">
                                <ShieldCheck
                                    className="shrink-0 text-secondary-foreground"
                                    size={17}
                                />
                                Mọi đánh giá hợp lệ đều nhận quyền lợi như nhau, không phụ thuộc vào
                                số sao.
                            </p>
                            {mutation.isError && (
                                <p className="text-sm text-red-700">
                                    {errorMessage(mutation.error)}
                                </p>
                            )}
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                >
                                    Hủy
                                </Button>
                                <Button type="submit" disabled={mutation.isPending}>
                                    {mutation.isPending
                                        ? "Đang gửi..."
                                        : appointment.review
                                          ? "Lưu thay đổi"
                                          : "Gửi đánh giá"}
                                </Button>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}

export function DoctorReviewsSection({ doctor }: { doctor: Doctor }) {
    const [page, setPage] = useState(1);
    const query = useQuery({
        queryKey: ["doctor-reviews", doctor.id, page],
        queryFn: () => reviewApi.doctor(doctor.id, page),
    });
    const distribution = doctor.rating_distribution ?? {};

    return (
        <section className="mt-16 border-t pt-12" aria-labelledby="doctor-reviews-title">
            <div className="grid gap-8 lg:grid-cols-[18rem_minmax(0,1fr)]">
                <div>
                    <p className="label-luxury">Trải nghiệm khách hàng</p>
                    <h2 id="doctor-reviews-title" className="mt-3 text-3xl text-primary">
                        Đánh giá đã xác thực
                    </h2>
                    <div className="mt-5 flex items-end gap-3">
                        <strong className="font-serif text-5xl text-primary">
                            {doctor.average_rating?.toFixed(1) ?? "—"}
                        </strong>
                        <span className="pb-1 text-sm text-muted-foreground">
                            {doctor.review_count ?? 0} đánh giá
                        </span>
                    </div>
                    <div className="mt-5 grid gap-2">
                        {[5, 4, 3, 2, 1].map((rating) => {
                            const count = distribution[String(rating)] ?? 0;
                            const width = doctor.review_count
                                ? (count / doctor.review_count) * 100
                                : 0;
                            return (
                                <div
                                    key={rating}
                                    className="grid grid-cols-[2rem_1fr_2rem] items-center gap-2 text-xs text-muted-foreground"
                                >
                                    <span>{rating}★</span>
                                    <span className="h-1.5 overflow-hidden rounded-full bg-muted">
                                        <span
                                            className="block h-full bg-secondary"
                                            style={{ width: `${width}%` }}
                                        />
                                    </span>
                                    <span>{count}</span>
                                </div>
                            );
                        })}
                    </div>
                </div>
                <div>
                    {query.isPending ? (
                        <LoadingState />
                    ) : query.isError ? (
                        <ErrorState
                            message={errorMessage(query.error)}
                            retry={() => query.refetch()}
                        />
                    ) : !query.data.data.length ? (
                        <EmptyState message="Chưa có đánh giá được công khai." />
                    ) : (
                        <div className="grid gap-4">
                            {query.data.data.map((review) => (
                                <ReviewCard key={review.id} review={review} />
                            ))}
                        </div>
                    )}
                    {query.data && (
                        <Pagination
                            current={query.data.meta.current_page}
                            last={query.data.meta.last_page}
                            onPage={setPage}
                        />
                    )}
                </div>
            </div>
        </section>
    );
}

export function ReviewCard({ review }: { review: Review }) {
    return (
        <article className="rounded-xl border bg-card p-5 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <Stars value={review.rating} />
                <time className="text-xs text-muted-foreground">
                    {new Date(review.created_at).toLocaleDateString("vi-VN")}
                </time>
            </div>
            <p className="mt-3 font-semibold text-primary">{review.reviewer.name}</p>
            {review.comment && (
                <p className="mt-2 text-sm leading-6 text-muted-foreground">“{review.comment}”</p>
            )}
            <p className="mt-4 flex items-center gap-1.5 text-xs font-semibold text-emerald-700">
                <ShieldCheck size={14} /> Đánh giá đã xác thực
            </p>
        </article>
    );
}

function Stars({ value }: { value: number }) {
    return (
        <span className="flex gap-0.5 text-secondary" aria-label={`${value} trên 5 sao`}>
            {[1, 2, 3, 4, 5].map((star) => (
                <Star key={star} size={17} fill={star <= value ? "currentColor" : "none"} />
            ))}
        </span>
    );
}
