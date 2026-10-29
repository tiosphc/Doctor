import * as React from "react";
import { toast } from "sonner";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { buttonClass, secondaryButtonClass } from "./ProductAdminShared";
import {
    applyDealerPrices,
    dealerPriceSampleRows,
    parseDealerPriceFile,
    parseDealerPricePaste,
    previewDealerPrices,
    type DealerPriceImportRow,
    type DealerPriceTier,
} from "./dealerPriceImport";
import type { DealerRule } from "./productWizard";

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    tiers: DealerPriceTier[];
    productSkus: string[];
    currentRules: DealerRule[];
    onApply: (rules: DealerRule[]) => void;
};

export function DealerPriceImportDialog({
    open,
    onOpenChange,
    tiers,
    productSkus,
    currentRules,
    onApply,
}: Props) {
    const [tab, setTab] = React.useState("paste");
    const [paste, setPaste] = React.useState("");
    const [fileRows, setFileRows] = React.useState<DealerPriceImportRow[]>([]);
    const [fileName, setFileName] = React.useState("");
    const [fileError, setFileError] = React.useState("");
    const [loadingFile, setLoadingFile] = React.useState(false);
    const fileRequest = React.useRef(0);
    const sample = dealerPriceSampleRows(tiers, productSkus);
    const rows = tab === "paste" ? parseDealerPricePaste(paste) : fileRows;
    const preview = previewDealerPrices(rows, tiers, productSkus, currentRules);
    const invalidCount = preview.filter((row) => row.errors.length).length;
    const validCount = preview.length - invalidCount;
    const fileBlocked = tab === "file" && (Boolean(fileError) || loadingFile);

    const close = (nextOpen: boolean) => {
        if (!nextOpen) {
            fileRequest.current++;
            setTab("paste");
            setPaste("");
            setFileRows([]);
            setFileName("");
            setFileError("");
            setLoadingFile(false);
        }
        onOpenChange(nextOpen);
    };

    const readFile = async (file?: File) => {
        if (!file) return;
        const request = ++fileRequest.current;
        setFileName(file.name);
        setFileRows([]);
        setFileError("");
        setLoadingFile(true);
        try {
            const parsed = await parseDealerPriceFile(file);
            if (fileRequest.current === request) {
                setFileRows(parsed);
                if (!parsed.length) {
                    setFileError("File không có dòng giá nào.");
                    toast.error("File không có dòng giá nào.");
                }
            }
        } catch (error) {
            if (fileRequest.current === request) {
                const message = error instanceof Error ? error.message : "Không đọc được file.";
                setFileError(message);
                toast.error(message);
            }
        } finally {
            if (fileRequest.current === request) setLoadingFile(false);
        }
    };

    const downloadSample = async () => {
        try {
            const XLSX = await import("xlsx");
            const sheet = XLSX.utils.aoa_to_sheet([["Tier", "SKU", "MOQ", "Price"], ...sample]);
            sheet["!cols"] = [{ wch: 16 }, { wch: 28 }, { wch: 10 }, { wch: 18 }];
            const workbook = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(workbook, sheet, "Gia dai ly");
            const bytes = XLSX.write(workbook, { bookType: "xlsx", type: "array" });
            const url = URL.createObjectURL(
                new Blob([bytes], {
                    type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                }),
            );
            const anchor = document.createElement("a");
            anchor.href = url;
            anchor.download = "mau_gia_dai_ly.xlsx";
            anchor.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch {
            toast.error("Không tạo được file mẫu Excel.");
        }
    };

    const apply = () => {
        if (!preview.length || invalidCount || fileBlocked) return;
        try {
            onApply(applyDealerPrices(currentRules, preview));
            close(false);
            toast.success(`Đã điền ${preview.length} mức giá đại lý. Lưu sản phẩm để hoàn tất.`);
        } catch (error) {
            toast.error(error instanceof Error ? error.message : "Không áp dụng được giá đại lý.");
        }
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="flex max-h-[90vh] w-[min(96vw,960px)] max-w-none flex-col gap-4 overflow-hidden p-4 sm:p-6">
                <DialogHeader>
                    <DialogTitle>Import hàng loạt giá Đại lý</DialogTitle>
                    <DialogDescription>
                        Xem trước và kiểm tra từng dòng. Giá chỉ được đưa vào form sau khi bạn bấm
                        Áp dụng.
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 flex-1 overflow-y-auto pr-1">
                    <Tabs value={tab} onValueChange={setTab}>
                        <TabsList className="h-auto w-full justify-start gap-1">
                            <TabsTrigger value="paste">Copy &amp; Paste</TabsTrigger>
                            <TabsTrigger value="file">Upload Excel / CSV</TabsTrigger>
                        </TabsList>
                        <TabsContent value="paste" className="space-y-3">
                            <p className="text-sm">
                                Mỗi dòng gồm 4 giá trị, cách nhau bằng dấu cách:{" "}
                                <strong>Tier SKU MOQ Giá</strong>
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Ví dụ: Gold DEMO-SKU-01-1 1 204250
                            </p>
                            <textarea
                                aria-label="Dữ liệu giá đại lý"
                                className="min-h-40 w-full resize-y rounded-md border bg-background p-3 font-mono text-sm"
                                placeholder="Gold DEMO-SKU-01-1 1 204250"
                                value={paste}
                                onChange={(event) => setPaste(event.target.value)}
                            />
                            <button
                                type="button"
                                className={secondaryButtonClass}
                                disabled={!sample.length}
                                onClick={() =>
                                    setPaste(sample.map((row) => row.join(" ")).join("\n"))
                                }
                            >
                                Điền dữ liệu mẫu
                            </button>
                        </TabsContent>
                        <TabsContent value="file" className="space-y-3">
                            <p className="text-sm">
                                Chọn file .xlsx, .xls hoặc .csv có các cột Tier, SKU, MOQ, Price.
                            </p>
                            <div className="flex flex-wrap items-center gap-3">
                                <input
                                    aria-label="Chọn file giá đại lý"
                                    type="file"
                                    accept=".xlsx,.xls,.csv"
                                    className="max-w-full text-sm"
                                    onChange={(event) => {
                                        void readFile(event.target.files?.[0]);
                                        event.target.value = "";
                                    }}
                                />
                                <button
                                    type="button"
                                    className={secondaryButtonClass}
                                    onClick={() => void downloadSample()}
                                >
                                    Tải file mẫu Excel
                                </button>
                            </div>
                            {fileName && (
                                <p className="text-xs text-muted-foreground">
                                    {loadingFile ? "Đang đọc: " : "File: "}
                                    {fileName}
                                </p>
                            )}
                            {fileError && (
                                <p role="alert" className="text-sm text-red-700">
                                    {fileError}
                                </p>
                            )}
                        </TabsContent>
                    </Tabs>
                    {preview.length > 0 && (
                        <div className="mt-5 space-y-3">
                            <p className="text-sm font-medium" aria-live="polite">
                                <span className="text-emerald-700">✓ {validCount} hợp lệ</span>
                                <span className="ml-4 text-red-700">✕ {invalidCount} lỗi</span>
                            </p>
                            <div className="max-h-72 overflow-auto rounded-md border">
                                <table className="w-full min-w-[680px] text-left text-sm">
                                    <thead className="sticky top-0 bg-muted">
                                        <tr>
                                            {[
                                                "Dòng",
                                                "Tier",
                                                "SKU",
                                                "MOQ",
                                                "Giá",
                                                "Hành động",
                                                "Trạng thái",
                                            ].map((label) => (
                                                <th key={label} className="px-3 py-2 font-medium">
                                                    {label}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {preview.map((row) => (
                                            <React.Fragment key={row.line}>
                                                <tr className="border-t align-top">
                                                    <td className="px-3 py-2">{row.line}</td>
                                                    <td className="px-3 py-2">{row.tier || "—"}</td>
                                                    <td className="px-3 py-2 font-mono text-xs">
                                                        {row.sku || "—"}
                                                    </td>
                                                    <td className="px-3 py-2">{row.moq || "—"}</td>
                                                    <td className="px-3 py-2">
                                                        {row.price &&
                                                        Number.isFinite(Number(row.price))
                                                            ? `${new Intl.NumberFormat("vi-VN").format(Number(row.price))} VND`
                                                            : row.price || "—"}
                                                    </td>
                                                    <td className="px-3 py-2">{row.action}</td>
                                                    <td className="px-3 py-2">
                                                        <span
                                                            className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${row.errors.length ? "bg-red-50 text-red-700" : "bg-emerald-50 text-emerald-700"}`}
                                                        >
                                                            {row.errors.length ? "Lỗi" : "Hợp lệ"}
                                                        </span>
                                                    </td>
                                                </tr>
                                                {row.errors.length > 0 && (
                                                    <tr className="border-t bg-red-50/50">
                                                        <td
                                                            colSpan={7}
                                                            className="px-3 py-2 text-xs text-red-700"
                                                        >
                                                            {row.errors.map((reason) => (
                                                                <p key={reason}>
                                                                    Dòng {row.line}: {reason}
                                                                </p>
                                                            ))}
                                                        </td>
                                                    </tr>
                                                )}
                                            </React.Fragment>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                </div>
                <DialogFooter className="gap-2 border-t pt-4">
                    <button
                        type="button"
                        className={secondaryButtonClass}
                        onClick={() => close(false)}
                    >
                        Hủy
                    </button>
                    <button
                        type="button"
                        className={buttonClass}
                        disabled={!preview.length || invalidCount > 0 || fileBlocked}
                        onClick={apply}
                    >
                        Áp dụng
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
