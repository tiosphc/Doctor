import { useState, type ReactNode } from "react";
import { useMutation, useQuery, useQueryClient, type UseQueryResult } from "@tanstack/react-query";
import { Link, Navigate } from "@tanstack/react-router";
import { ArrowRight, Plus } from "lucide-react";
import { Button } from "@/components/common/Button";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { OperationNotice } from "@/components/common/Feedback";
import { Input } from "@/components/common/Fields";
import { ServiceForm } from "@/components/services/ServiceForm";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { useAuth } from "@/contexts/AuthContext";
import { adminApi } from "@/services/adminApi";
import { errorMessage, firstFieldErrors } from "@/services/api";
import type { PaginatedResponse, Service } from "@/types";

function AdminGuard({ children }: { children: ReactNode }) {
    const { user, isLoading } = useAuth();
    if (isLoading) return <LoadingState label="Đang kiểm tra quyền quản trị..." />;
    if (!user) return <Navigate to="/login" />;
    if (user.role !== "admin") return <Navigate to="/account" />;
    return children;
}

function AdminTitle({
    title,
    description,
    action,
}: {
    title: string;
    description: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div className="min-w-0">
                <p className="label-luxury">Quản trị</p>
                <h1 className="mt-2 text-3xl text-primary md:text-4xl">{title}</h1>
                <p className="mt-3 max-w-3xl text-sm leading-6 text-muted-foreground">
                    {description}
                </p>
            </div>
            {action && <div className="shrink-0">{action}</div>}
        </div>
    );
}

export function AdminServiceCreatePage() {
    const client = useQueryClient();
    const [notice, setNotice] = useState("");
    const [formNotice, setFormNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formKey, setFormKey] = useState(0);
    const [createOpen, setCreateOpen] = useState(false);
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const categories = useQuery({
        queryKey: ["admin-service-categories"],
        queryFn: () => adminApi.serviceCategories(),
    });
    const query = useQuery({
        queryKey: ["admin-services", { editorial: true, search, page }],
        queryFn: () => adminApi.services({ search: search || undefined, page }),
    });
    const create = useMutation({
        mutationFn: adminApi.createService,
        onSuccess: async () => {
            setNotice("Đã tạo dịch vụ.");
            setFormNotice("");
            setErrors({});
            setFormKey((value) => value + 1);
            setCreateOpen(false);
            await client.invalidateQueries({ queryKey: ["admin-services"] });
            await client.invalidateQueries({ queryKey: ["admin-service-categories"] });
        },
    });
    async function submit(payload: FormData) {
        setFormNotice("");
        setErrors({});
        try {
            await create.mutateAsync(payload);
        } catch (reason) {
            setFormNotice(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        }
    }

    function openCreateDialog() {
        setFormNotice("");
        setErrors({});
        setCreateOpen(true);
    }

    function changeCreateOpen(open: boolean) {
        if (create.isPending) return;

        setCreateOpen(open);
        if (!open) {
            setFormNotice("");
            setErrors({});
            setFormKey((value) => value + 1);
        }
    }

    return (
        <AdminGuard>
            <AdminTitle
                title="Danh sách dịch vụ"
                description="Tìm kiếm và quản lý nội dung, hình ảnh, giá và trạng thái của các dịch vụ hiện có."
                action={
                    <Button
                        type="button"
                        onClick={openCreateDialog}
                        className="w-full shrink-0 sm:w-auto"
                    >
                        <Plus className="size-4" />
                        Thêm dịch vụ
                    </Button>
                }
            />
            <OperationNotice
                message={notice}
                success
                onClose={() => setNotice("")}
                className="mt-4"
            />
            <div className="mt-7 rounded-xl border bg-card p-4 shadow-sm">
                <Input
                    value={search}
                    onChange={(event) => {
                        setSearch(event.target.value);
                        setPage(1);
                    }}
                    placeholder="Tìm dịch vụ..."
                    aria-label="Tìm dịch vụ"
                />
            </div>
            <ServiceListing query={query} onPage={setPage} />
            <Dialog open={createOpen} onOpenChange={changeCreateOpen}>
                <DialogContent className="max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-6xl gap-0 overflow-y-auto rounded-2xl p-0 shadow-2xl">
                    <div className="sticky top-0 z-10 border-b bg-background px-5 py-5 sm:px-7">
                        <DialogHeader className="pr-8">
                            <DialogTitle className="font-serif text-2xl text-primary sm:text-3xl">
                                Thêm dịch vụ
                            </DialogTitle>
                            <DialogDescription className="leading-6">
                                Tạo dịch vụ, biên tập nội dung chi tiết và tải ảnh trực tiếp từ máy
                                tính của bạn.
                            </DialogDescription>
                        </DialogHeader>
                    </div>
                    <div className="p-4 sm:p-6">
                        <ServiceForm
                            key={formKey}
                            categories={categories.data?.data ?? []}
                            categoriesLoading={categories.isPending}
                            categoriesError={categories.isError}
                            isSubmitting={create.isPending}
                            serverErrors={errors}
                            notice={formNotice}
                            onDismissNotice={() => setFormNotice("")}
                            onSubmit={submit}
                        />
                    </div>
                </DialogContent>
            </Dialog>
        </AdminGuard>
    );
}

export function AdminServiceEditPage({ id }: { id: number }) {
    const client = useQueryClient();
    const [notice, setNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const service = useQuery({
        queryKey: ["admin-service", id],
        queryFn: () => adminApi.service(id),
        retry: false,
    });
    const categories = useQuery({
        queryKey: ["admin-service-categories"],
        queryFn: () => adminApi.serviceCategories(),
    });
    const update = useMutation({
        mutationFn: (payload: FormData) => adminApi.updateService(id, payload),
        onSuccess: async () => {
            setNotice("Đã lưu thay đổi.");
            setErrors({});
            await client.invalidateQueries({ queryKey: ["admin-service", id] });
            await client.invalidateQueries({ queryKey: ["admin-services"] });
            await client.invalidateQueries({ queryKey: ["service-detail"] });
        },
    });
    async function submit(payload: FormData) {
        setNotice("");
        setErrors({});
        try {
            await update.mutateAsync(payload);
        } catch (reason) {
            setNotice(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        }
    }
    return (
        <AdminGuard>
            <AdminTitle
                title={
                    service.data?.data.name
                        ? `Chỉnh sửa: ${service.data.data.name}`
                        : "Chỉnh sửa dịch vụ"
                }
                description="Cập nhật thông tin cơ bản, nội dung biên tập, hình ảnh và SEO của dịch vụ."
            />
            <div className="mt-7">
                {service.isPending ? (
                    <LoadingState label="Đang tải dịch vụ..." />
                ) : service.isError ? (
                    <ErrorState
                        message={errorMessage(service.error)}
                        retry={() => service.refetch()}
                    />
                ) : (
                    <ServiceForm
                        service={service.data.data}
                        categories={categories.data?.data ?? []}
                        categoriesLoading={categories.isPending}
                        categoriesError={categories.isError}
                        isSubmitting={update.isPending}
                        serverErrors={errors}
                        notice={notice}
                        onDismissNotice={() => setNotice("")}
                        onSubmit={submit}
                    />
                )}
            </div>
        </AdminGuard>
    );
}

function ServiceListing({
    query,
    onPage,
}: {
    query: UseQueryResult<PaginatedResponse<Service>>;
    onPage: (page: number) => void;
}) {
    return (
        <section className="mt-5" aria-label="Danh sách dịch vụ hiện có">
            {query.isPending ? (
                <LoadingState />
            ) : query.isError ? (
                <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
            ) : query.data?.data.length === 0 ? (
                <EmptyState message="Chưa có dịch vụ." />
            ) : (
                <div className="grid gap-4">
                    {query.data.data.map((service: Service) => (
                        <article
                            key={service.id}
                            className="card-surface flex flex-wrap items-center justify-between gap-4 p-5"
                        >
                            <div className="flex min-w-0 items-center gap-4">
                                {service.image ? (
                                    <img
                                        src={service.image}
                                        alt={service.name}
                                        className="size-16 shrink-0 rounded-lg border object-cover"
                                    />
                                ) : (
                                    <div className="size-16 shrink-0 rounded-lg bg-muted" />
                                )}
                                <div className="min-w-0">
                                    <p className="text-xs text-muted-foreground">
                                        {service.category || "Chưa phân loại"}
                                    </p>
                                    <h3 className="truncate text-lg font-semibold text-primary">
                                        {service.name}
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        {service.duration} phút · {service.price}
                                    </p>
                                </div>
                            </div>
                            <Link
                                to="/admin/services/$id"
                                params={{ id: String(service.id) }}
                                className="focus-premium inline-flex items-center gap-2 rounded-full border border-secondary px-4 py-2 text-sm font-semibold text-primary hover:bg-muted"
                            >
                                Chỉnh sửa <ArrowRight size={15} aria-hidden="true" />
                            </Link>
                        </article>
                    ))}
                </div>
            )}
            {query.data && (
                <Pagination
                    current={query.data.meta.current_page}
                    last={query.data.meta.last_page}
                    onPage={onPage}
                />
            )}
        </section>
    );
}
