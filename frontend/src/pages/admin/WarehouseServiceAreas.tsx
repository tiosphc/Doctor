import { useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { inventoryApi, inventoryKeys } from "@/services/inventoryApi";
import type { WarehouseServiceArea } from "@/types/inventory";
import { buttonClass, fieldClass, secondaryButtonClass } from "./ProductAdminShared";

export function WarehouseServiceAreas() {
    const client = useQueryClient();
    const [search, setSearch] = useState("");
    const [warehouseFilter, setWarehouseFilter] = useState("");
    const [provinceFilter, setProvinceFilter] = useState("");
    const [page, setPage] = useState(1);
    const [warehouseId, setWarehouseId] = useState("");
    const [provinceCode, setProvinceCode] = useState("");
    const [priority, setPriority] = useState(1);
    const [editing, setEditing] = useState<WarehouseServiceArea | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const filters = {
        search,
        warehouse_id: warehouseFilter ? Number(warehouseFilter) : undefined,
        province_code: provinceFilter,
        page,
    };
    const options = useQuery({
        queryKey: ["warehouse-service-area-options"],
        queryFn: inventoryApi.warehouseServiceAreaOptions,
    });
    const areas = useQuery({
        queryKey: inventoryKeys.warehouseServiceAreas(filters),
        queryFn: () => inventoryApi.warehouseServiceAreas(filters),
    });
    const save = useMutation({
        mutationFn: () =>
            editing
                ? inventoryApi.updateWarehouseServiceArea(editing.id, priority)
                : inventoryApi.createWarehouseServiceArea({
                      warehouse_id: Number(warehouseId),
                      province_code: provinceCode,
                      priority,
                  }),
        onSuccess: async () => {
            toast.success(editing ? "Đã cập nhật độ ưu tiên." : "Đã thêm vùng phục vụ kho.");
            setEditing(null);
            setWarehouseId("");
            setProvinceCode("");
            setPriority(1);
            setErrors({});
            await client.invalidateQueries({ queryKey: ["warehouse-service-areas"] });
        },
        onError: (error) => {
            setErrors(firstFieldErrors(error));
            toast.error(errorMessage(error));
        },
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        save.mutate();
    }

    return (
        <section id="service-areas" className="space-y-5 scroll-mt-24">
            <header>
                <h2 className="text-2xl text-primary">Vùng phục vụ kho</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Gán kho theo tỉnh và độ ưu tiên để hệ thống chọn kho khi giao hàng.
                </p>
            </header>
            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(300px,380px)]">
                <div className="min-w-0 rounded-xl border bg-card p-5">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <input
                            className={fieldClass}
                            aria-label="Tìm vùng phục vụ"
                            placeholder="Tìm kho hoặc tỉnh"
                            value={search}
                            onChange={(event) => {
                                setSearch(event.target.value);
                                setPage(1);
                            }}
                        />
                        <select
                            className={fieldClass}
                            aria-label="Lọc theo kho"
                            value={warehouseFilter}
                            onChange={(event) => {
                                setWarehouseFilter(event.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">Tất cả kho</option>
                            {options.data?.warehouses.map((warehouse) => (
                                <option key={warehouse.id} value={warehouse.id}>
                                    {warehouse.name}
                                </option>
                            ))}
                        </select>
                        <select
                            className={fieldClass}
                            aria-label="Lọc theo tỉnh"
                            value={provinceFilter}
                            onChange={(event) => {
                                setProvinceFilter(event.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">Tất cả tỉnh</option>
                            {options.data?.provinces.map((province) => (
                                <option key={province.code} value={province.code}>
                                    {province.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    {areas.isPending ? (
                        <LoadingState />
                    ) : areas.isError ? (
                        <ErrorState
                            message={errorMessage(areas.error)}
                            retry={() => void areas.refetch()}
                        />
                    ) : areas.data.data.length === 0 ? (
                        <EmptyState message="Chưa có vùng phục vụ phù hợp." />
                    ) : (
                        <>
                            <div className="mt-4 divide-y">
                                {areas.data.data.map((area) => (
                                    <div
                                        key={area.id}
                                        className="flex flex-wrap items-center justify-between gap-3 py-4 text-sm"
                                    >
                                        <div>
                                            <p className="font-semibold text-primary">
                                                {area.province.name} · {area.warehouse.name}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {area.warehouse.code} · Ưu tiên {area.priority}
                                                {area.warehouse.status === "inactive"
                                                    ? " · Kho ngừng hoạt động"
                                                    : ""}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            className={secondaryButtonClass}
                                            onClick={() => {
                                                setEditing(area);
                                                setPriority(area.priority);
                                                setErrors({});
                                            }}
                                        >
                                            Sửa ưu tiên
                                        </button>
                                    </div>
                                ))}
                            </div>
                            <Pagination
                                current={areas.data.current_page}
                                last={areas.data.last_page}
                                onPage={setPage}
                            />
                        </>
                    )}
                </div>
                <form
                    onSubmit={submit}
                    className="grid content-start gap-4 rounded-xl border bg-card p-5 text-sm"
                >
                    <h3 className="text-xl text-primary">
                        {editing ? "Cập nhật độ ưu tiên" : "Thêm vùng phục vụ"}
                    </h3>
                    {editing ? (
                        <p className="text-muted-foreground">
                            {editing.province.name} · {editing.warehouse.name}
                        </p>
                    ) : (
                        <>
                            <label className="grid gap-2 font-medium">
                                Kho hàng
                                <select
                                    required
                                    className={fieldClass}
                                    value={warehouseId}
                                    onChange={(event) => setWarehouseId(event.target.value)}
                                >
                                    <option value="">Chọn kho</option>
                                    {options.data?.warehouses.map((warehouse) => (
                                        <option key={warehouse.id} value={warehouse.id}>
                                            {warehouse.name} ({warehouse.code})
                                        </option>
                                    ))}
                                </select>
                                {errors["warehouse_id"] && (
                                    <span className="text-red-700">{errors["warehouse_id"]}</span>
                                )}
                            </label>
                            <label className="grid gap-2 font-medium">
                                Tỉnh / Thành phố
                                <select
                                    required
                                    className={fieldClass}
                                    value={provinceCode}
                                    onChange={(event) => setProvinceCode(event.target.value)}
                                >
                                    <option value="">Chọn tỉnh</option>
                                    {options.data?.provinces.map((province) => (
                                        <option key={province.code} value={province.code}>
                                            {province.name}
                                        </option>
                                    ))}
                                </select>
                                {errors["province_code"] && (
                                    <span className="text-red-700">{errors["province_code"]}</span>
                                )}
                            </label>
                        </>
                    )}
                    <label className="grid gap-2 font-medium">
                        Độ ưu tiên
                        <input
                            type="number"
                            min={0}
                            max={65535}
                            required
                            className={fieldClass}
                            value={priority}
                            onChange={(event) => setPriority(Number(event.target.value))}
                        />
                        {errors["priority"] && (
                            <span className="text-red-700">{errors["priority"]}</span>
                        )}
                    </label>
                    <div className="flex flex-wrap gap-3">
                        <button
                            type="submit"
                            disabled={save.isPending || options.isPending || options.isError}
                            className={buttonClass}
                        >
                            {save.isPending ? "Đang lưu..." : "Lưu"}
                        </button>
                        {editing && (
                            <button
                                type="button"
                                className={secondaryButtonClass}
                                onClick={() => {
                                    setEditing(null);
                                    setPriority(1);
                                    setErrors({});
                                }}
                            >
                                Hủy sửa
                            </button>
                        )}
                    </div>
                    {options.isError && (
                        <p role="alert" className="text-red-700">
                            {errorMessage(options.error)}
                        </p>
                    )}
                </form>
            </div>
        </section>
    );
}
