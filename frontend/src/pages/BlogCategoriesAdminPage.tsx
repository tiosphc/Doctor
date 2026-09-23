import { useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Navigate } from "@tanstack/react-router";
import { Button } from "@/components/common/Button";
import { OperationNotice } from "@/components/common/Feedback";
import { Field, Input } from "@/components/common/Fields";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { useAuth } from "@/contexts/AuthContext";
import { adminApi, type BlogCategoryInput } from "@/services/adminApi";
import { errorMessage, firstFieldErrors } from "@/services/api";

export function AdminBlogCategoriesPage() {
    const { user, isLoading } = useAuth();
    const client = useQueryClient();
    const [notice, setNotice] = useState("");
    const [page, setPage] = useState(1);
    const [noticeTone, setNoticeTone] = useState<"success" | "error">("success");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const categories = useQuery({
        queryKey: ["admin-blog-category-page", page],
        queryFn: () => adminApi.blogCategoryPage(page),
        enabled: user?.role === "admin",
    });
    const create = useMutation({
        mutationFn: (body: BlogCategoryInput) => adminApi.createBlogCategory(body),
        onSuccess: async () => {
            setNoticeTone("success");
            setNotice("Đã thêm danh mục thành công.");
            setErrors({});
            setPage(1);
            await client.invalidateQueries({ queryKey: ["admin-blog-categories"] });
            await client.invalidateQueries({ queryKey: ["admin-blog-category-page"] });
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
        const form = new FormData(formElement);
        try {
            await create.mutateAsync({ name: String(form.get("name") ?? "").trim() });
            formElement.reset();
        } catch (reason) {
            setNoticeTone("error");
            setNotice(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        }
    }

    return (
        <>
            <div>
                <p className="label-luxury">Quản trị</p>
                <h1 className="mt-2 text-3xl text-primary md:text-4xl">Thêm danh mục</h1>
                <p className="mt-3 text-sm text-muted-foreground">
                    Tạo các nhóm nội dung để sắp xếp bài viết kiến thức trên Junie.
                </p>
            </div>
            <form
                onSubmit={submit}
                className="card-surface mt-7 grid gap-4 p-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
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
                <Button type="submit" disabled={create.isPending} className="w-full sm:w-auto">
                    {create.isPending ? "Đang thêm..." : "Thêm danh mục"}
                </Button>
            </form>
            <OperationNotice
                message={notice}
                success={noticeTone === "success"}
                onClose={() => setNotice("")}
                className="mt-4"
            />
            <section className="mt-8">
                <h2 className="text-xl text-primary">Danh mục hiện có</h2>
                <div className="mt-5 overflow-x-auto rounded-md border bg-card">
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
                        <table className="w-full min-w-[460px] text-sm">
                            <thead>
                                <tr className="border-b text-left text-muted-foreground">
                                    <th scope="col" className="p-4">
                                        Tên danh mục
                                    </th>
                                    <th scope="col" className="p-4">
                                        Slug
                                    </th>
                                    <th scope="col" className="p-4 text-right">
                                        Số bài viết
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {categories.data.data.map((category) => (
                                    <tr key={category.id} className="border-b last:border-0">
                                        <th
                                            scope="row"
                                            className="p-4 text-left font-medium text-primary"
                                        >
                                            {category.name}
                                        </th>
                                        <td className="p-4 text-muted-foreground">
                                            {category.slug}
                                        </td>
                                        <td className="p-4 text-right">{category.blogs_count}</td>
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
        </>
    );
}
