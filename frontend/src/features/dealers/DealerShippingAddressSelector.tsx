import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { toast } from "sonner";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { errorMessage } from "@/services/api";
import { dealerApi } from "./api";
import { emptyDealerShippingForm } from "./types";
import type { DealerShippingFormValue } from "./types";

export function DealerShippingAddressSelector({
    accountId,
    value,
    errors,
    onChange,
}: {
    accountId: number;
    value: DealerShippingFormValue;
    errors: Partial<Record<keyof DealerShippingFormValue, string>>;
    onChange: (next: DealerShippingFormValue) => void;
}) {
    const [savedOpen, setSavedOpen] = useState(false);
    const addresses = useQuery({
        queryKey: ["dealer-shipping-addresses", accountId],
        queryFn: () => dealerApi.addresses(accountId),
        enabled: savedOpen,
    });
    const provinces = useQuery({
        queryKey: ["administrative-provinces"],
        queryFn: dealerApi.provinces,
    });
    const wards = useQuery({
        queryKey: ["administrative-wards", value.province_code],
        queryFn: () => dealerApi.wards(value.province_code),
        enabled: Boolean(value.province_code),
    });
    const setField = <K extends keyof DealerShippingFormValue>(
        field: K,
        next: DealerShippingFormValue[K],
    ) => onChange({ ...value, [field]: next });

    return (
        <section className="space-y-5 rounded-xl border bg-card p-5 sm:p-6">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="dealer-section-title text-primary">Thông tin giao hàng</h2>
                    <p className="dealer-meta mt-1 text-muted-foreground">
                        Nhập người nhận của đơn này. Địa chỉ chỉ được lưu khi bạn chọn bên dưới.
                    </p>
                </div>
                <button
                    type="button"
                    className="dealer-action rounded-md border px-3 text-primary hover:bg-accent"
                    onClick={() => setSavedOpen(true)}
                >
                    Chọn từ địa chỉ đã lưu
                </button>
            </div>
            <div className="grid gap-4 text-sm sm:grid-cols-2">
                <label className="grid gap-2 font-medium">
                    Họ tên người nhận *
                    <input
                        className="dealer-control rounded-md border bg-background px-3 font-normal"
                        value={value.recipient_name}
                        onChange={(event) => setField("recipient_name", event.target.value)}
                        aria-invalid={Boolean(errors.recipient_name)}
                    />
                    {errors.recipient_name && (
                        <span className="text-red-700">{errors.recipient_name}</span>
                    )}
                </label>
                <label className="grid gap-2 font-medium">
                    Số điện thoại *
                    <input
                        type="tel"
                        className="dealer-control rounded-md border bg-background px-3 font-normal"
                        value={value.recipient_phone}
                        onChange={(event) => setField("recipient_phone", event.target.value)}
                        aria-invalid={Boolean(errors.recipient_phone)}
                    />
                    {errors.recipient_phone && (
                        <span className="text-red-700">{errors.recipient_phone}</span>
                    )}
                </label>
                <label className="grid gap-2 font-medium">
                    Tỉnh / Thành phố *
                    <select
                        className="dealer-control rounded-md border bg-background px-3 font-normal"
                        value={value.province_code}
                        onChange={(event) =>
                            onChange({
                                ...value,
                                province_code: event.target.value,
                                shipping_district: "",
                                ward_code: "",
                            })
                        }
                        aria-invalid={Boolean(errors.province_code)}
                    >
                        <option value="">Chọn Tỉnh / Thành phố</option>
                        {provinces.data?.data.map((province) => (
                            <option key={province.code} value={province.code}>
                                {province.name}
                            </option>
                        ))}
                    </select>
                    {errors.province_code && (
                        <span className="text-red-700">{errors.province_code}</span>
                    )}
                </label>
                <label className="grid gap-2 font-medium">
                    Quận / Huyện (nếu có)
                    <input
                        className="dealer-control rounded-md border bg-background px-3 font-normal"
                        value={value.shipping_district}
                        onChange={(event) => setField("shipping_district", event.target.value)}
                        aria-invalid={Boolean(errors.shipping_district)}
                    />
                    {errors.shipping_district && (
                        <span className="text-red-700">{errors.shipping_district}</span>
                    )}
                </label>
                <label className="grid gap-2 font-medium">
                    Phường / Xã *
                    <select
                        className="dealer-control rounded-md border bg-background px-3 font-normal"
                        value={value.ward_code}
                        disabled={!value.province_code || wards.isPending}
                        onChange={(event) => setField("ward_code", event.target.value)}
                        aria-invalid={Boolean(errors.ward_code)}
                    >
                        <option value="">Chọn Phường / Xã</option>
                        {wards.data?.data.map((ward) => (
                            <option key={ward.code} value={ward.code}>
                                {ward.name}
                            </option>
                        ))}
                    </select>
                    {errors.ward_code && <span className="text-red-700">{errors.ward_code}</span>}
                </label>
                <label className="grid gap-2 font-medium sm:col-span-2">
                    Địa chỉ chi tiết *
                    <input
                        className="dealer-control rounded-md border bg-background px-3 font-normal"
                        placeholder="Ví dụ: 636 Lê Văn Lương"
                        value={value.address_line}
                        onChange={(event) => setField("address_line", event.target.value)}
                        aria-invalid={Boolean(errors.address_line)}
                    />
                    {errors.address_line && (
                        <span className="text-red-700">{errors.address_line}</span>
                    )}
                </label>
            </div>
            {provinces.isError && (
                <p role="alert" className="text-sm text-red-700">
                    {errorMessage(provinces.error)}
                </p>
            )}
            {wards.isError && (
                <p role="alert" className="text-sm text-red-700">
                    {errorMessage(wards.error)}
                </p>
            )}
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={value.save_address}
                    onChange={(event) => setField("save_address", event.target.checked)}
                />
                Lưu địa chỉ này để dùng lần sau
            </label>
            <p className="dealer-meta text-muted-foreground">
                Kho được tự động xác định theo địa chỉ giao hàng và tình trạng tồn kho.
            </p>
            <Dialog open={savedOpen} onOpenChange={setSavedOpen}>
                <DialogContent className="max-h-[90vh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto p-5 sm:p-6">
                    <DialogHeader>
                        <DialogTitle className="dealer-modal-title">
                            Chọn từ địa chỉ đã lưu
                        </DialogTitle>
                    </DialogHeader>
                    {addresses.isPending && <p className="text-sm">Đang tải địa chỉ...</p>}
                    {addresses.isError && (
                        <p role="alert" className="text-sm text-red-700">
                            {errorMessage(addresses.error)}
                        </p>
                    )}
                    {addresses.data?.data.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Chưa có địa chỉ nào được lưu.
                        </p>
                    )}
                    <div className="grid gap-2">
                        {addresses.data?.data.map((address) => (
                            <button
                                key={address.id}
                                type="button"
                                className="rounded-md border p-4 text-left text-[14px] leading-6 hover:bg-accent"
                                onClick={() => {
                                    onChange({
                                        recipient_name: address.recipient_name,
                                        recipient_phone: address.recipient_phone,
                                        province_code: address.province_code,
                                        ward_code: address.ward_code,
                                        address_line: address.address_line,
                                        shipping_district: address.district_legacy ?? "",
                                        save_address: false,
                                    });
                                    setSavedOpen(false);
                                    toast.success(
                                        "Đã điền địa chỉ vào đơn. Bạn có thể chỉnh sửa trước khi đặt hàng.",
                                    );
                                }}
                            >
                                <strong>{address.recipient_name}</strong> ·{" "}
                                {address.recipient_phone}
                                {address.is_default && (
                                    <span className="dealer-meta ml-2 text-primary">Mặc định</span>
                                )}
                                <p>
                                    {address.address_line}, {address.ward.name}
                                    {address.district_legacy
                                        ? `, ${address.district_legacy}`
                                        : ""}, {address.province.name}
                                </p>
                            </button>
                        ))}
                    </div>
                    <button
                        type="button"
                        className="dealer-action w-full rounded-md border px-3 text-primary hover:bg-accent"
                        onClick={() => {
                            onChange(emptyDealerShippingForm);
                            setSavedOpen(false);
                        }}
                    >
                        + Thêm địa chỉ mới
                    </button>
                </DialogContent>
            </Dialog>
        </section>
    );
}
