import { useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Navigate } from "@tanstack/react-router";
import { Plus } from "lucide-react";
import { Button } from "@/components/common/Button";
import { OperationNotice } from "@/components/common/Feedback";
import { Field, Input, Textarea } from "@/components/common/Fields";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { useAuth } from "@/contexts/AuthContext";
import { adminApi, type ServiceCategoryInput } from "@/services/adminApi";
import { errorMessage, firstFieldErrors } from "@/services/api";

export function AdminServiceCategoriesPage() {
    const { user, isLoading } = useAuth();
    const client = useQueryClient();
    const [notice, setNotice] = useState("");
    const [page, setPage] = useState(1);
    const [noticeTone, setNoticeTone] = useState<"success" | "error">("success");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [createOpen, setCreateOpen] = useState(false);
    const [formKey, setFormKey] = useState(0);
    const categories = useQuery({
        queryKey: ["admin-service-category-page", page],
        queryFn: () => adminApi.serviceCategoryPage(page),
        enabled: user?.role === "admin",
    });
    const create = useMutation({
        mutationFn: (body: ServiceCategoryInput) => adminApi.createServiceCategory(body),
        onSuccess: async () => {
            setNoticeTone("success");
            setNotice("Đã thêm danh mục dịch vụ thành công.");
            setErrors({});
            setPage(1);
            setCreateOpen(false);
            setFormKey((value) => value + 1);
            await client.invalidateQueries({ queryKey: ["admin-service-categories"] });
            await client.invalidateQueries({ queryKey: ["admin-service-category-page"] });
        },
    });

    if (isLoading) return <LoadingState label="Đang kiểm tra quyền quản trị..." />;
    if (!user) return <Navigate to="/login" />;
    if (user.role !== "admin") return <Navigate to="/account" />;

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setNotice("");
        setErrors({});
        const formElement = event.currentTarget;
        try {
            await create.mutateAsync(new FormData(formElement));
            formElement.reset();
        } catch (reason) {
            setNoticeTone("error");
            setNotice(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        }
    }

    function openCreateDialog() {
        setNotice("");
        setNoticeTone("success");
        setErrors({});
        setCreateOpen(true);
    }

    function changeCreateOpen(open: boolean) {
        if (create.isPending) return;

        setCreateOpen(open);
        if (!open) {
            setErrors({});
            if (noticeTone === "error") setNotice("");
            setFormKey((value) => value + 1);
        }
    }

    return (
        <>
            <div className="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                <div className="min-w-0">
                    <p className="label-luxury">Quản trị</p>
                    <h1 className="mt-2 text-3xl text-primary md:text-4xl">Danh mục dịch vụ</h1>
                    <p className="mt-3 max-w-3xl text-sm leading-6 text-muted-foreground">
                        Quản lý các nhóm dịch vụ để khách hàng dễ dàng tìm thấy liệu trình phù hợp.
                    </p>
                </div>
                <Button
                    type="button"
                    onClick={openCreateDialog}
                    className="w-full shrink-0 sm:w-auto"
                >
                    <Plus className="size-4" aria-hidden="true" />
                    Thêm danh mục
                </Button>
            </div>
            <OperationNotice
                message={noticeTone === "success" ? notice : ""}
                success
                onClose={() => setNotice("")}
                className="mt-4"
            />
            <section className="mt-7" aria-labelledby="service-category-list-title">
                <div>
                    <h2 id="service-category-list-title" className="text-xl text-primary">
                        Danh mục hiện có
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Mỗi dịch vụ mới cần thuộc một danh mục.
                    </p>
                </div>
                <div className="mt-5 overflow-x-auto rounded-xl border bg-card shadow-sm">
                    {categories.isPending ? (
                        <LoadingState label="Đang tải danh mục..." />
                    ) : categories.isError ? (
                        <ErrorState
                            message={errorMessage(categories.error)}
                            retry={() => categories.refetch()}
                        />
                    ) : categories.data.data.length === 0 ? (
                        <EmptyState message="Chưa có danh mục nào. Hãy thêm danh mục đầu tiên ở trên." />
                    ) : (
                        <table className="w-full min-w-[500px] text-sm">
                            <thead>
                                <tr className="border-b text-left text-muted-foreground">
                                    <th scope="col" className="p-4">
                                        Tên danh mục
                                    </th>
                                    <th scope="col" className="p-4">
                                        Slug
                                    </th>
                                    <th scope="col" className="p-4 text-right">
                                        Số dịch vụ
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {categories.data.data.map((category) => (
                                    <tr
                                        key={category.id}
                                        className="border-b transition-colors last:border-0 hover:bg-muted/30"
                                    >
                                        <th
                                            scope="row"
                                            className="p-4 text-left font-medium text-primary"
                                        >
                                            {category.name}
                                        </th>
                                        <td className="p-4 text-muted-foreground">
                                            {category.slug}
                                        </td>
                                        <td className="p-4 text-right">
                                            {category.services_count}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
                {categories.data && (
                    <Pagination
                        current={categories.data.meta.current_page}
                        last={categories.data.meta.last_page}
                        onPage={setPage}
                    />
                )}
            </section>
            <Dialog open={createOpen} onOpenChange={changeCreateOpen}>
                <DialogContent className="max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-2xl gap-0 overflow-y-auto rounded-2xl p-0 shadow-2xl">
                    <div className="border-b bg-background px-5 py-5 sm:px-7">
                        <DialogHeader className="pr-8">
                            <DialogTitle className="font-serif text-2xl text-primary sm:text-3xl">
                                Thêm danh mục
                            </DialogTitle>
                            <DialogDescription className="leading-6">
                                Tạo nhóm dịch vụ mới và tải ảnh bìa trực tiếp từ máy tính của bạn.
                            </DialogDescription>
                        </DialogHeader>
                    </div>
                    <form
                        key={formKey}
                        onSubmit={submit}
                        encType="multipart/form-data"
                        className="grid gap-5 p-5 sm:p-7 md:grid-cols-2"
                    >
                        <Field label="Tên danh mục *" error={errors["name"]}>
                            <Input
                                name="name"
                                required
                                maxLength={100}
                                placeholder="Ví dụ: Chăm sóc da"
                                aria-invalid={Boolean(errors["name"]) || undefined}
                            />
                        </Field>
                        <Field label="Ảnh bìa" error={errors["hero_image"]}>
                            <Input
                                name="hero_image"
                                type="file"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                aria-invalid={Boolean(errors["hero_image"]) || undefined}
                            />
                        </Field>
                        <div className="md:col-span-2">
                            <Field label="Mô tả ngắn" error={errors["short_description"]}>
                                <Textarea
                                    name="short_description"
                                    maxLength={1000}
                                    className="min-h-28"
                                    aria-invalid={Boolean(errors["short_description"]) || undefined}
                                />
                            </Field>
                        </div>
                        <OperationNotice
                            message={noticeTone === "error" ? notice : ""}
                            success={false}
                            onClose={() => setNotice("")}
                            className="md:col-span-2"
                        />
                        <Button
                            type="submit"
                            disabled={create.isPending}
                            className="w-full md:col-span-2"
                        >
                            {create.isPending ? "Đang thêm..." : "Thêm danh mục"}
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
