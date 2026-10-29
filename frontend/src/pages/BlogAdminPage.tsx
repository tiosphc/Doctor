import { useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Navigate, Link } from "@tanstack/react-router";
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
import { adminApi, type BlogInput } from "@/services/adminApi";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { useAuth } from "@/contexts/AuthContext";

export function AdminBlogsPage() {
    const { user, isLoading } = useAuth();
    const client = useQueryClient();
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const [notice, setNotice] = useState("");
    const [noticeTone, setNoticeTone] = useState<"success" | "error">("success");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [createOpen, setCreateOpen] = useState(false);
    const [formKey, setFormKey] = useState(0);
    const query = useQuery({
        queryKey: ["admin-blogs", { search, page }],
        queryFn: () => adminApi.blogs({ search: search || undefined, page }),
        enabled: user?.role === "admin",
    });
    const categories = useQuery({
        queryKey: ["admin-blog-categories"],
        queryFn: () => adminApi.blogCategories(),
        enabled: user?.role === "admin",
    });
    const create = useMutation({
        mutationFn: (body: BlogInput) => adminApi.createBlog(body),
        onSuccess: async () => {
            setNoticeTone("success");
            setNotice("Đã tạo bài viết thành công.");
            setErrors({});
            setPage(1);
            setCreateOpen(false);
            setFormKey((value) => value + 1);
            await client.invalidateQueries({ queryKey: ["admin-blogs"] });
            await client.invalidateQueries({ queryKey: ["admin-blog-categories"] });
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
            await create.mutateAsync(form);
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
                    <h1 className="mt-2 text-3xl text-primary md:text-4xl">Danh sách bài viết</h1>
                    <p className="mt-3 max-w-3xl text-sm leading-6 text-muted-foreground">
                        Tìm kiếm và quản lý các bài viết kiến thức đã đăng trên Junie.
                    </p>
                </div>
                <Button
                    type="button"
                    onClick={openCreateDialog}
                    className="w-full shrink-0 sm:w-auto"
                >
                    <Plus className="size-4" aria-hidden="true" />
                    Thêm blog
                </Button>
            </div>
            <OperationNotice
                message={noticeTone === "success" ? notice : ""}
                success
                onClose={() => setNotice("")}
                className="mt-4"
            />
            <div className="mt-7 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 className="text-xl text-primary">Bài viết đã đăng</h2>
                <Input
                    className="max-w-sm"
                    value={search}
                    onChange={(event) => {
                        setSearch(event.target.value);
                        setPage(1);
                    }}
                    placeholder="Tìm bài viết..."
                />
            </div>
            <div className="mt-5 overflow-x-auto rounded-xl border bg-card shadow-sm">
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                ) : query.data.data.length === 0 ? (
                    <EmptyState message="Chưa tìm thấy bài viết." />
                ) : (
                    <table className="w-full min-w-[700px] text-sm">
                        <thead>
                            <tr className="border-b text-left text-muted-foreground">
                                <th className="p-4">Tiêu đề</th>
                                <th className="p-4">Danh mục</th>
                                <th className="p-4">Ngày đăng</th>
                                <th className="p-4">Xem</th>
                            </tr>
                        </thead>
                        <tbody>
                            {query.data.data.map((blog) => (
                                <tr
                                    key={blog.id}
                                    className="border-b transition-colors last:border-0 hover:bg-muted/30"
                                >
                                    <td className="p-4 font-medium text-primary">{blog.title}</td>
                                    <td className="p-4">{blog.category}</td>
                                    <td className="p-4">
                                        {new Intl.DateTimeFormat("vi-VN").format(
                                            new Date(blog.published_at),
                                        )}
                                    </td>
                                    <td className="p-4">
                                        <Link
                                            to="/blogs/$slug"
                                            params={{ slug: blog.slug }}
                                            className="font-semibold text-primary"
                                        >
                                            Xem bài
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
            {query.data && (
                <Pagination
                    current={query.data.meta.current_page}
                    last={query.data.meta.last_page}
                    onPage={setPage}
                />
            )}
            <Dialog open={createOpen} onOpenChange={changeCreateOpen}>
                <DialogContent className="max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-5xl gap-0 overflow-y-auto rounded-2xl p-0 shadow-2xl">
                    <div className="sticky top-0 z-10 border-b bg-background px-5 py-5 sm:px-7">
                        <DialogHeader className="pr-8">
                            <DialogTitle className="font-serif text-2xl text-primary sm:text-3xl">
                                Thêm blog
                            </DialogTitle>
                            <DialogDescription className="leading-6">
                                Tạo bài viết kiến thức mới và tải ảnh đại diện trực tiếp từ máy tính
                                của bạn.
                            </DialogDescription>
                        </DialogHeader>
                    </div>
                    <form
                        key={formKey}
                        onSubmit={submit}
                        encType="multipart/form-data"
                        className="grid gap-5 p-5 sm:p-7 md:grid-cols-2"
                    >
                        <Field label="Tiêu đề *" error={errors["title"]}>
                            <Input name="title" required />
                        </Field>
                        <div className="grid content-start gap-2">
                            <Field label="Danh mục *" error={errors["category_id"]}>
                                <select
                                    id="blog-category"
                                    name="category_id"
                                    required
                                    disabled={
                                        categories.isPending ||
                                        categories.isError ||
                                        categories.data?.data.length === 0
                                    }
                                    aria-invalid={Boolean(errors["category_id"]) || undefined}
                                    aria-describedby={
                                        categories.isPending ||
                                        categories.isError ||
                                        categories.data?.data.length === 0
                                            ? "blog-category-help"
                                            : undefined
                                    }
                                    className="min-h-12 w-full rounded-md border border-input bg-card px-4 text-sm outline-none transition focus:border-primary focus:ring-1 focus:ring-primary disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <option value="">
                                        {categories.isPending
                                            ? "Đang tải danh mục..."
                                            : categories.isError
                                              ? "Không thể tải danh mục"
                                              : categories.data?.data.length
                                                ? "Chọn danh mục"
                                                : "Chưa có danh mục"}
                                    </option>
                                    {categories.data?.data.map((category) => (
                                        <option key={category.id} value={category.id}>
                                            {category.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            {categories.isError ? (
                                <p
                                    id="blog-category-help"
                                    role="alert"
                                    className="text-xs text-red-700"
                                >
                                    Không thể tải danh mục. {errorMessage(categories.error)}{" "}
                                    <button
                                        type="button"
                                        className="font-semibold underline underline-offset-2"
                                        onClick={() => categories.refetch()}
                                    >
                                        Thử lại
                                    </button>
                                </p>
                            ) : categories.data?.data.length === 0 ? (
                                <p
                                    id="blog-category-help"
                                    className="text-xs text-muted-foreground"
                                >
                                    Chưa có danh mục. Vui lòng{" "}
                                    <Link
                                        to="/admin/blog-categories"
                                        className="font-semibold text-primary underline underline-offset-2"
                                    >
                                        thêm danh mục
                                    </Link>{" "}
                                    trước khi đăng bài.
                                </p>
                            ) : categories.isPending ? (
                                <p
                                    id="blog-category-help"
                                    className="text-xs text-muted-foreground"
                                >
                                    Đang tải danh mục...
                                </p>
                            ) : null}
                        </div>
                        <Field label="Mô tả ngắn *" error={errors["excerpt"]}>
                            <Textarea name="excerpt" required className="min-h-28" />
                        </Field>
                        <Field label="Ảnh đại diện" error={errors["image"]}>
                            <Input
                                name="image"
                                type="file"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                aria-invalid={Boolean(errors["image"]) || undefined}
                            />
                        </Field>
                        <div className="md:col-span-2">
                            <Field label="Nội dung *" error={errors["content"]}>
                                <Textarea name="content" required className="min-h-56" />
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
                            disabled={
                                create.isPending ||
                                categories.isPending ||
                                categories.isError ||
                                categories.data?.data.length === 0
                            }
                            className="w-full md:col-span-2"
                        >
                            {create.isPending ? "Đang tạo..." : "Đăng bài viết"}
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
