import { useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { UserPlus } from "lucide-react";
import { AdminGuard, AdminTitle } from "@/pages/admin/AdminPages";
import { Button } from "@/components/common/Button";
import { OperationNotice } from "@/components/common/Feedback";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { Field, Input } from "@/components/common/Fields";
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { adminApi } from "@/services/adminApi";
import { errorMessage, firstFieldErrors } from "@/services/api";
import type { StaffAccountInput } from "@/services/staffApi";
import type { StaffUser } from "@/types";

export function AdminStaffPage() {
    const client = useQueryClient();
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [editing, setEditing] = useState<StaffUser | null>(null);
    const [editorOpen, setEditorOpen] = useState(false);
    const [deleting, setDeleting] = useState<StaffUser | null>(null);
    const [notice, setNotice] = useState("");
    const [formNotice, setFormNotice] = useState("");
    const [noticeSuccess, setNoticeSuccess] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const query = useQuery({
        queryKey: ["admin-staff", { page, search, role: "receptionist" }],
        queryFn: () => adminApi.staff({ page, search: search || undefined, role: "receptionist" }),
    });
    const mutation = useMutation({
        mutationFn: ({
            id,
            body,
        }: {
            id?: number;
            body: StaffAccountInput | Partial<StaffAccountInput>;
        }) =>
            id ? adminApi.updateStaff(id, body) : adminApi.createStaff(body as StaffAccountInput),
        onSuccess: async (_data, variables) => {
            setNotice(
                variables.id ? "Cập nhật nhân viên thành công." : "Tạo nhân viên thành công.",
            );
            setNoticeSuccess(true);
            setEditing(null);
            setEditorOpen(false);
            await client.invalidateQueries({ queryKey: ["admin-staff"] });
        },
    });
    const deleteMutation = useMutation({
        mutationFn: (id: number) => adminApi.deleteStaff(id),
        onSuccess: async (response) => {
            setDeleting(null);
            setNotice(response.message);
            setNoticeSuccess(true);
            await client.invalidateQueries({ queryKey: ["admin-staff"] });
        },
        onError: (reason) => {
            setNotice(errorMessage(reason));
            setNoticeSuccess(false);
            toast.error(errorMessage(reason));
        },
    });
    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const formElement = event.currentTarget;
        setFormNotice("");
        setErrors({});
        const form = new FormData(formElement);
        const password = String(form.get("password") ?? "");
        const passwordConfirmation = String(form.get("password_confirmation") ?? "");
        const sharedFields = {
            name: String(form.get("name") ?? "").trim(),
            email: String(form.get("email") ?? "").trim(),
            phone: String(form.get("phone") ?? "").trim(),
        };
        const body: StaffAccountInput | Partial<StaffAccountInput> = editing
            ? {
                  ...sharedFields,
                  ...(password ? { password, password_confirmation: passwordConfirmation } : {}),
              }
            : {
                  ...sharedFields,
                  role: "receptionist",
                  password,
                  password_confirmation: passwordConfirmation,
              };
        try {
            await mutation.mutateAsync(editing ? { id: editing.id, body } : { body });
            formElement.reset();
        } catch (reason) {
            const fieldErrors = firstFieldErrors(reason);
            setErrors(fieldErrors);

            if (Object.keys(fieldErrors).length === 0) {
                setFormNotice(errorMessage(reason));
            }
        }
    }

    function openCreateDialog() {
        setEditing(null);
        setErrors({});
        setFormNotice("");
        setEditorOpen(true);
    }

    function openEditDialog(staff: StaffUser) {
        setEditing(staff);
        setErrors({});
        setFormNotice("");
        setEditorOpen(true);
    }

    function changeEditorOpen(open: boolean) {
        if (mutation.isPending) return;

        setEditorOpen(open);
        if (!open) {
            setEditing(null);
            setErrors({});
            setFormNotice("");
        }
    }
    return (
        <AdminGuard>
            <AdminTitle
                title="Danh sách nhân viên"
                description="Tìm kiếm, tạo và quản lý tài khoản nhân viên lễ tân."
                action={
                    <Button
                        type="button"
                        onClick={openCreateDialog}
                        className="w-full shrink-0 sm:w-auto"
                    >
                        <UserPlus className="size-4" />
                        Thêm nhân viên
                    </Button>
                }
            />
            <Dialog open={editorOpen} onOpenChange={changeEditorOpen}>
                <DialogContent className="max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-2xl gap-0 overflow-y-auto rounded-2xl p-0 shadow-2xl">
                    <div className="border-b bg-muted/35 px-5 py-5 sm:px-7">
                        <DialogHeader className="pr-8">
                            <div className="mb-2 grid size-11 place-items-center rounded-full bg-primary text-primary-foreground">
                                <UserPlus className="size-5" />
                            </div>
                            <DialogTitle className="font-sans text-xl font-bold leading-snug text-primary sm:text-2xl">
                                {editing ? "Chỉnh sửa nhân viên" : "Thêm nhân viên mới"}
                            </DialogTitle>
                            <DialogDescription className="max-w-xl leading-6">
                                {editing
                                    ? "Cập nhật thông tin tài khoản và đổi mật khẩu khi cần."
                                    : "Tạo tài khoản lễ tân để tiếp nhận và vận hành lịch hẹn."}
                            </DialogDescription>
                        </DialogHeader>
                    </div>
                    <form key={editing?.id ?? "new"} onSubmit={submit}>
                        <div className="grid items-start gap-x-5 gap-y-4 px-5 py-6 sm:px-7 md:grid-cols-2">
                            <Field label="Họ và tên *" error={errors["name"]}>
                                <Input
                                    name="name"
                                    defaultValue={editing?.name ?? ""}
                                    autoFocus
                                    required
                                />
                            </Field>
                            <Field label="Email *" error={errors["email"]}>
                                <Input
                                    name="email"
                                    type="email"
                                    autoComplete="email"
                                    defaultValue={editing?.email ?? ""}
                                    required
                                />
                            </Field>
                            <Field label="Số điện thoại" error={errors["phone"]}>
                                <Input
                                    name="phone"
                                    type="tel"
                                    autoComplete="tel"
                                    defaultValue={editing?.phone ?? ""}
                                />
                            </Field>
                            <div className="flex min-h-12 items-center rounded-xl border border-blue-100 bg-blue-50/70 px-4 text-sm text-blue-950">
                                Vai trò: <strong className="ml-1">Lễ tân</strong>
                            </div>
                            <Field
                                label={editing ? "Mật khẩu mới (tùy chọn)" : "Mật khẩu *"}
                                error={errors["password"]}
                            >
                                <Input
                                    name="password"
                                    type="password"
                                    autoComplete="new-password"
                                    required={!editing}
                                />
                            </Field>
                            <Field
                                label="Xác nhận mật khẩu"
                                error={errors["password_confirmation"]}
                            >
                                <Input
                                    name="password_confirmation"
                                    type="password"
                                    autoComplete="new-password"
                                    required={!editing}
                                />
                            </Field>
                            {editing && (
                                <p className="text-sm leading-6 text-muted-foreground md:col-span-2">
                                    Để trống hai trường mật khẩu nếu bạn chỉ muốn cập nhật thông tin
                                    nhân viên.
                                </p>
                            )}
                            {formNotice && (
                                <OperationNotice
                                    message={formNotice}
                                    success={false}
                                    onClose={() => setFormNotice("")}
                                    className="md:col-span-2"
                                />
                            )}
                        </div>
                        <DialogFooter className="gap-3 border-t bg-muted/20 px-5 py-4 sm:px-7 sm:space-x-0">
                            <Button
                                type="button"
                                variant="ghost"
                                disabled={mutation.isPending}
                                onClick={() => changeEditorOpen(false)}
                            >
                                Hủy
                            </Button>
                            <Button type="submit" disabled={mutation.isPending}>
                                <UserPlus className="size-4" />
                                {mutation.isPending
                                    ? "Đang lưu..."
                                    : editing
                                      ? "Lưu thay đổi"
                                      : "Thêm nhân viên"}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <OperationNotice
                message={notice}
                success={noticeSuccess}
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
                    placeholder="Tìm theo tên hoặc email..."
                />
            </div>
            <div className="mt-5 overflow-x-auto rounded-xl border bg-card shadow-sm">
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                ) : !query.data?.data.length ? (
                    <EmptyState message="Chưa có tài khoản nhân viên." />
                ) : (
                    <table className="w-full min-w-[560px] text-sm">
                        <thead className="bg-muted/45">
                            <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <th className="p-4">Nhân viên</th>
                                <th className="p-4">Email</th>
                                <th className="p-4">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            {query.data.data.map((staff) => (
                                <tr
                                    key={staff.id}
                                    className="border-b transition-colors last:border-0 hover:bg-muted/25"
                                >
                                    <td className="p-4 font-semibold text-primary">{staff.name}</td>
                                    <td className="p-4">{staff.email}</td>
                                    <td className="p-4">
                                        <div className="flex flex-wrap items-center gap-4">
                                            <button
                                                type="button"
                                                className="font-semibold text-primary"
                                                onClick={() => openEditDialog(staff)}
                                            >
                                                Chỉnh sửa
                                            </button>
                                            <button
                                                type="button"
                                                className="font-semibold text-red-700 hover:text-red-800"
                                                onClick={() => setDeleting(staff)}
                                            >
                                                Xóa
                                            </button>
                                        </div>
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
            <AlertDialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open && !deleteMutation.isPending) setDeleting(null);
                }}
            >
                <AlertDialogContent className="w-[calc(100%-2rem)] max-w-md rounded-2xl">
                    <AlertDialogHeader>
                        <AlertDialogTitle>Xóa nhân viên?</AlertDialogTitle>
                        <AlertDialogDescription className="leading-6">
                            Bạn có chắc muốn xóa tài khoản {deleting?.name}? Thao tác này sẽ chấm
                            dứt quyền đăng nhập của nhân viên và không thể hoàn tác.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={deleteMutation.isPending}>
                            Hủy
                        </AlertDialogCancel>
                        <Button
                            type="button"
                            className="border-red-700 bg-red-700 hover:bg-red-800"
                            disabled={!deleting || deleteMutation.isPending}
                            onClick={() => deleting && deleteMutation.mutate(deleting.id)}
                        >
                            {deleteMutation.isPending ? "Đang xóa..." : "Xóa nhân viên"}
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AdminGuard>
    );
}
