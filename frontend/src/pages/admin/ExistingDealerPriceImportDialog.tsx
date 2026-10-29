import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { errorMessage } from "@/services/api";
import { productApi, type DealerPriceImportPreview } from "@/services/productApi";
import { buttonClass, fieldClass, secondaryButtonClass } from "./ProductAdminShared";

export function ExistingDealerPriceImportDialog({
    product,
    onClose,
}: {
    product: { id: number; name: string };
    onClose: () => void;
}) {
    const client = useQueryClient();
    const [paste, setPaste] = useState("");
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<DealerPriceImportPreview | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");

    async function showPreview() {
        setBusy(true);
        setError("");
        setPreview(null);
        try {
            setPreview(await productApi.previewDealerPriceImport(product.id, paste, file));
        } catch (reason) {
            setError(errorMessage(reason));
        } finally {
            setBusy(false);
        }
    }

    async function apply() {
        if (!preview?.preview_token) return;
        setBusy(true);
        setError("");
        try {
            const result = await productApi.applyDealerPriceImport(
                product.id,
                preview.preview_token,
            );
            await client.invalidateQueries({ queryKey: ["catalog-prices", "dealer"] });
            toast.success(`Đã áp dụng ${result.applied_count} dòng giá đại lý.`);
            onClose();
        } catch (reason) {
            setPreview(null);
            setError(errorMessage(reason));
        } finally {
            setBusy(false);
        }
    }

    async function downloadTemplate() {
        try {
            const blob = await productApi.dealerPriceImportTemplate(product.id);
            const url = URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.href = url;
            link.download = `mau_gia_dai_ly_${product.id}.csv`;
            link.click();
            URL.revokeObjectURL(url);
        } catch (reason) {
            toast.error(errorMessage(reason));
        }
    }

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
        >
            <DialogContent className="max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl overflow-y-auto p-5 sm:p-6">
                <DialogHeader>
                    <DialogTitle>Import giá đại lý · {product.name}</DialogTitle>
                    <DialogDescription>
                        Mỗi dòng nhập theo thứ tự Tier SKU MOQ Giá. Có thể tải file CSV, XLS hoặc
                        XLSX.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4 text-sm">
                    <button
                        type="button"
                        className={`${secondaryButtonClass} justify-self-start`}
                        onClick={() => void downloadTemplate()}
                    >
                        Tải mẫu CSV
                    </button>
                    <label className="grid gap-2 font-medium">
                        Dán danh sách giá
                        <textarea
                            className={`${fieldClass} min-h-32 font-mono`}
                            value={paste}
                            disabled={Boolean(file)}
                            onChange={(event) => {
                                setPaste(event.target.value);
                                setPreview(null);
                            }}
                            placeholder="GOLD JUNIE-SKU-01 1 100000"
                        />
                    </label>
                    <label className="grid gap-2 font-medium">
                        Hoặc chọn file
                        <input
                            type="file"
                            accept=".csv,.xls,.xlsx"
                            className={fieldClass}
                            onChange={(event) => {
                                setFile(event.target.files?.[0] ?? null);
                                setPreview(null);
                            }}
                        />
                    </label>
                    <button
                        type="button"
                        className={`${secondaryButtonClass} justify-self-start`}
                        disabled={busy || (!paste.trim() && !file)}
                        onClick={() => void showPreview()}
                    >
                        {busy ? "Đang xử lý..." : "Xem trước"}
                    </button>
                    {error && (
                        <p role="alert" className="rounded-md bg-red-50 p-3 text-red-700">
                            {error}
                        </p>
                    )}
                    {preview && (
                        <div className="min-w-0 overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-[640px] text-left text-sm">
                                <thead className="bg-muted/40">
                                    <tr>
                                        <th className="p-3">Dòng</th>
                                        <th className="p-3">Tier</th>
                                        <th className="p-3">SKU</th>
                                        <th className="p-3">MOQ</th>
                                        <th className="p-3">Giá</th>
                                        <th className="p-3">Kết quả</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {preview.rows.map((row) => (
                                        <tr key={row.line}>
                                            <td className="p-3">{row.line}</td>
                                            <td className="p-3">{row.tier}</td>
                                            <td className="p-3 font-mono">{row.sku}</td>
                                            <td className="p-3">{row.moq}</td>
                                            <td className="p-3">{row.price}</td>
                                            <td
                                                className={`p-3 ${row.errors.length ? "text-red-700" : "text-emerald-700"}`}
                                            >
                                                {row.errors.length
                                                    ? row.errors.join(" · ")
                                                    : row.action}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {preview?.can_apply && (
                        <button
                            type="button"
                            className={`${buttonClass} justify-self-start`}
                            disabled={busy}
                            onClick={() => void apply()}
                        >
                            {busy ? "Đang áp dụng..." : `Áp dụng ${preview.rows.length} dòng giá`}
                        </button>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
