import { useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { EmptyState, ErrorState, LoadingState } from "@/components/common/AsyncState";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { dealerApi } from "./api";
import type { DealerShippingAddress, DealerShippingAddressInput } from "./types";

const emptyAddress: DealerShippingAddressInput = {
    recipient_name: "",
    recipient_phone: "",
    address_line: "",
    province_code: "",
    ward_code: "",
    is_default: false,
};

export function DealerAddressManager({ accountId }: { accountId: number }) {
    const client = useQueryClient();
    const [editingId, setEditingId] = useState<number | null>(null);
    const [form, setForm] = useState<DealerShippingAddressInput>(emptyAddress);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const addresses = useQuery({
        queryKey: ["dealer-shipping-addresses", accountId],
        queryFn: () => dealerApi.addresses(accountId),
    });
    const provinces = useQuery({
        queryKey: ["administrative-provinces"],
        queryFn: dealerApi.provinces,
    });
    const wards = useQuery({
        queryKey: ["administrative-wards", form.province_code],
        queryFn: () => dealerApi.wards(form.province_code),
        enabled: Boolean(form.province_code),
    });
    const save = useMutation({
        mutationFn: () =>
            editingId === null
                ? dealerApi.createAddress(accountId, form)
                : dealerApi.updateAddress(accountId, editingId, form),
        onSuccess: async () => {
            toast.success("Đã lưu địa chỉ giao hàng.");
            setEditingId(null);
            setForm(emptyAddress);
            setErrors({});
            await client.invalidateQueries({ queryKey: ["dealer-shipping-addresses", accountId] });
        },
        onError: (error) => {
            setErrors(firstFieldErrors(error));
            toast.error(errorMessage(error));
        },
    });
    const remove = useMutation({
        mutationFn: (addressId: number) => dealerApi.deleteAddress(accountId, addressId),
        onSuccess: async () => {
            toast.success("Đã xóa địa chỉ giao hàng.");
            await client.invalidateQueries({ queryKey: ["dealer-shipping-addresses", accountId] });
        },
        onError: (error) => toast.error(errorMessage(error)),
    });

    function edit(address: DealerShippingAddress) {
        setEditingId(address.id);
        setForm({
            recipient_name: address.recipient_name,
            recipient_phone: address.recipient_phone,
            address_line: address.address_line,
            province_code: address.province_code,
            ward_code: address.ward_code,
            postal_code: address.postal_code ?? null,
            is_default: address.is_default,
        });
        setErrors({});
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        save.mutate();
    }

    return (
        <section className="max-w-4xl space-y-5 rounded-xl border bg-card p-5 sm:p-6">
            <div>
                <h2 className="dealer-section-title text-primary">Địa chỉ giao hàng</h2>
                <p className="dealer-meta mt-1 text-muted-foreground">
                    Quản lý địa chỉ đã lưu để sử dụng khi đặt hàng.
                </p>
            </div>
            {addresses.isPending ? (
                <LoadingState />
            ) : addresses.isError ? (
                <ErrorState
                    message={errorMessage(addresses.error)}
                    retry={() => void addresses.refetch()}
                />
            ) : addresses.data.data.length === 0 ? (
                <EmptyState message="Chưa có địa chỉ giao hàng nào." />
            ) : (
                <ul className="grid gap-3">
                    {addresses.data.data.map((address) => (
                        <li key={address.id} className="rounded-lg border p-4 text-sm">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p className="font-semibold text-primary">
                                        {address.recipient_name} · {address.recipient_phone}
                                        {address.is_default && (
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                Mặc định
                                            </span>
                                        )}
                                    </p>
                                    <p className="mt-1 text-muted-foreground">
                                        {address.address_line}, {address.ward.name},{" "}
                                        {address.province.name}
                                    </p>
                                </div>
                                <div className="flex gap-3 font-medium text-primary">
                                    <button
                                        type="button"
                                        onClick={() => edit(address)}
                                        className="underline"
                                    >
                                        Sửa
                                    </button>
                                    <button
                                        type="button"
                                        disabled={remove.isPending}
                                        onClick={() => {
                                            if (window.confirm("Xóa địa chỉ giao hàng này?"))
                                                remove.mutate(address.id);
                                        }}
                                        className="underline disabled:opacity-50"
                                    >
                                        Xóa
                                    </button>
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <form onSubmit={submit} className="grid gap-4 border-t pt-5 text-sm sm:grid-cols-2">
                <h3 className="font-semibold text-primary sm:col-span-2">
                    {editingId === null ? "Thêm địa chỉ" : "Sửa địa chỉ"}
                </h3>
                <AddressField
                    label="Người nhận"
                    name="recipient_name"
                    value={form.recipient_name}
                    error={errors["recipient_name"]}
                    onChange={(value) => setForm({ ...form, recipient_name: value })}
                />
                <AddressField
                    label="Số điện thoại"
                    name="recipient_phone"
                    value={form.recipient_phone}
                    error={errors["recipient_phone"]}
                    onChange={(value) => setForm({ ...form, recipient_phone: value })}
                />
                <label className="grid gap-2 font-medium">
                    Tỉnh / Thành phố
                    <select
                        required
                        value={form.province_code}
                        onChange={(event) =>
                            setForm({ ...form, province_code: event.target.value, ward_code: "" })
                        }
                        className="dealer-control rounded-md border bg-background px-3 font-normal"
                    >
                        <option value="">Chọn tỉnh / thành phố</option>
                        {provinces.data?.data.map((province) => (
                            <option key={province.code} value={province.code}>
                                {province.name}
                            </option>
                        ))}
                    </select>
                    {errors["province_code"] && (
                        <span className="text-red-700">{errors["province_code"]}</span>
                    )}
                </label>
                <label className="grid gap-2 font-medium">
                    Phường / Xã
                    <select
                        required
                        value={form.ward_code}
                        disabled={!form.province_code || wards.isPending}
                        onChange={(event) => setForm({ ...form, ward_code: event.target.value })}
                        className="dealer-control rounded-md border bg-background px-3 font-normal"
                    >
                        <option value="">Chọn phường / xã</option>
                        {wards.data?.data.map((ward) => (
                            <option key={ward.code} value={ward.code}>
                                {ward.name}
                            </option>
                        ))}
                    </select>
                    {errors["ward_code"] && (
                        <span className="text-red-700">{errors["ward_code"]}</span>
                    )}
                </label>
                <div className="sm:col-span-2">
                    <AddressField
                        label="Địa chỉ chi tiết"
                        name="address_line"
                        value={form.address_line}
                        error={errors["address_line"]}
                        onChange={(value) => setForm({ ...form, address_line: value })}
                    />
                </div>
                <AddressField
                    label="Mã bưu chính (nếu có)"
                    name="postal_code"
                    value={form.postal_code ?? ""}
                    error={errors["postal_code"]}
                    onChange={(value) => setForm({ ...form, postal_code: value })}
                    required={false}
                />
                <label className="flex items-center gap-2 font-medium">
                    <input
                        type="checkbox"
                        checked={form.is_default ?? false}
                        onChange={(event) => setForm({ ...form, is_default: event.target.checked })}
                    />
                    Đặt làm địa chỉ mặc định
                </label>
                <div className="flex gap-3 sm:col-span-2">
                    <button
                        type="submit"
                        disabled={save.isPending || provinces.isError || wards.isError}
                        className="dealer-action rounded-md bg-primary px-4 text-primary-foreground disabled:opacity-50"
                    >
                        {save.isPending ? "Đang lưu..." : "Lưu địa chỉ"}
                    </button>
                    {editingId !== null && (
                        <button
                            type="button"
                            onClick={() => {
                                setEditingId(null);
                                setForm(emptyAddress);
                                setErrors({});
                            }}
                            className="dealer-action rounded-md border px-4"
                        >
                            Hủy sửa
                        </button>
                    )}
                </div>
                {(provinces.isError || wards.isError) && (
                    <p role="alert" className="text-red-700 sm:col-span-2">
                        Không tải được danh sách địa phương. Vui lòng thử lại.
                    </p>
                )}
            </form>
        </section>
    );
}

function AddressField({
    label,
    name,
    value,
    error,
    onChange,
    required = true,
}: {
    label: string;
    name: string;
    value: string;
    error?: string | undefined;
    onChange: (value: string) => void;
    required?: boolean;
}) {
    return (
        <label className="grid gap-2 font-medium">
            {label}
            <input
                name={name}
                required={required}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="dealer-control rounded-md border bg-background px-3 font-normal"
                aria-invalid={Boolean(error)}
            />
            {error && <span className="text-red-700">{error}</span>}
        </label>
    );
}
