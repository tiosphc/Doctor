import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { dealerApi, dealerKeys } from "./api";
import { walletDate, walletMoney } from "./dealerWalletFormat";

const statuses = {
    pending: { label: "Chờ xác nhận", className: "bg-amber-100 text-amber-800" },
    approved: { label: "Đã xác nhận", className: "bg-emerald-100 text-emerald-800" },
    rejected: { label: "Từ chối", className: "bg-rose-100 text-rose-800" },
} as const;

export function DealerWalletDepositRequestPanel({
    accountId,
    userId,
}: {
    accountId: number;
    userId?: number;
}) {
    const client = useQueryClient();
    const [amount, setAmount] = useState("");
    const [proof, setProof] = useState<File | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [reference, setReference] = useState("");
    const [note, setNote] = useState("");
    const [page, setPage] = useState(1);
    const requests = useQuery({
        queryKey: dealerKeys.depositRequests(userId, accountId, page),
        queryFn: () => dealerApi.depositRequests(accountId, page),
    });
    useEffect(() => {
        if (!proof) {
            setPreview(null);
            return;
        }
        const url = URL.createObjectURL(proof);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [proof]);
    const create = useMutation({
        mutationFn: () => {
            const body = new FormData();
            body.set("amount", amount);
            if (proof) body.set("payment_proof", proof);
            if (reference.trim()) body.set("transaction_reference", reference.trim());
            if (note.trim()) body.set("note", note.trim());
            return dealerApi.createDepositRequest(accountId, body);
        },
        onSuccess: async () => {
            setAmount("");
            setProof(null);
            setReference("");
            setNote("");
            setPage(1);
            toast.success("Đã gửi yêu cầu nạp tiền.");
            await client.invalidateQueries({
                queryKey: ["dealer-wallet-deposit-requests", userId, accountId],
            });
        },
        onError: (error) => toast.error(errorMessage(error)),
    });
    const errors = firstFieldErrors(create.error);

    return (
        <section className="space-y-5 rounded-2xl border bg-card p-5 sm:p-6">
            <div>
                <h2 className="text-xl font-semibold text-[#092b5c]">Tạo yêu cầu nạp tiền</h2>
                <p className="mt-2 text-sm leading-6 text-muted-foreground">
                    Chuyển khoản theo thông tin thanh toán của Junie, sau đó gửi chứng từ để Admin
                    kiểm tra và xác nhận nạp tiền vào ví.
                </p>
            </div>
            <form
                className="grid max-w-xl gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (!create.isPending && proof && /^[1-9]\d*$/.test(amount)) create.mutate();
                }}
            >
                <label className="grid gap-1.5 text-sm font-medium">
                    Số tiền (VND) *
                    <input
                        className="dealer-control rounded-xl border px-3"
                        inputMode="numeric"
                        value={amount}
                        onChange={(event) => setAmount(event.target.value.replace(/\D/g, ""))}
                        placeholder="1.000.000"
                        required
                    />
                    {errors["amount"] && (
                        <span className="text-xs text-rose-700">{errors["amount"]}</span>
                    )}
                </label>
                <div className="grid gap-2 text-sm font-medium">
                    Ảnh chứng từ thanh toán *
                    <label
                        className="flex min-h-28 cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-[#bc9151]/60 bg-[#f8f7f4] p-4 text-center text-sm text-[#092b5c]"
                        onDragOver={(event) => event.preventDefault()}
                        onDrop={(event) => {
                            event.preventDefault();
                            setProof(event.dataTransfer.files[0] ?? null);
                        }}
                    >
                        <span>{proof ? proof.name : "Kéo ảnh vào đây hoặc chọn ảnh"}</span>
                        <span className="text-xs font-normal text-muted-foreground">
                            JPG, PNG, WebP · tối đa 5 MB
                        </span>
                        <input
                            key={proof?.name ?? "empty"}
                            type="file"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                            className="sr-only"
                            onChange={(event) => setProof(event.target.files?.[0] ?? null)}
                        />
                    </label>
                    {preview && (
                        <img
                            src={preview}
                            alt="Xem trước chứng từ"
                            className="max-h-64 max-w-full rounded-xl border object-contain"
                        />
                    )}
                    {proof && (
                        <button
                            type="button"
                            className="w-fit text-sm text-primary underline"
                            onClick={() => setProof(null)}
                        >
                            Xóa ảnh để chọn lại
                        </button>
                    )}
                    {errors["payment_proof"] && (
                        <span className="text-xs text-rose-700">{errors["payment_proof"]}</span>
                    )}
                </div>
                <label className="grid gap-1.5 text-sm font-medium">
                    Mã giao dịch (nếu có)
                    <input
                        className="dealer-control rounded-xl border px-3"
                        maxLength={255}
                        value={reference}
                        onChange={(event) => setReference(event.target.value)}
                    />
                </label>
                <label className="grid gap-1.5 text-sm font-medium">
                    Ghi chú (nếu có)
                    <textarea
                        className="min-h-20 rounded-xl border px-3 py-2"
                        maxLength={2000}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                    />
                </label>
                <button
                    type="submit"
                    disabled={create.isPending || !proof || !/^[1-9]\d*$/.test(amount)}
                    className="dealer-action w-fit rounded-xl bg-[#092b5c] px-5 text-white disabled:opacity-50"
                >
                    {create.isPending ? "Đang gửi..." : "Gửi yêu cầu nạp tiền"}
                </button>
            </form>
            <div className="border-t pt-5">
                <h3 className="text-lg font-semibold text-[#092b5c]">Yêu cầu nạp gần đây</h3>
                {requests.isPending ? (
                    <p className="mt-3 text-sm">Đang tải...</p>
                ) : requests.isError ? (
                    <p role="alert" className="mt-3 text-sm text-rose-700">
                        {errorMessage(requests.error)}
                    </p>
                ) : requests.data.data.length === 0 ? (
                    <p className="mt-3 text-sm text-muted-foreground">Chưa có yêu cầu nạp tiền.</p>
                ) : (
                    <div className="mt-3 divide-y">
                        {requests.data.data.map((item) => (
                            <div
                                key={item.id}
                                className="flex flex-wrap items-start justify-between gap-3 py-3 text-sm"
                            >
                                <div className="space-y-1">
                                    <p className="font-semibold text-[#092b5c]">
                                        {item.request_code}
                                    </p>
                                    <p>
                                        {walletDate(item.created_at)} · {walletMoney(item.amount)}
                                    </p>
                                    {item.transaction_reference && (
                                        <p className="text-muted-foreground">
                                            Mã giao dịch: {item.transaction_reference}
                                        </p>
                                    )}
                                    {item.status === "rejected" && item.rejection_reason && (
                                        <p className="text-rose-700">
                                            Lý do: {item.rejection_reason}
                                        </p>
                                    )}
                                </div>
                                <span
                                    className={`rounded-full px-3 py-1 text-xs font-semibold ${statuses[item.status].className}`}
                                >
                                    {statuses[item.status].label}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
                {requests.data && requests.data.last_page > 1 && (
                    <div className="mt-4 flex justify-end gap-3 text-sm">
                        <button disabled={page <= 1} onClick={() => setPage(page - 1)}>
                            Trước
                        </button>
                        <span>
                            {page}/{requests.data.last_page}
                        </span>
                        <button
                            disabled={page >= requests.data.last_page}
                            onClick={() => setPage(page + 1)}
                        >
                            Sau
                        </button>
                    </div>
                )}
            </div>
        </section>
    );
}
